<?php

declare(strict_types=1);

use App\Core\Blog\Analytics\BlogAnalyticsCapabilities;
use App\Core\Blog\Analytics\BlogAnalyticsReportInterface;
use App\Core\Blog\Analytics\BlogArticleAnalyticsSummary;
use App\Core\Blog\BlogException;
use App\Core\Blog\BlogService;
use App\Core\Blog\Categories\BlogReservedCategoryPolicy;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Http\BlogAdminHttpController;
use App\Core\Blog\Http\BlogCategoryAdminHttpController;
use App\Core\Blog\Http\BlogAdminHttpRuntime;
use App\Core\Blog\Http\BlogAdminHttpRuntimeInterface;
use App\Core\Blog\Http\BlogAnalyticsAdminHttpRuntimeInterface;
use App\Core\Blog\Http\BlogStructuredEditorHttpRuntimeInterface;
use App\Core\Blog\Http\BlogTagAdminHttpRuntimeInterface;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\Seo\PdoBlogUrlHistoryRepository;
use App\Core\Blog\Tags\BlogTagCapabilities;
use App\Core\Blog\Tags\BlogTagService;
use App\Core\Blog\Tags\Persistence\PdoBlogTagRepository;
use App\Core\Http\PrivateRouteTransportPolicy;
use App\Core\Http\Request;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationRepository;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Media\MediaService;
use App\Core\WebAdmin\Navigation\WebAdminNavigationCatalog;
use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogAdminControllerClock implements ClockInterface
{
    public function __construct(private readonly DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class LegacyBlogAdminRuntimeAdapter implements
    BlogAdminHttpRuntimeInterface
{
    /** @var list<string> */
    public array $requestedGateCapabilities = [];
    public int $deniedMediaGateRuns = 0;

    public function __construct(
        private readonly BlogAdminHttpRuntime $inner
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

    public function mutationGate(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        string $capability
    ): Closure {
        $this->requestedGateCapabilities[] = $capability;
        if ($capability === MediaService::VIEW_CAPABILITY) {
            return function (PDO $pdo): string {
                $this->deniedMediaGateRuns += 1;
                throw new BlogException(BlogException::ACTOR_GATE_FAILED);
            };
        }

        return $this->inner->mutationGate(
            $sessionToken,
            $csrfToken,
            $capability
        );
    }
}

final class AnalyticsBlogAdminRuntimeAdapter implements
    BlogAdminHttpRuntimeInterface,
    BlogAnalyticsAdminHttpRuntimeInterface
{
    public function __construct(
        private readonly BlogAdminHttpRuntime $inner,
        private readonly BlogAnalyticsReportInterface $report
    ) {
    }

    public function projectRoot(): string { return $this->inner->projectRoot(); }
    public function languages(): array { return $this->inner->languages(); }
    public function blogConfig(): BlogConfig { return $this->inner->blogConfig(); }
    public function webAdminConfig(): WebAdminConfig
    {
        return $this->inner->webAdminConfig();
    }
    public function service(): BlogService { return $this->inner->service(); }
    public function authentication(): WebAdminAuthenticationService
    {
        return $this->inner->authentication();
    }
    public function authorization(): WebAdminAuthorizationService
    {
        return $this->inner->authorization();
    }
    public function mutationGate(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        string $capability
    ): Closure {
        return $this->inner->mutationGate(
            $sessionToken,
            $csrfToken,
            $capability
        );
    }
    public function analyticsReport(): BlogAnalyticsReportInterface
    {
        return $this->report;
    }
}

final class PreTombstoneBlogAdminRuntimeAdapter implements
    BlogAdminHttpRuntimeInterface
{
    public function __construct(
        private readonly BlogAdminHttpRuntime $inner,
        private readonly BlogService $blogService
    ) {
    }

    public function projectRoot(): string { return $this->inner->projectRoot(); }
    public function languages(): array { return $this->inner->languages(); }
    public function blogConfig(): BlogConfig { return $this->inner->blogConfig(); }
    public function webAdminConfig(): WebAdminConfig
    {
        return $this->inner->webAdminConfig();
    }
    public function service(): BlogService { return $this->blogService; }
    public function authentication(): WebAdminAuthenticationService
    {
        return $this->inner->authentication();
    }
    public function authorization(): WebAdminAuthorizationService
    {
        return $this->inner->authorization();
    }
    public function mutationGate(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        string $capability
    ): Closure {
        return $this->inner->mutationGate(
            $sessionToken,
            $csrfToken,
            $capability
        );
    }
}

final class TagsReadyBlogAdminRuntimeAdapter implements
    BlogTagAdminHttpRuntimeInterface
{
    /** @var list<string> */
    public array $requestedGateCapabilities = [];

    public function __construct(
        private readonly BlogAdminHttpRuntime $inner,
        private readonly BlogTagService $tags
    ) {
    }

    public function projectRoot(): string { return $this->inner->projectRoot(); }
    public function languages(): array { return $this->inner->languages(); }
    public function blogConfig(): BlogConfig { return $this->inner->blogConfig(); }
    public function webAdminConfig(): WebAdminConfig
    {
        return $this->inner->webAdminConfig();
    }
    public function service(): BlogService { return $this->inner->service(); }
    public function authentication(): WebAdminAuthenticationService
    {
        return $this->inner->authentication();
    }
    public function authorization(): WebAdminAuthorizationService
    {
        return $this->inner->authorization();
    }
    public function mutationGate(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        string $capability
    ): Closure {
        $this->requestedGateCapabilities[] = $capability;

        return $this->inner->mutationGate(
            $sessionToken,
            $csrfToken,
            $capability
        );
    }
    public function tagService(): ?BlogTagService { return $this->tags; }
    public function tagsReady(): bool { return true; }
}

final class BlogAdminHttpControllerTest extends TestCase
{
    private PDO $pdo;
    private string $projectRoot;
    private Filesystem $filesystem;
    private BlogAdminHttpController $controller;
    private BlogAdminHttpRuntime $runtime;
    private string $sessionToken;
    private string $csrfToken;
    private string $previousTraceSetting;

    protected function setUp(): void
    {
        $this->previousTraceSetting = (string) ini_get(
            'zend.exception_ignore_args'
        );
        ini_set('zend.exception_ignore_args', '1');
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-blog-admin-controller-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir([
            $this->projectRoot . '/App/config/routes',
            $this->projectRoot . '/public',
        ]);
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/routes/get.php',
            "<?php\nreturn [];\n"
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/routes/post.php',
            "<?php\nreturn [];\n"
        );

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

        $clock = new BlogAdminControllerClock(
            new DateTimeImmutable('2030-01-01 10:00:00 UTC')
        );
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
        $this->seedActor($tokens, $config);

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
        $blogRepository = new PdoBlogRepository(
            $this->pdo,
            $blogScope,
            true,
            reservedCategoryPolicyEnabled: true
        );
        $structuredContentRepository = new
            App\Core\Blog\StructuredContent\Persistence\PdoBlogStructuredContentRepository(
                $this->pdo,
                $blogScope
            );
        $service = new BlogService(
            $blogRepository,
            new RandomUuidV4Generator(),
            $clock,
            structuredContentRepository: $structuredContentRepository,
            mediaAvailability: new
                App\Core\Blog\StructuredContent\Media\PdoWebAdminMediaAvailabilityAdapter(
                    $this->pdo,
                    $webAdminScope
                ),
            urlHistory: new PdoBlogUrlHistoryRepository(
                $this->pdo,
                $blogScope
            )
        );
        $languages = ['es', 'eu'];
        $blogConfig = new BlogConfig(
            [
                'es' => '/es/noticias',
                'eu' => '/eu/albisteak',
            ],
            BlogConfig::DEFAULT_SITEMAP_PATH,
            BlogConfig::DEFAULT_TABLE_PREFIX,
            'test',
            defaultLocale: 'es'
        );
        $this->runtime = new BlogAdminHttpRuntime(
            $this->projectRoot,
            $languages,
            $blogConfig,
            $config,
            $service,
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
            navigation: new WebAdminNavigationCatalog([
                new WebAdminNavigationItem(
                    'blog',
                    'Art&iacute;culos',
                    '/blog',
                    BlogAdminHttpController::VIEW_CAPABILITY
                ),
            ])
        );
        $this->controller = new BlogAdminHttpController($this->runtime);
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->previousTraceSetting);
        $this->filesystem->remove($this->projectRoot);
    }

    public function testCompleteDraftPublishAndUnpublishFlowUsesPrg(): void
    {
        $empty = $this->controller->index($this->get('/admin/blog'));
        self::assertSame(200, $empty->status());
        self::assertStringContainsString('No hay art&iacute;culos', $empty->body());
        self::assertSame(1, substr_count($empty->body(), '<main'));
        self::assertStringContainsString('data-webadmin-shell', $empty->body());
        self::assertStringContainsString(
            'href="/admin/blog" aria-current="page"',
            $empty->body()
        );
        self::assertStringContainsString(
            'action="/admin/logout"',
            $empty->body()
        );
        self::assertStringContainsString(
            '/assets/modules/blog/blog-admin.css',
            $empty->body()
        );
        self::assertStringContainsString(
            '/assets/modules/blog/blog-admin-list.js',
            $empty->body()
        );
        self::assertStringContainsString(
            "style-src 'self'",
            $empty->headers()['Content-Security-Policy']
        );
        self::assertStringContainsString(
            "script-src 'self'",
            $empty->headers()['Content-Security-Policy']
        );
        self::assertStringContainsString(
            "img-src 'self'",
            $empty->headers()['Content-Security-Policy']
        );
        self::assertStringContainsString(
            "frame-src 'self'",
            $empty->headers()['Content-Security-Policy']
        );
        $this->assertPrivateHeaders($empty);

        $create = $this->controller->create($this->post(
            '/admin/blog/posts/create',
            ['csrf' => $this->csrfToken, 'post' => '', 'locale' => 'es']
                + $this->editorial('matrix')
        ));
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_post_localizations'
        )->fetchColumn());

        $post = (string) $this->pdo->query(
            'SELECT public_id FROM ls_blog_posts'
        )->fetchColumn();
        $this->assertCreatePrg($create, $post, 'es');
        $edit = $this->controller->edit($this->get(
            '/admin/blog/posts/edit',
            ['post' => $post, 'locale' => 'es']
        ));
        self::assertSame(200, $edit->status());
        self::assertStringContainsString(
            'name="lock_version" value="1"',
            $edit->body()
        );
        self::assertStringContainsString(
            'name="csrf" value="' . $this->csrfToken . '"',
            $edit->body()
        );

        $saveForm = [
            'csrf' => $this->csrfToken,
            'post' => $post,
            'locale' => 'es',
            'lock_version' => '1',
        ] + $this->editorial('matrix-reloaded');
        $this->assertPrg($this->controller->save($this->post(
            '/admin/blog/posts/save',
            $saveForm
        )));
        self::assertSame(409, $this->controller->save($this->post(
            '/admin/blog/posts/save',
            $saveForm
        ))->status());

        $publishForm = [
            'csrf' => $this->csrfToken,
            'post' => $post,
            'locale' => 'es',
            'lock_version' => '2',
        ];
        $this->assertPrg($this->controller->publish($this->post(
            '/admin/blog/posts/publish',
            $publishForm
        )));
        self::assertSame('published', $this->pdo->query(
            'SELECT status FROM ls_blog_post_localizations'
        )->fetchColumn());

        $publishForm['lock_version'] = '3';
        $retired = $this->controller->unpublish($this->post(
            '/admin/blog/posts/unpublish',
            $publishForm
        ));
        self::assertSame(303, $retired->status());
        self::assertSame(
            '/admin/blog/posts/url?post=' . $post . '&locale=es',
            $retired->headers()['Location']
        );
        self::assertSame('draft', $this->pdo->query(
            'SELECT status FROM ls_blog_post_localizations'
        )->fetchColumn());
        $urlPage = $this->controller->urlManager($this->get(
            '/admin/blog/posts/url',
            ['post' => $post, 'locale' => 'es']
        ));
        self::assertSame(200, $urlPage->status());
        self::assertStringContainsString('responde 404', $urlPage->body());
        self::assertStringContainsString(
            'name="historical_slug" value="matrix-reloaded"',
            $urlPage->body()
        );

        $gone = $this->controller->finalizeUrl($this->post(
            '/admin/blog/posts/url-resolution',
            [
                'csrf' => $this->csrfToken,
                'post' => $post,
                'locale' => 'es',
                'lock_version' => '4',
                'historical_slug' => 'matrix-reloaded',
                'resolution' => 'gone',
                'replacement_post' => '',
            ]
        ));
        self::assertSame(303, $gone->status());
        self::assertSame(
            '/admin/blog/posts/url?post=' . $post . '&locale=es',
            $gone->headers()['Location']
        );
        self::assertSame('gone', $this->pdo->query(
            'SELECT state FROM ls_blog_url_history '
                . "WHERE slug = 'matrix-reloaded'"
        )->fetchColumn());
    }

    public function testDuplicateTrashAndRestoreEnforceCsrfAndCapabilities(): void
    {
        self::assertSame(303, $this->controller->create($this->post(
            '/admin/blog/posts/create',
            ['csrf' => $this->csrfToken, 'post' => '', 'locale' => 'es']
                + $this->editorial('editorial-actions')
        ))->status());
        $source = (string) $this->pdo->query(
            'SELECT public_id FROM ls_blog_posts'
        )->fetchColumn();

        $invalidDuplicate = $this->controller->duplicate($this->post(
            '/admin/blog/posts/duplicate',
            [
                'csrf' => str_repeat('X', 43),
                'post' => $source,
                'locale' => 'es',
                'destination_locale' => 'es',
                'lock_version' => '1',
                'operation_id' => '91000000-0000-4000-8000-000000000001',
            ]
        ));
        self::assertSame(403, $invalidDuplicate->status());
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_posts'
        )->fetchColumn());

        $inactiveLocale = $this->controller->duplicate($this->post(
            '/admin/blog/posts/duplicate',
            [
                'csrf' => $this->csrfToken,
                'post' => $source,
                'locale' => 'es',
                'destination_locale' => 'fr',
                'lock_version' => '1',
                'operation_id' => '91000000-0000-4000-8000-000000000002',
            ]
        ));
        self::assertSame(422, $inactiveLocale->status());
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_posts'
        )->fetchColumn());

        $duplicate = $this->controller->duplicate($this->post(
            '/admin/blog/posts/duplicate',
            [
                'csrf' => $this->csrfToken,
                'post' => $source,
                'locale' => 'es',
                'destination_locale' => 'es',
                'lock_version' => '1',
                'operation_id' => '91000000-0000-4000-8000-000000000003',
            ]
        ));
        self::assertSame(303, $duplicate->status());
        self::assertMatchesRegularExpression(
            '#\A/admin/blog/editor\?post=[0-9a-f-]{36}&locale=es\z#',
            $duplicate->headers()['Location']
        );
        self::assertSame(2, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_posts'
        )->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ls_blog_post_localizations "
                . "WHERE status = 'draft' AND slug IS NULL "
                . "AND h1 = 'Copia de Matrix'"
        )->fetchColumn());

        $replay = $this->controller->duplicate($this->post(
            '/admin/blog/posts/duplicate',
            [
                'csrf' => $this->csrfToken,
                'post' => $source,
                'locale' => 'es',
                'destination_locale' => 'es',
                'lock_version' => '1',
                'operation_id' => '91000000-0000-4000-8000-000000000003',
            ]
        ));
        self::assertSame(303, $replay->status());
        self::assertSame(
            $duplicate->headers()['Location'],
            $replay->headers()['Location']
        );
        self::assertSame(2, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_posts'
        )->fetchColumn());

        $keyDrift = $this->controller->duplicate($this->post(
            '/admin/blog/posts/duplicate',
            [
                'csrf' => $this->csrfToken,
                'post' => $source,
                'locale' => 'es',
                'destination_locale' => 'eu',
                'lock_version' => '1',
                'operation_id' => '91000000-0000-4000-8000-000000000003',
            ]
        ));
        self::assertSame(409, $keyDrift->status());
        self::assertStringContainsString(
            'No se pudo crear el borrador',
            $keyDrift->body()
        );
        self::assertStringContainsString(
            'No se ha creado un segundo borrador',
            $keyDrift->body()
        );
        self::assertStringContainsString(
            'href="/admin/blog"',
            $keyDrift->body()
        );
        self::assertSame('text/html; charset=utf-8',
            $keyDrift->headers()['Content-Type']);
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_copy_operations'
        )->fetchColumn());

        $trash = $this->controller->trashPost($this->post(
            '/admin/blog/posts/trash',
            [
                'csrf' => $this->csrfToken,
                'post' => $source,
                'locale' => 'es',
                'lock_version' => '1',
            ]
        ));
        self::assertSame(303, $trash->status());
        self::assertSame('/admin/blog/trash', $trash->headers()['Location']);
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_post_tombstones'
        )->fetchColumn());
        $trashPage = $this->controller->trash($this->get(
            '/admin/blog/trash'
        ));
        self::assertSame(200, $trashPage->status());
        self::assertStringContainsString(
            'name="post" value="' . $source . '"',
            $trashPage->body()
        );
        self::assertStringContainsString(
            'action="/admin/blog/posts/restore"',
            $trashPage->body()
        );
        self::assertStringContainsString(
            '<th scope="col">Estado</th>',
            $trashPage->body()
        );
        self::assertStringContainsString(
            'blogAdminPage__postStatus--deleted',
            $trashPage->body()
        );
        self::assertStringContainsString(
            '<span>Eliminado</span>',
            $trashPage->body()
        );

        $invalidRestore = $this->controller->restoreFromTrash($this->post(
            '/admin/blog/posts/restore',
            [
                'csrf' => str_repeat('X', 43),
                'post' => $source,
                'locale' => 'es',
                'lock_version' => '2',
            ]
        ));
        self::assertSame(403, $invalidRestore->status());
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_post_tombstones'
        )->fetchColumn());

        $restore = $this->controller->restoreFromTrash($this->post(
            '/admin/blog/posts/restore',
            [
                'csrf' => $this->csrfToken,
                'post' => $source,
                'locale' => 'es',
                'lock_version' => '2',
            ]
        ));
        self::assertSame(303, $restore->status());
        self::assertSame('/admin/blog/trash', $restore->headers()['Location']);
        self::assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_post_tombstones'
        )->fetchColumn());

        $this->removeCapability(BlogAdminHttpController::DELETE_CAPABILITY);
        self::assertSame(403, $this->controller->trash($this->get(
            '/admin/blog/trash'
        ))->status());
        self::assertSame(403, $this->controller->trashPost($this->post(
            '/admin/blog/posts/trash',
            [
                'csrf' => $this->csrfToken,
                'post' => $source,
                'locale' => 'es',
                'lock_version' => '3',
            ]
        ))->status());
        self::assertStringNotContainsString(
            'action="/admin/blog/posts/trash"',
            $this->controller->index($this->get('/admin/blog'))->body()
        );
    }

    public function testIndependentDuplicateRequiresCategoryEditButLocaleCopyDoesNot(): void
    {
        self::assertSame(303, $this->controller->create($this->post(
            '/admin/blog/posts/create',
            ['csrf' => $this->csrfToken, 'post' => '', 'locale' => 'es']
                + $this->editorial('category-protected-copy')
        ))->status());
        $source = (string) $this->pdo->query(
            'SELECT public_id FROM ls_blog_posts'
        )->fetchColumn();
        $this->removeCapability(
            BlogCategoryAdminHttpController::EDIT_CAPABILITY
        );

        $index = $this->controller->index($this->get('/admin/blog'));
        self::assertSame(200, $index->status());
        self::assertStringContainsString(
            'action="/admin/blog/posts/duplicate"',
            $index->body()
        );
        self::assertMatchesRegularExpression(
            '/name="destination_locale" value="es"[^>]* disabled>/',
            $index->body()
        );
        self::assertMatchesRegularExpression(
            '/name="destination_locale" value="eu"(?![^>]* disabled)[^>]*>/',
            $index->body()
        );
        $response = $this->controller->duplicate($this->post(
            '/admin/blog/posts/duplicate',
            [
                'csrf' => $this->csrfToken,
                'post' => $source,
                'locale' => 'es',
                'destination_locale' => 'es',
                'lock_version' => '1',
                'operation_id' => '92000000-0000-4000-8000-000000000001',
            ]
        ));

        self::assertSame(403, $response->status());
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_posts'
        )->fetchColumn());

        $localeCopy = $this->controller->duplicate($this->post(
            '/admin/blog/posts/duplicate',
            [
                'csrf' => $this->csrfToken,
                'post' => $source,
                'locale' => 'es',
                'destination_locale' => 'eu',
                'lock_version' => '1',
                'operation_id' => '92000000-0000-4000-8000-000000000002',
            ]
        ));
        self::assertSame(303, $localeCopy->status());
        self::assertSame(
            '/admin/blog/editor?post=' . $source . '&locale=eu',
            $localeCopy->headers()['Location']
        );
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_posts'
        )->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_post_localizations'
        )->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ls_blog_post_localizations "
                . "WHERE locale = 'eu' AND status = 'draft' "
                . "AND slug IS NULL AND h1 = 'Matrix'"
        )->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_editorial_workspaces'
        )->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_publication_heads'
        )->fetchColumn());
    }

    public function testIndependentDuplicateRequiresTagEditWhenTagsAreReady(): void
    {
        self::assertSame(303, $this->controller->create($this->post(
            '/admin/blog/posts/create',
            ['csrf' => $this->csrfToken, 'post' => '', 'locale' => 'es']
                + $this->editorial('tag-protected-copy')
        ))->status());
        $source = (string) $this->pdo->query(
            'SELECT public_id FROM ls_blog_posts'
        )->fetchColumn();
        $runtime = new TagsReadyBlogAdminRuntimeAdapter(
            $this->runtime,
            new BlogTagService(new PdoBlogTagRepository(
                $this->pdo,
                MigrationScope::forTablePrefix('blog', 'ls_blog_')
            ))
        );
        $controller = new BlogAdminHttpController($runtime);

        $index = $controller->index($this->get('/admin/blog'));
        self::assertSame(200, $index->status());
        self::assertMatchesRegularExpression(
            '/name="destination_locale" value="es"[^>]* disabled>/',
            $index->body()
        );
        self::assertMatchesRegularExpression(
            '/name="destination_locale" value="eu"(?![^>]* disabled)[^>]*>/',
            $index->body()
        );

        $denied = $controller->duplicate($this->post(
            '/admin/blog/posts/duplicate',
            [
                'csrf' => $this->csrfToken,
                'post' => $source,
                'locale' => 'es',
                'destination_locale' => 'es',
                'lock_version' => '1',
                'operation_id' => '92500000-0000-4000-8000-000000000001',
            ]
        ));
        self::assertSame(403, $denied->status());
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_posts'
        )->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_copy_operations'
        )->fetchColumn());
        self::assertSame([], $runtime->requestedGateCapabilities);

        $this->addCapability(BlogTagCapabilities::EDIT);
        $editOnly = $controller->duplicate($this->post(
            '/admin/blog/posts/duplicate',
            [
                'csrf' => $this->csrfToken,
                'post' => $source,
                'locale' => 'es',
                'destination_locale' => 'es',
                'lock_version' => '1',
                'operation_id' => '92500000-0000-4000-8000-000000000002',
            ]
        ));
        self::assertSame(403, $editOnly->status());
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_posts'
        )->fetchColumn());
        self::assertSame([], $runtime->requestedGateCapabilities);

        $this->addCapability(BlogTagCapabilities::VIEW);
        $allowed = $controller->duplicate($this->post(
            '/admin/blog/posts/duplicate',
            [
                'csrf' => $this->csrfToken,
                'post' => $source,
                'locale' => 'es',
                'destination_locale' => 'es',
                'lock_version' => '1',
                'operation_id' => '92500000-0000-4000-8000-000000000003',
            ]
        ));
        self::assertSame(303, $allowed->status());
        self::assertSame(2, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_posts'
        )->fetchColumn());
        self::assertContains(
            BlogTagCapabilities::EDIT,
            $runtime->requestedGateCapabilities
        );
        self::assertContains(
            BlogTagCapabilities::VIEW,
            $runtime->requestedGateCapabilities
        );
    }

    public function testPreTombstoneRuntimeKeepsDuplicateAndHidesTrashSurface(): void
    {
        self::assertSame(303, $this->controller->create($this->post(
            '/admin/blog/posts/create',
            ['csrf' => $this->csrfToken, 'post' => '', 'locale' => 'es']
                + $this->editorial('before-tombstones')
        ))->status());
        $source = (string) $this->pdo->query(
            'SELECT public_id FROM ls_blog_posts'
        )->fetchColumn();
        $scope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $legacyService = new BlogService(
            new PdoBlogRepository(
                $this->pdo,
                $scope,
                reservedCategoryPolicyEnabled: true
            ),
            new RandomUuidV4Generator(),
            new BlogAdminControllerClock(
                new DateTimeImmutable('2030-01-01 10:00:00 UTC')
            ),
            structuredContentRepository: new
                App\Core\Blog\StructuredContent\Persistence\PdoBlogStructuredContentRepository(
                    $this->pdo,
                    $scope
                ),
            mediaAvailability: new
                App\Core\Blog\StructuredContent\Media\PdoWebAdminMediaAvailabilityAdapter(
                    $this->pdo,
                    MigrationScope::forTablePrefix(
                        'webadmin',
                        'ls_webadmin_'
                    )
                )
        );
        $controller = new BlogAdminHttpController(
            new PreTombstoneBlogAdminRuntimeAdapter(
                $this->runtime,
                $legacyService
            )
        );

        $index = $controller->index($this->get('/admin/blog'));
        self::assertSame(200, $index->status());
        self::assertStringContainsString(
            'action="/admin/blog/posts/duplicate"',
            $index->body()
        );
        self::assertStringNotContainsString(
            'action="/admin/blog/posts/trash"',
            $index->body()
        );
        self::assertStringNotContainsString(
            'href="/admin/blog/trash"',
            $index->body()
        );
        self::assertSame(303, $controller->duplicate($this->post(
            '/admin/blog/posts/duplicate',
            [
                'csrf' => $this->csrfToken,
                'post' => $source,
                'locale' => 'es',
                'destination_locale' => 'es',
                'lock_version' => '1',
                'operation_id' => '93000000-0000-4000-8000-000000000001',
            ]
        ))->status());
        self::assertSame(404, $controller->trash($this->get(
            '/admin/blog/trash'
        ))->status());
        foreach ([
            'trash' => 'trashPost',
            'restore' => 'restoreFromTrash',
        ] as $path => $method) {
            $response = $controller->{$method}($this->post(
                '/admin/blog/posts/' . $path,
                [
                    'csrf' => $this->csrfToken,
                    'post' => $source,
                    'locale' => 'es',
                    'lock_version' => '1',
                ]
            ));
            self::assertSame(404, $response->status(), $path);
        }
    }

    public function testLocaleChoiceUsesConfiguredPathsAndOmitsExistingVariants(): void
    {
        $new = $this->controller->newPost($this->get(
            '/admin/blog/posts/new'
        ));
        self::assertSame(200, $new->status());
        self::assertStringContainsString(
            '<option value="" selected disabled>Selecciona un idioma</option>',
            $new->body()
        );
        self::assertStringContainsString(
            '<option value="es">es &mdash; /es/noticias</option>',
            $new->body()
        );
        self::assertStringContainsString(
            '<option value="eu">eu &mdash; /eu/albisteak</option>',
            $new->body()
        );

        $missingLocale = $this->controller->create($this->post(
            '/admin/blog/posts/create',
            ['csrf' => $this->csrfToken, 'post' => '', 'locale' => '']
                + $this->editorial('matrix-missing-locale')
        ));
        self::assertSame(400, $missingLocale->status());
        self::assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_posts'
        )->fetchColumn());

        $created = $this->controller->create($this->post(
            '/admin/blog/posts/create',
            ['csrf' => $this->csrfToken, 'post' => '', 'locale' => 'es']
                + $this->editorial('matrix-es')
        ));
        self::assertSame(303, $created->status());
        $post = (string) $this->pdo->query(
            'SELECT public_id FROM ls_blog_posts'
        )->fetchColumn();

        $translation = $this->controller->newPost($this->get(
            '/admin/blog/posts/new',
            ['post' => $post]
        ));
        self::assertSame(200, $translation->status());
        self::assertStringNotContainsString(
            '<option value="es">',
            $translation->body()
        );
        self::assertStringContainsString(
            '<option value="eu">eu &mdash; /eu/albisteak</option>',
            $translation->body()
        );

        $editBeforeTranslation = $this->controller->edit($this->get(
            '/admin/blog/posts/edit',
            ['post' => $post, 'locale' => 'es']
        ));
        self::assertStringNotContainsString(
            'A&ntilde;adir otro idioma',
            $editBeforeTranslation->body()
        );
        self::assertStringNotContainsString(
            '/admin/blog/posts/new?post=',
            $editBeforeTranslation->body()
        );

        $added = $this->controller->create($this->post(
            '/admin/blog/posts/create',
            ['csrf' => $this->csrfToken, 'post' => $post, 'locale' => 'eu']
                + $this->editorial('matrix-eu')
        ));
        self::assertSame(303, $added->status());

        $complete = $this->controller->newPost($this->get(
            '/admin/blog/posts/new',
            ['post' => $post]
        ));
        self::assertSame(200, $complete->status());
        self::assertStringNotContainsString(
            'action="/admin/blog/posts/create"',
            $complete->body()
        );
        self::assertStringNotContainsString('name="locale"', $complete->body());
        self::assertStringContainsString(
            'todos los idiomas activos',
            $complete->body()
        );

        $editComplete = $this->controller->edit($this->get(
            '/admin/blog/posts/edit',
            ['post' => $post, 'locale' => 'es']
        ));
        self::assertStringNotContainsString(
            'A&ntilde;adir otro idioma',
            $editComplete->body()
        );
        self::assertStringNotContainsString(
            'todos los idiomas activos',
            $editComplete->body()
        );

        $unknown = $this->controller->newPost($this->get(
            '/admin/blog/posts/new',
            ['post' => '99999999-9999-4999-8999-999999999999']
        ));
        self::assertSame(404, $unknown->status());
    }

    public function testLegacyRuntimeRevalidatesMediaInsideCreateTransaction(): void
    {
        self::assertTrue($this->runtime->authorization()->hasCapability(
            $this->sessionToken,
            MediaService::VIEW_CAPABILITY
        ));
        $legacyRuntime = new LegacyBlogAdminRuntimeAdapter($this->runtime);
        self::assertNotInstanceOf(
            BlogStructuredEditorHttpRuntimeInterface::class,
            $legacyRuntime
        );
        $controller = new BlogAdminHttpController($legacyRuntime);
        $postsBefore = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_posts'
        )->fetchColumn();
        $localizationsBefore = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_post_localizations'
        )->fetchColumn();
        $auditBefore = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_audit_log'
        )->fetchColumn();

        $response = $controller->create($this->post(
            '/admin/blog/posts/create',
            ['csrf' => $this->csrfToken, 'post' => '', 'locale' => 'es']
                + $this->editorial('matrix-legacy-gate')
        ));

        self::assertSame(403, $response->status());
        self::assertSame('Forbidden', $response->body());
        self::assertSame([], $response->headerValues('Location'));
        self::assertSame([
            BlogAdminHttpController::EDIT_CAPABILITY,
            MediaService::VIEW_CAPABILITY,
        ], $legacyRuntime->requestedGateCapabilities);
        self::assertSame(1, $legacyRuntime->deniedMediaGateRuns);
        self::assertSame($postsBefore, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_posts'
        )->fetchColumn());
        self::assertSame($localizationsBefore, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_post_localizations'
        )->fetchColumn());
        self::assertSame($auditBefore, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_audit_log'
        )->fetchColumn());
    }

    public function testPublicationGuardBlocksStaticRouteBeforeMutation(): void
    {
        $created = $this->controller->create($this->post(
            '/admin/blog/posts/create',
            ['csrf' => $this->csrfToken, 'post' => '', 'locale' => 'es']
                + $this->editorial('matrix')
        ));
        $post = (string) $this->pdo->query(
            'SELECT public_id FROM ls_blog_posts'
        )->fetchColumn();
        $this->assertCreatePrg($created, $post, 'es');
        $inactive = $this->controller->publish($this->post(
            '/admin/blog/posts/publish',
            [
                'csrf' => $this->csrfToken,
                'post' => $post,
                'locale' => 'fr',
                'lock_version' => '1',
            ]
        ));
        self::assertSame(422, $inactive->status());
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/routes/get.php',
            "<?php\nreturn ['/es/noticias/matrix' => "
                . "['view' => 'legacy.php']];\n"
        );

        $response = $this->controller->publish($this->post(
            '/admin/blog/posts/publish',
            [
                'csrf' => $this->csrfToken,
                'post' => $post,
                'locale' => 'es',
                'lock_version' => '1',
            ]
        ));

        self::assertSame(409, $response->status());
        self::assertSame('draft', $this->pdo->query(
            'SELECT status FROM ls_blog_post_localizations'
        )->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT lock_version FROM ls_blog_post_localizations'
        )->fetchColumn());
    }

    public function testPrivatePreviewReadsOnlyTheStoredVariant(): void
    {
        $editorial = array_replace($this->editorial('matrix-preview'), [
            'h1' => 'Matrix & preview',
            'body_text' => "Primer bloque.\n\nSegundo bloque.",
        ]);
        $created = $this->controller->create($this->post(
            '/admin/blog/posts/create',
            ['csrf' => $this->csrfToken, 'post' => '', 'locale' => 'es']
                + $editorial
        ));
        $post = (string) $this->pdo->query(
            'SELECT public_id FROM ls_blog_posts'
        )->fetchColumn();
        $this->assertCreatePrg($created, $post, 'es');
        $before = $this->pdo->query(
            'SELECT status, lock_version, body_text FROM '
            . 'ls_blog_post_localizations'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($before);
        $auditBefore = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_audit_log'
        )->fetchColumn();

        $response = $this->controller->preview($this->get(
            '/admin/blog/posts/preview',
            ['post' => $post, 'locale' => 'es']
        ));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Matrix &amp; preview', $response->body());
        self::assertStringContainsString('<p>Primer bloque.</p>', $response->body());
        self::assertStringContainsString('<p>Segundo bloque.</p>', $response->body());
        self::assertStringContainsString('Estado: Borrador', $response->body());
        self::assertStringNotContainsString('rel="canonical"', $response->body());
        $this->assertPrivateHeaders($response);

        $after = $this->pdo->query(
            'SELECT status, lock_version, body_text FROM '
            . 'ls_blog_post_localizations'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame($before, $after);
        self::assertSame($auditBefore, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_audit_log'
        )->fetchColumn());

        self::assertSame(404, $this->controller->preview($this->get(
            '/admin/blog/posts/preview',
            [
                'post' => '99999999-9999-4999-8999-999999999999',
                'locale' => 'es',
            ]
        ))->status());
    }

    public function testDuplicateReplayExplainsThatItsResultIsInTrash(): void
    {
        self::assertSame(303, $this->controller->create($this->post(
            '/admin/blog/posts/create',
            ['csrf' => $this->csrfToken, 'post' => '', 'locale' => 'es']
                + $this->editorial('copy-replay-trash')
        ))->status());
        $source = (string) $this->pdo->query(
            'SELECT public_id FROM ls_blog_posts'
        )->fetchColumn();
        $operation = '91000000-0000-4000-8000-000000000099';
        $copyForm = [
            'csrf' => $this->csrfToken,
            'post' => $source,
            'locale' => 'es',
            'destination_locale' => 'es',
            'lock_version' => '1',
            'operation_id' => $operation,
        ];

        $created = $this->controller->duplicate($this->post(
            '/admin/blog/posts/duplicate',
            $copyForm
        ));
        self::assertSame(303, $created->status());
        parse_str(
            (string) parse_url(
                $created->headers()['Location'],
                PHP_URL_QUERY
            ),
            $createdQuery
        );
        $createdPost = (string) ($createdQuery['post'] ?? '');
        self::assertMatchesRegularExpression(
            '/\A[0-9a-f-]{36}\z/',
            $createdPost
        );

        self::assertSame(303, $this->controller->trashPost($this->post(
            '/admin/blog/posts/trash',
            [
                'csrf' => $this->csrfToken,
                'post' => $createdPost,
                'locale' => 'es',
                'lock_version' => '1',
            ]
        ))->status());

        $replay = $this->controller->duplicate($this->post(
            '/admin/blog/posts/duplicate',
            $copyForm
        ));
        self::assertSame(409, $replay->status());
        self::assertStringContainsString(
            'El borrador creado por esta solicitud est&aacute; en la Papelera.',
            $replay->body()
        );
        self::assertStringContainsString(
            'No se ha creado otro; rest&aacute;uralo para continuar.',
            $replay->body()
        );
        self::assertSame(2, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_posts'
        )->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_post_localizations'
        )->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_post_tombstones'
        )->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_copy_operations'
        )->fetchColumn());
    }

    public function testMalformedSemanticAndUnauthorizedRequestsFailClosed(): void
    {
        $malformed = $this->post('/admin/blog/posts/create', [
            'csrf' => $this->csrfToken,
            'post' => '',
            'locale' => 'es',
            'unexpected' => 'value',
        ] + $this->editorial('matrix'));
        self::assertSame(400, $this->controller->create($malformed)->status());

        $invalid = ['csrf' => $this->csrfToken, 'post' => '', 'locale' => 'es']
            + $this->editorial('Invalid Slug');
        self::assertSame(422, $this->controller->create($this->post(
            '/admin/blog/posts/create',
            $invalid
        ))->status());

        $wrongCsrf = ['csrf' => str_repeat('X', 43), 'post' => '', 'locale' => 'es']
            + $this->editorial('matrix');
        self::assertSame(403, $this->controller->create($this->post(
            '/admin/blog/posts/create',
            $wrongCsrf
        ))->status());
        self::assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_posts'
        )->fetchColumn());

        $inactiveLocale = [
            'csrf' => $this->csrfToken,
            'post' => '',
            'locale' => 'fr',
        ] + $this->editorial('matrix');
        self::assertSame(422, $this->controller->create($this->post(
            '/admin/blog/posts/create',
            $inactiveLocale
        ))->status());
        self::assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_posts'
        )->fetchColumn());

        $this->pdo->exec(
            'DELETE FROM ls_webadmin_user_capabilities WHERE capability_id = '
            . "(SELECT id FROM ls_webadmin_capabilities "
            . "WHERE code = 'blog.articles.edit')"
        );
        self::assertSame(403, $this->controller->newPost(
            $this->get('/admin/blog/posts/new')
        )->status());

        $this->pdo->exec(
            'DELETE FROM ls_webadmin_user_capabilities WHERE capability_id = '
            . "(SELECT id FROM ls_webadmin_capabilities "
            . "WHERE code = 'blog.articles.view')"
        );
        self::assertSame(403, $this->controller->preview($this->get(
            '/admin/blog/posts/preview',
            ['post' => $this->fixtureUuid('4', 1), 'locale' => 'es']
        ))->status());
    }

    public function testAnonymousAndHeadRequestsDoNotExposePrivateContent(): void
    {
        $anonymous = Request::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/blog',
            'HTTPS' => 'on',
        ]);
        $response = $this->controller->index($anonymous);
        self::assertSame(303, $response->status());
        self::assertSame('/admin/login', $response->headers()['Location']);

        $anonymousPreview = Request::fromInput([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/blog/posts/preview',
            'HTTPS' => 'on',
        ], query: [
            'post' => $this->fixtureUuid('4', 1),
            'locale' => 'es',
        ]);
        $response = $this->controller->preview($anonymousPreview);
        self::assertSame(303, $response->status());
        self::assertSame('/admin/login', $response->headers()['Location']);

        $head = Request::fromServer([
            'REQUEST_METHOD' => 'HEAD',
            'REQUEST_URI' => '/admin/blog',
            'HTTPS' => 'on',
        ]);
        $response = $this->controller->index($head);
        self::assertSame(303, $response->status());
        self::assertSame('', $response->body());
        self::assertSame('/admin/login', $response->headers()['Location']);

        $headPreview = Request::fromInput([
            'REQUEST_METHOD' => 'HEAD',
            'REQUEST_URI' => '/admin/blog/posts/preview',
            'HTTPS' => 'on',
        ], query: [
            'post' => $this->fixtureUuid('4', 1),
            'locale' => 'es',
        ]);
        $response = $this->controller->preview($headPreview);
        self::assertSame(303, $response->status());
        self::assertSame('', $response->body());
        self::assertSame('/admin/login', $response->headers()['Location']);

        $insecure = Request::fromInput([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/blog',
        ], cookies: ['LS_WEBADMIN_SID' => $this->sessionToken]);
        self::assertSame(400, $this->controller->index($insecure)->status());
    }

    public function testTypedDevelopmentControllerAcceptsOnlyExactLoopbackHttp(): void
    {
        $controller = new BlogAdminHttpController(
            $this->runtime,
            transportPolicy: new PrivateRouteTransportPolicy(),
            environment: [
                'RAIZ' => 'http://localhost:1309',
                'DEV_MODE' => '1',
            ]
        );
        $request = Request::fromInput([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/blog',
            'HTTP_HOST' => 'localhost:1309',
            'REMOTE_ADDR' => '127.0.0.1',
        ], cookies: [
            'LS_WEBADMIN_SID' => $this->sessionToken,
        ]);

        self::assertSame(200, $controller->index($request)->status());

        $wrongHost = Request::fromInput([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/blog',
            'HTTP_HOST' => 'localhost:1310',
            'REMOTE_ADDR' => '127.0.0.1',
        ], cookies: [
            'LS_WEBADMIN_SID' => $this->sessionToken,
        ]);
        self::assertSame(400, $controller->index($wrongHost)->status());
    }

    public function testIndexUsesBoundedPreviousAndNextPagination(): void
    {
        $this->seedBlogSummaries(51);

        $first = $this->controller->index($this->get('/admin/blog'));
        self::assertSame(200, $first->status());
        self::assertSame(21, substr_count($first->body(), '<tr>'));
        self::assertStringContainsString(
            'rel="next" href="/admin/blog?offset=20"',
            $first->body()
        );
        self::assertStringNotContainsString('rel="prev"', $first->body());

        $last = $this->controller->index($this->get(
            '/admin/blog',
            ['offset' => '40']
        ));
        self::assertSame(200, $last->status());
        self::assertSame(12, substr_count($last->body(), '<tr>'));
        self::assertStringContainsString(
            'rel="prev" href="/admin/blog?offset=20"',
            $last->body()
        );
        self::assertStringNotContainsString('rel="next"', $last->body());

        self::assertSame(400, $this->controller->index($this->get(
            '/admin/blog',
            ['offset' => '25']
        ))->status());
        self::assertSame(400, $this->controller->updated($this->get(
            '/admin/blog/posts/updated',
            ['offset' => '50']
        ))->status());
    }

    public function testIndexSearchFiltersAndPaginationShareTheNativeGet(): void
    {
        $this->seedBlogSummaries(51);

        $first = $this->controller->index($this->get('/admin/blog', [
            'q' => 'Matrix',
            'status' => 'draft',
            'locale' => 'es',
        ]));
        self::assertSame(200, $first->status());
        self::assertStringContainsString(
            'data-blog-admin-filter-form',
            $first->body()
        );
        self::assertStringContainsString(
            'data-blog-admin-results data-blog-admin-result-count="20"',
            $first->body()
        );
        self::assertStringContainsString(
            'rel="next" href="/admin/blog?q=Matrix&amp;status=draft'
                . '&amp;locale=es&amp;offset=20"',
            $first->body()
        );
        self::assertStringContainsString(
            "connect-src 'self'",
            $first->headers()['Content-Security-Policy']
        );

        $last = $this->controller->index($this->get('/admin/blog', [
            'q' => 'Matrix',
            'status' => 'draft',
            'locale' => 'es',
            'offset' => '40',
        ]));
        self::assertSame(200, $last->status());
        self::assertStringContainsString('Matrix 51', $last->body());
        self::assertStringNotContainsString('Matrix 40</th>', $last->body());
        self::assertStringContainsString(
            'rel="prev" href="/admin/blog?q=Matrix&amp;status=draft'
                . '&amp;locale=es&amp;offset=20"',
            $last->body()
        );

        $bySlug = $this->controller->index($this->get('/admin/blog', [
            'q' => 'matrix-51',
        ]));
        self::assertSame(200, $bySlug->status());
        self::assertStringContainsString('Matrix 51', $bySlug->body());
        self::assertStringNotContainsString('Matrix 50</th>', $bySlug->body());

        foreach ([['q' => 'x'], ['locale' => 'fr']] as $invalidQuery) {
            self::assertSame(400, $this->controller->index($this->get(
                '/admin/blog',
                $invalidQuery
            ))->status());
        }
    }

    public function testIndexExcludesCanonicalDummyBeforeReactivePaging(): void
    {
        $this->seedBlogSummaries(30);
        $this->assignCanonicalDummyToSeededSummaries(6);

        $first = $this->controller->index($this->get('/admin/blog'));
        self::assertSame(200, $first->status());
        self::assertStringContainsString(
            'data-blog-admin-results data-blog-admin-result-count="20"',
            $first->body()
        );
        self::assertStringContainsString(
            'rel="next" href="/admin/blog?offset=20"',
            $first->body()
        );
        for ($index = 1; $index <= 6; ++$index) {
            self::assertStringNotContainsString(
                '>Matrix ' . $index . '</th>',
                $first->body()
            );
        }
        self::assertStringContainsString('>Matrix 7</th>', $first->body());

        // This is the same native GET consumed by the progressive-enhancement
        // fetch path; filtering, sorting, offsets and its result sentinel all
        // remain downstream of the canonical exclusion.
        $reactiveGet = $this->controller->index($this->get('/admin/blog', [
            'q' => 'Matrix',
            'status' => 'draft',
            'locale' => 'es',
            'offset' => '20',
            'per_page' => '10',
            'sort' => 'title',
            'dir' => 'asc',
        ]));
        self::assertSame(200, $reactiveGet->status());
        self::assertStringContainsString(
            'data-blog-admin-results data-blog-admin-result-count="4"',
            $reactiveGet->body()
        );
        self::assertStringNotContainsString(
            'rel="next"',
            $reactiveGet->body()
        );
        foreach (['Matrix 30', 'Matrix 7', 'Matrix 8', 'Matrix 9'] as $title) {
            self::assertStringContainsString(
                '>' . $title . '</th>',
                $reactiveGet->body()
            );
        }

        self::assertSame(400, $this->controller->index($this->get(
            '/admin/blog',
            ['include_dummy' => '1']
        ))->status());
        self::assertStringNotContainsString('include_dummy', $first->body());
    }

    public function testIndexOnlyOffersActionsAllowedByStateAndCapabilities(): void
    {
        foreach (['matrix-draft', 'matrix-published'] as $slug) {
            $created = $this->controller->create($this->post(
                '/admin/blog/posts/create',
                ['csrf' => $this->csrfToken, 'post' => '', 'locale' => 'es']
                    + $this->editorial($slug)
            ));
            self::assertSame(303, $created->status());
        }
        $statement = $this->pdo->query(
            'SELECT p.public_id, l.slug, l.lock_version FROM ls_blog_posts p '
            . 'JOIN ls_blog_post_localizations l ON l.post_id = p.id'
        );
        self::assertNotFalse($statement);
        $posts = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $posts[(string) $row['slug']] = [
                'public_id' => (string) $row['public_id'],
                'lock_version' => (string) $row['lock_version'],
            ];
        }
        self::assertArrayHasKey('matrix-draft', $posts);
        self::assertArrayHasKey('matrix-published', $posts);
        $this->assertPrg($this->controller->publish($this->post(
            '/admin/blog/posts/publish',
            [
                'csrf' => $this->csrfToken,
                'post' => $posts['matrix-published']['public_id'],
                'locale' => 'es',
                'lock_version' => $posts['matrix-published']['lock_version'],
            ]
        )));
        $publishedPreviewWithCapabilities = $this->controller->preview(
            $this->get('/admin/blog/posts/preview', [
                'post' => $posts['matrix-published']['public_id'],
                'locale' => 'es',
            ])
        );
        self::assertSame(200, $publishedPreviewWithCapabilities->status());
        self::assertStringContainsString(
            '/admin/blog/editor?post='
                . $posts['matrix-published']['public_id']
                . '&amp;locale=es',
            $publishedPreviewWithCapabilities->body()
        );
        $this->removeCapability(BlogAdminHttpController::PUBLISH_CAPABILITY);

        $index = $this->controller->index($this->get('/admin/blog'));
        self::assertSame(200, $index->status());
        self::assertStringContainsString(
            '/admin/blog/editor?post='
                . $posts['matrix-draft']['public_id']
                . '&amp;locale=es',
            $index->body()
        );
        self::assertStringContainsString(
            '/admin/blog/editor?post='
                . $posts['matrix-published']['public_id']
                . '&amp;locale=es',
            $index->body()
        );
        self::assertSame(1, substr_count(
            $index->body(),
            '/admin/blog/editor/preview?'
        ));
        self::assertStringContainsString(
            'aria-label="Vista web"',
            $index->body()
        );
        $publishedPreview = $this->controller->preview($this->get(
            '/admin/blog/posts/preview',
            [
                'post' => $posts['matrix-published']['public_id'],
                'locale' => 'es',
            ]
        ));
        self::assertSame(200, $publishedPreview->status());
        self::assertStringContainsString(
            '/admin/blog/editor?post='
                . $posts['matrix-published']['public_id']
                . '&amp;locale=es',
            $publishedPreview->body()
        );
        $draftPreview = $this->controller->preview($this->get(
            '/admin/blog/posts/preview',
            [
                'post' => $posts['matrix-draft']['public_id'],
                'locale' => 'es',
            ]
        ));
        self::assertSame(200, $draftPreview->status());
        self::assertStringContainsString(
            '/admin/blog/editor?post=' . $posts['matrix-draft']['public_id']
                . '&amp;locale=es',
            $draftPreview->body()
        );

        $this->removeCapability(MediaService::VIEW_CAPABILITY);
        $withoutMedia = $this->controller->index($this->get('/admin/blog'));
        self::assertSame(200, $withoutMedia->status());
        self::assertStringNotContainsString(
            '/admin/blog/editor?',
            $withoutMedia->body()
        );
        self::assertStringNotContainsString(
            '/admin/blog/editor/preview?',
            $withoutMedia->body()
        );
        self::assertSame(1, substr_count(
            $withoutMedia->body(),
            '/admin/blog/posts/preview?'
        ));
        self::assertStringContainsString(
            'aria-label="Vista web"',
            $withoutMedia->body()
        );
        self::assertStringNotContainsString(
            '/admin/blog/posts/new',
            $withoutMedia->body()
        );
        $draftPreviewWithoutMedia = $this->controller->preview($this->get(
            '/admin/blog/posts/preview',
            [
                'post' => $posts['matrix-draft']['public_id'],
                'locale' => 'es',
            ]
        ));
        self::assertSame(200, $draftPreviewWithoutMedia->status());
        self::assertStringNotContainsString(
            '/admin/blog/editor?',
            $draftPreviewWithoutMedia->body()
        );

        $postsBeforeDeniedCreate = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_posts'
        )->fetchColumn();
        $localizationsBeforeDeniedCreate = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_post_localizations'
        )->fetchColumn();
        $auditBeforeDeniedCreate = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_audit_log'
        )->fetchColumn();

        $newWithoutMedia = $this->controller->newPost($this->get(
            '/admin/blog/posts/new'
        ));
        self::assertSame(403, $newWithoutMedia->status());
        self::assertSame('Forbidden', $newWithoutMedia->body());
        self::assertSame([], $newWithoutMedia->headerValues('Location'));

        $createWithoutMedia = $this->controller->create($this->post(
            '/admin/blog/posts/create',
            ['csrf' => $this->csrfToken, 'post' => '', 'locale' => 'es']
                + $this->editorial('matrix-forbidden')
        ));
        self::assertSame(403, $createWithoutMedia->status());
        self::assertSame('Forbidden', $createWithoutMedia->body());
        self::assertSame([], $createWithoutMedia->headerValues('Location'));
        self::assertSame($postsBeforeDeniedCreate, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_posts'
        )->fetchColumn());
        self::assertSame(
            $localizationsBeforeDeniedCreate,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ls_blog_post_localizations'
            )->fetchColumn()
        );
        self::assertSame($auditBeforeDeniedCreate, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_audit_log'
        )->fetchColumn());
    }

    public function testIndexAddsOneBoundedAnalyticsReportWhenCapabilityIsReady(): void
    {
        $created = $this->controller->create($this->post(
            '/admin/blog/posts/create',
            ['csrf' => $this->csrfToken, 'post' => '', 'locale' => 'es']
                + $this->editorial('matrix-analytics')
        ));
        self::assertSame(303, $created->status());
        $localizationPublicId = (string) $this->pdo->query(
            'SELECT public_id FROM ls_blog_post_localizations '
                . 'ORDER BY id DESC LIMIT 1'
        )->fetchColumn();
        $report = new class($localizationPublicId) implements
            BlogAnalyticsReportInterface {
            /** @var list<string> */
            public array $requested = [];
            public ?DateTimeImmutable $from = null;
            public ?DateTimeImmutable $to = null;

            public function __construct(private readonly string $publicId)
            {
            }

            public function summariesForLocalizations(
                array $localizationPublicIds,
                DateTimeImmutable $fromInclusive,
                DateTimeImmutable $toExclusive
            ): array {
                $this->requested = $localizationPublicIds;
                $this->from = $fromInclusive;
                $this->to = $toExclusive;

                return [
                    $this->publicId => new BlogArticleAnalyticsSummary(
                        $this->publicId,
                        42,
                        30,
                        8,
                        12_000,
                        20,
                        15
                    ),
                ];
            }
        };
        $clock = new BlogAdminControllerClock(
            new DateTimeImmutable('2030-01-01 10:00:00 UTC')
        );
        $controller = new BlogAdminHttpController(
            new AnalyticsBlogAdminRuntimeAdapter($this->runtime, $report),
            clock: $clock
        );

        $response = $controller->index($this->get(
            '/admin/blog',
            ['period' => '7']
        ));

        self::assertSame(200, $response->status());
        self::assertSame([$localizationPublicId], $report->requested);
        self::assertSame(
            '2029-12-25 10:00:00',
            $report->from?->format('Y-m-d H:i:s')
        );
        self::assertSame(
            '2030-01-01 10:00:00',
            $report->to?->format('Y-m-d H:i:s')
        );
        self::assertStringContainsString('Rebote del Blog', $response->body());
        self::assertStringContainsString('>42</td>', $response->body());
        self::assertStringContainsString(
            '<option value="7" selected>',
            $response->body()
        );
    }

    private function seedActor(
        SecureTokenGenerator $tokens,
        WebAdminConfig $config
    ): void {
        $this->pdo->exec(
            "INSERT INTO ls_webadmin_users "
            . "(public_id, email_canonical, status, auth_version, activated_at) "
            . "VALUES ('10000000-0000-4000-8000-000000000001', "
            . "'editor@example.test', 'active', 1, "
            . "'2030-01-01 09:00:00.000000')"
        );
        $userId = (int) $this->pdo->lastInsertId();
        $credential = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_credentials '
            . '(user_id, password_hash, password_set_at) '
            . 'VALUES (:user, :hash, :set_at)'
        );
        self::assertNotFalse($credential);
        self::assertTrue($credential->execute([
            'user' => $userId,
            'hash' => PasswordHasher::productive()->hash(
                'Correct horse battery staple 1!'
            ),
            'set_at' => '2030-01-01 09:00:00.000000',
        ]));
        foreach ([
            'webadmin.access',
            BlogAdminHttpController::VIEW_CAPABILITY,
            BlogAdminHttpController::EDIT_CAPABILITY,
            BlogAdminHttpController::PUBLISH_CAPABILITY,
            BlogAdminHttpController::DELETE_CAPABILITY,
            BlogAnalyticsCapabilities::VIEW,
            BlogCategoryAdminHttpController::EDIT_CAPABILITY,
            MediaService::VIEW_CAPABILITY,
        ] as $capability) {
            $statement = $this->pdo->prepare(
                'INSERT INTO ls_webadmin_user_capabilities '
                . '(user_id, capability_id) SELECT :user, id FROM '
                . 'ls_webadmin_capabilities WHERE code = :code'
            );
            self::assertNotFalse($statement);
            self::assertTrue($statement->execute([
                'user' => $userId,
                'code' => $capability,
            ]));
            self::assertSame(1, $statement->rowCount(), $capability);
        }

        $session = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_sessions '
            . '(public_id, user_id, session_type, token_hash, '
            . 'csrf_token_hash, auth_version, pending_action_token_id, '
            . 'created_at, last_seen_at, idle_expires_at, '
            . 'absolute_expires_at, revoked_at) VALUES '
            . '(:public_id, :user, :type, :token, :csrf, 1, NULL, '
            . ':created, :seen, :idle, :absolute, NULL)'
        );
        self::assertNotFalse($session);
        self::assertTrue($session->execute([
            'public_id' => '20000000-0000-4000-8000-000000000002',
            'user' => $userId,
            'type' => 'authenticated',
            'token' => $tokens->hashForStorage($this->sessionToken),
            'csrf' => $tokens->hashForStorage($this->csrfToken),
            'created' => '2030-01-01 09:55:00.000000',
            'seen' => '2030-01-01 09:59:00.000000',
            'idle' => '2030-01-01 10:05:00.000000',
            'absolute' => '2030-01-01 11:00:00.000000',
        ]));
        self::assertSame('LS_WEBADMIN_SID', $config->cookieName());
    }

    private function removeCapability(string $capability): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM ls_webadmin_user_capabilities WHERE capability_id = '
            . '(SELECT id FROM ls_webadmin_capabilities WHERE code = :code)'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute(['code' => $capability]));
        self::assertSame(1, $statement->rowCount(), $capability);
    }

    private function addCapability(string $capability): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_user_capabilities '
            . '(user_id, capability_id) SELECT u.id, c.id FROM '
            . 'ls_webadmin_users u CROSS JOIN ls_webadmin_capabilities c '
            . 'WHERE u.public_id = :user AND c.code = :code'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            'user' => '10000000-0000-4000-8000-000000000001',
            'code' => $capability,
        ]));
        self::assertSame(1, $statement->rowCount(), $capability);
    }

    private function seedBlogSummaries(int $count): void
    {
        $post = $this->pdo->prepare(
            'INSERT INTO ls_blog_posts '
            . '(public_id, created_by_user_public_id, created_at, updated_at) '
            . 'VALUES (:public_id, :actor, :created, :updated)'
        );
        $localization = $this->pdo->prepare(
            'INSERT INTO ls_blog_post_localizations '
            . '(public_id, post_id, locale, slug, h1, body_text, '
            . 'created_by_user_public_id, updated_by_user_public_id, '
            . 'created_at, updated_at) VALUES '
            . '(:public_id, :post_id, :locale, :slug, :h1, :body, '
            . ':created_by, :updated_by, :created, :updated)'
        );
        self::assertNotFalse($post);
        self::assertNotFalse($localization);
        $actor = '10000000-0000-4000-8000-000000000001';
        $timestamp = '2030-01-01 09:00:00.000000';
        for ($index = 1; $index <= $count; ++$index) {
            self::assertTrue($post->execute([
                'public_id' => $this->fixtureUuid('4', $index),
                'actor' => $actor,
                'created' => $timestamp,
                'updated' => $timestamp,
            ]));
            $postId = (int) $this->pdo->lastInsertId();
            self::assertTrue($localization->execute([
                'public_id' => $this->fixtureUuid('5', $index),
                'post_id' => $postId,
                'locale' => 'es',
                'slug' => 'matrix-' . $index,
                'h1' => 'Matrix ' . $index,
                'body' => 'Matrix body ' . $index,
                'created_by' => $actor,
                'updated_by' => $actor,
                'created' => $timestamp,
                'updated' => $timestamp,
            ]));
        }
    }

    private function assignCanonicalDummyToSeededSummaries(int $count): void
    {
        $categoryId = (int) $this->pdo->query(
            'SELECT id FROM ls_blog_categories WHERE public_id = '
                . $this->pdo->quote(
                    BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID
                )
        )->fetchColumn();
        self::assertGreaterThan(0, $categoryId);
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_post_categories '
                . '(public_id, post_id, category_id, '
                . 'assigned_by_user_public_id) SELECT :relation, id, '
                . ':category, :actor FROM ls_blog_posts '
                . 'WHERE public_id = :post'
        );
        self::assertNotFalse($statement);
        for ($index = 1; $index <= $count; ++$index) {
            self::assertTrue($statement->execute([
                'relation' => $this->fixtureUuid('8', $index),
                'category' => $categoryId,
                'actor' => '10000000-0000-4000-8000-000000000001',
                'post' => $this->fixtureUuid('4', $index),
            ]));
            self::assertSame(1, $statement->rowCount());
        }
    }

    private function fixtureUuid(string $prefix, int $sequence): string
    {
        return $prefix . '0000000-0000-4000-8000-'
            . str_pad((string) $sequence, 12, '0', STR_PAD_LEFT);
    }

    private function executeMigration(
        App\Core\Modules\Migrations\MigrationDefinition $migration,
        MigrationScope $scope
    ): void {
        foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
            self::assertNotFalse($this->pdo->exec($sql));
        }
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): Request
    {
        return Request::fromInput([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
            'HTTPS' => 'on',
            'REMOTE_ADDR' => '192.0.2.50',
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
            'REMOTE_ADDR' => '192.0.2.50',
        ], form: $form, cookies: [
            'LS_WEBADMIN_SID' => $this->sessionToken,
        ], headers: [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => 'Blog admin test browser',
        ]);
    }

    /** @return array<string, string> */
    private function editorial(string $slug): array
    {
        return [
            'h1' => 'Matrix',
            'slug' => $slug,
            'seo_title' => 'Matrix title',
            'meta_description' => 'Matrix description',
            'excerpt' => 'Matrix excerpt',
            'body_text' => 'Matrix body',
        ];
    }

    private function assertPrg(App\Core\Http\Response $response): void
    {
        self::assertSame(303, $response->status());
        self::assertSame(
            '/admin/blog/posts/updated',
            $response->headers()['Location']
        );
        self::assertSame('', $response->body());
        self::assertStringNotContainsString('Matrix', implode(
            "\n",
            $response->headers()
        ));
    }

    private function assertCreatePrg(
        App\Core\Http\Response $response,
        string $post,
        string $locale
    ): void {
        self::assertSame(303, $response->status());
        self::assertSame(
            '/admin/blog/editor?post=' . rawurlencode($post)
                . '&locale=' . rawurlencode($locale),
            $response->headers()['Location']
        );
        self::assertSame('', $response->body());
        self::assertStringNotContainsString('Matrix', implode(
            "\n",
            $response->headers()
        ));
    }

    private function assertPrivateHeaders(
        App\Core\Http\Response $response
    ): void {
        self::assertSame(
            'no-store, no-cache, must-revalidate, max-age=0',
            $response->headers()['Cache-Control']
        );
        self::assertSame(
            'noindex, nofollow, noarchive',
            $response->headers()['X-Robots-Tag']
        );
        self::assertStringContainsString(
            "form-action 'self'",
            $response->headers()['Content-Security-Policy']
        );
    }
}
