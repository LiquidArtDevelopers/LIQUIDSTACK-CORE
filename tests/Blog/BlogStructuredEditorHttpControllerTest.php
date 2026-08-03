<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Core\Blog\Audit\WebAdminBlogMutationAuditAdapter;
use App\Core\Blog\BlogService;
use App\Core\Blog\Categories\BlogCategoryService;
use App\Core\Blog\Categories\Persistence\PdoBlogCategoryRepository;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Http\BlogAdminHttpController;
use App\Core\Blog\Http\BlogAdminHttpRuntime;
use App\Core\Blog\Http\BlogCategoryAdminHttpController;
use App\Core\Blog\Http\BlogStructuredEditorHttpController;
use App\Core\Blog\Http\BlogStructuredEditorHttpRuntimeInterface;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Categories\BlogCategoryEditorCatalogAdapter;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredEditorService;
use App\Core\Blog\StructuredContent\Media\BlogEditorMediaAsset;
use App\Core\Blog\StructuredContent\Media\BlogEditorMediaCatalogInterface;
use App\Core\Blog\StructuredContent\Media\PdoBlogEditorImageResolver;
use App\Core\Blog\StructuredContent\Media\PdoWebAdminMediaAvailabilityAdapter;
use App\Core\Blog\StructuredContent\Media\WebAdminMediaCatalogAdapter;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredPlainDraftWriteGuard;
use App\Core\Blog\StructuredContent\Persistence\PdoBlogStructuredContentRepository;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationRepository;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Media\MediaService;
use App\Core\WebAdmin\Media\PdoMediaRepository;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use Closure;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class StructuredEditorHttpClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-01-01 10:00:00 UTC');
    }
}

/** Test-only race injector wrapped around the real HTTP runtime. */
final class CapabilityRaceBlogStructuredRuntime implements
    BlogStructuredEditorHttpRuntimeInterface
{
    private bool $injected = false;

    public function __construct(
        private readonly BlogAdminHttpRuntime $inner,
        private readonly Closure $beforeMutationGate,
        private readonly ?BlogEditorMediaCatalogInterface $mediaCatalog = null
    ) {
    }

    public function projectRoot(): string
    {
        return $this->inner->projectRoot();
    }

    public function languages(): array
    {
        return $this->inner->languages();
    }

    public function blogConfig(): BlogConfig
    {
        return $this->inner->blogConfig();
    }

    public function webAdminConfig(): WebAdminConfig
    {
        return $this->inner->webAdminConfig();
    }

    public function service(): BlogService
    {
        return $this->inner->service();
    }

    public function authentication(): WebAdminAuthenticationService
    {
        return $this->inner->authentication();
    }

    public function authorization(): WebAdminAuthorizationService
    {
        return $this->inner->authorization();
    }

    public function structuredEditor(): BlogStructuredEditorService
    {
        return $this->inner->structuredEditor();
    }

    public function editorMediaCatalog(): BlogEditorMediaCatalogInterface
    {
        return $this->mediaCatalog ?? $this->inner->editorMediaCatalog();
    }

    public function editorImageResolver(): BlogImageResolverInterface
    {
        return $this->inner->editorImageResolver();
    }

    public function mutationGate(
        string $sessionToken,
        string $csrfToken,
        string $capability
    ): Closure {
        return $this->inner->mutationGate(
            $sessionToken,
            $csrfToken,
            $capability
        );
    }

    public function mutationGateAll(
        string $sessionToken,
        string $csrfToken,
        array $capabilities
    ): Closure {
        $gate = $this->inner->mutationGateAll(
            $sessionToken,
            $csrfToken,
            $capabilities
        );
        if (!$this->injected) {
            $this->injected = true;
            ($this->beforeMutationGate)();
        }

        return $gate;
    }
}

final class BlogStructuredEditorHttpControllerTest extends TestCase
{
    private const ACTOR = '10000000-0000-4000-8000-000000000001';
    private const POST = '20000000-0000-4000-8000-000000000001';
    private const LOCALIZATION = '21000000-0000-4000-8000-000000000001';
    private const MEDIA = '30000000-0000-4000-8000-000000000001';
    private const ASSIGNED_CATEGORY =
        '70000000-0000-4000-8000-000000000001';
    private const AVAILABLE_CATEGORY =
        '70000000-0000-4000-8000-000000000002';
    private const MISSING_MEDIA =
        '30000000-0000-4000-8000-000000000099';

    private PDO $pdo;
    private BlogAdminHttpRuntime $runtime;
    private BlogStructuredEditorHttpController $controller;
    private string $sessionToken;
    private string $csrfToken;
    private int $actorId;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $webAdminScope = MigrationScope::forTablePrefix(
            'webadmin',
            'ls_webadmin_'
        );
        $blogScope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        foreach (WebAdminMigrationProvider::migrations() as $migration) {
            $this->executeMigration($migration, $webAdminScope);
        }
        foreach (BlogMigrationProvider::migrations() as $migration) {
            $this->executeMigration(
                $migration,
                $migration->targetScopeModuleId() === 'webadmin'
                    ? $webAdminScope
                    : $blogScope
            );
        }

