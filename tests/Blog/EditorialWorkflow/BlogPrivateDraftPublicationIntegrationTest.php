<?php

declare(strict_types=1);

namespace Tests\Blog\EditorialWorkflow;

use App\Core\Blog\Audit\BlogMutationAuditEvent;
use App\Core\Blog\Audit\BlogMutationAuditPortInterface;
use App\Core\Blog\BlogException;
use App\Core\Blog\BlogService;
use App\Core\Blog\Categories\BlogCategoryDraft;
use App\Core\Blog\Categories\BlogCategoryException;
use App\Core\Blog\Categories\BlogCategoryService;
use App\Core\Blog\Categories\Persistence\PdoBlogCategoryRepository;
use App\Core\Blog\EditorialWorkflow\Persistence\PdoBlogEditorialWorkspaceRepository;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredEditorService;
use App\Core\Blog\StructuredContent\Media\BlogMediaAvailabilityPortInterface;
use App\Core\Blog\StructuredContent\Persistence\PdoBlogStructuredContentRepository;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Blog\BlogPrivateDraftPublicationMigrationPostconditionVerifier;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class PrivateDraftClock implements ClockInterface
{
    private int $tick = 0;

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2026-08-05T10:00:' . sprintf('%02d', $this->tick++) . '+00:00'
        );
    }
}

final class PrivateDraftUuidGenerator implements UuidGeneratorInterface
{
    private int $next = 1;

    public function generateV4(): string
    {
        return sprintf(
            '40000000-0000-4000-8000-%012d',
            $this->next++
        );
    }
}

final class PrivateDraftMediaAvailability implements
    BlogMediaAvailabilityPortInterface
{
    public function assertAvailable(PDO $transaction, array $mediaAssetPublicIds): void
    {
        if (!$transaction->inTransaction() || $mediaAssetPublicIds !== []) {
            throw new \RuntimeException('Unexpected media contract.');
        }
    }
}

final class PrivateDraftAuditRecorder implements BlogMutationAuditPortInterface
{
    /** @var list<string> */
    public array $operations = [];

    public function record(PDO $pdo, BlogMutationAuditEvent $event): void
    {
        if (!$pdo->inTransaction()) {
            throw new \RuntimeException('Audit escaped transaction.');
        }
        $this->operations[] = $event->operation();
    }
}

final class BlogPrivateDraftPublicationIntegrationTest extends TestCase
{
    private const ACTOR = '40000000-0000-4000-8000-999999999999';

