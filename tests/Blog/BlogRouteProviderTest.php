<?php

declare(strict_types=1);

use App\Core\Blog\Http\BlogAdminHttpRuntimeFactoryInterface;
use App\Core\Blog\Http\BlogAdminHttpRuntimeInterface;
use App\Core\Blog\Http\BlogAdminRuntimeIssueReporterInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\Blog\BlogRouteProvider;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Routing\ModuleRouteCollection;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class UnavailableBlogAdminRuntimeFactory implements
    BlogAdminHttpRuntimeFactoryInterface
{
    public int $calls = 0;

    public function create(
        ModuleRuntimeContext $context,
        WebAdminConfig $webAdminConfig
    ): BlogAdminHttpRuntimeInterface {
        ++$this->calls;

        throw new RuntimeException('runtime intentionally unavailable');
    }
}

final class CapturingBlogAdminIssueReporter implements
    BlogAdminRuntimeIssueReporterInterface
{
    /** @var list<string> */
    public array $issues = [];

    public function report(string $issueCode): void
    {
        $this->issues[] = $issueCode;
    }
}

final class BlogRouteProviderTest extends TestCase
{
    private string $projectRoot;
    private Filesystem $filesystem;
    private UnavailableBlogAdminRuntimeFactory $factory;
    private CapturingBlogAdminIssueReporter $reporter;
    private ModuleRouteCollection $routes;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-blog-admin-routes-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectRoot . '/App/config');
        $this->factory = new UnavailableBlogAdminRuntimeFactory();
        $this->reporter = new CapturingBlogAdminIssueReporter();
        $this->routes = new ModuleRouteCollection();
        $this->claimWebAdmin($this->routes, '/admin');
        $this->provider()->registerRoutes(
            $this->routes,
            new ModuleRuntimeContext($this->projectRoot)
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
    }

    public function testRegistersExactlyTheDocumentedPrivateSurface(): void
    {
        $gets = [
            '/admin/blog',
            '/admin/blog/posts/new',
            '/admin/blog/posts/edit',
            '/admin/blog/posts/preview',
            '/admin/blog/posts/updated',
        ];
        foreach ($gets as $path) {
            $query = in_array($path, [
                '/admin/blog/posts/edit',
                '/admin/blog/posts/preview',
            ], true)
                ? ['post' => $this->uuid(), 'locale' => 'es']
                : [];
            $response = $this->routes->dispatch($this->get($path, $query));
            self::assertNotNull($response);
            self::assertSame(303, $response->status(), $path);
            self::assertSame('/admin/login', $response->headers()['Location']);
        }

        self::assertSame(404, $this->routes->dispatch(
            $this->get('/admin/blog/')
        )?->status());
        self::assertSame(404, $this->routes->dispatch(
            $this->get('/admin/blog/posts/delete')
        )?->status());

        $previewPost = Request::fromServer([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/blog/posts/preview',
            'HTTPS' => 'on',
        ]);
        $previewResponse = $this->routes->dispatch($previewPost);
        self::assertNotNull($previewResponse);
        self::assertSame(405, $previewResponse->status());
        self::assertSame('GET, HEAD', $previewResponse->headers()['Allow']);
        self::assertSame(0, $this->factory->calls);
    }

    public function testHeadIsBodylessAndDoesNotOpenTheRuntime(): void
    {
        $response = $this->routes->dispatch(Request::fromInput([
            'REQUEST_METHOD' => 'HEAD',
            'REQUEST_URI' => '/admin/blog/posts/edit',
            'HTTPS' => 'on',
        ], query: [
            'post' => $this->uuid(),
            'locale' => 'es',
        ]));

        self::assertNotNull($response);
        self::assertSame(200, $response->status());
        self::assertSame('', $response->body());
        self::assertSame(
            'no-store, no-cache, must-revalidate, max-age=0',
            $response->headers()['Cache-Control']
        );
        self::assertSame(0, $this->factory->calls);
    }

    public function testPreviewHeadIsLazyAndMalformedQueryFailsClosed(): void
    {
        $head = $this->routes->dispatch(Request::fromInput([
            'REQUEST_METHOD' => 'HEAD',
            'REQUEST_URI' => '/admin/blog/posts/preview',
            'HTTPS' => 'on',
        ], query: [
            'post' => $this->uuid(),
            'locale' => 'es',
        ]));
        self::assertNotNull($head);
        self::assertSame(200, $head->status());
        self::assertSame('', $head->body());

        $malformed = $this->routes->dispatch($this->get(
            '/admin/blog/posts/preview',
            [
                'post' => $this->uuid(),
                'locale' => 'es',
                'slug' => 'must-not-be-accepted',
            ],
            str_repeat('A', 43)
        ));
        self::assertNotNull($malformed);
        self::assertSame(400, $malformed->status());
        self::assertSame(0, $this->factory->calls);
    }

    public function testPaginatedHeadStaysLazyAndPrgTargetRejectsQuery(): void
    {
        $head = $this->routes->dispatch(Request::fromInput([
            'REQUEST_METHOD' => 'HEAD',
            'REQUEST_URI' => '/admin/blog',
            'HTTPS' => 'on',
        ], query: ['offset' => '50']));
        self::assertNotNull($head);
        self::assertSame(200, $head->status());
        self::assertSame('', $head->body());

        $sid = str_repeat('A', 43);
        $overlap = $this->routes->dispatch($this->get(
            '/admin/blog',
            ['offset' => '25'],
            $sid
        ));
        self::assertNotNull($overlap);
        self::assertSame(400, $overlap->status());

        $updated = $this->routes->dispatch($this->get(
            '/admin/blog/posts/updated',
            ['offset' => '50'],
            $sid
        ));
        self::assertNotNull($updated);
        self::assertSame(400, $updated->status());
        self::assertSame(0, $this->factory->calls);
    }

    public function testMalformedInsecureAndWrongMethodRequestsFailPreflight(): void
    {
        $insecure = Request::fromInput([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/blog',
        ], cookies: ['LS_WEBADMIN_SID' => str_repeat('A', 43)]);
        self::assertSame(400, $this->routes->dispatch($insecure)?->status());

        $malformed = $this->get('/admin/blog/posts/edit', [
            'post' => $this->uuid(),
            'locale' => 'es',
            'unexpected' => 'secret',
        ], str_repeat('A', 43));
        self::assertSame(400, $this->routes->dispatch($malformed)?->status());

        $wrongMethod = Request::fromServer([
            'REQUEST_METHOD' => 'DELETE',
            'REQUEST_URI' => '/admin/blog/posts/save',
            'HTTPS' => 'on',
        ]);
        $response = $this->routes->dispatch($wrongMethod);
        self::assertNotNull($response);
        self::assertSame(405, $response->status());
        self::assertSame('POST', $response->headers()['Allow']);
        self::assertSame(0, $this->factory->calls);
    }

    public function testExactLoopbackHttpRedirectsAnonymousUserInDevelopment(): void
    {
        $routes = new ModuleRouteCollection();
        $this->claimWebAdmin($routes, '/admin');
        $this->provider()->registerRoutes(
            $routes,
            new ModuleRuntimeContext($this->projectRoot, [
                'RAIZ' => 'http://localhost:1309',
                'DEV_MODE' => '1',
            ])
        );

        $response = $routes->dispatch(Request::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/blog',
            'HTTP_HOST' => 'localhost:1309',
            'REMOTE_ADDR' => '::1',
        ]));

        self::assertNotNull($response);
        self::assertSame(303, $response->status());
        self::assertSame('/admin/login', $response->headers()['Location']);
        self::assertSame(0, $this->factory->calls);
    }

    public function testDevelopmentHttpWithMismatchedHostFailsPreflight(): void
    {
        $routes = new ModuleRouteCollection();
        $this->claimWebAdmin($routes, '/admin');
        $this->provider()->registerRoutes(
            $routes,
            new ModuleRuntimeContext($this->projectRoot, [
                'RAIZ' => 'http://localhost:1309',
                'DEV_MODE' => '1',
            ])
        );

        $response = $routes->dispatch(Request::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/blog',
            'HTTP_HOST' => 'localhost:1310',
            'REMOTE_ADDR' => '127.0.0.1',
        ]));

        self::assertNotNull($response);
        self::assertSame(400, $response->status());
        self::assertSame(0, $this->factory->calls);
    }

    public function testValidAuthenticatedShapeReachesRuntimeOnlyOnce(): void
    {
        $sid = str_repeat('A', 43);
        foreach (range(1, 2) as $_) {
            $response = $this->routes->dispatch($this->get(
                '/admin/blog',
                [],
                $sid
            ));
            self::assertNotNull($response);
            self::assertSame(503, $response->status());
            self::assertSame('Service unavailable', $response->body());
        }

        self::assertSame(2, $this->factory->calls);
        self::assertSame([
            'blog.admin_runtime_unavailable',
            'blog.admin_runtime_unavailable',
        ], $this->reporter->issues);
    }

    public function testCustomEffectiveWebAdminPrefixOwnsTheBlogChild(): void
    {
        $this->filesystem->mkdir($this->projectRoot . '/App/config/modules');
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/modules/webadmin.php',
            "<?php\n\nreturn ['path' => '/gestion'];\n"
        );
        $routes = new ModuleRouteCollection();
        $this->claimWebAdmin($routes, '/gestion');
        $this->provider()->registerRoutes(
            $routes,
            new ModuleRuntimeContext($this->projectRoot)
        );

        $response = $routes->dispatch($this->get('/gestion/blog'));
        self::assertNotNull($response);
        self::assertSame(303, $response->status());
        self::assertSame('/gestion/login', $response->headers()['Location']);
        self::assertNull($routes->dispatch($this->get('/admin/blog')));
    }

    public function testBlogCannotClaimWithoutTheEffectiveWebAdminOwner(): void
    {
        $routes = new ModuleRouteCollection();
        $this->provider()->registerRoutes(
            $routes,
            new ModuleRuntimeContext($this->projectRoot)
        );

        self::assertNull($routes->dispatch($this->get('/admin/blog')));
        self::assertSame(0, $this->factory->calls);
    }

    public function testBlogFollowsWebAdminFallbackAfterAStaticCollision(): void
    {
        $this->filesystem->mkdir([
            $this->projectRoot . '/App/config/modules',
            $this->projectRoot . '/App/config/routes',
        ]);
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/modules/webadmin.php',
            "<?php\n\nreturn ['path' => '/gestion'];\n"
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/routes/get.php',
            "<?php\nreturn ['/gestion' => ['view' => 'legacy.php']];\n"
        );
        $routes = new ModuleRouteCollection();
        $this->claimWebAdmin($routes, '/admin');
        $this->provider()->registerRoutes(
            $routes,
            new ModuleRuntimeContext($this->projectRoot)
        );

        $response = $routes->dispatch($this->get('/admin/blog'));
        self::assertNotNull($response);
        self::assertSame(303, $response->status());
        self::assertSame('/admin/login', $response->headers()['Location']);
        self::assertNull($routes->dispatch($this->get('/gestion/blog')));
    }

    public function testInvalidWebAdminConfigFailsClosedWithoutLeakingValue(): void
    {
        $this->filesystem->mkdir($this->projectRoot . '/App/config/modules');
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/modules/webadmin.php',
            "<?php\nreturn ['path' => '/admin?private-secret'];\n"
        );
        $routes = new ModuleRouteCollection();
        $this->claimWebAdmin($routes, '/admin');
        $this->provider()->registerRoutes(
            $routes,
            new ModuleRuntimeContext($this->projectRoot)
        );

        $response = $routes->dispatch($this->get(
            '/admin/blog',
            [],
            str_repeat('A', 43)
        ));
        self::assertNotNull($response);
        self::assertSame(503, $response->status());
        self::assertSame(0, $this->factory->calls);
        self::assertSame(
            ['blog.webadmin_configuration_invalid'],
            $this->reporter->issues
        );
        $again = $routes->dispatch($this->get(
            '/admin/blog',
            [],
            str_repeat('A', 43)
        ));
        self::assertNotNull($again);
        self::assertSame(503, $again->status());
        self::assertSame(0, $this->factory->calls);
        self::assertSame(
            ['blog.webadmin_configuration_invalid'],
            $this->reporter->issues
        );
        self::assertStringNotContainsString(
            'private-secret',
            json_encode($this->reporter->issues, JSON_THROW_ON_ERROR)
        );
    }

    /** @param array<string, string> $query */
    private function get(
        string $path,
        array $query = [],
        ?string $sid = null
    ): Request {
        return Request::fromInput([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
            'HTTPS' => 'on',
        ], query: $query, cookies: $sid === null
            ? []
            : ['LS_WEBADMIN_SID' => $sid]);
    }

    private function provider(): BlogRouteProvider
    {
        return new BlogRouteProvider(
            runtimeFactory: $this->factory,
            issueReporter: $this->reporter
        );
    }

    private function claimWebAdmin(
        ModuleRouteCollection $routes,
        string $prefix
    ): void {
        $routes->claimPrefix(
            'webadmin',
            $prefix,
            static fn (Request $request): Response =>
                new Response(404, 'webadmin-not-found'),
            static fn (Request $request, array $allowed): Response =>
                new Response(405, '', ['Allow' => implode(', ', $allowed)])
        );
    }

    private function uuid(): string
    {
        return '11111111-1111-4111-8111-111111111111';
    }
}
