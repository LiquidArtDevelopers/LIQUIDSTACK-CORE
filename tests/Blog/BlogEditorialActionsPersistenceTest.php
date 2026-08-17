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
use App\Core\Blog\EditorialWorkflow\Persistence\PdoBlogEditorialWorkspaceRepository;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Document\BlogDocumentV2Projector;
use App\Core\Blog\StructuredContent\Document\BlogDocumentWalker;
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
use PHPUnit\Framework\Attributes\DataProvider;
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
            '0019_blog_copy_operation_idempotency',
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

    public function testCopyFlowsCloneOnlyCurrentPrivateStateAsDraft(): void
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
        $duplicateRevisions = $this->content->listRevisions(
            $duplicate->localizationPublicId(),
            10,
            0
        );
        self::assertCount(1, $duplicateRevisions);
        self::assertSame(1, $duplicateRevisions[0]->revisionNumber());
        self::assertSame(1, $duplicateRevisions[0]->variantLockVersion());
        self::assertSame(1, $duplicateRevisions[0]->mediaCount());
        self::assertSame(
            $structured->canonicalJson(),
            $this->content->revision(
                $duplicateRevisions[0]->revisionPublicId()
            )?->snapshot()->canonicalJson()
        );
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

        $publishedSource = $this->service([])->publish(
            $this->gate(),
            $source->postPublicId(),
            'es',
            1
        );
        self::assertSame(BlogPostVariant::PUBLISHED, $publishedSource->status());

        $localeAudit = new EditorialActionAudit();
        $localeCopy = $this->service([
            $this->id(35),
            $this->id(36),
            $this->id(37),
        ], $localeAudit)->addLocalizationCopy(
            $this->gate(),
            $source->postPublicId(),
            'es',
            'eu',
            2
        );

        self::assertSame($source->postPublicId(), $localeCopy->postPublicId());
        self::assertSame('eu', $localeCopy->locale());
        self::assertSame(BlogPostVariant::DRAFT, $localeCopy->status());
        self::assertSame(1, $localeCopy->lockVersion());
        self::assertNull($localeCopy->draft()->slug());
        self::assertSame($sourceH1, $localeCopy->draft()->h1());
        self::assertSame(
            $structured->canonicalJson(),
            $this->content->current(
                $localeCopy->localizationPublicId()
            )?->snapshot()->canonicalJson()
        );
        $localeRevisions = $this->content->listRevisions(
            $localeCopy->localizationPublicId(),
            10,
            0
        );
        self::assertCount(1, $localeRevisions);
        self::assertSame(1, $localeRevisions[0]->revisionNumber());
        self::assertSame(1, $localeRevisions[0]->variantLockVersion());
        self::assertSame(1, $localeRevisions[0]->mediaCount());
        self::assertSame(
            $structured->canonicalJson(),
            $this->content->revision(
                $localeRevisions[0]->revisionPublicId()
            )?->snapshot()->canonicalJson()
        );
        self::assertSame(
            [[self::MEDIA], [self::MEDIA]],
            $this->media->checks
        );
        self::assertSame(3, $this->rowCount('content_media'));
        self::assertSame(3, $this->rowCount('revision_media'));
        self::assertSame(2, $this->rowCount('post_categories'));
        self::assertSame(
            [$category->categoryPublicId()],
            $this->categoryIds($localeCopy->postPublicId())
        );
        self::assertCount(1, $localeAudit->events);
        self::assertSame(
            BlogMutationAuditEvent::ADD_LOCALE,
            $localeAudit->events[0]->operation()
        );
        self::assertSame(
            $source->postPublicId(),
            $localeAudit->events[0]->postPublicId()
        );
        self::assertSame(BlogPostVariant::PUBLISHED, $this->repository->variant(
            $source->postPublicId(),
            'es'
        )?->status());
        self::assertSame('matrix-source', $this->service([])->resolvePublished(
            'es',
            'matrix-source'
        )?->draft()->slug());
        self::assertSame(
            $structured->canonicalJson(),
            $this->content->current(
                $source->localizationPublicId()
            )?->snapshot()->canonicalJson()
        );
        $sourceRevisions = $this->content->listRevisions(
            $source->localizationPublicId(),
            10,
            0
        );
        self::assertCount(1, $sourceRevisions);
        self::assertSame(1, $sourceRevisions[0]->revisionNumber());
        self::assertSame(1, $sourceRevisions[0]->variantLockVersion());
        $this->expectIssue(BlogException::LOCALE_CONFLICT, fn () =>
            $this->service([$this->id(38)])->addLocalizationCopy(
                $this->gate(),
                $source->postPublicId(),
                'es',
                'eu',
                2
            )
        );
        self::assertSame(3, $this->rowCount('post_localizations'));
    }

    public function testCopyOperationIsExactlyIdempotentAndRejectsKeyDrift(): void
    {
        $source = $this->service([
            $this->id(201),
            $this->id(202),
        ])->createPost(
            $this->gate(),
            'es',
            $this->completeDraft('idempotent-source')
        );
        $audit = new EditorialActionAudit();
        $service = $this->service([
            $this->id(203), $this->id(204),
            $this->id(205), $this->id(206),
            $this->id(207),
            $this->id(208), $this->id(209),
        ], $audit);
        $operation = $this->id(250);

        $created = $service->duplicatePost(
            $this->gate(),
            $source->postPublicId(),
            'es',
            1,
            $operation
        );
        $replayed = $service->duplicatePost(
            $this->gate(),
            $source->postPublicId(),
            'es',
            1,
            $operation
        );

        self::assertSame($created->postPublicId(), $replayed->postPublicId());
        self::assertSame(
            $created->localizationPublicId(),
            $replayed->localizationPublicId()
        );
        self::assertSame(2, $this->rowCount('posts'));
        self::assertSame(2, $this->rowCount('post_localizations'));
        self::assertSame(1, $this->rowCount('copy_operations'));
        self::assertCount(1, $audit->events);

        $this->expectIssue(BlogException::IDEMPOTENCY_CONFLICT, fn () =>
            $service->addLocalizationCopy(
                $this->gate(),
                $source->postPublicId(),
                'es',
                'en',
                1,
                $operation
            )
        );
        $otherActor = static fn (PDO $pdo): string =>
            'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $this->expectIssue(BlogException::IDEMPOTENCY_CONFLICT, fn () =>
            $service->duplicatePost(
                $otherActor,
                $source->postPublicId(),
                'es',
                1,
                $operation
            )
        );
        self::assertSame(2, $this->rowCount('posts'));
        self::assertSame(2, $this->rowCount('post_localizations'));
        self::assertSame(1, $this->rowCount('copy_operations'));
    }

    public function testCopyReplayReportsItsRecoverableTrashedDestination(): void
    {
        $source = $this->service([
            $this->id(251),
            $this->id(252),
        ])->createPost(
            $this->gate(),
            'es',
            $this->completeDraft('replay-trash-source')
        );
        $audit = new EditorialActionAudit();
        $service = $this->service([
            $this->id(253), $this->id(254),
            $this->id(255), $this->id(256),
            $this->id(257), $this->id(258),
            $this->id(259),
        ], $audit);
        $operation = $this->id(260);

        $created = $service->duplicatePost(
            $this->gate(),
            $source->postPublicId(),
            'es',
            1,
            $operation
        );
        $trashed = $service->trashPost(
            $this->gate(),
            $created->postPublicId(),
            'es',
            1
        );
        self::assertSame(2, $trashed->lockVersion());

        $this->expectIssue(BlogException::COPY_RESULT_TRASHED, fn () =>
            $service->duplicatePost(
                $this->gate(),
                $source->postPublicId(),
                'es',
                1,
                $operation
            )
        );
        self::assertSame(2, $this->rowCount('posts'));
        self::assertSame(2, $this->rowCount('post_localizations'));
        self::assertSame(1, $this->rowCount('post_tombstones'));
        self::assertSame(1, $this->rowCount('copy_operations'));
        self::assertSame([
            BlogMutationAuditEvent::DUPLICATE,
            BlogMutationAuditEvent::TRASH,
        ], array_map(
            static fn (BlogMutationAuditEvent $event): string =>
                $event->operation(),
            $audit->events
        ));
    }

    public function testDuplicateDoesNotReintroduceStandaloneWrittenModules(): void
    {
        $this->applyMigrations([
            '0011_blog_layout_editor_v2',
            '0014_blog_private_draft_publication',
        ]);
        $layoutContent = new PdoBlogStructuredContentRepository(
            $this->pdo,
            $this->scope,
            layoutReady: true
        );
        $workflow = new PdoBlogEditorialWorkspaceRepository(
            $this->pdo,
            $this->scope
        );
        $sourceSnapshot = new BlogStructuredDraft(
            'Texto unificado',
            BlogDocument::fromArray([
                'schema' => BlogDocument::SCHEMA,
                'version' => BlogDocument::LAYOUT_VERSION,
                'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
                'blocks' => [[
                    'id' => $this->id(80),
                    'type' => 'section',
                    'children' => [[
                        'id' => $this->id(81),
                        'type' => 'heading',
                        'level' => 2,
                        'content' => [[
                            'type' => 'text',
                            'text' => 'Titulo heredado',
                            'marks' => [],
                        ]],
                        'preset' => 'accent-line',
                        'presentation' => [
                            'width' => 'full',
                            'align' => 'start',
                            'text_align' => 'start',
                            'size' => 'm',
                            'font_weight' => 'default',
                            'text_color' => 'default',
                            'spacing_before' => 'none',
                            'spacing_after' => 'none',
                        ],
                    ], [
                        'id' => $this->id(82),
                        'type' => 'list',
                        'ordered' => false,
                        'items' => [[
                            'id' => $this->id(83),
                            'content' => [[
                                'type' => 'text',
                                'text' => 'Punto heredado',
                                'marks' => [],
                            ]],
                        ]],
                        'marker' => 'square',
                        'presentation' => [
                            'width' => '80',
                            'align' => 'center',
                            'text_align' => 'start',
                            'size' => 'm',
                            'text_color' => 'color02',
                            'spacing_before' => 's',
                            'spacing_after' => 'm',
                        ],
                    ]],
                ]],
            ]),
            'texto-unificado',
            'SEO texto unificado',
            'Descripcion de texto unificado.',
            'Extracto de texto unificado.'
        );
        $source = $this->service([
            $this->id(84),
            $this->id(85),
        ])->createPost(
            $this->gate(),
            'es',
            $sourceSnapshot->compatibilityDraft()
        );
        $categoryRepository = new PdoBlogCategoryRepository(
            $this->pdo,
            $this->scope,
            true
        );
        $liveCategories = new BlogCategoryService(
            $categoryRepository,
            new EditorialActionUuidSequence([
                $this->id(100),
                $this->id(101),
                $this->id(102),
                $this->id(103),
                $this->id(104),
            ]),
            $this->clock
        );
        $liveCategory = $liveCategories->create(
            $this->gate(),
            'es',
            new BlogCategoryDraft('Categoria publica', 'categoria-publica')
        );
        $privateCategory = $liveCategories->create(
            $this->gate(),
            'es',
            new BlogCategoryDraft('Categoria privada', 'categoria-privada')
        );
        $liveCategories->assignToPost(
            $this->gate(),
            $source->postPublicId(),
            [$liveCategory->categoryPublicId()]
        );
        $this->repository->transactional(function () use (
            $layoutContent,
            $source,
            $sourceSnapshot
        ): void {
            $layoutContent->upsertCurrent(
                $source->localizationPublicId(),
                $this->id(86),
                $sourceSnapshot,
                self::ACTOR,
                $this->clock->now()
            );
            $layoutContent->replaceCurrentMedia(
                $source->localizationPublicId(),
                [],
                $this->clock->now()
            );
        });
        $published = $this->service([])->publish(
            $this->gate(),
            $source->postPublicId(),
            'es',
            1
        );
        self::assertSame(BlogPostVariant::PUBLISHED, $published->status());
        $privateCategories = new BlogCategoryService(
            $categoryRepository,
            new EditorialActionUuidSequence([$this->id(105)]),
            $this->clock,
            workflowRepository: $workflow
        );
        self::assertSame(1, $privateCategories->assignToVariant(
            $this->gate(),
            $source->postPublicId(),
            'es',
            $published->lockVersion(),
            0,
            [$privateCategory->categoryPublicId()]
        ));
        self::assertSame(
            [$liveCategory->categoryPublicId()],
            $this->categoryIds($source->postPublicId())
        );
        self::assertSame(
            [$privateCategory->categoryPublicId()],
            $workflow->workspaceCategoryPublicIds($source->postPublicId())
        );
        $privateSnapshot = new BlogStructuredDraft(
            'Texto privado',
            (new BlogDocumentV2Projector(
                new EditorialActionUuidSequence([])
            ))->project($sourceSnapshot->document()),
            'texto-privado',
            'SEO privado',
            'Descripcion privada.',
            'Extracto privado.'
        );
        $privateRevisionPublicId = $this->id(90);
        $this->repository->transactional(function () use (
            $layoutContent,
            $workflow,
            $source,
            $privateSnapshot,
            $privateRevisionPublicId
        ): void {
            $layoutContent->appendPrivateRevision(
                $source->localizationPublicId(),
                $privateRevisionPublicId,
                2,
                $privateSnapshot,
                self::ACTOR,
                $this->clock->now()
            );
            $layoutContent->appendRevisionMedia(
                $privateRevisionPublicId,
                [],
                $this->clock->now()
            );
            $workflow->storeDraftRevision(
                $source->localizationPublicId(),
                $privateRevisionPublicId,
                0,
                self::ACTOR,
                $this->clock->now()
            );
            self::assertTrue($workflow->advancePrivateLock(
                $source->localizationPublicId(),
                2,
                self::ACTOR
            ));
        });
        $copy = (new BlogService(
            $this->repository,
            new EditorialActionUuidSequence([
                $this->id(91),
                $this->id(92),
                $this->id(93),
                $this->id(94),
                $this->id(95),
            ]),
            $this->clock,
            structuredContentRepository: $layoutContent,
            mediaAvailability: $this->media,
            editorialWorkflowRepository: $workflow,
            layoutProjector: new BlogDocumentV2Projector(
                new EditorialActionUuidSequence([])
            )
        ))->duplicatePost(
            $this->gate(),
            $source->postPublicId(),
            'es',
            3
        );

        self::assertStringStartsWith('Copia de Texto privado', $copy->draft()->h1());
        self::assertSame('SEO privado', $copy->draft()->seoTitle());
        self::assertSame(
            [$privateCategory->categoryPublicId()],
            $this->categoryIds($copy->postPublicId())
        );
        $copySnapshot = $layoutContent->current(
            $copy->localizationPublicId()
        )?->snapshot();
        self::assertNotNull($copySnapshot);
        $modules = (new BlogDocumentWalker())->modules(
            $copySnapshot->document()
        );
        self::assertSame(['paragraph', 'paragraph'], array_column(
            $modules,
            'type'
        ));
        self::assertSame(
            [$this->id(81), $this->id(82)],
            array_column($modules, 'id')
        );
        self::assertSame('accent-line', $modules[0]['content'][0]['preset']);
        self::assertSame('square', $modules[1]['content'][0]['marker']);
        self::assertSame(
            [$this->id(83)],
            array_column($modules[1]['content'][0]['items'], 'id')
        );
        $copyRevisions = $layoutContent->listRevisions(
            $copy->localizationPublicId(),
            10,
            0
        );
        self::assertCount(1, $copyRevisions);
        self::assertSame(1, $copyRevisions[0]->revisionNumber());
        self::assertSame(1, $copyRevisions[0]->variantLockVersion());
        self::assertSame(0, $copyRevisions[0]->mediaCount());
        self::assertSame(
            $copySnapshot->canonicalJson(),
            $layoutContent->revision(
                $copyRevisions[0]->revisionPublicId()
            )?->snapshot()->canonicalJson()
        );
        self::assertSame(
            $sourceSnapshot->canonicalJson(),
            $layoutContent->current(
                $source->localizationPublicId()
            )?->snapshot()->canonicalJson()
        );
        self::assertSame(
            $privateRevisionPublicId,
            $workflow->workspace(
                $source->localizationPublicId()
            )?->draftRevisionPublicId()
        );
        self::assertSame(
            $privateSnapshot->canonicalJson(),
            $layoutContent->revision(
                $privateRevisionPublicId
            )?->snapshot()->canonicalJson()
        );
        $sourceRevisions = $layoutContent->listRevisions(
            $source->localizationPublicId(),
            10,
            0
        );
        self::assertCount(1, $sourceRevisions);
        self::assertSame(1, $sourceRevisions[0]->revisionNumber());
        self::assertSame(3, $sourceRevisions[0]->variantLockVersion());
        self::assertSame(
            [$liveCategory->categoryPublicId()],
            $this->categoryIds($source->postPublicId())
        );
        self::assertSame(
            [$privateCategory->categoryPublicId()],
            $workflow->workspaceCategoryPublicIds($source->postPublicId())
        );
        self::assertNull($workflow->publicationHead(
            $source->localizationPublicId()
        ));
        self::assertSame(3, $this->repository->variant(
            $source->postPublicId(),
            'es'
        )?->lockVersion());
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

    public function testDuplicateRollsBackEveryStructuredCloneWriteWhenAuditFails(): void
    {
        $structured = $this->structuredDraft('Audit source');
        $source = $this->service([
            $this->id(50),
            $this->id(51),
        ])->createPost(
            $this->gate(),
            'es',
            $structured->compatibilityDraft()
        );
        $this->repository->transactional(function () use (
            $source,
            $structured
        ): void {
            $this->content->upsertCurrent(
                $source->localizationPublicId(),
                $this->id(56),
                $structured,
                self::ACTOR,
                $this->clock->now()
            );
            $this->content->replaceCurrentMedia(
                $source->localizationPublicId(),
                $structured->mediaReferences(),
                $this->clock->now()
            );
            self::assertSame(1, $this->content->appendRevision(
                $source->localizationPublicId(),
                $this->id(57),
                1,
                $structured,
                self::ACTOR,
                $this->clock->now()
            ));
            $this->content->appendRevisionMedia(
                $this->id(57),
                $structured->mediaReferences(),
                $this->clock->now()
            );
        });
        $audit = new EditorialActionAudit(BlogMutationAuditEvent::DUPLICATE);
        $service = $this->service([
            $this->id(52),
            $this->id(53),
            $this->id(54),
            $this->id(55),
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
        self::assertSame(1, $this->rowCount('content_docs'));
        self::assertSame(1, $this->rowCount('content_revisions'));
        self::assertSame(1, $this->rowCount('content_media'));
        self::assertSame(1, $this->rowCount('revision_media'));
        self::assertSame([], $audit->events);
        self::assertSame([[self::MEDIA]], $this->media->checks);
    }

    public function testAddLocaleRollsBackEveryStructuredCopyWriteWhenAuditFails(): void
    {
        $structured = $this->structuredDraft('Locale audit source');
        $source = $this->service([
            $this->id(110),
            $this->id(111),
        ])->createPost(
            $this->gate(),
            'es',
            $structured->compatibilityDraft()
        );
        $this->repository->transactional(function () use (
            $source,
            $structured
        ): void {
            $this->content->upsertCurrent(
                $source->localizationPublicId(),
                $this->id(112),
                $structured,
                self::ACTOR,
                $this->clock->now()
            );
            $this->content->replaceCurrentMedia(
                $source->localizationPublicId(),
                $structured->mediaReferences(),
                $this->clock->now()
            );
            self::assertSame(1, $this->content->appendRevision(
                $source->localizationPublicId(),
                $this->id(113),
                1,
                $structured,
                self::ACTOR,
                $this->clock->now()
            ));
            $this->content->appendRevisionMedia(
                $this->id(113),
                $structured->mediaReferences(),
                $this->clock->now()
            );
        });
        $audit = new EditorialActionAudit(BlogMutationAuditEvent::ADD_LOCALE);
        $service = $this->service([
            $this->id(114),
            $this->id(115),
            $this->id(116),
        ], $audit);

        $this->expectIssue(BlogException::STORAGE_UNAVAILABLE, fn () =>
            $service->addLocalizationCopy(
                $this->gate(),
                $source->postPublicId(),
                'es',
                'en',
                1
            )
        );

        self::assertSame(1, $this->rowCount('posts'));
        self::assertSame(1, $this->rowCount('post_localizations'));
        self::assertSame(1, $this->rowCount('content_docs'));
        self::assertSame(1, $this->rowCount('content_revisions'));
        self::assertSame(1, $this->rowCount('content_media'));
        self::assertSame(1, $this->rowCount('revision_media'));
        self::assertNull($this->repository->variant(
            $source->postPublicId(),
            'en'
        ));
        self::assertSame(
            $structured->canonicalJson(),
            $this->content->current(
                $source->localizationPublicId()
            )?->snapshot()->canonicalJson()
        );
        self::assertSame(
            $structured->canonicalJson(),
            $this->content->revision(
                $this->id(113)
            )?->snapshot()->canonicalJson()
        );
        self::assertSame(
            'matrix-source',
            $this->repository->variant(
                $source->postPublicId(),
                'es'
            )?->draft()->slug()
        );
        self::assertSame(1, $this->repository->variant(
            $source->postPublicId(),
            'es'
        )?->lockVersion());
        self::assertSame([], $audit->events);
        self::assertSame([[self::MEDIA]], $this->media->checks);
    }

    #[DataProvider('invalidPrivateWorkspaceCases')]
    public function testCopyFailsClosedForInvalidPrivateWorkspace(
        bool $publishedSource
    ): void {
        $this->applyMigrations([
            '0011_blog_layout_editor_v2',
            '0014_blog_private_draft_publication',
        ]);
        $layoutContent = new PdoBlogStructuredContentRepository(
            $this->pdo,
            $this->scope,
            layoutReady: true
        );
        $workflow = new PdoBlogEditorialWorkspaceRepository(
            $this->pdo,
            $this->scope
        );
        $publicSnapshot = $this->structuredDraft('Workspace source');
        $source = $this->service([
            $this->id(120),
            $this->id(121),
        ])->createPost(
            $this->gate(),
            'es',
            $publicSnapshot->compatibilityDraft()
        );
        $this->repository->transactional(function () use (
            $layoutContent,
            $source,
            $publicSnapshot
        ): void {
            $layoutContent->upsertCurrent(
                $source->localizationPublicId(),
                $this->id(122),
                $publicSnapshot,
                self::ACTOR,
                $this->clock->now()
            );
            $layoutContent->replaceCurrentMedia(
                $source->localizationPublicId(),
                $publicSnapshot->mediaReferences(),
                $this->clock->now()
            );
            self::assertSame(1, $layoutContent->appendRevision(
                $source->localizationPublicId(),
                $this->id(123),
                1,
                $publicSnapshot,
                self::ACTOR,
                $this->clock->now()
            ));
            $layoutContent->appendRevisionMedia(
                $this->id(123),
                $publicSnapshot->mediaReferences(),
                $this->clock->now()
            );
        });
        if ($publishedSource) {
            $source = $this->service([])->publish(
                $this->gate(),
                $source->postPublicId(),
                'es',
                1
            );
        }
        $workspaceBaseLock = $source->lockVersion();
        $privateSnapshot = $this->structuredDraft('Private workspace');
        $this->repository->transactional(function () use (
            $layoutContent,
            $workflow,
            $source,
            $privateSnapshot,
            $publishedSource,
            $workspaceBaseLock
        ): void {
            if ($publishedSource) {
                self::assertSame(1, $workflow->promotePublicationHead(
                    $source->localizationPublicId(),
                    $this->id(123),
                    0,
                    self::ACTOR,
                    $this->clock->now()
                ));
            }
            self::assertSame(2, $layoutContent->appendPrivateRevision(
                $source->localizationPublicId(),
                $this->id(124),
                $workspaceBaseLock,
                $privateSnapshot,
                self::ACTOR,
                $this->clock->now()
            ));
            $layoutContent->appendRevisionMedia(
                $this->id(124),
                $privateSnapshot->mediaReferences(),
                $this->clock->now()
            );
            $workflow->storeDraftRevision(
                $source->localizationPublicId(),
                $this->id(124),
                0,
                self::ACTOR,
                $this->clock->now()
            );
            if ($publishedSource) {
                self::assertTrue($workflow->advancePrivateLock(
                    $source->localizationPublicId(),
                    $workspaceBaseLock,
                    self::ACTOR
                ));
            }
        });
        $copyExpectedLock = $workspaceBaseLock + ($publishedSource ? 1 : 0);
        $service = new BlogService(
            $this->repository,
            new EditorialActionUuidSequence([
                $this->id(125),
                $this->id(126),
            ]),
            $this->clock,
            structuredContentRepository: $layoutContent,
            mediaAvailability: $this->media,
            editorialWorkflowRepository: $workflow
        );

        $this->expectIssue(BlogException::STORAGE_UNAVAILABLE, fn () =>
            $service->duplicatePost(
                $this->gate(),
                $source->postPublicId(),
                'es',
                $copyExpectedLock
            )
        );

        self::assertSame(1, $this->rowCount('posts'));
        self::assertSame(1, $this->rowCount('post_localizations'));
        self::assertSame(1, $this->rowCount('content_docs'));
        self::assertSame(2, $this->rowCount('content_revisions'));
        self::assertSame(1, $this->rowCount('content_media'));
        self::assertSame(2, $this->rowCount('revision_media'));
        self::assertSame(
            $publicSnapshot->canonicalJson(),
            $layoutContent->current(
                $source->localizationPublicId()
            )?->snapshot()->canonicalJson()
        );
        self::assertSame(
            $privateSnapshot->canonicalJson(),
            $layoutContent->revision(
                $this->id(124)
            )?->snapshot()->canonicalJson()
        );
        self::assertSame(
            $this->id(124),
            $workflow->workspace(
                $source->localizationPublicId()
            )?->draftRevisionPublicId()
        );
        self::assertSame(
            $publishedSource
                ? BlogPostVariant::PUBLISHED
                : BlogPostVariant::DRAFT,
            $this->repository->variant(
                $source->postPublicId(),
                'es'
            )?->status()
        );
        self::assertSame($copyExpectedLock, $this->repository->variant(
            $source->postPublicId(),
            'es'
        )?->lockVersion());
        self::assertSame([], $this->media->checks);
    }

    /** @return array<string, array{0: bool}> */
    public static function invalidPrivateWorkspaceCases(): array
    {
        return [
            'stale publication base' => [true],
            'workspace attached to draft source' => [false],
        ];
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