    public function testPublishedEditsAndCategoriesStayPrivateUntilAtomicPublish(): void
    {
        $pdo = $this->pdo();
        $scope = MigrationScope::forTablePrefix('blog', 'private_blog_');
        $this->migrate($pdo, $scope);
        self::assertTrue(
            (new BlogPrivateDraftPublicationMigrationPostconditionVerifier())
                ->verify($pdo, $scope)
        );
        $clock = new PrivateDraftClock();
        $uuids = new PrivateDraftUuidGenerator();
        $blogRepository = new PdoBlogRepository($pdo, $scope);
        $contentRepository = new PdoBlogStructuredContentRepository(
            $pdo,
            $scope,
            layoutReady: true
        );
        $workflow = new PdoBlogEditorialWorkspaceRepository($pdo, $scope);
        $media = new PrivateDraftMediaAvailability();
        $audit = new PrivateDraftAuditRecorder();
        $blog = new BlogService(
            $blogRepository,
            $uuids,
            $clock,
            $audit,
            structuredContentRepository: $contentRepository,
            mediaAvailability: $media,
            editorialWorkflowRepository: $workflow
        );
        $editor = new BlogStructuredEditorService(
            $blogRepository,
            $contentRepository,
            $media,
            $uuids,
            $clock,
            $audit,
            layoutReady: true,
            workflowRepository: $workflow
        );
        $categories = new BlogCategoryService(
            new PdoBlogCategoryRepository($pdo, $scope, true),
            $uuids,
            $clock,
            workflowRepository: $workflow
        );
        $gate = static fn (PDO $transaction): string => self::ACTOR;

        $publicDraft = $this->draft('Public H1', 'Public body', 'public-slug');
        $variant = $blog->createPost(
            $gate,
            'es',
            $publicDraft->compatibilityDraft()
        );
        $categoryA = $categories->create(
            $gate,
            'es',
            new BlogCategoryDraft('Public category', 'public-category')
        );
        $categoryB = $categories->create(
            $gate,
            'es',
            new BlogCategoryDraft('Private category', 'private-category')
        );
        $categoryWorkspaceVersion = $categories->assignToVariant(
            $gate,
            $variant->postPublicId(),
            'es',
            $variant->lockVersion(),
            0,
            [$categoryA->categoryPublicId()]
        );
        self::assertSame(1, $categoryWorkspaceVersion);
        $variant = $editor->save(
            $gate,
            $variant->postPublicId(),
            'es',
            $variant->lockVersion(),
            $publicDraft
        );
        $variant = $editor->publishSaved(
            $gate,
            $variant->postPublicId(),
            'es',
            $variant->lockVersion(),
            $categoryWorkspaceVersion
        );

        $beforeLocalization = $this->localization($pdo, $scope);
        $beforeDocument = $this->currentDocumentJson($pdo, $scope);
        self::assertSame(
            [$categoryA->categoryPublicId()],
            $categories->assignedToPost($variant->postPublicId())
        );

        $privateDraft = $this->draft(
            'Private H1',
            'Private body',
            'private-slug'
        );
        $variant = $editor->save(
            $gate,
            $variant->postPublicId(),
            'es',
            $variant->lockVersion(),
            $privateDraft
        );
        self::assertSame($beforeDocument, $this->currentDocumentJson($pdo, $scope));
        $afterPrivateSave = $this->localization($pdo, $scope);
        foreach (['h1', 'slug', 'body_text', 'status', 'updated_at'] as $field) {
            self::assertSame(
                $beforeLocalization[$field],
                $afterPrivateSave[$field],
                'Private save changed public field ' . $field
            );
        }
        self::assertSame(
            $beforeLocalization['lock_version'] + 1,
            $afterPrivateSave['lock_version']
        );
        self::assertSame(
            'Private H1',
            $editor->loadEditor(
                $variant->postPublicId(),
                'es'
            )->workingSnapshot()?->compatibilityDraft()->h1()
        );

        $categoryWorkspaceVersion = $categories->assignToVariant(
            $gate,
            $variant->postPublicId(),
            'es',
            $variant->lockVersion(),
            0,
            [$categoryB->categoryPublicId()]
        );
        self::assertSame(1, $categoryWorkspaceVersion);
        $reloaded = $blog->loadPost($variant->postPublicId(), 'es');
        self::assertSame($variant->lockVersion(), $reloaded->lockVersion());
        self::assertSame(
            [$categoryA->categoryPublicId()],
            $categories->assignedToPost($variant->postPublicId())
        );
        self::assertSame(
            [$categoryB->categoryPublicId()],
            $categories->assignedToVariant($variant->postPublicId(), 'es')
        );
        try {
            $categories->deleteLocalization(
                $gate,
                $categoryB->categoryPublicId(),
                'es',
                $categoryB->lockVersion()
            );
            self::fail('A private workspace category cannot be deleted.');
        } catch (BlogCategoryException $exception) {
            self::assertSame(
                BlogCategoryException::IN_USE,
                $exception->issueCode()
            );
        }

        try {
            $editor->publishSaved(
                $gate,
                $variant->postPublicId(),
                'es',
                $variant->lockVersion(),
                0
            );
            self::fail('A stale category workspace must block publication.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::LOCK_CONFLICT,
                $exception->issueCode()
            );
        }
        self::assertSame(
            $afterPrivateSave,
            $this->localization($pdo, $scope)
        );
        self::assertSame($beforeDocument, $this->currentDocumentJson($pdo, $scope));
        self::assertSame(
            'Private H1',
            $editor->loadEditor(
                $variant->postPublicId(),
                'es'
            )->workingSnapshot()?->compatibilityDraft()->h1()
        );
        self::assertSame(
            [$categoryA->categoryPublicId()],
            $categories->assignedToPost($variant->postPublicId())
        );
        self::assertSame(
            [$categoryB->categoryPublicId()],
            $categories->assignedToVariant($variant->postPublicId(), 'es')
        );
        self::assertSame(
            1,
            $categories->categoryWorkspaceVersion($variant->postPublicId())
        );
        self::assertSame(1, (int) $pdo->query(
            'SELECT COUNT(*) FROM '
                . $scope->quotedTable('editorial_workspaces', 'sqlite')
        )->fetchColumn());
        self::assertSame(
            ['create', 'save', 'publish', 'save'],
            $audit->operations
        );

        $published = $editor->publishSaved(
            $gate,
            $variant->postPublicId(),
            'es',
            $variant->lockVersion(),
            $categoryWorkspaceVersion
        );
        self::assertSame('published', $published->status());
        self::assertSame('Private H1', $published->draft()->h1());
        self::assertSame('private-slug', $published->draft()->slug());
        self::assertNotSame($beforeDocument, $this->currentDocumentJson($pdo, $scope));
        self::assertSame(
            [$categoryB->categoryPublicId()],
            $categories->assignedToPost($published->postPublicId())
        );
        self::assertSame(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM '
                . $scope->quotedTable('editorial_workspaces', 'sqlite'))
                ->fetchColumn()
        );
        self::assertSame(
            2,
            (int) $pdo->query('SELECT publication_version FROM '
                . $scope->quotedTable('publication_heads', 'sqlite'))
                ->fetchColumn()
        );
        self::assertSame(['create', 'save', 'publish', 'save', 'publish'], $audit->operations);

