<?php

declare(strict_types=1);

namespace Tests\Blog\Seo;

use App\Core\Blog\BlogPostSummary;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\Seo\BlogSeoCatalogProjectionService;
use App\Core\Blog\Seo\BlogRobotsPreferences;
use App\Core\Blog\Seo\BlogSeoScore;
use App\Core\Blog\Seo\PdoBlogSeoCatalogSnapshotRepository;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Modules\Migrations\MigrationScope;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class BlogSeoCatalogCountingPdo extends PDO
{
    public int $prepareCount = 0;

    public function prepare(
        string $query,
        array $options = []
    ): PDOStatement|false {
        ++$this->prepareCount;

        return parent::prepare($query, $options);
    }
}

final class BlogSeoCatalogProjectionTest extends TestCase
{
    private BlogSeoCatalogCountingPdo $pdo;

    protected function setUp(): void
    {
        $this->pdo = new BlogSeoCatalogCountingPdo('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->schema();
    }

    public function testBatchUsesOneQueryForWorkspaceCurrentAndLegacyRows(): void
    {
        $current = $this->draft('Estado actual', 'estado-actual');
        $published = $this->draft('Estado publicado', 'estado-publicado');
        $private = $this->draft('Estado privado', 'estado-privado');
        $corrupt = $this->draft('Estado corrupto', 'estado-corrupto');

        $this->insertLocalization(1, $this->id(101), $current);
        $this->insertDocument(1, 1, $current);

        $this->insertLocalization(2, $this->id(102), $published);
        $this->insertDocument(2, 2, $published);
        $this->insertRevision(20, 2, $private);
        $this->pdo->prepare(
            'INSERT INTO ls_blog_editorial_workspaces '
            . '(localization_id, draft_revision_id) VALUES (?, ?)'
        )->execute([2, 20]);

        $legacy = $this->draft('Estado legacy', 'estado-legacy');
        $this->insertLocalization(3, $this->id(103), $legacy);

        $this->insertLocalization(4, $this->id(104), $corrupt);
        $this->insertDocument(4, 4, $corrupt, str_repeat('0', 64));

        $repository = $this->repository();
        $this->pdo->prepareCount = 0;
        $snapshots = $repository->snapshots([
            $this->id(101),
            $this->id(102),
            $this->id(103),
            $this->id(104),
        ]);

        self::assertSame(1, $this->pdo->prepareCount);
        self::assertSame([
            $this->id(101),
            $this->id(102),
            $this->id(103),
        ], array_keys($snapshots));
        self::assertSame(
            'Estado actual',
            $snapshots[$this->id(101)]->compatibilityDraft()->h1()
        );
        self::assertSame(
            'Estado privado',
            $snapshots[$this->id(102)]->compatibilityDraft()->h1()
        );
        self::assertSame(
            'Estado legacy',
            $snapshots[$this->id(103)]->compatibilityDraft()->h1()
        );
    }

    public function testEmptyAndOversizedRequestsNeverQuery(): void
    {
        $repository = $this->repository();
        $this->pdo->prepareCount = 0;

        self::assertSame([], $repository->snapshots([]));
        self::assertSame(0, $this->pdo->prepareCount);

        $ids = [];
        foreach (range(1, 51) as $index) {
            $ids[] = $this->id(1000 + $index);
        }
        try {
            $repository->snapshots($ids);
            self::fail('The oversized SEO catalog batch was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $this->pdo->prepareCount);
        }
    }

    public function testMaximumBatchStillUsesOneQuery(): void
    {
        $draft = $this->draft('Catálogo acotado', 'catalogo-acotado');
        $ids = [];
        foreach (range(1, 50) as $index) {
            $ids[] = $this->id(3000 + $index);
            $this->insertLocalization(
                $index,
                $this->id(3000 + $index),
                $draft
            );
        }
        $repository = $this->repository();
        $this->pdo->prepareCount = 0;

        $snapshots = $repository->snapshots($ids);

        self::assertCount(50, $snapshots);
        self::assertSame(1, $this->pdo->prepareCount);
    }

    public function testLayoutCompanionsProjectCurrentAndWorkspaceV2(): void
    {
        $current = $this->layoutDraft('Layout actual', 'layout-actual');
        $published = $this->layoutDraft(
            'Layout publicado',
            'layout-publicado'
        );
        $private = $this->layoutDraft('Layout privado', 'layout-privado');

        $this->insertLocalization(1, $this->id(401), $current);
        $this->insertDocument(1, 1, $current);
        $this->insertLayoutDocument(1, $current);

        $this->insertLocalization(2, $this->id(402), $published);
        $this->insertDocument(2, 2, $published);
        $this->insertLayoutDocument(2, $published);
        $this->insertRevision(20, 2, $private);
        $this->insertLayoutRevision(20, $private);
        $this->pdo->prepare(
            'INSERT INTO ls_blog_editorial_workspaces '
            . '(localization_id, draft_revision_id) VALUES (?, ?)'
        )->execute([2, 20]);

        $repository = new PdoBlogSeoCatalogSnapshotRepository(
            $this->pdo,
            MigrationScope::forTablePrefix('blog', 'ls_blog_'),
            privateDraftPublicationReady: true,
            layoutEditorReady: true
        );
        $snapshots = $repository->snapshots([
            $this->id(401),
            $this->id(402),
        ]);

        self::assertSame([$this->id(401), $this->id(402)], array_keys(
            $snapshots
        ));
        self::assertSame(
            BlogDocument::LAYOUT_VERSION,
            $snapshots[$this->id(401)]->schemaVersion()
        );
        self::assertSame(
            'Layout privado',
            $snapshots[$this->id(402)]->compatibilityDraft()->h1()
        );
    }

    public function testInvalidLayoutVersionAndHashAreOmitted(): void
    {
        $v1 = $this->draft('Companion V1', 'companion-v1');
        $corrupt = $this->layoutDraft(
            'Companion corrupto',
            'companion-corrupto'
        );

        $this->insertLocalization(1, $this->id(501), $v1);
        $this->insertDocument(1, 1, $v1);
        $this->insertLayoutDocument(1, $v1);

        $this->insertLocalization(2, $this->id(502), $corrupt);
        $this->insertDocument(2, 2, $corrupt);
        $this->insertLayoutDocument(2, $corrupt, str_repeat('0', 64));

        $repository = new PdoBlogSeoCatalogSnapshotRepository(
            $this->pdo,
            MigrationScope::forTablePrefix('blog', 'ls_blog_'),
            layoutEditorReady: true
        );

        self::assertSame([], $repository->snapshots([
            $this->id(501),
            $this->id(502),
        ]));
    }

    public function testProjectionReturnsScoresKeyedByLocalization(): void
    {
        $first = $this->draft('Guía editorial completa', 'guia-editorial');
        $second = $this->draft('Otra guía completa', 'otra-guia');
        $this->insertLocalization(1, $this->id(201), $first);
        $this->insertDocument(1, 1, $first);
        $this->insertLocalization(2, $this->id(202), $second);

        $service = new BlogSeoCatalogProjectionService($this->repository());
        $scores = $service->scoresFor([
            $this->summary(1, 201, $first),
            $this->summary(2, 202, $second),
        ], ['es' => '/noticias']);

        self::assertSame([$this->id(201), $this->id(202)], array_keys($scores));
        self::assertContainsOnlyInstancesOf(BlogSeoScore::class, $scores);
        self::assertSame(11, $scores[$this->id(201)]->totalChecks());
        self::assertSame(11, $scores[$this->id(202)]->totalChecks());
    }

    public function testNoindexForcesZeroButNofollowAloneDoesNot(): void
    {
        $draft = $this->draft('GuÃ­a robots completa', 'guia-robots');
        $this->insertLocalization(1, $this->id(211), $draft);
        $this->insertDocument(1, 1, $draft);
        $this->insertLocalization(2, $this->id(212), $draft);
        $this->insertDocument(2, 2, $draft);

        $scores = (new BlogSeoCatalogProjectionService($this->repository()))
            ->scoresFor([
                $this->summary(
                    1,
                    211,
                    $draft,
                    new BlogRobotsPreferences(false, true)
                ),
                $this->summary(
                    2,
                    212,
                    $draft,
                    new BlogRobotsPreferences(true, false)
                ),
            ], ['es' => '/noticias']);

        self::assertSame(0, $scores[$this->id(211)]->percentage());
        self::assertSame(BlogSeoScore::BAND_RED, $scores[$this->id(211)]->band());
        self::assertGreaterThan(0, $scores[$this->id(212)]->percentage());
    }

    private function repository(): PdoBlogSeoCatalogSnapshotRepository
    {
        return new PdoBlogSeoCatalogSnapshotRepository(
            $this->pdo,
            MigrationScope::forTablePrefix('blog', 'ls_blog_'),
            privateDraftPublicationReady: true
        );
    }

    private function schema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE ls_blog_post_localizations ('
            . 'id INTEGER PRIMARY KEY, public_id TEXT NOT NULL UNIQUE, '
            . 'h1 TEXT NOT NULL, slug TEXT, seo_title TEXT, '
            . 'meta_description TEXT, excerpt TEXT, body_text TEXT NOT NULL)'
        );
        $this->pdo->exec(
            'CREATE TABLE ls_blog_content_docs ('
            . 'id INTEGER PRIMARY KEY, localization_id INTEGER NOT NULL UNIQUE, '
            . 'schema_version INTEGER NOT NULL, template_key TEXT NOT NULL, '
            . 'document_json TEXT NOT NULL, document_bytes INTEGER NOT NULL, '
            . 'document_sha256 TEXT NOT NULL, body_text_sha256 TEXT NOT NULL, '
            . 'snapshot_sha256 TEXT NOT NULL)'
        );
        $this->pdo->exec(
            'CREATE TABLE ls_blog_content_revisions ('
            . 'id INTEGER PRIMARY KEY, localization_id INTEGER NOT NULL, '
            . 'schema_version INTEGER NOT NULL, template_key TEXT NOT NULL, '
            . 'document_json TEXT NOT NULL, document_bytes INTEGER NOT NULL, '
            . 'document_sha256 TEXT NOT NULL, body_text_sha256 TEXT NOT NULL, '
            . 'snapshot_sha256 TEXT NOT NULL, h1 TEXT NOT NULL, slug TEXT, '
            . 'seo_title TEXT, meta_description TEXT, excerpt TEXT, '
            . 'body_text TEXT NOT NULL)'
        );
        $this->pdo->exec(
            'CREATE TABLE ls_blog_editorial_workspaces ('
            . 'localization_id INTEGER PRIMARY KEY, draft_revision_id INTEGER)'
        );
        $this->pdo->exec(
            'CREATE TABLE ls_blog_content_layout_docs ('
            . 'document_id INTEGER PRIMARY KEY, schema_version INTEGER NOT NULL, '
            . 'template_key TEXT NOT NULL, document_json TEXT NOT NULL, '
            . 'document_bytes INTEGER NOT NULL, document_sha256 TEXT NOT NULL, '
            . 'snapshot_sha256 TEXT NOT NULL)'
        );
        $this->pdo->exec(
            'CREATE TABLE ls_blog_content_layout_revisions ('
            . 'revision_id INTEGER PRIMARY KEY, schema_version INTEGER NOT NULL, '
            . 'template_key TEXT NOT NULL, document_json TEXT NOT NULL, '
            . 'document_bytes INTEGER NOT NULL, document_sha256 TEXT NOT NULL, '
            . 'snapshot_sha256 TEXT NOT NULL)'
        );
    }

    private function insertLocalization(
        int $id,
        string $publicId,
        BlogStructuredDraft $draft
    ): void {
        $metadata = $draft->compatibilityDraft();
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_post_localizations '
            . '(id, public_id, h1, slug, seo_title, meta_description, excerpt, '
            . 'body_text) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        self::assertTrue($statement->execute([
            $id,
            $publicId,
            $metadata->h1(),
            $metadata->slug(),
            $metadata->seoTitle(),
            $metadata->metaDescription(),
            $metadata->excerpt(),
            $metadata->bodyText(),
        ]));
    }

    private function insertDocument(
        int $id,
        int $localizationId,
        BlogStructuredDraft $draft,
        ?string $documentHash = null
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_content_docs '
            . '(id, localization_id, schema_version, template_key, '
            . 'document_json, document_bytes, document_sha256, '
            . 'body_text_sha256, snapshot_sha256) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        self::assertTrue($statement->execute([
            $id,
            $localizationId,
            $draft->compatibilitySchemaVersion(),
            $draft->compatibilityTemplateKey(),
            $draft->compatibilityCanonicalJson(),
            $draft->compatibilityDocumentBytes(),
            $documentHash ?? $draft->compatibilityDocumentSha256(),
            $draft->bodyTextSha256(),
            $draft->compatibilitySnapshotSha256(),
        ]));
    }

    private function insertRevision(
        int $id,
        int $localizationId,
        BlogStructuredDraft $draft
    ): void {
        $metadata = $draft->compatibilityDraft();
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_content_revisions '
            . '(id, localization_id, schema_version, template_key, '
            . 'document_json, document_bytes, document_sha256, '
            . 'body_text_sha256, snapshot_sha256, h1, slug, seo_title, '
            . 'meta_description, excerpt, body_text) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        self::assertTrue($statement->execute([
            $id,
            $localizationId,
            $draft->compatibilitySchemaVersion(),
            $draft->compatibilityTemplateKey(),
            $draft->compatibilityCanonicalJson(),
            $draft->compatibilityDocumentBytes(),
            $draft->compatibilityDocumentSha256(),
            $draft->bodyTextSha256(),
            $draft->compatibilitySnapshotSha256(),
            $metadata->h1(),
            $metadata->slug(),
            $metadata->seoTitle(),
            $metadata->metaDescription(),
            $metadata->excerpt(),
            $metadata->bodyText(),
        ]));
    }

    private function insertLayoutDocument(
        int $documentId,
        BlogStructuredDraft $draft,
        ?string $documentHash = null
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_content_layout_docs '
            . '(document_id, schema_version, template_key, document_json, '
            . 'document_bytes, document_sha256, snapshot_sha256) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        self::assertTrue($statement->execute([
            $documentId,
            $draft->schemaVersion(),
            $draft->templateKey(),
            $draft->canonicalJson(),
            $draft->documentBytes(),
            $documentHash ?? $draft->documentSha256(),
            $draft->snapshotSha256(),
        ]));
    }

    private function insertLayoutRevision(
        int $revisionId,
        BlogStructuredDraft $draft
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_content_layout_revisions '
            . '(revision_id, schema_version, template_key, document_json, '
            . 'document_bytes, document_sha256, snapshot_sha256) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        self::assertTrue($statement->execute([
            $revisionId,
            $draft->schemaVersion(),
            $draft->templateKey(),
            $draft->canonicalJson(),
            $draft->documentBytes(),
            $draft->documentSha256(),
            $draft->snapshotSha256(),
        ]));
    }

    private function summary(
        int $post,
        int $localization,
        BlogStructuredDraft $draft,
        ?BlogRobotsPreferences $robotsPreferences = null
    ): BlogPostSummary {
        $metadata = $draft->compatibilityDraft();

        return new BlogPostSummary(
            $this->id($post),
            $this->id($localization),
            'es',
            $metadata->slug(),
            $metadata->h1(),
            BlogPostVariant::DRAFT,
            null,
            1,
            new DateTimeImmutable('2030-01-01', new DateTimeZone('UTC')),
            robotsPreferences: $robotsPreferences
        );
    }

    private function draft(string $h1, string $slug): BlogStructuredDraft
    {
        $body = $this->body(320);

        return new BlogStructuredDraft(
            $h1,
            BlogDocument::fromArray([
                'schema' => BlogDocument::SCHEMA,
                'version' => BlogDocument::VERSION,
                'template' => 'article-basic-01',
                'blocks' => [[
                    'id' => $this->id(900),
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'text',
                        'text' => $body,
                        'marks' => [],
                    ]],
                ]],
            ]),
            $slug,
            'Guía editorial completa para comprender decisiones importantes',
            'Una descripción editorial suficientemente completa para explicar '
                . 'el contenido, anticipar su utilidad y orientar a quien busca '
                . 'una respuesta clara antes de tomar una decisión importante.',
            'Resumen editorial del contenido.'
        );
    }

    private function layoutDraft(string $h1, string $slug): BlogStructuredDraft
    {
        return new BlogStructuredDraft(
            $h1,
            BlogDocument::fromArray([
                'schema' => BlogDocument::SCHEMA,
                'version' => BlogDocument::LAYOUT_VERSION,
                'template' => 'article-basic-01',
                'blocks' => [[
                    'id' => $this->id(800),
                    'type' => 'section',
                    'children' => [[
                        'id' => $this->id(801),
                        'type' => 'heading',
                        'level' => 2,
                        'content' => [[
                            'type' => 'text',
                            'text' => 'Sección editorial',
                            'marks' => [],
                        ]],
                        'presentation' => [
                            'width' => 'full',
                            'align' => 'start',
                        ],
                    ], [
                        'id' => $this->id(802),
                        'type' => 'paragraph',
                        'content' => [[
                            'type' => 'text',
                            'text' => $this->body(320),
                            'marks' => [],
                        ]],
                        'presentation' => [
                            'width' => '80',
                            'align' => 'center',
                        ],
                    ]],
                ]],
            ]),
            $slug,
            'Guía editorial completa para comprender decisiones importantes',
            'Una descripción editorial suficientemente completa para explicar '
                . 'el contenido y orientar decisiones con claridad, contexto '
                . 'y rigor antes de escoger la alternativa más adecuada.',
            'Resumen editorial del contenido.'
        );
    }

    private function body(int $words): string
    {
        $terms = [
            'guía', 'editorial', 'contexto', 'decisión', 'criterio',
            'personas', 'respuesta', 'detalle', 'ejemplo', 'análisis',
            'contenido', 'lectura', 'propuesta', 'claridad', 'objetivo',
        ];
        $result = [];
        for ($index = 0; $index < $words; ++$index) {
            $result[] = $terms[$index % count($terms)];
        }

        return implode(' ', $result);
    }

    private function id(int $number): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $number);
    }
}
