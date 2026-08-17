<?php

declare(strict_types=1);

namespace Tests\Blog\Migrations;

use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentV2CompatibilityCanonicalizer;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredSnapshotHasher;
use App\Core\Blog\StructuredContent\Persistence\PdoBlogStructuredContentRepository;
use App\Core\Modules\Blog\BlogLayoutEditorMigrationPostconditionVerifier;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Blog\BlogMigrationRequirements;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class BlogLayoutEditorMigrationTest extends TestCase
{
    private const POST = '21000000-0000-4000-8000-000000000001';
    private const LOCALIZATION = '21000000-0000-4000-8000-000000000002';
    private const DOCUMENT = '21000000-0000-4000-8000-000000000003';
    private const REVISION = '21000000-0000-4000-8000-000000000004';
    private const ACTOR = '21000000-0000-4000-8000-000000000005';

    public function testCompanionSchemaIsAdditiveExactAndIdempotent(): void
    {
        $pdo = $this->sqlite();
        $scope = MigrationScope::forTablePrefix('blog', 'layout_blog_');
        $migrations = $this->migrations();
        foreach ([
            '0001_blog_posts',
            '0003_blog_categories',
            '0005_blog_structured_content',
            '0006_blog_sitemap_publication_state',
            '0007_blog_post_tombstones',
            '0009_blog_analytics',
            '0011_blog_layout_editor_v2',
        ] as $id) {
            $this->apply($pdo, $migrations[$id], $scope);
        }
        $layout = $migrations['0011_blog_layout_editor_v2'];
        $this->apply($pdo, $layout, $scope);

        self::assertInstanceOf(
            BlogLayoutEditorMigrationPostconditionVerifier::class,
            $layout->postconditionVerifier()
        );
        self::assertTrue($layout->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
        $sql = implode("\n", $layout->statementsFor('sqlite', $scope));
        self::assertStringContainsString(
            'layout_blog_content_layout_docs',
            $sql
        );
        self::assertStringContainsString(
            'layout_blog_content_layout_revisions',
            $sql
        );
        self::assertStringContainsString('"schema_version" = 2', $sql);
        self::assertStringNotContainsString('{{', $sql);

        $pdo->exec('DROP TABLE layout_blog_content_layout_revisions');
        self::assertFalse($layout->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
    }

    public function testLayoutRequirementIsAnExplicitOptionalBoundary(): void
    {
        $requirement = BlogMigrationRequirements::layoutEditor();

        self::assertSame('blog', $requirement->moduleId());
        self::assertSame('blog.layout_editor.v2', $requirement->featureId());
        self::assertSame([
            '0001_blog_posts',
            '0003_blog_categories',
            '0005_blog_structured_content',
            '0011_blog_layout_editor_v2',
        ], $requirement->migrationIds());
    }

    public function testPostconditionValidatesCanonicalCompanionsAndHashes(): void
    {
        [$pdo, $scope] = $this->layoutDatabase();
        $draft = $this->layoutDraft('Wake up, Neo.');
        $this->seedLocalization($pdo, $draft);
        $repository = new PdoBlogStructuredContentRepository(
            $pdo,
            $scope,
            layoutReady: true
        );
        $pdo->beginTransaction();
        $repository->upsertCurrent(
            self::LOCALIZATION,
            self::DOCUMENT,
            $draft,
            self::ACTOR,
            $this->now()
        );
        $repository->appendRevision(
            self::LOCALIZATION,
            self::REVISION,
            1,
            $draft,
            self::ACTOR,
            $this->now()
        );
        self::assertTrue($pdo->commit());

        $verifier = new BlogLayoutEditorMigrationPostconditionVerifier();
        self::assertTrue($verifier->verify($pdo, $scope));

        $statement = $pdo->prepare(
            'UPDATE layout_blog_content_layout_docs '
                . 'SET snapshot_sha256 = :snapshot WHERE document_id = 1'
        );
        self::assertTrue($statement->execute([
            'snapshot' => str_repeat('0', 64),
        ]));
        self::assertFalse($verifier->verify($pdo, $scope));
    }

    public function testPostconditionRejectsJsonThatIsNotARealV2Document(): void
    {
        [$pdo, $scope] = $this->layoutDatabase();
        $draft = $this->layoutDraft('Follow the white rabbit.');
        $this->seedLocalization($pdo, $draft);
        $repository = new PdoBlogStructuredContentRepository(
            $pdo,
            $scope,
            layoutReady: true
        );
        $pdo->beginTransaction();
        $repository->upsertCurrent(
            self::LOCALIZATION,
            self::DOCUMENT,
            $draft,
            self::ACTOR,
            $this->now()
        );
        self::assertTrue($pdo->commit());

        $invalid = '[]';
        $statement = $pdo->prepare(
            'UPDATE layout_blog_content_layout_docs SET '
                . 'document_json = :json, document_bytes = :bytes, '
                . 'document_sha256 = :sha WHERE document_id = 1'
        );
        self::assertTrue($statement->execute([
            'json' => $invalid,
            'bytes' => strlen($invalid),
            'sha' => hash('sha256', $invalid),
        ]));

        self::assertFalse(
            (new BlogLayoutEditorMigrationPostconditionVerifier())->verify(
                $pdo,
                $scope
            )
        );
    }

    public function testOldV2RowsWithoutTextAlignRemainReadableAndValid(): void
    {
        [$pdo, $scope] = $this->layoutDatabase();
        $draft = $this->layoutDraft('There is no spoon.');
        $this->seedLocalization($pdo, $draft);
        $repository = new PdoBlogStructuredContentRepository(
            $pdo,
            $scope,
            layoutReady: true
        );
        $pdo->beginTransaction();
        $repository->upsertCurrent(
            self::LOCALIZATION,
            self::DOCUMENT,
            $draft,
            self::ACTOR,
            $this->now()
        );
        $repository->appendRevision(
            self::LOCALIZATION,
            self::REVISION,
            1,
            $draft,
            self::ACTOR,
            $this->now()
        );
        self::assertTrue($pdo->commit());

        $legacyCandidates = array_values(array_filter(
            (new BlogDocumentV2CompatibilityCanonicalizer())
                ->candidates($draft->document()),
            static fn (string $candidate): bool =>
                !str_contains($candidate, 'text_align')
                && !str_contains($candidate, '"css"')
        ));
        $legacyJson = $legacyCandidates[0] ?? null;
        self::assertNotNull($legacyJson);
        self::assertStringNotContainsString('text_align', $legacyJson);
        self::assertStringNotContainsString('"css"', $legacyJson);
        $legacySha256 = hash('sha256', $legacyJson);
        $legacySnapshotSha256 = (new BlogStructuredSnapshotHasher())->hash(
            $draft->compatibilityDraft(),
            $legacySha256
        );
        foreach (['content_layout_docs', 'content_layout_revisions'] as $table) {
            $statement = $pdo->prepare(
                'UPDATE layout_blog_' . $table . ' SET '
                    . 'document_json = :json, document_bytes = :bytes, '
                    . 'document_sha256 = :sha, snapshot_sha256 = :snapshot'
            );
            self::assertTrue($statement->execute([
                'json' => $legacyJson,
                'bytes' => strlen($legacyJson),
                'sha' => $legacySha256,
                'snapshot' => $legacySnapshotSha256,
            ]));
        }

        $current = $repository->current(self::LOCALIZATION);
        $revision = $repository->revision(self::REVISION);
        self::assertNotNull($current);
        self::assertNotNull($revision);
        self::assertSame(
            'start',
            $current->snapshot()->document()->blocks()[0]['children'][0]
                ['presentation']['text_align']
        );
        self::assertSame(
            'start',
            $revision->snapshot()->document()->blocks()[0]['children'][0]
                ['presentation']['text_align']
        );
        self::assertSame(
            '',
            $current->snapshot()->document()->blocks()[0]['children'][2]['css']
        );
        self::assertTrue(
            (new BlogLayoutEditorMigrationPostconditionVerifier())->verify(
                $pdo,
                $scope
            )
        );
    }

    /** @return array<string, MigrationDefinition> */
    private function migrations(): array
    {
        $result = [];
        foreach (BlogMigrationProvider::migrations() as $migration) {
            $result[$migration->id()] = $migration;
        }

        return $result;
    }

    /** @return array{PDO, MigrationScope} */
    private function layoutDatabase(): array
    {
        $pdo = $this->sqlite();
        $scope = MigrationScope::forTablePrefix('blog', 'layout_blog_');
        $migrations = $this->migrations();
        foreach ([
            '0001_blog_posts',
            '0003_blog_categories',
            '0005_blog_structured_content',
            '0006_blog_sitemap_publication_state',
            '0007_blog_post_tombstones',
            '0009_blog_analytics',
            '0011_blog_layout_editor_v2',
        ] as $id) {
            $this->apply($pdo, $migrations[$id], $scope);
        }

        return [$pdo, $scope];
    }

    private function seedLocalization(
        PDO $pdo,
        BlogStructuredDraft $draft
    ): void {
        $timestamp = '2030-01-01 00:00:00.000000';
        $post = $pdo->prepare(
            'INSERT INTO layout_blog_posts '
                . '(public_id, created_by_user_public_id, created_at, '
                . 'updated_at) VALUES (:public_id, :actor, :created_at, '
                . ':updated_at)'
        );
        self::assertTrue($post->execute([
            'public_id' => self::POST,
            'actor' => self::ACTOR,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]));
        $compatibility = $draft->compatibilityDraft();
        $localization = $pdo->prepare(
            'INSERT INTO layout_blog_post_localizations '
                . '(public_id, post_id, locale, slug, h1, seo_title, '
                . 'meta_description, excerpt, body_text, status, '
                . 'published_at, lock_version, created_by_user_public_id, '
                . 'updated_by_user_public_id, created_at, updated_at) '
                . 'SELECT :public_id, id, :locale, :slug, :h1, :seo_title, '
                . ':description, :excerpt, :body_text, :status, NULL, 1, '
                . ':actor, :actor, :created_at, :updated_at FROM '
                . 'layout_blog_posts WHERE public_id = :post_public_id'
        );
        self::assertTrue($localization->execute([
            'public_id' => self::LOCALIZATION,
            'locale' => 'es',
            'slug' => $compatibility->slug(),
            'h1' => $compatibility->h1(),
            'seo_title' => $compatibility->seoTitle(),
            'description' => $compatibility->metaDescription(),
            'excerpt' => $compatibility->excerpt(),
            'body_text' => $compatibility->bodyText(),
            'status' => 'draft',
            'actor' => self::ACTOR,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'post_public_id' => self::POST,
        ]));
    }

    private function layoutDraft(string $body): BlogStructuredDraft
    {
        return new BlogStructuredDraft(
            'Matrix H1',
            BlogDocument::fromArray([
                'schema' => BlogDocument::SCHEMA,
                'version' => BlogDocument::LAYOUT_VERSION,
                'template' => 'article-basic-01',
                'blocks' => [[
                    'id' => '22000000-0000-4000-8000-000000000001',
                    'type' => 'section',
                    'presentation' => [
                        'background' => 'color00',
                    ],
                    'children' => [[
                        'id' => '22000000-0000-4000-8000-000000000002',
                        'type' => 'heading',
                        'level' => 2,
                        'content' => [[
                            'type' => 'text',
                            'text' => 'Matrix section',
                            'marks' => [],
                        ]],
                        'presentation' => [
                            'width' => 'full',
                            'align' => 'start',
                        ],
                    ], [
                        'id' => '22000000-0000-4000-8000-000000000003',
                        'type' => 'paragraph',
                        'content' => [[
                            'type' => 'text',
                            'text' => $body,
                            'marks' => [],
                        ]],
                        'presentation' => [
                            'width' => '80',
                            'align' => 'center',
                        ],
                    ], [
                        'id' => '22000000-0000-4000-8000-000000000004',
                        'type' => 'embed',
                        'html' => '<iframe src="https://player.vimeo.com/video/123"></iframe>',
                        'caption' => null,
                        'presentation' => [
                            'width' => 'full',
                            'align' => 'start',
                        ],
                    ]],
                ]],
            ]),
            'matrix-layout',
            'Matrix layout SEO',
            'Matrix layout description.',
            'Matrix layout excerpt.'
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2030-01-01 00:00:00',
            new DateTimeZone('UTC')
        );
    }

    private function apply(
        PDO $pdo,
        MigrationDefinition $migration,
        MigrationScope $scope
    ): void {
        foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
            self::assertNotFalse($pdo->exec($sql));
        }
    }

    private function sqlite(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }
}