        $unchangedLocalization = $this->localization($pdo, $scope);
        $unchangedSitemap = $blog->publishedSitemapEntriesForPost(
            $published->postPublicId()
        );
        self::assertCount(1, $unchangedSitemap);
        $unchangedLastmod = $unchangedSitemap[0]->updatedAt()->format(
            'Y-m-d H:i:s.u'
        );
        $unchangedPublicationVersion = (int) $pdo->query(
            'SELECT publication_version FROM '
                . $scope->quotedTable('publication_heads', 'sqlite')
        )->fetchColumn();
        $unchangedRevisionCount = (int) $pdo->query(
            'SELECT COUNT(*) FROM '
                . $scope->quotedTable('content_revisions', 'sqlite')
        )->fetchColumn();
        $sameCategoryWorkspaceVersion = $categories->assignToVariant(
            $gate,
            $published->postPublicId(),
            'es',
            $published->lockVersion(),
            0,
            [$categoryB->categoryPublicId()]
        );
        self::assertSame(1, $sameCategoryWorkspaceVersion);

        $unchanged = $editor->publishSaved(
            $gate,
            $published->postPublicId(),
            'es',
            $published->lockVersion(),
            $sameCategoryWorkspaceVersion
        );
        self::assertSame(
            $unchangedLocalization,
            $this->localization($pdo, $scope)
        );
        self::assertSame($published->lockVersion(), $unchanged->lockVersion());
        self::assertSame(
            $unchangedLastmod,
            $blog->publishedSitemapEntriesForPost(
                $published->postPublicId()
            )[0]->updatedAt()->format('Y-m-d H:i:s.u')
        );
        self::assertSame(
            $unchangedPublicationVersion,
            (int) $pdo->query(
                'SELECT publication_version FROM '
                    . $scope->quotedTable('publication_heads', 'sqlite')
            )->fetchColumn()
        );
        self::assertSame(
            $unchangedRevisionCount,
            (int) $pdo->query(
                'SELECT COUNT(*) FROM '
                    . $scope->quotedTable('content_revisions', 'sqlite')
            )->fetchColumn()
        );
        self::assertSame(
            0,
            $categories->categoryWorkspaceVersion($published->postPublicId())
        );
        $auditAfterConsumedNoOp = $audit->operations;
        $unchanged = $editor->publishSaved(
            $gate,
            $published->postPublicId(),
            'es',
            $unchanged->lockVersion(),
            0
        );
        self::assertSame($auditAfterConsumedNoOp, $audit->operations);
        self::assertSame(
            $unchangedLocalization,
            $this->localization($pdo, $scope)
        );

