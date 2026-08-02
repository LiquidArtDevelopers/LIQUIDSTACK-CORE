<?php

declare(strict_types=1);

use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Http\BlogPublicHttpController;
use App\Core\Blog\Http\BlogPublicHttpRuntime;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\PublicDelivery\BlogPublicMediaDelivery;
use App\Core\Blog\PublicDelivery\PdoBlogPublicMediaRepository;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Persistence\PdoBlogStructuredContentRepository;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\WebAdmin\Media\PrivateMediaStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogPublicStructuredDeliveryTest extends TestCase
{
    private const ACTOR = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const POST_STRUCTURED = '11111111-1111-4111-8111-111111111111';
    private const LOCALIZATION_STRUCTURED = '22222222-2222-4222-8222-222222222222';
    private const DOCUMENT_STRUCTURED = '33333333-3333-4333-8333-333333333333';
    private const POST_LEGACY = '44444444-4444-4444-8444-444444444444';
    private const LOCALIZATION_LEGACY = '55555555-5555-4555-8555-555555555555';
    private const POST_DRAFT = '66666666-6666-4666-8666-666666666666';
    private const LOCALIZATION_DRAFT = '77777777-7777-4777-8777-777777777777';
    private const DOCUMENT_DRAFT = '88888888-8888-4888-8888-888888888888';
    private const ASSET_CURRENT = '99999999-9999-4999-8999-999999999999';
    private const ASSET_DRAFT = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const ASSET_OLD_REVISION = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    private const BLOCK_CURRENT = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    private const BLOCK_DRAFT = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    private const BLOCK_PARAGRAPH = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
    private const BLOCK_VIDEO = '12121212-1212-4212-8212-121212121212';

    private PDO $pdo;
    private string $projectRoot;
    private Filesystem $filesystem;
    private PrivateMediaStorage $storage;
    private BlogPublicHttpController $controller;
    private BlogStructuredDraft $structuredDraft;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-blog-public-structured-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectRoot);
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->createSchema();

        $blogScope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $webAdminScope = MigrationScope::forTablePrefix(
            'webadmin',
            'ls_webadmin_'
        );
        $this->structuredDraft = new BlogStructuredDraft(
            'Structured Matrix',
            BlogDocument::fromArray([
                'schema' => BlogDocument::SCHEMA,
                'version' => BlogDocument::VERSION,
                'template' => 'article-basic-01',
                'blocks' => [
                    [
                        'id' => self::BLOCK_PARAGRAPH,
                        'type' => 'paragraph',
                        'content' => [[
                            'type' => 'text',
                            'text' => 'Structured & "quoted" \'single\'',
                            'marks' => ['strong'],
                        ]],
                    ],
                    [
                        'id' => self::BLOCK_CURRENT,
                        'type' => 'image',
                        'media_asset_public_id' => self::ASSET_CURRENT,
                        'alt' => 'Neo & "Trinity" friends',
                        'title' => 'Image "title"',
                        'caption' => 'Escaped & contextual caption',
                        'decorative' => false,
                        'display' => 'wide',
                    ],
                    [
                        'id' => self::BLOCK_VIDEO,
                        'type' => 'video',
                        'provider' => 'youtube',
                        'video_id' => 'dQw4w9WgXcQ',
                        'title' => 'Matrix trailer',
                        'start_seconds' => 15,
                    ],
                ],
            ]),
            'structured-matrix',
            'Structured Matrix | News',
            'A complete structured Matrix article.',
            'Structured Matrix excerpt.'
        );
        $this->insertFixtures();

        $this->storage = new PrivateMediaStorage(
            $this->projectRoot,
            $this->projectRoot . '/storage/liquidstack/webadmin/media'
        );
        $this->storage->initialize();
        $this->storeVariant(self::ASSET_CURRENT, 480, 'avif-current-480');
        $this->storeVariant(self::ASSET_CURRENT, 960, 'avif-current-960');
        $this->storeVariant(self::ASSET_DRAFT, 480, 'avif-draft-480');
        $this->storeVariant(
            self::ASSET_OLD_REVISION,
            480,
            'avif-old-revision-480'
        );

        $mediaRepository = new PdoBlogPublicMediaRepository(
            $this->pdo,
            $blogScope,
            $webAdminScope
        );
        $runtime = new BlogPublicHttpRuntime(
            new BlogConfig(
                ['en' => '/en/news'],
                '/blog-sitemap.xml',
                'ls_blog_',
                'fixture'
            ),
            BlogPublicOrigin::fromEnvironment([
                BlogPublicOrigin::ENV => 'https://example.test',
            ]),
            new BlogService(new PdoBlogRepository($this->pdo, $blogScope)),
            new PdoBlogStructuredContentRepository($this->pdo, $blogScope),
            new BlogPublicMediaDelivery($mediaRepository, $this->storage)
        );
        $this->controller = new BlogPublicHttpController($runtime);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
    }

    public function testStructuredCurrentDocumentUsesSharedRendererAndLegacyFallsBack(): void
    {
        $structured = $this->controller->article('en', 'structured-matrix');
        self::assertNotNull($structured);
        self::assertSame(200, $structured->status());
        self::assertStringContainsString(
            'class="blogDocument blogDocument--basic"',
            $structured->body()
        );
        self::assertStringContainsString(
            '<strong>Structured &amp; &quot;quoted&quot; &apos;single&apos;</strong>',
            $structured->body()
        );
        self::assertStringContainsString(
            'srcset="/_liquidstack/blog-media/' . self::ASSET_CURRENT
                . '/480.avif 480w, /_liquidstack/blog-media/'
                . self::ASSET_CURRENT . '/960.avif 960w"',
            $structured->body()
        );
        self::assertStringContainsString(
            'alt="Neo &amp; &quot;Trinity&quot; friends"',
            $structured->body()
        );
        self::assertStringContainsString(
            'width="960" height="640"',
            $structured->body()
        );
        self::assertStringContainsString('data-blog-lite-youtube', $structured->body());
        self::assertStringNotContainsString('<iframe', $structured->body());
        self::assertStringNotContainsString('youtube.com/embed', $structured->body());
        self::assertStringContainsString(
            "img-src 'self' data:",
            $structured->headers()['Content-Security-Policy']
        );

        $legacy = $this->controller->article('en', 'legacy-matrix');
        self::assertNotNull($legacy);
        self::assertStringContainsString(
            '<p>Legacy &amp; &quot;body&quot;.</p>',
            $legacy->body()
        );
        self::assertStringNotContainsString('blogDocument--', $legacy->body());
    }

    public function testOnlyCurrentPublishedReferencesCanBeDelivered(): void
    {
        self::assertSame(
            404,
            $this->controller->media(self::ASSET_DRAFT, 480, false)->status()
        );
        self::assertSame(
            404,
            $this->controller->media(
                self::ASSET_OLD_REVISION,
                480,
                false
            )->status()
        );
        self::assertNull($this->controller->article('en', 'draft-matrix'));
    }

    public function testGetAndHeadHaveParityAndUseVerifiedPrivateStorage(): void
    {
        $get = $this->controller->media(self::ASSET_CURRENT, 480, false);
        $head = $this->controller->media(self::ASSET_CURRENT, 480, true);

        self::assertSame(200, $get->status());
        self::assertSame($get->status(), $head->status());
        self::assertSame($get->headers(), $head->headers());
        self::assertSame('avif-current-480', $get->body());
        self::assertSame('', $head->body());
        self::assertSame(
            (string) strlen('avif-current-480'),
            $get->headers()['Content-Length']
        );
        self::assertSame('image/avif', $get->headers()['Content-Type']);
    }

    public function testStorageFailureIsTheSameNonEnumeratingNotFound(): void
    {
        $missing = $this->controller->media(
            'abababab-abab-4bab-8bab-abababababab',
            480,
            false
        );
        $path = $this->projectRoot
            . '/storage/liquidstack/webadmin/media/99/'
            . self::ASSET_CURRENT . '/480.avif';
        self::assertFileExists($path);
        self::assertTrue(unlink($path));

        $failed = $this->controller->media(self::ASSET_CURRENT, 480, false);
        self::assertSame(404, $failed->status());
        self::assertSame($missing->status(), $failed->status());
        self::assertSame($missing->headers(), $failed->headers());
        self::assertSame($missing->body(), $failed->body());
        self::assertStringNotContainsString('99/', $failed->body());
    }

    public function testDebugViewsRedactStorageKeysAndFileContents(): void
    {
        $repository = new PdoBlogPublicMediaRepository(
            $this->pdo,
            MigrationScope::forTablePrefix('blog', 'ls_blog_'),
            MigrationScope::forTablePrefix('webadmin', 'ls_webadmin_')
        );
        $variant = $repository->publishedVariant(
            self::ASSET_CURRENT,
            480
        );
        self::assertNotNull($variant);
        $variantDebug = print_r($variant, true);
        self::assertStringContainsString('[redacted]', $variantDebug);
        self::assertStringNotContainsString(self::ASSET_CURRENT, $variantDebug);
        self::assertStringNotContainsString('/480.avif', $variantDebug);

        $file = (new BlogPublicMediaDelivery(
            $repository,
            $this->storage
        ))->file(self::ASSET_CURRENT, 480, false);
        self::assertNotNull($file);
        $fileDebug = print_r($file, true);
        self::assertStringContainsString('[redacted]', $fileDebug);
        self::assertStringNotContainsString('avif-current-480', $fileDebug);
        self::assertStringNotContainsString(hash(
            'sha256',
            'avif-current-480'
        ), $fileDebug);
    }

    private function createSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE ls_blog_posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    created_by_user_public_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE ls_blog_post_localizations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    post_id INTEGER NOT NULL,
    locale TEXT NOT NULL,
    slug TEXT NULL,
    h1 TEXT NOT NULL,
    seo_title TEXT NULL,
    meta_description TEXT NULL,
    excerpt TEXT NULL,
    body_text TEXT NOT NULL,
    status TEXT NOT NULL,
    published_at TEXT NULL,
    lock_version INTEGER NOT NULL,
    created_by_user_public_id TEXT NOT NULL,
    updated_by_user_public_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE ls_blog_content_docs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    localization_id INTEGER NOT NULL UNIQUE,
    schema_version INTEGER NOT NULL,
    template_key TEXT NOT NULL,
    document_json TEXT NOT NULL,
    document_bytes INTEGER NOT NULL,
    document_sha256 TEXT NOT NULL,
    body_text_sha256 TEXT NOT NULL,
    snapshot_sha256 TEXT NOT NULL,
    created_by_user_public_id TEXT NOT NULL,
    updated_by_user_public_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE ls_blog_content_media (
    document_id INTEGER NOT NULL,
    block_public_id TEXT NOT NULL,
    media_asset_public_id TEXT NOT NULL,
    role TEXT NOT NULL,
    created_at TEXT NOT NULL,
    PRIMARY KEY (document_id, block_public_id, role)
);
CREATE TABLE ls_blog_content_revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE
);
CREATE TABLE ls_blog_revision_media (
    revision_id INTEGER NOT NULL,
    block_public_id TEXT NOT NULL,
    media_asset_public_id TEXT NOT NULL,
    role TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE TABLE ls_webadmin_media_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE
);
CREATE TABLE ls_webadmin_media_variants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    asset_id INTEGER NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,
    bytes INTEGER NOT NULL,
    sha256 TEXT NOT NULL,
    storage_key TEXT NOT NULL,
    mime TEXT NOT NULL,
    UNIQUE (asset_id, width)
);
SQL);
    }

    private function insertFixtures(): void
    {
        $this->insertPost(
            self::POST_STRUCTURED,
            self::LOCALIZATION_STRUCTURED,
            'structured-matrix',
            $this->structuredDraft,
            'published'
        );
        $this->insertDocument(
            self::LOCALIZATION_STRUCTURED,
            self::DOCUMENT_STRUCTURED,
            $this->structuredDraft,
            self::BLOCK_CURRENT,
            self::ASSET_CURRENT
        );

        $legacy = new \App\Core\Blog\BlogDraft(
            'Legacy Matrix',
            'Legacy & "body".',
            'legacy-matrix',
            'Legacy Matrix | News',
            'A complete legacy Matrix article.',
            'Legacy Matrix excerpt.'
        );
        $this->insertPost(
            self::POST_LEGACY,
            self::LOCALIZATION_LEGACY,
            'legacy-matrix',
            $legacy,
            'published'
        );

        $draftDocument = new BlogStructuredDraft(
            'Draft Matrix',
            BlogDocument::fromArray([
                'schema' => BlogDocument::SCHEMA,
                'version' => BlogDocument::VERSION,
                'template' => 'article-basic-01',
                'blocks' => [[
                    'id' => self::BLOCK_DRAFT,
                    'type' => 'image',
                    'media_asset_public_id' => self::ASSET_DRAFT,
                    'alt' => 'Draft',
                    'title' => null,
                    'caption' => null,
                    'decorative' => false,
                    'display' => 'content',
                ]],
            ]),
            'draft-matrix',
            'Draft Matrix | News',
            'A complete draft Matrix article.',
            'Draft Matrix excerpt.'
        );
        $this->insertPost(
            self::POST_DRAFT,
            self::LOCALIZATION_DRAFT,
            'draft-matrix',
            $draftDocument,
            'draft'
        );
        $this->insertDocument(
            self::LOCALIZATION_DRAFT,
            self::DOCUMENT_DRAFT,
            $draftDocument,
            self::BLOCK_DRAFT,
            self::ASSET_DRAFT
        );

        foreach ([
            self::ASSET_CURRENT,
            self::ASSET_DRAFT,
            self::ASSET_OLD_REVISION,
        ] as $asset) {
            $statement = $this->pdo->prepare(
                'INSERT INTO ls_webadmin_media_assets (public_id) VALUES (?)'
            );
            $statement->execute([$asset]);
        }
        $this->insertVariant(self::ASSET_CURRENT, 480, 320, 'avif-current-480');
        $this->insertVariant(self::ASSET_CURRENT, 960, 640, 'avif-current-960');
        $this->insertVariant(self::ASSET_DRAFT, 480, 320, 'avif-draft-480');
        $this->insertVariant(
            self::ASSET_OLD_REVISION,
            480,
            320,
            'avif-old-revision-480'
        );
        $this->pdo->exec(
            "INSERT INTO ls_blog_content_revisions (public_id) VALUES "
            . "('13131313-1313-4313-8313-131313131313')"
        );
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_revision_media '
            . '(revision_id, block_public_id, media_asset_public_id, role, created_at) '
            . 'VALUES (1, ?, ?, ?, ?)'
        );
        $statement->execute([
            '14141414-1414-4414-8414-141414141414',
            self::ASSET_OLD_REVISION,
            'image',
            $this->timestamp(),
        ]);
    }

    private function insertPost(
        string $postPublicId,
        string $localizationPublicId,
        string $slug,
        BlogStructuredDraft|\App\Core\Blog\BlogDraft $draft,
        string $status
    ): void {
        $compatibility = $draft instanceof BlogStructuredDraft
            ? $draft->compatibilityDraft()
            : $draft;
        $this->pdo->prepare(
            'INSERT INTO ls_blog_posts '
            . '(public_id, created_by_user_public_id, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?)'
        )->execute([
            $postPublicId,
            self::ACTOR,
            $this->timestamp(),
            $this->timestamp(),
        ]);
        $postId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO ls_blog_post_localizations '
            . '(public_id, post_id, locale, slug, h1, seo_title, '
            . 'meta_description, excerpt, body_text, status, published_at, '
            . 'lock_version, created_by_user_public_id, '
            . 'updated_by_user_public_id, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $localizationPublicId,
            $postId,
            'en',
            $slug,
            $compatibility->h1(),
            $compatibility->seoTitle(),
            $compatibility->metaDescription(),
            $compatibility->excerpt(),
            $compatibility->bodyText(),
            $status,
            $status === 'published' ? $this->timestamp() : null,
            1,
            self::ACTOR,
            self::ACTOR,
            $this->timestamp(),
            $this->timestamp(),
        ]);
    }

    private function insertDocument(
        string $localizationPublicId,
        string $documentPublicId,
        BlogStructuredDraft $draft,
        string $blockPublicId,
        string $assetPublicId
    ): void {
        $localizationId = $this->pdo->query(
            "SELECT id FROM ls_blog_post_localizations WHERE public_id = "
            . $this->pdo->quote($localizationPublicId)
        )->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO ls_blog_content_docs '
            . '(public_id, localization_id, schema_version, template_key, '
            . 'document_json, document_bytes, document_sha256, '
            . 'body_text_sha256, snapshot_sha256, created_by_user_public_id, '
            . 'updated_by_user_public_id, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $documentPublicId,
            $localizationId,
            $draft->schemaVersion(),
            $draft->templateKey(),
            $draft->canonicalJson(),
            $draft->documentBytes(),
            $draft->documentSha256(),
            $draft->bodyTextSha256(),
            $draft->snapshotSha256(),
            self::ACTOR,
            self::ACTOR,
            $this->timestamp(),
            $this->timestamp(),
        ]);
        $documentId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO ls_blog_content_media '
            . '(document_id, block_public_id, media_asset_public_id, role, created_at) '
            . 'VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $documentId,
            $blockPublicId,
            $assetPublicId,
            'image',
            $this->timestamp(),
        ]);
    }

    private function insertVariant(
        string $assetPublicId,
        int $width,
        int $height,
        string $contents
    ): void {
        $assetId = $this->pdo->query(
            'SELECT id FROM ls_webadmin_media_assets WHERE public_id = '
            . $this->pdo->quote($assetPublicId)
        )->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO ls_webadmin_media_variants '
            . '(asset_id, width, height, bytes, sha256, storage_key, mime) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $assetId,
            $width,
            $height,
            strlen($contents),
            hash('sha256', $contents),
            substr($assetPublicId, 0, 2) . '/' . $assetPublicId
                . '/' . $width . '.avif',
            'image/avif',
        ]);
    }

    private function storeVariant(
        string $assetPublicId,
        int $width,
        string $contents
    ): void {
        $staging = $this->storage->createStagingDirectory();
        self::assertNotFalse(file_put_contents(
            $staging . '/' . $width . '.avif',
            $contents
        ));

        $assetDirectory = $this->projectRoot
            . '/storage/liquidstack/webadmin/media/'
            . substr($assetPublicId, 0, 2) . '/' . $assetPublicId;
        if (is_dir($assetDirectory)) {
            $target = $assetDirectory . '/' . $width . '.avif';
            self::assertTrue(rename($staging . '/' . $width . '.avif', $target));
            self::assertTrue(rmdir($staging));
            return;
        }
        $this->storage->promote($staging, $assetPublicId);
    }

    private function timestamp(): string
    {
        return '2026-01-01 00:00:00.000000';
    }
}
