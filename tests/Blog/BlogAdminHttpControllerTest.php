<?php

declare(strict_types=1);

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Http\BlogAdminHttpController;
use App\Core\Blog\Http\BlogAdminHttpRuntime;
use App\Core\Blog\Http\BlogAdminHttpRuntimeInterface;
use App\Core\Blog\Http\BlogStructuredEditorHttpRuntimeInterface;
use App\Core\Blog\Persistence\PdoBlogRepository;
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
        $service = new BlogService(
            new PdoBlogRepository($this->pdo, $blogScope),
            new RandomUuidV4Generator(),
            $clock
        );
        $this->runtime = new BlogAdminHttpRuntime(
            $this->projectRoot,
            ['es'],
            BlogConfig::defaults(['es']),
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
            )
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
        $this->assertPrg($this->controller->unpublish($this->post(
            '/admin/blog/posts/unpublish',
            $publishForm
        )));
        self::assertSame('draft', $this->pdo->query(
            'SELECT status FROM ls_blog_post_localizations'
        )->fetchColumn());
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
            "<?php\nreturn ['/blog/matrix' => ['view' => 'legacy.php']];\n"
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
        self::assertSame(51, substr_count($first->body(), '<tr>'));
        self::assertStringContainsString(
            'rel="next" href="/admin/blog?offset=50"',
            $first->body()
        );
        self::assertStringNotContainsString('rel="prev"', $first->body());

        $last = $this->controller->index($this->get(
            '/admin/blog',
            ['offset' => '50']
        ));
        self::assertSame(200, $last->status());
        self::assertSame(2, substr_count($last->body(), '<tr>'));
        self::assertStringContainsString(
            'rel="prev" href="/admin/blog"',
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
        self::assertStringNotContainsString(
            '/admin/blog/editor?post='
                . $posts['matrix-published']['public_id']
                . '&amp;locale=es',
            $index->body()
        );
        self::assertSame(2, substr_count(
            $index->body(),
            '/admin/blog/editor/preview?'
        ));
        $publishedPreview = $this->controller->preview($this->get(
            '/admin/blog/posts/preview',
            [
                'post' => $posts['matrix-published']['public_id'],
                'locale' => 'es',
            ]
        ));
        self::assertSame(200, $publishedPreview->status());
        self::assertStringNotContainsString(
            '/admin/blog/editor?',
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
        self::assertSame(2, substr_count(
            $withoutMedia->body(),
            '/admin/blog/posts/preview?'
        ));
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
                'Correct horse battery staple'
            ),
            'set_at' => '2030-01-01 09:00:00.000000',
        ]));
        foreach ([
            'webadmin.access',
            BlogAdminHttpController::VIEW_CAPABILITY,
            BlogAdminHttpController::EDIT_CAPABILITY,
            BlogAdminHttpController::PUBLISH_CAPABILITY,
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