        $clock = new StructuredEditorHttpClock();
        $config = new WebAdminConfig(
            '/admin',
            'ls_webadmin_',
            'LS_WEBADMIN_SID',
            300,
            3600,
            'test'
        );
        $securityKey = SecurityKey::fromRawBytes(str_repeat('K', 32));
        $tokens = new SecureTokenGenerator();
        $this->sessionToken = rtrim(strtr(
            base64_encode(str_repeat('S', 32)),
            '+/',
            '-_'
        ), '=');
        $this->csrfToken = $securityKey->deriveToken(
            'csrf.session',
            $this->sessionToken
        );
        $this->seedActor($tokens);
        $this->seedLegacyVariant();
        $this->seedMedia();
        $this->seedCategories();

        $tables = WebAdminTableNames::fromPdo(
            $this->pdo,
            'ls_webadmin_'
        );
        $hasher = PasswordHasher::productive();
        $authentication = new WebAdminAuthenticationService(
            new WebAdminAuthenticationRepository($this->pdo, $tables),
            $config,
            $securityKey,
            $clock,
            new RandomUuidV4Generator(),
            $hasher,
            $tokens
        );
        $authorization = new WebAdminAuthorizationService(
            $this->pdo,
            $tables,
            $clock,
            $tokens,
            $hasher
        );
        $blogRepository = new PdoBlogRepository($this->pdo, $blogScope);
        $structuredRepository = new PdoBlogStructuredContentRepository(
            $this->pdo,
            $blogScope
        );
        $audit = new WebAdminBlogMutationAuditAdapter(
            $this->pdo,
            $tables
        );
        $blogService = new BlogService(
            $blogRepository,
            new RandomUuidV4Generator(),
            $clock,
            $audit,
            new BlogStructuredPlainDraftWriteGuard(
                $this->pdo,
                $structuredRepository
            )
        );
        $structuredEditor = new BlogStructuredEditorService(
            $blogRepository,
            $structuredRepository,
            new PdoWebAdminMediaAvailabilityAdapter(
                $this->pdo,
                $webAdminScope
            ),
            new RandomUuidV4Generator(),
            $clock,
            $audit
        );
        $this->runtime = new BlogAdminHttpRuntime(
            __DIR__,
            ['es'],
            BlogConfig::defaults(['es']),
            $config,
            $blogService,
            $authentication,
            $authorization,
            $this->pdo,
            new WebAdminMutationActorGate(
                $this->pdo,
                $tables,
                $config,
                $securityKey,
                $clock,
                $tokens,
                $hasher
            ),
            $structuredEditor,
            new WebAdminMediaCatalogAdapter(
                new PdoMediaRepository($this->pdo, $tables)
            ),
            new PdoBlogEditorImageResolver(
                $this->pdo,
                $webAdminScope,
                '/admin'
            ),
            editorCategoryCatalog: new BlogCategoryEditorCatalogAdapter(
                new BlogCategoryService(
                    new PdoBlogCategoryRepository($this->pdo, $blogScope)
                )
            )
        );
        $this->controller = new BlogStructuredEditorHttpController(
            $this->runtime
        );
    }

    public function testLegacyGetAndHeadSurfacesAreReadOnlyAndPrivate(): void
    {
        $query = ['post' => self::POST, 'locale' => 'es'];
        $editor = $this->controller->edit($this->get(
            '/admin/blog/editor',
            $query
        ));
        self::assertSame(200, $editor->status());
        self::assertStringContainsString(
            '<h1 id="blog-editor-title">Construir art&iacute;culo</h1>',
            $editor->body()
        );
        self::assertStringContainsString('Legacy first paragraph.', $editor->body());
        self::assertStringContainsString('Matrix poster', $editor->body());
        self::assertStringContainsString(
            '/admin/blog/posts/new?post=' . self::POST,
            $editor->body()
        );
        self::assertStringContainsString(
            '/admin/blog/categories/assign?post=' . self::POST
                . '&amp;locale=es',
            $editor->body()
        );
        self::assertStringContainsString(
            'data-blog-category-assignment-form '
                . 'data-blog-category-locale="es"',
            $editor->body()
        );
        self::assertStringContainsString(
            'name="categories[]" value="' . self::ASSIGNED_CATEGORY
                . '" checked',
            $editor->body()
        );
        self::assertStringContainsString(
            'name="categories[]" value="' . self::AVAILABLE_CATEGORY . '"',
            $editor->body()
        );
        self::assertStringContainsString(
            'action="/admin/blog/posts/publish"',
            $editor->body()
        );
        self::assertStringContainsString(
            'data-blog-seo-endpoint="/admin/blog/editor/seo-analysis"',
            $editor->body()
        );
        self::assertStringContainsString(
            'Revisi&oacute;n SEO editorial',
            $editor->body()
        );
        self::assertStringContainsString(
            'Los avisos son orientativos: nunca bloquean',
            $editor->body()
        );
        self::assertStringContainsString('>Publicar</button>', $editor->body());
        self::assertStringContainsString(
            'name="lock_version" value="1"',
            $editor->body()
        );
        $this->assertPrivateHtml($editor);
        self::assertStringContainsString(
            "connect-src 'self'",
            $editor->headers()['Content-Security-Policy']
        );

        $editorHead = $this->controller->edit($this->head(
            '/admin/blog/editor',
            $query
        ));
        self::assertSame(200, $editorHead->status());
        self::assertSame('', $editorHead->body());
        self::assertSame(
            $editor->headers()['Content-Security-Policy'],
            $editorHead->headers()['Content-Security-Policy']
        );

        $preview = $this->controller->preview($this->get(
            '/admin/blog/editor/preview',
            $query
        ));
        self::assertSame(200, $preview->status());
        self::assertStringContainsString(
            '>Legacy first paragraph.</p>',
            $preview->body()
        );
        self::assertStringContainsString(
            '>Legacy second paragraph.</p>',
            $preview->body()
        );
        self::assertStringNotContainsString('rel="canonical"', $preview->body());
        $this->assertPrivateHtml($preview);

        $previewHead = $this->controller->preview($this->head(
            '/admin/blog/editor/preview',
            $query
        ));
        self::assertSame(200, $previewHead->status());
        self::assertSame('', $previewHead->body());

        $revisions = $this->controller->revisions($this->get(
            '/admin/blog/editor/revisions',
            $query
        ));
        self::assertSame(200, $revisions->status());
        self::assertStringContainsString(
            'No hay revisiones guardadas.',
            $revisions->body()
        );
        self::assertStringContainsString(
            'data-webadmin-shell',
            $revisions->body()
        );
        self::assertSame(1, substr_count($revisions->body(), '<main'));
        $this->assertPrivateHtml($revisions);
        $revisionsHead = $this->controller->revisions($this->head(
            '/admin/blog/editor/revisions',
            $query
        ));
        self::assertSame(200, $revisionsHead->status());
        self::assertSame('', $revisionsHead->body());

        self::assertSame(0, $this->rowCount('ls_blog_content_docs'));
        self::assertSame(0, $this->rowCount('ls_blog_content_revisions'));
        self::assertSame(0, $this->rowCount('ls_webadmin_audit_log'));

        $anonymous = $this->controller->edit(Request::fromInput([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/blog/editor',
            'HTTPS' => 'on',
        ], query: $query));
        self::assertSame(303, $anonymous->status());
        self::assertSame('/admin/login', $anonymous->headers()['Location']);
        self::assertSame('', $anonymous->body());
        self::assertSame(
            'noindex, nofollow, noarchive',
            $anonymous->headers()['X-Robots-Tag']
        );
        self::assertStringContainsString(
            "default-src 'none'",
            $anonymous->headers()['Content-Security-Policy']
        );
    }

    public function testDraftEditorRemainsAvailableWithoutPublishCapability(): void
    {
        $this->removeCapability(
            BlogAdminHttpController::PUBLISH_CAPABILITY
        );
        $query = ['post' => self::POST, 'locale' => 'es'];

        $editor = $this->controller->edit($this->get(
            '/admin/blog/editor',
            $query
        ));
        self::assertSame(200, $editor->status());
        self::assertStringContainsString(
            'action="/admin/blog/editor/save"',
            $editor->body()
        );
        self::assertStringContainsString(
            '>Guardar documento</button>',
            $editor->body()
        );
        self::assertStringNotContainsString(
            'action="/admin/blog/posts/publish"',
            $editor->body()
        );
        self::assertStringNotContainsString(
            'action="/admin/blog/posts/unpublish"',
            $editor->body()
        );
        self::assertSame(200, $this->controller->preview($this->get(
            '/admin/blog/editor/preview',
            $query
        ))->status());
    }

    public function testDraftEditorOmitsCategoriesWithoutEditCapability(): void
    {
        $this->removeCapability(
            BlogCategoryAdminHttpController::EDIT_CAPABILITY
        );

        $editor = $this->controller->edit($this->get(
            '/admin/blog/editor',
            ['post' => self::POST, 'locale' => 'es']
        ));

        self::assertSame(200, $editor->status());
        self::assertStringContainsString(
            'action="/admin/blog/editor/save"',
            $editor->body()
        );
        self::assertStringNotContainsString(
            'data-blog-category-assignment-form',
            $editor->body()
        );
        self::assertStringNotContainsString(
            '/admin/blog/categories/assign',
            $editor->body()
        );
    }

    public function testEditorKeepsReferencedMediaOutsideRecentCatalog(): void
    {
        $saved = $this->controller->save($this->post(
            '/admin/blog/editor/save',
            $this->saveForm(
                $this->documentJson(
                    'The referenced poster remains selectable.',
                    self::MEDIA
                ),
                1,
                'Referenced Matrix poster'
            )
        ));
        $this->assertEditorRedirect($saved);

        $this->seedNewerMedia(48);
        $tables = WebAdminTableNames::fromPdo(
            $this->pdo,
            'ls_webadmin_'
        );
        $recent = (new PdoMediaRepository($this->pdo, $tables))
            ->listPage(1, 48)
            ->items();
        self::assertNotContains(
            self::MEDIA,
            array_column($recent, 'public_id'),
            'The fixture must prove the referenced asset is older than page 1.'
        );

        $editor = $this->controller->edit($this->get(
            '/admin/blog/editor',
            ['post' => self::POST, 'locale' => 'es']
        ));

        self::assertSame(200, $editor->status());
        self::assertStringContainsString(
            'value="' . self::MEDIA . '" data-thumbnail-url="'
                . '/admin/media/file?asset=' . self::MEDIA
                . '&amp;width=480"',
            $editor->body()
        );
        self::assertStringNotContainsString('storage_key', $editor->body());
        $this->assertPrivateHtml($editor);
    }

    public function testLegacyMediaCatalogRuntimeUsesCompatibleFallback(): void
    {
        $saved = $this->controller->save($this->post(
            '/admin/blog/editor/save',
            $this->saveForm(
                $this->documentJson('Legacy catalog compatibility.', self::MEDIA),
                1,
                'Legacy catalog compatibility'
            )
        ));
        $this->assertEditorRedirect($saved);

        $legacyCatalog = new class implements BlogEditorMediaCatalogInterface {
            public function recent(int $limit): array
            {
                return [new BlogEditorMediaAsset(
                    '30000000-0000-4000-8000-000000000002',
                    'Legacy runtime recent media'
                )];
            }
        };
        $legacyRuntime = new CapabilityRaceBlogStructuredRuntime(
            $this->runtime,
            static function (): void {
            },
            $legacyCatalog
        );
        $editor = (new BlogStructuredEditorHttpController($legacyRuntime))
            ->edit($this->get(
                '/admin/blog/editor',
                ['post' => self::POST, 'locale' => 'es']
            ));

        self::assertSame(200, $editor->status());
        self::assertStringContainsString(
            'Legacy runtime recent media',
            $editor->body()
        );
        self::assertStringNotContainsString(
            'data-blog-category-assignment-form',
            $editor->body()
        );
        $this->assertPrivateHtml($editor);
    }

    public function testSeoAnalysisIsAuthenticatedAdvisoryJsonAndReadOnly(): void
    {
        $response = $this->controller->seoAnalysis($this->post(
            '/admin/blog/editor/seo-analysis',
            $this->saveForm(
                $this->documentJson('Matrix context and a clear choice.'),
                1,
                'A clear guide to choosing inside Matrix'
            )
        ));

        self::assertSame(200, $response->status());
        self::assertSame(
            'application/json; charset=utf-8',
            $response->headers()['Content-Type']
        );
        self::assertSame(
            'no-store, no-cache, must-revalidate, max-age=0',
            $response->headers()['Cache-Control']
        );
        $payload = json_decode(
            $response->body(),
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('liquidstack.blog.seo-analysis', $payload['schema']);
        self::assertSame(1, $payload['version']);
        self::assertTrue($payload['advisory']);
        self::assertArrayNotHasKey('score', $payload);
        self::assertCount(12, $payload['checks']);
        self::assertSame('es', $payload['serp_preview']['locale']);
        self::assertSame(0, $this->rowCount('ls_blog_content_docs'));
        self::assertSame(0, $this->rowCount('ls_blog_content_revisions'));
        self::assertSame(0, $this->rowCount('ls_webadmin_audit_log'));

        $invalidCsrf = $this->saveForm(
            $this->documentJson('Valid body.'),
            1,
            'Valid H1'
        );
        $invalidCsrf['csrf'] = 'wrong';
        self::assertSame(403, $this->controller->seoAnalysis($this->post(
            '/admin/blog/editor/seo-analysis',
            $invalidCsrf
        ))->status());

        $this->removeCapability(BlogAdminHttpController::EDIT_CAPABILITY);
        self::assertSame(403, $this->controller->seoAnalysis($this->post(
            '/admin/blog/editor/seo-analysis',
            $this->saveForm(
                $this->documentJson('Valid body.'),
                1,
                'Valid H1'
            )
        ))->status());
    }

    public function testSeoAnalysisRejectsInvalidPayloadWithoutLeakingContent(): void
    {
        $form = $this->saveForm('{"tampered":true}', 1, 'Sensitive H1');
        $response = $this->controller->seoAnalysis($this->post(
            '/admin/blog/editor/seo-analysis',
            $form
        ));

        self::assertSame(422, $response->status());
        self::assertSame(
            'application/json; charset=utf-8',
            $response->headers()['Content-Type']
        );
        self::assertStringNotContainsString('Sensitive H1', $response->body());
        self::assertStringNotContainsString('tampered', $response->body());
        self::assertStringContainsString(
            'unprocessable_content',
            $response->body()
        );
    }

    public function testSaveAdoptsLegacyAndRestoreAppendsImmutableRevision(): void
    {
        $firstJson = $this->documentJson('First structured body.');
        $saved = $this->controller->save($this->post(
            '/admin/blog/editor/save',
            $this->saveForm($firstJson, 1, 'First structured H1')
        ));
        $this->assertEditorRedirect($saved);
        self::assertSame(1, $this->rowCount('ls_blog_content_docs'));
        self::assertSame(1, $this->rowCount('ls_blog_content_revisions'));
        self::assertSame(2, $this->lockVersion());
        self::assertSame('First structured body.', $this->bodyText());
        self::assertSame(1, $this->auditCount('blog.article.saved'));

        $firstRevision = (string) $this->pdo->query(
            'SELECT public_id FROM ls_blog_content_revisions '
                . 'WHERE revision_number = 1'
        )->fetchColumn();
        $list = $this->controller->revisions($this->get(
            '/admin/blog/editor/revisions',
            ['post' => self::POST, 'locale' => 'es']
        ));
        self::assertSame(200, $list->status());
        self::assertStringContainsString('Revisi&oacute;n 1', $list->body());
        $detail = $this->controller->revisions($this->get(
            '/admin/blog/editor/revisions',
            [
                'post' => self::POST,
                'locale' => 'es',
                'revision' => $firstRevision,
            ]
        ));
        self::assertSame(200, $detail->status());
        self::assertStringContainsString('First structured body.', $detail->body());
        self::assertStringContainsString('data-webadmin-shell', $detail->body());
        self::assertSame(1, substr_count($detail->body(), '<main'));
        $this->assertPrivateHtml($detail);
        $detailHead = $this->controller->revisions($this->head(
            '/admin/blog/editor/revisions',
            [
                'post' => self::POST,
                'locale' => 'es',
                'revision' => $firstRevision,
            ]
        ));
        self::assertSame(200, $detailHead->status());
        self::assertSame('', $detailHead->body());

        $unknownRevision =
            '80000000-0000-4000-8000-000000000099';
        $unknown = $this->controller->revisions($this->get(
            '/admin/blog/editor/revisions',
            [
                'post' => self::POST,
                'locale' => 'es',
                'revision' => $unknownRevision,
            ]
        ));
        self::assertSame(404, $unknown->status());
        self::assertSame('Not found', $unknown->body());
        $this->assertDoesNotLeak($unknown, $unknownRevision);

        $second = $this->controller->save($this->post(
            '/admin/blog/editor/save',
            $this->saveForm(
                $this->documentJson('Second structured body.', self::MEDIA),
                2,
                'Second structured H1'
            )
        ));
        $this->assertEditorRedirect($second);
        self::assertSame(2, $this->rowCount('ls_blog_content_revisions'));
        self::assertSame(3, $this->lockVersion());

        $preview = $this->controller->preview($this->get(
            '/admin/blog/editor/preview',
            ['post' => self::POST, 'locale' => 'es']
        ));
        self::assertSame(200, $preview->status());
        self::assertStringContainsString(
            '/admin/media/file?asset=' . self::MEDIA . '&amp;width=900',
            $preview->body()
        );
        self::assertStringNotContainsString('storage_key', $preview->body());

        $restoreForm = [
            'csrf' => $this->csrfToken,
            'post' => self::POST,
            'locale' => 'es',
            'lock_version' => '3',
            'revision' => $firstRevision,
        ];
        $wrongRestoreCsrf = $restoreForm;
        $wrongRestoreCsrf['csrf'] = str_repeat('X', 43);
        $forbiddenRestore = $this->controller->restore($this->post(
            '/admin/blog/editor/restore',
            $wrongRestoreCsrf
        ));
        self::assertSame(403, $forbiddenRestore->status());
        self::assertSame(2, $this->rowCount('ls_blog_content_revisions'));
        self::assertSame(3, $this->lockVersion());

        $restored = $this->controller->restore($this->post(
            '/admin/blog/editor/restore',
            $restoreForm
        ));
        $this->assertEditorRedirect($restored);
        self::assertSame(3, $this->rowCount('ls_blog_content_revisions'));
        self::assertSame(4, $this->lockVersion());
        self::assertSame('First structured H1', $this->h1());
        self::assertSame('First structured body.', $this->bodyText());
        self::assertSame(1, $this->auditCount('blog.article.restored'));
    }

    public function testCsrfAndBothCapabilitiesAreRevalidatedWithoutWrites(): void
    {
        $form = $this->saveForm(
            $this->documentJson('Protected structured body.'),
            1,
            'Protected H1'
        );
        $wrongCsrf = $form;
        $wrongCsrf['csrf'] = str_repeat('X', 43);
        $response = $this->controller->save($this->post(
            '/admin/blog/editor/save',
            $wrongCsrf
        ));
        self::assertSame(403, $response->status());

        foreach ([
            BlogAdminHttpController::EDIT_CAPABILITY,
            MediaService::VIEW_CAPABILITY,
        ] as $capability) {
            $this->removeCapability($capability);
            $response = $this->controller->save($this->post(
                '/admin/blog/editor/save',
                $form
            ));
            self::assertSame(403, $response->status(), $capability);
            $this->addCapability($capability);
        }

        $raceRuntime = new CapabilityRaceBlogStructuredRuntime(
            $this->runtime,
            function (): void {
                $this->removeCapability(MediaService::VIEW_CAPABILITY);
            }
        );
        $race = (new BlogStructuredEditorHttpController($raceRuntime))->save(
            $this->post('/admin/blog/editor/save', $form)
        );
        self::assertSame(403, $race->status());
        self::assertSame('Forbidden', $race->body());
        self::assertSame(0, $this->rowCount('ls_blog_content_docs'));
        self::assertSame(0, $this->rowCount('ls_blog_content_revisions'));
        self::assertSame(0, $this->rowCount('ls_webadmin_audit_log'));
        self::assertSame(1, $this->lockVersion());
        self::assertSame(
            "Legacy first paragraph.\n\nLegacy second paragraph.",
            $this->bodyText()
        );
    }

    public function testStaleLockAndUnavailableMediaFailClosedWithoutLeakage(): void
    {
        $secret = 'Never leak this submitted Matrix draft';
        $stale = $this->controller->save($this->post(
            '/admin/blog/editor/save',
            $this->saveForm($this->documentJson($secret), 2, $secret)
        ));
        self::assertSame(409, $stale->status());
        self::assertSame('Conflict', $stale->body());
        $this->assertDoesNotLeak($stale, $secret);

        $missing = $this->controller->save($this->post(
            '/admin/blog/editor/save',
            $this->saveForm(
                $this->documentJson($secret, self::MISSING_MEDIA),
                1,
                $secret
            )
        ));
        self::assertSame(422, $missing->status());
        self::assertSame('Unprocessable content', $missing->body());
        $this->assertDoesNotLeak($missing, $secret);
        $this->assertDoesNotLeak($missing, self::MISSING_MEDIA);

        $malformed = $this->controller->save($this->post(
            '/admin/blog/editor/save',
            $this->saveForm('{"secret":"' . $secret . '"}', 1, $secret)
        ));
        self::assertSame(422, $malformed->status());
        $this->assertDoesNotLeak($malformed, $secret);

        self::assertSame(0, $this->rowCount('ls_blog_content_docs'));
        self::assertSame(0, $this->rowCount('ls_blog_content_revisions'));
        self::assertSame(0, $this->rowCount('ls_webadmin_audit_log'));
        self::assertSame(1, $this->lockVersion());
    }

    public function testLatePersistenceFailureRollsBackEveryStructuredWrite(): void
    {
        self::assertNotFalse($this->pdo->exec(
            "CREATE TRIGGER fail_structured_revision BEFORE INSERT ON "
                . "ls_blog_content_revisions BEGIN SELECT RAISE(ABORT, "
                . "'Never expose this storage detail'); END"
        ));
        $secret = 'Never expose this submitted content';
        $response = $this->controller->save($this->post(
            '/admin/blog/editor/save',
            $this->saveForm($this->documentJson($secret), 1, $secret)
        ));

        self::assertSame(503, $response->status());
        self::assertSame('Service unavailable', $response->body());
        $this->assertDoesNotLeak($response, $secret);
        $this->assertDoesNotLeak($response, 'storage detail');
        self::assertSame(0, $this->rowCount('ls_blog_content_docs'));
        self::assertSame(0, $this->rowCount('ls_blog_content_media'));
        self::assertSame(0, $this->rowCount('ls_blog_content_revisions'));
        self::assertSame(0, $this->rowCount('ls_blog_revision_media'));
        self::assertSame(0, $this->rowCount('ls_webadmin_audit_log'));
        self::assertSame(1, $this->lockVersion());
        self::assertSame('Legacy H1', $this->h1());
        self::assertSame(
            "Legacy first paragraph.\n\nLegacy second paragraph.",
            $this->bodyText()
        );
        self::assertFalse($this->pdo->inTransaction());
    }

    private function seedActor(SecureTokenGenerator $tokens): void
    {
        self::assertNotFalse($this->pdo->exec(
            "INSERT INTO ls_webadmin_users "
                . "(public_id, email_canonical, status, auth_version, activated_at) "
                . "VALUES ('" . self::ACTOR . "', 'editor@example.test', "
                . "'active', 1, '2030-01-01 09:00:00.000000')"
        ));
        $this->actorId = (int) $this->pdo->lastInsertId();
        $credential = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_credentials '
                . '(user_id, password_hash, password_set_at) VALUES (?, ?, ?)'
        );
        self::assertTrue($credential->execute([
            $this->actorId,
            PasswordHasher::productive()->hash(
                'Correct horse battery staple 1!'
            ),
            '2030-01-01 09:00:00.000000',
        ]));
        foreach ([
            'webadmin.access',
            BlogAdminHttpController::VIEW_CAPABILITY,
            BlogAdminHttpController::EDIT_CAPABILITY,
            BlogAdminHttpController::PUBLISH_CAPABILITY,
            BlogCategoryAdminHttpController::EDIT_CAPABILITY,
            MediaService::VIEW_CAPABILITY,
        ] as $capability) {
            $this->addCapability($capability);
        }

        $session = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_sessions '
                . '(public_id, user_id, session_type, token_hash, '
                . 'csrf_token_hash, auth_version, created_at, last_seen_at, '
                . 'idle_expires_at, absolute_expires_at) VALUES '
                . '(:public_id, :user, :type, :token, :csrf, 1, :created, '
                . ':seen, :idle, :absolute)'
        );
        self::assertTrue($session->execute([
            'public_id' => '11000000-0000-4000-8000-000000000001',
            'user' => $this->actorId,
            'type' => 'authenticated',
            'token' => $tokens->hashForStorage($this->sessionToken),
            'csrf' => $tokens->hashForStorage($this->csrfToken),
            'created' => '2030-01-01 09:55:00.000000',
            'seen' => '2030-01-01 09:59:00.000000',
            'idle' => '2030-01-01 10:05:00.000000',
            'absolute' => '2030-01-01 11:00:00.000000',
        ]));
    }

    private function seedLegacyVariant(): void
    {
        $post = $this->pdo->prepare(
            'INSERT INTO ls_blog_posts '
                . '(public_id, created_by_user_public_id, created_at, updated_at) '
                . 'VALUES (?, ?, ?, ?)'
        );
        self::assertTrue($post->execute([
            self::POST,
            self::ACTOR,
            '2030-01-01 09:00:00.000000',
            '2030-01-01 09:00:00.000000',
        ]));
        $postId = (int) $this->pdo->lastInsertId();
        $variant = $this->pdo->prepare(
            'INSERT INTO ls_blog_post_localizations '
                . '(public_id, post_id, locale, slug, h1, seo_title, '
                . 'meta_description, excerpt, body_text, status, lock_version, '
                . 'created_by_user_public_id, updated_by_user_public_id, '
                . 'created_at, updated_at) VALUES '
                . '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        self::assertTrue($variant->execute([
            self::LOCALIZATION,
            $postId,
            'es',
            'legacy-matrix',
            'Legacy H1',
            'Legacy SEO title',
            'Legacy meta description.',
            'Legacy excerpt.',
            "Legacy first paragraph.\n\nLegacy second paragraph.",
            'draft',
            1,
            self::ACTOR,
            self::ACTOR,
            '2030-01-01 09:00:00.000000',
            '2030-01-01 09:00:00.000000',
        ]));
    }

    private function seedMedia(): void
    {
        $asset = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_media_assets '
                . '(public_id, label, source_mime, source_width, '
                . 'source_height, source_bytes, source_sha256, '
                . 'created_by_user_id, created_at) VALUES '
                . '(?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        self::assertTrue($asset->execute([
            self::MEDIA,
            'Matrix poster',
            'image/jpeg',
            900,
            506,
            500,
            str_repeat('a', 64),
            $this->actorId,
            '2030-01-01 09:30:00.000000',
        ]));
        $assetId = (int) $this->pdo->lastInsertId();
        $variant = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_media_variants '
                . '(asset_id, width, height, bytes, sha256, storage_key, '
                . 'mime, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ([[480, 270], [900, 506]] as [$width, $height]) {
            self::assertTrue($variant->execute([
                $assetId,
                $width,
                $height,
                200,
                str_repeat((string) ($width === 480 ? 'b' : 'c'), 64),
                'blog/matrix-' . $width . '.avif',
                'image/avif',
                '2030-01-01 09:30:00.000000',
            ]));
        }
    }

    private function seedCategories(): void
    {
        $postId = (int) $this->pdo->query(
            "SELECT id FROM ls_blog_posts WHERE public_id = '" . self::POST . "'"
        )->fetchColumn();
        $insertCategory = $this->pdo->prepare(
            'INSERT INTO ls_blog_categories '
                . '(public_id, created_by_user_public_id) VALUES (?, ?)'
        );
        $insertLocale = $this->pdo->prepare(
            'INSERT INTO ls_blog_category_locales '
                . '(public_id, category_id, locale, slug, name, '
                . 'created_by_user_public_id, updated_by_user_public_id) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ([
            [self::ASSIGNED_CATEGORY, 1, 'noticias', 'Noticias'],
            [self::AVAILABLE_CATEGORY, 2, 'fiscalidad', 'Fiscalidad'],
        ] as [$categoryId, $seed, $slug, $name]) {
            self::assertTrue($insertCategory->execute([
                $categoryId,
                self::ACTOR,
            ]));
            $internalId = (int) $this->pdo->lastInsertId();
            self::assertTrue($insertLocale->execute([
                sprintf('71000000-0000-4000-8000-%012d', $seed),
                $internalId,
                'es',
                $slug,
                $name,
                self::ACTOR,
                self::ACTOR,
            ]));

            if ($categoryId === self::ASSIGNED_CATEGORY) {
                $assignment = $this->pdo->prepare(
                    'INSERT INTO ls_blog_post_categories '
                        . '(public_id, post_id, category_id, '
                        . 'assigned_by_user_public_id) VALUES (?, ?, ?, ?)'
                );
                self::assertTrue($assignment->execute([
                    '72000000-0000-4000-8000-000000000001',
                    $postId,
                    $internalId,
                    self::ACTOR,
                ]));
            }
        }
    }

    private function seedNewerMedia(int $count): void
    {
        $asset = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_media_assets '
                . '(public_id, label, source_mime, source_width, '
                . 'source_height, source_bytes, source_sha256, '
                . 'created_by_user_id, created_at) VALUES '
                . '(?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $variant = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_media_variants '
                . '(asset_id, width, height, bytes, sha256, storage_key, '
                . 'mime, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        for ($index = 1; $index <= $count; ++$index) {
            $publicId = sprintf(
                '31000000-0000-4000-8000-%012d',
                $index
            );
            $createdAt = sprintf(
                '2030-01-02 09:30:%02d.000000',
                $index % 60
            );
            self::assertTrue($asset->execute([
                $publicId,
                'Recent Matrix media ' . $index,
                'image/jpeg',
                900,
                506,
                500,
                hash('sha256', 'source-' . $index),
                $this->actorId,
                $createdAt,
            ]));
            $assetId = (int) $this->pdo->lastInsertId();
            self::assertTrue($variant->execute([
                $assetId,
                480,
                270,
                200,
                hash('sha256', 'variant-' . $index),
                'blog/recent-' . $index . '-480.avif',
                'image/avif',
                $createdAt,
            ]));
        }
    }

    private function executeMigration(
        MigrationDefinition $migration,
        MigrationScope $scope
    ): void {
        foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
            self::assertNotFalse($this->pdo->exec($sql));
        }
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query): Request
    {
        return $this->navigation('GET', $path, $query);
    }

    /** @param array<string, string> $query */
    private function head(string $path, array $query): Request
    {
        return $this->navigation('HEAD', $path, $query);
    }

    /** @param array<string, string> $query */
    private function navigation(
        string $method,
        string $path,
        array $query
    ): Request {
        return Request::fromInput([
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path,
            'HTTPS' => 'on',
            'REMOTE_ADDR' => '192.0.2.80',
        ], query: $query, cookies: [
            'LS_WEBADMIN_SID' => $this->sessionToken,
        ]);
    }

    /** @param array<string, string> $form */
    private function post(string $path, array $form): Request
    {
        return Request::fromInput([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => $path,
            'HTTPS' => 'on',
            'REMOTE_ADDR' => '192.0.2.80',
        ], form: $form, cookies: [
            'LS_WEBADMIN_SID' => $this->sessionToken,
        ], headers: [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => 'Structured editor integration test',
        ]);
    }

    /** @return array<string, string> */
    private function saveForm(
        string $documentJson,
        int $lockVersion,
        string $h1
    ): array {
        return [
            'csrf' => $this->csrfToken,
            'post' => self::POST,
            'locale' => 'es',
            'lock_version' => (string) $lockVersion,
            'document_json' => $documentJson,
            'h1' => $h1,
            'slug' => 'structured-matrix',
            'seo_title' => 'Structured Matrix SEO title',
            'meta_description' => 'Structured Matrix meta description.',
            'excerpt' => 'Structured Matrix excerpt.',
        ];
    }

    private function documentJson(
        string $text,
        ?string $mediaAssetPublicId = null
    ): string {
        $blocks = [[
            'id' => '40000000-0000-4000-8000-000000000001',
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => $text,
                'marks' => [],
            ]],
        ]];
        if ($mediaAssetPublicId !== null) {
            $blocks[] = [
                'id' => '40000000-0000-4000-8000-000000000002',
                'type' => 'image',
                'media_asset_public_id' => $mediaAssetPublicId,
                'alt' => 'Matrix poster alternative',
                'title' => 'Matrix poster title',
                'caption' => 'Matrix poster caption.',
                'decorative' => false,
                'display' => 'content',
            ];
        }

        return (new BlogDocumentCodec())->encode(BlogDocument::fromArray([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => $blocks,
        ]));
    }

    private function addCapability(string $capability): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO ls_webadmin_user_capabilities '
                . '(user_id, capability_id) SELECT :user, id FROM '
                . 'ls_webadmin_capabilities WHERE code = :code'
        );
        self::assertTrue($statement->execute([
            'user' => $this->actorId,
            'code' => $capability,
        ]));
    }

    private function removeCapability(string $capability): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM ls_webadmin_user_capabilities WHERE user_id = :user '
                . 'AND capability_id = (SELECT id FROM '
                . 'ls_webadmin_capabilities WHERE code = :code)'
        );
        self::assertTrue($statement->execute([
            'user' => $this->actorId,
            'code' => $capability,
        ]));
    }

    private function rowCount(string $table): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ' . $table
        )->fetchColumn();
    }

    private function auditCount(string $event): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ls_webadmin_audit_log WHERE event_code = ?'
        );
        self::assertTrue($statement->execute([$event]));

        return (int) $statement->fetchColumn();
    }

    private function lockVersion(): int
    {
        return (int) $this->pdo->query(
            "SELECT lock_version FROM ls_blog_post_localizations "
                . "WHERE public_id = '" . self::LOCALIZATION . "'"
        )->fetchColumn();
    }

    private function h1(): string
    {
        return (string) $this->pdo->query(
            "SELECT h1 FROM ls_blog_post_localizations WHERE public_id = '"
                . self::LOCALIZATION . "'"
        )->fetchColumn();
    }

    private function bodyText(): string
    {
        return (string) $this->pdo->query(
            "SELECT body_text FROM ls_blog_post_localizations "
                . "WHERE public_id = '" . self::LOCALIZATION . "'"
        )->fetchColumn();
    }

    private function assertEditorRedirect(Response $response): void
    {
        self::assertSame(303, $response->status());
        self::assertSame(
            '/admin/blog/editor?post=' . self::POST . '&locale=es',
            $response->headers()['Location']
        );
        self::assertSame('', $response->body());
        self::assertSame(
            'noindex, nofollow, noarchive',
            $response->headers()['X-Robots-Tag']
        );
        self::assertStringContainsString(
            "default-src 'none'",
            $response->headers()['Content-Security-Policy']
        );
    }

    private function assertPrivateHtml(Response $response): void
    {
        self::assertSame(
            'no-store, no-cache, must-revalidate, max-age=0',
            $response->headers()['Cache-Control']
        );
        self::assertSame(
            'noindex, nofollow, noarchive',
            $response->headers()['X-Robots-Tag']
        );
        $csp = $response->headers()['Content-Security-Policy'];
        self::assertStringContainsString("default-src 'none'", $csp);
        self::assertStringContainsString("img-src 'self' data:", $csp);
        self::assertStringContainsString("style-src 'self'", $csp);
        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringContainsString("form-action 'self'", $csp);
        self::assertStringNotContainsString("'unsafe-inline'", $csp);
        self::assertStringNotContainsString("'unsafe-eval'", $csp);
        self::assertStringNotContainsString('https:', $csp);
        self::assertSame('no-referrer', $response->headers()['Referrer-Policy']);
        self::assertSame('DENY', $response->headers()['X-Frame-Options']);
    }

    private function assertDoesNotLeak(Response $response, string $secret): void
    {
        self::assertStringNotContainsString($secret, $response->body());
        self::assertStringNotContainsString(
            $secret,
            implode("\n", $response->headers())
        );
    }
}
