<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Core\Blog\Audit\BlogMutationAuditEvent;
use App\Core\Blog\Audit\BlogMutationAuditPortInterface;
use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogException;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogService;
use App\Core\Blog\Categories\BlogCategoryDraft;
use App\Core\Blog\Categories\BlogCategoryService;
use App\Core\Blog\Categories\Persistence\PdoBlogCategoryRepository;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Media\BlogMediaAvailabilityPortInterface;
use App\Core\Blog\StructuredContent\Persistence\PdoBlogStructuredContentRepository;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EditorialActionUuidSequence implements UuidGeneratorInterface
{
    /** @param list<string> $values */
    public function __construct(private array $values)
    {
    }

    public function generateV4(): string
    {
        $value = array_shift($this->values);
        if (!is_string($value)) {
            throw new RuntimeException('Editorial UUID fixture exhausted.');
        }

        return $value;
    }
}

final class EditorialActionClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2030-01-01 10:00:00.123456',
            new DateTimeZone('UTC')
        );
    }
}

final class EditorialActionAudit implements BlogMutationAuditPortInterface
{
    /** @var list<BlogMutationAuditEvent> */
    public array $events = [];

    public function __construct(private readonly ?string $failOn = null)
    {
    }

    public function record(PDO $pdo, BlogMutationAuditEvent $event): void
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Audit must share the Blog transaction.');
        }
        if ($event->operation() === $this->failOn) {
            throw new RuntimeException('Intentional audit failure.');
        }
        $this->events[] = $event;
    }
}

final class EditorialActionMediaAvailability implements
    BlogMediaAvailabilityPortInterface
{
    /** @var list<list<string>> */
    public array $checks = [];

    public function __construct(private readonly PDO $expectedPdo)
    {
    }

    public function assertAvailable(PDO $transaction, array $mediaAssetPublicIds): void
    {
        if ($transaction !== $this->expectedPdo || !$transaction->inTransaction()) {
            throw new RuntimeException('Media validation left the Blog transaction.');
        }
        $this->checks[] = $mediaAssetPublicIds;
    }
}