        $pending = $editor->save(
            $gate,
            $published->postPublicId(),
            'es',
            $published->lockVersion(),
            $this->draft('Pending H1', 'Pending body', 'pending-slug')
        );
        $pendingCategoryVersion = $categories->assignToVariant(
            $gate,
            $pending->postPublicId(),
            'es',
            $pending->lockVersion(),
            0,
            [$categoryA->categoryPublicId()]
        );
        self::assertSame(1, $pendingCategoryVersion);
        $reloadedPending = $blog->loadPost($pending->postPublicId(), 'es');
        self::assertSame(
            $pending->lockVersion(),
            $reloadedPending->lockVersion()
        );
        self::assertSame(1, (int) $pdo->query(
            'SELECT COUNT(*) FROM '
                . $scope->quotedTable('editorial_workspaces', 'sqlite')
        )->fetchColumn());
        $unpublished = $blog->unpublish(
            $gate,
            $pending->postPublicId(),
            'es',
            $pending->lockVersion()
        );
        self::assertSame('draft', $unpublished->status());
        self::assertSame('Pending H1', $unpublished->draft()->h1());
        self::assertSame(
            'Pending H1',
            $editor->loadEditor(
                $unpublished->postPublicId(),
                'es'
            )->current()?->snapshot()->compatibilityDraft()->h1()
        );
        self::assertSame(
            [$categoryB->categoryPublicId()],
            $categories->assignedToPost($unpublished->postPublicId())
        );
        self::assertSame(
            [$categoryA->categoryPublicId()],
            $categories->assignedToVariant($unpublished->postPublicId(), 'es')
        );
        self::assertSame(
            1,
            $categories->categoryWorkspaceVersion(
                $unpublished->postPublicId()
            )
        );
        self::assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM '
                . $scope->quotedTable('editorial_workspaces', 'sqlite')
        )->fetchColumn());
        self::assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM '
                . $scope->quotedTable('publication_heads', 'sqlite')
        )->fetchColumn());
        self::assertTrue(
            (new BlogPrivateDraftPublicationMigrationPostconditionVerifier())
                ->verify($pdo, $scope)
        );
    }

    public function testIncompleteLayoutCanBeSavedButCannotBePublished(): void
    {
        $pdo = $this->pdo();
        $scope = MigrationScope::forTablePrefix('blog', 'incomplete_blog_');
        $this->migrate($pdo, $scope);
        $clock = new PrivateDraftClock();
        $uuids = new PrivateDraftUuidGenerator();
        $blogRepository = new PdoBlogRepository($pdo, $scope);
        $contentRepository = new PdoBlogStructuredContentRepository(
            $pdo,
            $scope,
            layoutReady: true
        );
        $workflow = new PdoBlogEditorialWorkspaceRepository($pdo, $scope);
        $media = new PrivateDraftMediaAvailability();
        $audit = new PrivateDraftAuditRecorder();
        $blog = new BlogService(
            $blogRepository,
            $uuids,
            $clock,
            $audit,
            structuredContentRepository: $contentRepository,
            mediaAvailability: $media,
            editorialWorkflowRepository: $workflow
        );
        $editor = new BlogStructuredEditorService(
            $blogRepository,
            $contentRepository,
            $media,
            $uuids,
            $clock,
            $audit,
            layoutReady: true,
            workflowRepository: $workflow
        );
        $gate = static fn (PDO $transaction): string => self::ACTOR;
        $draft = $this->incompleteLayoutDraft();
        $variant = $blog->createPost(
            $gate,
            'es',
            $draft->compatibilityDraft()
        );

        $variant = $editor->save(
            $gate,
            $variant->postPublicId(),
            'es',
            $variant->lockVersion(),
            $draft
        );
        self::assertSame(
            BlogDocument::LAYOUT_VERSION,
            $editor->loadEditor(
                $variant->postPublicId(),
                'es'
            )->workingSnapshot()?->schemaVersion()
        );

        try {
            $editor->publishSaved(
                $gate,
                $variant->postPublicId(),
                'es',
                $variant->lockVersion(),
                0
            );
            self::fail('An incomplete layout must not become public.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::PUBLISH_INCOMPLETE,
                $exception->issueCode()
            );
        }

        $stored = $blog->loadPost($variant->postPublicId(), 'es');
        self::assertSame('draft', $stored->status());
        self::assertSame($variant->lockVersion(), $stored->lockVersion());
        self::assertSame(['create', 'save'], $audit->operations);
    }

    public function testCategoryWorkspaceIsSharedAndCasProtectedAcrossLocales(): void
    {
        $pdo = $this->pdo();
        $scope = MigrationScope::forTablePrefix('blog', 'locale_blog_');
        $this->migrate($pdo, $scope);
        $clock = new PrivateDraftClock();
        $uuids = new PrivateDraftUuidGenerator();
        $repository = new PdoBlogRepository($pdo, $scope);
        $content = new PdoBlogStructuredContentRepository(
            $pdo,
            $scope,
            layoutReady: true
        );
        $workflow = new PdoBlogEditorialWorkspaceRepository($pdo, $scope);
        $media = new PrivateDraftMediaAvailability();
        $blog = new BlogService(
            $repository,
            $uuids,
            $clock,
            structuredContentRepository: $content,
            mediaAvailability: $media,
            editorialWorkflowRepository: $workflow
        );
        $editor = new BlogStructuredEditorService(
            $repository,
            $content,
            $media,
            $uuids,
            $clock,
            layoutReady: true,
            workflowRepository: $workflow
        );
        $categories = new BlogCategoryService(
            new PdoBlogCategoryRepository($pdo, $scope, true),
            $uuids,
            $clock,
            workflowRepository: $workflow
        );
        $gate = static fn (PDO $transaction): string => self::ACTOR;
        $esDraft = $this->draft('ES H1', 'ES body', 'es-slug');
        $enDraft = $this->draft('EN H1', 'EN body', 'en-slug');
        $es = $blog->createPost($gate, 'es', $esDraft->compatibilityDraft());
        $en = $blog->addLocalization(
            $gate,
            $es->postPublicId(),
            'en',
            $enDraft->compatibilityDraft()
        );
        $categoryA = $categories->create(
            $gate,
            'es',
            new BlogCategoryDraft('Category A', 'category-a')
        );
        $categoryB = $categories->create(
            $gate,
            'es',
            new BlogCategoryDraft('Category B', 'category-b')
        );
        $initialCategoryWorkspaceVersion = $categories->assignToVariant(
            $gate,
            $es->postPublicId(),
            'es',
            $es->lockVersion(),
            0,
            [$categoryA->categoryPublicId()]
        );
        $es = $editor->save(
            $gate,
            $es->postPublicId(),
            'es',
            $es->lockVersion(),
            $esDraft
        );
        $es = $editor->publishSaved(
            $gate,
            $es->postPublicId(),
            'es',
            $es->lockVersion(),
            $initialCategoryWorkspaceVersion
        );
        $en = $editor->save(
            $gate,
            $en->postPublicId(),
            'en',
            $en->lockVersion(),
            $enDraft
        );
        $en = $editor->publishSaved(
            $gate,
            $en->postPublicId(),
            'en',
            $en->lockVersion(),
            0
        );
        $esLockBeforeCategories = $es->lockVersion();
        $enLockBeforeCategories = $en->lockVersion();
        $categoryWorkspaceVersion = $categories->assignToVariant(
            $gate,
            $es->postPublicId(),
            'es',
            $es->lockVersion(),
            0,
            [$categoryB->categoryPublicId()]
        );
        self::assertSame(1, $categoryWorkspaceVersion);
        self::assertSame(
            [$categoryB->categoryPublicId()],
            $categories->assignedToVariant($en->postPublicId(), 'en')
        );

        try {
            $categories->assignToVariant(
                $gate,
                $en->postPublicId(),
                'en',
                $en->lockVersion(),
                0,
                [$categoryA->categoryPublicId()]
            );
            self::fail('A stale shared category workspace must conflict.');
        } catch (BlogCategoryException $exception) {
            self::assertSame(
                BlogCategoryException::LOCK_CONFLICT,
                $exception->issueCode()
            );
        }
        $categoryWorkspaceVersion = $categories->assignToVariant(
            $gate,
            $en->postPublicId(),
            'en',
            $en->lockVersion(),
            $categoryWorkspaceVersion,
            [$categoryA->categoryPublicId()]
        );
        self::assertSame(2, $categoryWorkspaceVersion);
        self::assertSame(
            $esLockBeforeCategories,
            $blog->loadPost($es->postPublicId(), 'es')->lockVersion()
        );
        self::assertSame(
            $enLockBeforeCategories,
            $blog->loadPost($en->postPublicId(), 'en')->lockVersion()
        );
        $editor->publishSaved(
            $gate,
            $es->postPublicId(),
            'es',
            $es->lockVersion(),
            $categoryWorkspaceVersion
        );
        self::assertSame(
            [$categoryA->categoryPublicId()],
            $categories->assignedToPost($es->postPublicId())
        );
        self::assertSame(
            [$categoryA->categoryPublicId()],
            $categories->assignedToVariant($en->postPublicId(), 'en')
        );
        self::assertSame(
            0,
            $categories->categoryWorkspaceVersion($es->postPublicId())
        );
        self::assertTrue(
            (new BlogPrivateDraftPublicationMigrationPostconditionVerifier())
                ->verify($pdo, $scope)
        );
    }

    private function incompleteLayoutDraft(): BlogStructuredDraft
    {
        $document = (new BlogDocumentCodec())->decodeDraft(json_encode([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => 'article-basic-01',
            'blocks' => [[
                'id' => '42000000-0000-4000-8000-000000000001',
                'type' => 'section',
                'children' => [
                    [
                        'id' => '42000000-0000-4000-8000-000000000002',
                        'type' => 'heading',
                        'level' => 2,
                        'content' => [],
                        'presentation' => [
                            'width' => 'full',
                            'align' => 'start',
                        ],
                    ],
                    [
                        'id' => '42000000-0000-4000-8000-000000000003',
                        'type' => 'paragraph',
                        'content' => [[
                            'type' => 'text',
                            'text' => 'Visible body remains valid.',
                            'marks' => [],
                        ]],
                        'presentation' => [
                            'width' => 'full',
                            'align' => 'start',
                        ],
                    ],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));

        return new BlogStructuredDraft(
            'Incomplete H1',
            $document,
            'incomplete-slug',
            'Incomplete SEO title',
            'Incomplete meta description',
            'Incomplete excerpt'
        );
    }

    private function draft(string $h1, string $body, string $slug): BlogStructuredDraft
    {
        return new BlogStructuredDraft(
            $h1,
            BlogDocument::fromArray([
                'schema' => BlogDocument::SCHEMA,
                'version' => BlogDocument::VERSION,
                'template' => 'article-basic-01',
                'blocks' => [[
                    'id' => '41000000-0000-4000-8000-000000000001',
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'text',
                        'text' => $body,
                        'marks' => [],
                    ]],
                ]],
            ]),
            $slug,
            $h1 . ' SEO',
            $h1 . ' description',
            $h1 . ' excerpt'
        );
    }

    /** @return array<string, mixed> */
    private function localization(PDO $pdo, MigrationScope $scope): array
    {
        $row = $pdo->query(
            'SELECT h1, slug, body_text, status, updated_at, lock_version FROM '
                . $scope->quotedTable('post_localizations', 'sqlite')
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $row['lock_version'] = (int) $row['lock_version'];

        return $row;
    }

    private function currentDocumentJson(PDO $pdo, MigrationScope $scope): string
    {
        $value = $pdo->query(
            'SELECT document_json FROM '
                . $scope->quotedTable('content_docs', 'sqlite')
        )->fetchColumn();
        self::assertIsString($value);

        return $value;
    }

    private function migrate(PDO $pdo, MigrationScope $scope): void
    {
        $required = array_fill_keys([
            '0001_blog_posts',
            '0003_blog_categories',
            '0005_blog_structured_content',
            '0006_blog_sitemap_publication_state',
            '0007_blog_post_tombstones',
            '0009_blog_analytics',
            '0011_blog_layout_editor_v2',
            '0012_blog_editor_preferences',
            '0014_blog_private_draft_publication',
        ], true);
        foreach (BlogMigrationProvider::migrations() as $migration) {
            if (
                !$migration instanceof MigrationDefinition
                || !isset($required[$migration->id()])
            ) {
                continue;
            }
            foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
                self::assertNotFalse($pdo->exec($sql));
            }
        }
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA ignore_check_constraints = OFF');

        return $pdo;
    }
}
