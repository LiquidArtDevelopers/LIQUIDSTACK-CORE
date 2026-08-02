<?php

declare(strict_types=1);

namespace Tests\Blog\Categories;

use App\Core\Blog\Categories\Audit\BlogCategoryAuditEvent;
use App\Core\Blog\Categories\Audit\BlogCategoryAuditPortInterface;
use App\Core\Blog\Categories\BlogCategoryDraft;
use App\Core\Blog\Categories\BlogCategoryException;
use App\Core\Blog\Categories\BlogCategoryPublicProjectionService;
use App\Core\Blog\Categories\BlogCategoryService;
use App\Core\Blog\Categories\Persistence\PdoBlogCategoryRepository;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class CategoryTestUuidGenerator implements UuidGeneratorInterface
{
    private int $sequence = 1;

    public function generateV4(): string
    {
        return sprintf(
            '70000000-0000-4000-8000-%012x',
            $this->sequence++
        );
    }
}

final class CategoryTestClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2030-01-01 10:00:00',
            new DateTimeZone('UTC')
        );
    }
}

final class CapturingCategoryAudit implements BlogCategoryAuditPortInterface
{
    /** @var list<BlogCategoryAuditEvent> */
    public array $events = [];

    public function record(PDO $pdo, BlogCategoryAuditEvent $event): void
    {
        if (!$pdo->inTransaction()) {
            throw new \RuntimeException('Audit must share the transaction.');
        }
        $this->events[] = $event;
    }
}