final class BlogEditorialActionsPersistenceTest extends TestCase
{
    private const ACTOR = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const MEDIA = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private PDO $pdo;
    private MigrationScope $scope;
    private PdoBlogRepository $repository;
    private PdoBlogStructuredContentRepository $content;
    private EditorialActionClock $clock;
    private EditorialActionMediaAvailability $media;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->scope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $this->applyMigrations([
            '0001_blog_posts',
            '0003_blog_categories',
            '0005_blog_structured_content',
            '0006_blog_sitemap_publication_state',
            '0007_blog_post_tombstones',
        ]);
        $this->repository = new PdoBlogRepository(
            $this->pdo,
            $this->scope,
            true
        );
        $this->content = new PdoBlogStructuredContentRepository(
            $this->pdo,
            $this->scope
        );
        $this->clock = new EditorialActionClock();
        $this->media = new EditorialActionMediaAvailability($this->pdo);
    }

    public function testDuplicateClonesCurrentDocumentMediaAndCategoriesAsDraft(): void
    {
        $sourceH1 = str_repeat('é', 127) . 'a';
        $structured = $this->structuredDraft($sourceH1);
        $source = $this->service([
            $this->id(1),
            $this->id(2),
        ])->createPost(
            $this->gate(),
            'es',
            $structured->compatibilityDraft()
        );
        $this->repository->transactional(function () use ($source, $structured): void {
            $this->content->upsertCurrent(
                $source->localizationPublicId(),
                $this->id(3),
                $structured,
                self::ACTOR,
                $this->clock->now()
            );
            $this->content->replaceCurrentMedia(
                $source->localizationPublicId(),
                $structured->mediaReferences(),
                $this->clock->now()
            );
            $this->content->appendRevision(
                $source->localizationPublicId(),
                $this->id(4),
                1,
                $structured,
                self::ACTOR,
                $this->clock->now()
            );
            $this->content->appendRevisionMedia(
                $this->id(4),
                $structured->mediaReferences(),
                $this->clock->now()
            );
        });

        $categoryService = new BlogCategoryService(
            new PdoBlogCategoryRepository($this->pdo, $this->scope),
            new EditorialActionUuidSequence([
                $this->id(20),
                $this->id(21),
                $this->id(22),
            ]),
            $this->clock
        );
        $category = $categoryService->create(
            $this->gate(),
            'es',
            new BlogCategoryDraft('Fiscalidad', 'fiscalidad')
        );
        $categoryService->assignToPost(
            $this->gate(),
            $source->postPublicId(),
            [$category->categoryPublicId()]
        );

        $audit = new EditorialActionAudit();
        $duplicate = $this->service([
            $this->id(30),
            $this->id(31),
            $this->id(32),
            $this->id(33),
            $this->id(34),
        ], $audit)->duplicatePost(
            $this->gate(),
            $source->postPublicId(),
            'es',
            1
        );

        self::assertSame(BlogPostVariant::DRAFT, $duplicate->status());
        self::assertSame(1, $duplicate->lockVersion());
        self::assertNull($duplicate->draft()->slug());
        self::assertSame(
            $structured->compatibilityDraft()->seoTitle(),
            $duplicate->draft()->seoTitle()
        );
        self::assertStringStartsWith('Copia de ', $duplicate->draft()->h1());
        self::assertLessThanOrEqual(
            BlogDraft::MAX_H1_BYTES,
            strlen($duplicate->draft()->h1())
        );
        self::assertSame(1, preg_match('//u', $duplicate->draft()->h1()));

        $copyDocument = $this->content->current(
            $duplicate->localizationPublicId()
        );
        self::assertNotNull($copyDocument);
        self::assertSame(
            $structured->canonicalJson(),
            $copyDocument->snapshot()->canonicalJson()
        );
        self::assertCount(1, $this->content->listRevisions(
            $duplicate->localizationPublicId(),
            10,
            0
        ));
        self::assertSame([[self::MEDIA]], $this->media->checks);
        self::assertSame(2, $this->rowCount('content_media'));
        self::assertSame(2, $this->rowCount('revision_media'));
        self::assertSame(
            [$category->categoryPublicId()],
            $this->categoryIds($duplicate->postPublicId())
        );
        self::assertCount(1, $audit->events);
        self::assertSame(
            BlogMutationAuditEvent::DUPLICATE,
            $audit->events[0]->operation()
        );
        self::assertSame(
            $duplicate->postPublicId(),
            $audit->events[0]->postPublicId()
        );
        self::assertSame('matrix-source', $this->repository->variant(
            $source->postPublicId(),
            'es'
        )?->draft()->slug());
    }

    public function testTrashRestoreAndPublishedUnpublishBoundaryUseLockVersions(): void
    {
        $source = $this->service([
            $this->id(40),
            $this->id(41),
        ])->createPost(
            $this->gate(),
            'es',
            $this->completeDraft('recoverable')
        );
        $audit = new EditorialActionAudit();
        $service = $this->service([], $audit);

        $trashed = $service->trashPost(
            $this->gate(),
            $source->postPublicId(),
            'es',
            1
        );
        self::assertSame(2, $trashed->lockVersion());
        self::assertSame([], $service->listPosts());
        self::assertSame(2, $service->listTrashedPosts()[0]->lockVersion());
        $this->expectIssue(BlogException::VARIANT_NOT_FOUND, fn () =>
            $service->loadPost($source->postPublicId(), 'es')
        );
        $this->expectIssue(BlogException::LOCK_CONFLICT, fn () =>
            $service->restoreTrashedPost(
                $this->gate(),
                $source->postPublicId(),
                'es',
                1
            )
        );

        $restored = $service->restoreTrashedPost(
            $this->gate(),
            $source->postPublicId(),
            'es',
            2
        );
        self::assertSame(3, $restored->lockVersion());
        self::assertSame([], $service->listTrashedPosts());
        self::assertCount(1, $service->listPosts());

        $published = $service->publish(
            $this->gate(),
            $source->postPublicId(),
            'es',
            3
        );
        self::assertSame(BlogPostVariant::PUBLISHED, $published->status());
        $this->expectIssue(BlogException::INVALID_STATE, fn () =>
            $service->trashPost(
                $this->gate(),
                $source->postPublicId(),
                'es',
                4
            )
        );
        self::assertNotNull($service->resolvePublished('es', 'recoverable'));

        $draft = $service->unpublish(
            $this->gate(),
            $source->postPublicId(),
            'es',
            4
        );
        self::assertSame(5, $draft->lockVersion());
        $service->trashPost(
            $this->gate(),
            $source->postPublicId(),
            'es',
            5
        );
        self::assertSame([], $service->listPosts());
        self::assertCount(1, $service->listTrashedPosts());
        self::assertSame([
            BlogMutationAuditEvent::TRASH,
            BlogMutationAuditEvent::RESTORE_FROM_TRASH,
            BlogMutationAuditEvent::PUBLISH,
            BlogMutationAuditEvent::UNPUBLISH,
            BlogMutationAuditEvent::TRASH,
        ], array_map(
            static fn (BlogMutationAuditEvent $event): string =>
                $event->operation(),
            $audit->events
        ));
    }

    public function testDuplicateRollsBackEveryCloneWriteWhenAuditFails(): void
    {
        $source = $this->service([
            $this->id(50),
            $this->id(51),
        ])->createPost(
            $this->gate(),
            'es',
            $this->completeDraft('audit-source')
        );
        $audit = new EditorialActionAudit(BlogMutationAuditEvent::DUPLICATE);
        $service = $this->service([
            $this->id(52),
            $this->id(53),
        ], $audit);

        $this->expectIssue(BlogException::STORAGE_UNAVAILABLE, fn () =>
            $service->duplicatePost(
                $this->gate(),
                $source->postPublicId(),
                'es',
                1
            )
        );

        self::assertSame(1, $this->rowCount('posts'));
        self::assertSame(1, $this->rowCount('post_localizations'));
        self::assertSame([], $audit->events);
        self::assertSame([], $this->media->checks);
    }

    public function testDuplicateRemainsAvailableBeforeTombstoneMigration(): void
    {
        $this->pdo->exec('DROP TABLE ls_blog_post_tombstones');
        $legacyRepository = new PdoBlogRepository($this->pdo, $this->scope);
        $sourceService = new BlogService(
            $legacyRepository,
            new EditorialActionUuidSequence([$this->id(60), $this->id(61)]),
            $this->clock,
            structuredContentRepository: $this->content,
            mediaAvailability: $this->media
        );
        $source = $sourceService->createPost(
            $this->gate(),
            'es',
            $this->completeDraft('before-tombstones')
        );
        $service = new BlogService(
            $legacyRepository,
            new EditorialActionUuidSequence([$this->id(62), $this->id(63)]),
            $this->clock,
            structuredContentRepository: $this->content,
            mediaAvailability: $this->media
        );

        self::assertFalse($service->trashAvailable());
        $copy = $service->duplicatePost(
            $this->gate(),
            $source->postPublicId(),
            'es',
            1
        );
        self::assertSame('Copia de Recoverable draft', $copy->draft()->h1());
        self::assertNull($copy->draft()->slug());
        $this->expectIssue(BlogException::STORAGE_UNAVAILABLE, fn () =>
            $service->trashPost(
                $this->gate(),
                $source->postPublicId(),
                'es',
                1
            )
        );
    }

    public function testActiveAndPublicReadsDefensivelyExcludeTombstones(): void
    {
        $service = $this->service([$this->id(80), $this->id(81)]);
        $source = $service->createPost(
            $this->gate(),
            'es',
            $this->completeDraft('defensive-exclusion')
        );
        $service->publish(
            $this->gate(),
            $source->postPublicId(),
            'es',
            1
        );
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_post_tombstones '
                . '(post_localization_id, trashed_by_user_public_id, '
                . 'trashed_at) SELECT id, :actor, :trashed_at FROM '
                . 'ls_blog_post_localizations WHERE public_id = :localization'
        );
        self::assertTrue($statement->execute([
            'actor' => self::ACTOR,
            'trashed_at' => '2030-01-01 10:00:00.123456',
            'localization' => $source->localizationPublicId(),
        ]));

        self::assertSame([], $service->listPosts());
        $this->expectIssue(BlogException::VARIANT_NOT_FOUND, fn () =>
            $service->loadPost($source->postPublicId(), 'es')
        );
        self::assertNull($service->resolvePublished(
            'es',
            'defensive-exclusion'
        ));
        self::assertSame([], $service->listPublishedCards('es'));
        self::assertSame([], $service->publishedSitemapEntriesForPost(
            $source->postPublicId()
        ));
    }

    /** @param list<string> $ids */
    private function service(
        array $ids,
        ?BlogMutationAuditPortInterface $audit = null
    ): BlogService {
        return new BlogService(
            $this->repository,
            new EditorialActionUuidSequence($ids),
            $this->clock,
            $audit,
            structuredContentRepository: $this->content,
            mediaAvailability: $this->media
        );
    }

    private function structuredDraft(string $h1): BlogStructuredDraft
    {
        return new BlogStructuredDraft(
            $h1,
            BlogDocument::fromArray([
                'schema' => BlogDocument::SCHEMA,
                'version' => BlogDocument::VERSION,
                'template' => BlogDocumentTemplateRegistry::ARTICLE_COVER,
                'blocks' => [
                    [
                        'id' => $this->id(70),
                        'type' => 'image',
                        'media_asset_public_id' => self::MEDIA,
                        'alt' => 'Documento fiscal',
                        'title' => null,
                        'caption' => null,
                        'decorative' => false,
                        'display' => 'cover',
                    ],
                    [
                        'id' => $this->id(71),
                        'type' => 'paragraph',
                        'content' => [[
                            'type' => 'text',
                            'text' => 'Contenido fiscal estructurado.',
                            'marks' => [],
                        ]],
                    ],
                ],
            ]),
            'matrix-source',
            'SEO title preserved',
            'Meta description preserved.',
            'Excerpt preserved.'
        );
    }

    private function completeDraft(string $slug): BlogDraft
    {
        return new BlogDraft(
            'Recoverable draft',
            'Editorial body',
            $slug,
            'Recoverable SEO title',
            'Recoverable meta description.',
            'Recoverable excerpt.'
        );
    }

    /** @return \Closure(PDO): string */
    private function gate(): \Closure
    {
        return static function (PDO $pdo): string {
            if (!$pdo->inTransaction()) {
                throw new RuntimeException('Actor gate must run in transaction.');
            }

            return self::ACTOR;
        };
    }

    /** @param list<string> $ids */
    private function applyMigrations(array $ids): void
    {
        $wanted = array_fill_keys($ids, true);
        foreach (BlogMigrationProvider::migrations() as $migration) {
            if (!isset($wanted[$migration->id()])) {
                continue;
            }
            foreach ($migration->statementsFor('sqlite', $this->scope) as $sql) {
                $this->pdo->exec($sql);
            }
            unset($wanted[$migration->id()]);
        }
        self::assertSame([], array_keys($wanted));
    }

    /** @return list<string> */
    private function categoryIds(string $postPublicId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.public_id FROM ls_blog_post_categories pc '
                . 'JOIN ls_blog_posts p ON p.id = pc.post_id '
                . 'JOIN ls_blog_categories c ON c.id = pc.category_id '
                . 'WHERE p.public_id = :post ORDER BY c.public_id'
        );
        $statement->execute(['post' => $postPublicId]);

        return array_map(
            static fn (array $row): string => (string) $row['public_id'],
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    private function rowCount(string $suffix): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM "ls_blog_' . $suffix . '"'
        )->fetchColumn();
    }

    /** @param callable(): mixed $operation */
    private function expectIssue(string $issue, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected Blog issue ' . $issue . '.');
        } catch (BlogException $exception) {
            self::assertSame($issue, $exception->issueCode());
        }
    }

    private function id(int $value): string
    {
        return sprintf('90000000-0000-4000-8000-%012x', $value);
    }
}
