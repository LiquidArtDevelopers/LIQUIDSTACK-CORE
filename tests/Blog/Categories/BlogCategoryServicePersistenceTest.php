<?php

declare(strict_types=1);

namespace Tests\Blog\Categories;

use App\Core\Blog\Categories\Audit\BlogCategoryAuditEvent;
use App\Core\Blog\Categories\Audit\BlogCategoryAuditPortInterface;
use App\Core\Blog\Categories\BlogCategoryDraft;
use App\Core\Blog\Categories\BlogCategoryException;
use App\Core\Blog\Categories\BlogCategoryLocalization;
use App\Core\Blog\Categories\BlogCategoryPublicProjectionService;
use App\Core\Blog\Categories\BlogReservedCategoryPolicy;
use App\Core\Blog\Categories\BlogCategoryService;
use App\Core\Blog\Categories\Persistence\BlogCategoryRepositoryInterface;
use App\Core\Blog\Categories\Persistence\PdoBlogCategoryRepository;
use App\Core\Blog\Categories\PublishedCategoryFilter;
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
        self::assertSame(
            ['es'],
            $this->service->localesForCategory($created->categoryPublicId())
        );
        $this->service->addLocalization(
            $this->gate(),
            $created->categoryPublicId(),
            'eu',
            new BlogCategoryDraft('Albisteak', 'albisteak')
        );
        self::assertSame(
            ['es', 'eu'],
            $this->service->localesForCategory($created->categoryPublicId())
        );
        try {
            $this->service->localesForCategory(
                '99999999-9999-4999-8999-999999999999'
            );
            self::fail('An unknown category aggregate must not be projected.');
        } catch (BlogCategoryException $exception) {
            self::assertSame(
                BlogCategoryException::NOT_FOUND,
                $exception->issueCode()
            );
        }
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

    public function testReservedDummyCategoryIsHiddenAndImmutable(): void
    {
        $this->insertLegacyDummy('es', 91);
        $this->insertLegacyDummy('en', 92);
        $migrations = iterator_to_array(
            BlogMigrationProvider::migrations(),
            false
        );
        $seeds = array_values(array_filter(
            $migrations,
            static fn ($migration): bool =>
                $migration->id() === '0017_blog_dummy_category'
        ));
        self::assertCount(1, $seeds);
        $seed = $seeds[0];
        self::assertSame('0017_blog_dummy_category', $seed->id());
        foreach ($seed->statementsFor('sqlite', $this->scope) as $sql) {
            $this->pdo->exec($sql);
        }
        foreach ($seed->statementsFor('sqlite', $this->scope) as $sql) {
            $this->pdo->exec($sql);
        }
        self::assertSame(3, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ls_blog_category_locales "
                . "WHERE slug = 'dummy'"
        )->fetchColumn());

        self::assertSame([], $this->service->list());
        self::assertSame(
            BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID,
            $this->service->reservedCategoryPublicId()
        );

        foreach ([
            fn () => $this->service->create(
                $this->gate(),
                'es',
                new BlogCategoryDraft('Dummy', 'dummy')
            ),
            fn () => $this->service->addLocalization(
                $this->gate(),
                BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID,
                'es',
                new BlogCategoryDraft('Pruebas', 'pruebas')
            ),
            fn () => $this->service->deleteLocalization(
                $this->gate(),
                BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID,
                BlogReservedCategoryPolicy::DUMMY_LOCALE,
                1
            ),
            fn () => $this->service->load(
                BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID,
                BlogReservedCategoryPolicy::DUMMY_LOCALE
            ),
            fn () => $this->service->localesForCategory(
                BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID
            ),
        ] as $operation) {
            try {
                $operation();
                self::fail('The reserved category must be immutable.');
            } catch (BlogCategoryException $exception) {
                self::assertSame(
                    BlogCategoryException::RESERVED,
                    $exception->issueCode()
                );
            }
        }

        $post = $this->insertPost(false);
        try {
            $this->service->assignToPost($this->gate(), $post, [
                BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID,
            ]);
            self::fail('Ordinary assignment cannot add Dummy.');
        } catch (BlogCategoryException $exception) {
            self::assertSame(
                BlogCategoryException::RESERVED,
                $exception->issueCode()
            );
        }
    }

    public function testLocalizedDeletionIsOptimisticAndRejectsAssignedAggregate(): void
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

        self::assertFalse($this->service->deleteLocalization(
            $this->gate(),
            $created->categoryPublicId(),
            'es',
            1
        ));
        self::assertSame(['eu'], $this->service->localesForCategory(
            $created->categoryPublicId()
        ));

        $post = $this->insertPost(false);
        $this->service->assignToPost(
            $this->gate(),
            $post,
            [$created->categoryPublicId()]
        );
        try {
            $this->service->deleteLocalization(
                $this->gate(),
                $created->categoryPublicId(),
                'eu',
                1
            );
            self::fail('An assigned category cannot be deleted.');
        } catch (BlogCategoryException $exception) {
            self::assertSame(
                BlogCategoryException::IN_USE,
                $exception->issueCode()
            );
        }
        self::assertSame(['eu'], $this->service->localesForCategory(
            $created->categoryPublicId()
        ));

        $this->service->assignToPost($this->gate(), $post, []);
        self::assertTrue($this->service->deleteLocalization(
            $this->gate(),
            $created->categoryPublicId(),
            'eu',
            1
        ));
        try {
            $this->service->localesForCategory($created->categoryPublicId());
            self::fail('The empty aggregate must be removed.');
        } catch (BlogCategoryException $exception) {
            self::assertSame(
                BlogCategoryException::NOT_FOUND,
                $exception->issueCode()
            );
        }
        self::assertSame(
            ['create', 'add_locale', 'delete', 'assign', 'assign', 'delete'],
            array_map(
                static fn (BlogCategoryAuditEvent $event): string =>
                    $event->operation(),
                $this->audit->events
            )
        );
    }

    public function testLocaleLookupSupportsTheOriginalRepositoryContract(): void
    {
        $repository = $this->createMock(
            BlogCategoryRepositoryInterface::class
        );
        $spanish = $this->localization(
            '80000000-0000-4000-8000-000000000002',
            '81000000-0000-4000-8000-000000000002',
            'es'
        );
        $basque = $this->localization(
            '80000000-0000-4000-8000-000000000002',
            '81000000-0000-4000-8000-000000000003',
            'eu'
        );
        $repository->expects(self::exactly(3))
            ->method('category')
            ->willReturnCallback(static fn (
                string $categoryPublicId,
                string $locale
            ): ?BlogCategoryLocalization => match ($locale) {
                'es' => $spanish,
                'en' => null,
                'eu' => $basque,
                default => throw new \RuntimeException(
                    'Unexpected compatibility-locale request.'
                ),
            });

        self::assertSame(
            ['es', 'eu'],
            (new BlogCategoryService($repository))->localesForCategory(
                '80000000-0000-4000-8000-000000000002',
                ['es', 'en', 'eu']
            )
        );
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

    public function testPublicFiltersFailClosedInsteadOfTruncatingOverflow(): void
    {
        $postPublicId = $this->insertPost(true);
        $postLookup = $this->pdo->prepare(
            'SELECT id FROM ls_blog_posts WHERE public_id = ?'
        );
        $postLookup->execute([$postPublicId]);
        $postId = (int) $postLookup->fetchColumn();
        $categoryLookup = $this->pdo->prepare(
            'SELECT id FROM ls_blog_categories WHERE public_id = ?'
        );
        $assignment = $this->pdo->prepare(
            'INSERT INTO ls_blog_post_categories '
            . '(public_id, post_id, category_id, '
            . 'assigned_by_user_public_id) VALUES (?, ?, ?, ?)'
        );

        for (
            $sequence = 1;
            $sequence <= BlogCategoryRepositoryInterface::MAX_PUBLIC_FILTERS;
            ++$sequence
        ) {
            $categoryPublicId = $this->insertCategory($sequence);
            $categoryLookup->execute([$categoryPublicId]);
            $assignment->execute([
                sprintf(
                    '82000000-0000-4000-8000-%012x',
                    $sequence
                ),
                $postId,
                (int) $categoryLookup->fetchColumn(),
                self::ACTOR,
            ]);
        }
        $projection = new BlogCategoryPublicProjectionService(
            BlogConfig::defaults(['es']),
            $this->repository
        );
        self::assertCount(
            BlogCategoryRepositoryInterface::MAX_PUBLIC_FILTERS,
            $projection->filtersForLocale('es')
        );

        $overflowSequence =
            BlogCategoryRepositoryInterface::MAX_PUBLIC_FILTERS + 1;
        $overflowCategory = $this->insertCategory($overflowSequence);
        $categoryLookup->execute([$overflowCategory]);
        $assignment->execute([
            sprintf(
                '82000000-0000-4000-8000-%012x',
                $overflowSequence
            ),
            $postId,
            (int) $categoryLookup->fetchColumn(),
            self::ACTOR,
        ]);

        try {
            $projection->filtersForLocale('es');
            self::fail('A public filter overflow must not be truncated.');
        } catch (BlogCategoryException $exception) {
            self::assertSame(
                BlogCategoryException::STORAGE_UNAVAILABLE,
                $exception->issueCode()
            );
        }
    }

    public function testProjectionAlsoBoundsAlternativeRepositoryAdapters(): void
    {
        $filter = new PublishedCategoryFilter(
            '83000000-0000-4000-8000-000000000001',
            'es',
            'matrix',
            'Matrix',
            1
        );
        $repository = $this->createMock(
            BlogCategoryRepositoryInterface::class
        );
        $repository->expects(self::once())
            ->method('publicFilters')
            ->with('es')
            ->willReturn(array_fill(
                0,
                BlogCategoryRepositoryInterface::MAX_PUBLIC_FILTERS + 1,
                $filter
            ));
        $projection = new BlogCategoryPublicProjectionService(
            BlogConfig::defaults(['es']),
            $repository
        );

        try {
            $projection->filtersForLocale('es');
            self::fail('An alternative adapter cannot bypass the public cap.');
        } catch (BlogCategoryException $exception) {
            self::assertSame(
                BlogCategoryException::STORAGE_UNAVAILABLE,
                $exception->issueCode()
            );
        }
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

    private function insertLegacyDummy(string $locale, int $sequence): void
    {
        $category = sprintf(
            '82000000-0000-4000-8000-%012x',
            $sequence
        );
        $localization = sprintf(
            '83000000-0000-4000-8000-%012x',
            $sequence
        );
        $this->pdo->prepare(
            'INSERT INTO ls_blog_categories '
                . '(public_id, created_by_user_public_id) VALUES (?, ?)'
        )->execute([$category, self::ACTOR]);
        $categoryId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO ls_blog_category_locales '
                . '(public_id, category_id, locale, slug, name, '
                . 'created_by_user_public_id, updated_by_user_public_id) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $localization,
            $categoryId,
            $locale,
            'dummy',
            'Dummy legacy',
            self::ACTOR,
            self::ACTOR,
        ]);
    }

    private function localization(
        string $categoryPublicId,
        string $localizationPublicId,
        string $locale
    ): BlogCategoryLocalization {
        return new BlogCategoryLocalization(
            $categoryPublicId,
            $localizationPublicId,
            $locale,
            new BlogCategoryDraft('Matrix', 'matrix-' . $locale),
            1,
            new DateTimeImmutable('2030-01-01 10:00:00 UTC')
        );
    }
}