final class BlogCategoryServicePersistenceTest extends TestCase
{
    private PDO $pdo;
    private MigrationScope $scope;
    private PdoBlogCategoryRepository $repository;
    private BlogCategoryService $service;
    private CapturingCategoryAudit $audit;
    private const ACTOR = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->scope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $migrations = iterator_to_array(
            BlogMigrationProvider::migrations(),
            false
        );
        foreach ([$migrations[0], $migrations[2]] as $migration) {
            foreach ($migration->statementsFor('sqlite', $this->scope) as $sql) {
                $this->pdo->exec($sql);
            }
        }
        $this->repository = new PdoBlogCategoryRepository(
            $this->pdo,
            $this->scope
        );
        $this->audit = new CapturingCategoryAudit();
        $this->service = new BlogCategoryService(
            $this->repository,
            new CategoryTestUuidGenerator(),
            new CategoryTestClock(),
            $this->audit
        );
    }

    public function testLocalizedCrudUsesOptimisticConcurrencyAndSafeAudit(): void
    {
        $created = $this->service->create(
            $this->gate(),
            'es',
            new BlogCategoryDraft('Noticias', 'noticias')
        );
        $this->service->addLocalization(
            $this->gate(),
            $created->categoryPublicId(),
            'eu',
            new BlogCategoryDraft('Albisteak', 'albisteak')
        );
        $saved = $this->service->save(
            $this->gate(),
            $created->categoryPublicId(),
            'es',
            1,
            new BlogCategoryDraft('Novedades', 'novedades')
        );
        self::assertSame(2, $saved->lockVersion());
        self::assertCount(3, $this->audit->events);
        self::assertSame(
            ['create', 'add_locale', 'save'],
            array_map(
                static fn (BlogCategoryAuditEvent $event): string =>
                    $event->operation(),
                $this->audit->events
            )
        );

        try {
            $this->service->save(
                $this->gate(),
                $created->categoryPublicId(),
                'es',
                1,
                new BlogCategoryDraft('Caducada', 'caducada')
            );
            self::fail('A stale editor must conflict.');
        } catch (BlogCategoryException $exception) {
            self::assertSame(
                BlogCategoryException::LOCK_CONFLICT,
                $exception->issueCode()
            );
        }
        self::assertSame('Novedades', $this->service->load(
            $created->categoryPublicId(),
            'es'
        )->draft()->name());
    }

    public function testAssignmentsScalePastFiftyAndRollbackOnActorFailure(): void
    {
        $post = $this->insertPost(false);
        $categoryIds = [];
        for ($index = 1; $index <= 51; ++$index) {
            $categoryIds[] = $this->insertCategory($index);
        }
        self::assertCount(
            51,
            $this->service->list(
                BlogCategoryService::MAX_ASSIGNMENTS,
                0,
                'es'
            )
        );
        $this->service->assignToPost($this->gate(), $post, $categoryIds);
        self::assertSame($categoryIds, $this->service->assignedToPost($post));

        try {
            $this->service->assignToPost(
                static fn (PDO $pdo): string => throw new \RuntimeException(),
                $post,
                []
            );
            self::fail('Actor denial must fail closed.');
        } catch (\App\Core\Blog\BlogException $exception) {
            self::assertSame(
                \App\Core\Blog\BlogException::ACTOR_GATE_FAILED,
                $exception->issueCode()
            );
        }
        self::assertSame($categoryIds, $this->service->assignedToPost($post));
    }

    public function testPublicProjectionIsCompleteLocaleFilteredAndLeaksNoDbIds(): void
    {
        $post = $this->insertPost(true);
        $category = $this->service->create(
            $this->gate(),
            'es',
            new BlogCategoryDraft('Noticias', 'noticias')
        );
        $this->service->assignToPost(
            $this->gate(),
            $post,
            [$category->categoryPublicId()]
        );
        $projection = new BlogCategoryPublicProjectionService(
            BlogConfig::defaults(['es', 'eu']),
            $this->repository
        );
        $filters = $projection->filtersForLocale('es');
        self::assertSame('noticias', $filters[0]['slug']);
        self::assertSame(1, $filters[0]['count']);
        self::assertSame([], $projection->filtersForLocale('eu'));

        $cards = $projection->postsForFilter('es', 'noticias');
        self::assertSame([[
            'locale' => 'es',
            'slug' => 'matrix-publicada',
            'url' => '/blog/matrix-publicada',
            'h1' => 'Matrix publicada',
            'excerpt' => 'Una noticia completa para probar el recurso.',
            'published_at' => '2030-01-01T09:00:00+00:00',
            'updated_at' => '2030-01-01T09:30:00+00:00',
        ]], $cards);
        self::assertArrayNotHasKey('id', $filters[0]);
        self::assertArrayNotHasKey('public_id', $filters[0]);
        self::assertArrayNotHasKey('post_public_id', $cards[0]);
        self::assertArrayNotHasKey('category_id', $cards[0]);
    }

    /** @return callable(PDO): string */
    private function gate(): callable
    {
        $expected = $this->pdo;
        return static fn (PDO $pdo): string =>
            $pdo === $expected ? self::ACTOR : throw new \RuntimeException();
    }

    private function insertPost(bool $published): string
    {
        $publicId = $published
            ? '90000000-0000-4000-8000-000000000001'
            : '90000000-0000-4000-8000-000000000002';
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_posts '
            . '(public_id, created_by_user_public_id) VALUES (?, ?)'
        );
        $statement->execute([$publicId, self::ACTOR]);
        $postId = (int) $this->pdo->lastInsertId();
        if ($published) {
            $statement = $this->pdo->prepare(
                'INSERT INTO ls_blog_post_localizations '
                . '(public_id, post_id, locale, slug, h1, seo_title, '
                . 'meta_description, excerpt, body_text, status, '
                . 'published_at, created_by_user_public_id, '
                . 'updated_by_user_public_id, created_at, updated_at) VALUES '
                . '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                '91000000-0000-4000-8000-000000000001', $postId, 'es',
                'matrix-publicada', 'Matrix publicada', 'Matrix SEO',
                'Descripcion de Matrix.',
                'Una noticia completa para probar el recurso.',
                'Contenido publicado.', 'published',
                '2030-01-01 09:00:00.000000', self::ACTOR, self::ACTOR,
                '2030-01-01 08:00:00.000000',
                '2030-01-01 09:30:00.000000',
            ]);
        }

        return $publicId;
    }

    private function insertCategory(int $sequence): string
    {
        $category = sprintf(
            '80000000-0000-4000-8000-%012x',
            $sequence
        );
        $localization = sprintf(
            '81000000-0000-4000-8000-%012x',
            $sequence
        );
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_categories '
            . '(public_id, created_by_user_public_id) VALUES (?, ?)'
        );
        $statement->execute([$category, self::ACTOR]);
        $categoryId = (int) $this->pdo->lastInsertId();
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_category_locales '
            . '(public_id, category_id, locale, slug, name, '
            . 'created_by_user_public_id, updated_by_user_public_id) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $localization, $categoryId, 'es', 'categoria-' . $sequence,
            'Categoria ' . $sequence, self::ACTOR, self::ACTOR,
        ]);

        return $category;
    }
}
