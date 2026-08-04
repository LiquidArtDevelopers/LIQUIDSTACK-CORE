<?php

declare(strict_types=1);

namespace Tests\Blog\Analytics;

use App\Core\Blog\Analytics\BlogAnalyticsHttpRuntime;
use App\Core\Blog\Analytics\BlogAnalyticsHttpRuntimeFactoryInterface;
use App\Core\Blog\Analytics\BlogAnalyticsPageGrantCodec;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Http\Request;
use App\Core\Modules\Blog\BlogAnalyticsRouteProvider;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Routing\ModuleRouteCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use App\Core\WebAdmin\Security\SecurityKey;

final class UnusedBlogAnalyticsRuntimeFactory implements
    BlogAnalyticsHttpRuntimeFactoryInterface
{
    public int $calls = 0;

    public function create(
        ModuleRuntimeContext $context
    ): BlogAnalyticsHttpRuntime {
        ++$this->calls;
        throw new \RuntimeException('Runtime must stay lazy.');
    }
}

final class BlogAnalyticsRouteProviderTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;
    private UnusedBlogAnalyticsRuntimeFactory $factory;
    private ModuleRouteCollection $routes;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir() . '/liquidstack-blog-analytics-route-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->root . '/App/config/modules');
        $this->filesystem->dumpFile(
            $this->root . '/App/config/langs.php',
            "<?php\nreturn ['es'];\n"
        );
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/blog.php',
            <<<'PHP'
<?php
return [
    'analytics' => [
        'enabled' => true,
        'collect_in_dev' => true,
    ],
];
PHP
        );
        $this->factory = new UnusedBlogAnalyticsRuntimeFactory();
        $this->routes = new ModuleRouteCollection();
        (new BlogAnalyticsRouteProvider($this->factory))->registerRoutes(
            $this->routes,
            new ModuleRuntimeContext($this->root, [
                'RAIZ' => 'http://localhost:1309',
                'DEV_MODE' => '1',
                'LIQUIDSTACK_WEBADMIN_SECURITY_KEY' => rtrim(strtr(
                    base64_encode(str_repeat('k', 32)),
                    '+/',
                    '-_'
                ), '='),
            ])
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testRevokeIsExactPreRuntimeAndClearsOnlyAnalyticsCookies(): void
    {
        $response = $this->routes->dispatch($this->request(
            'POST',
            BlogAnalyticsRouteProvider::REVOKE_PATH
        ));

        self::assertNotNull($response);
        self::assertSame(204, $response->status());
        self::assertSame(0, $this->factory->calls);
        self::assertSame([
            'LS_BLOG_AV=; Path=/; Max-Age=0; SameSite=Lax',
            'LS_BLOG_AS=; Path=/; Max-Age=0; SameSite=Lax',
        ], $response->headerValues('Set-Cookie'));
        self::assertStringNotContainsString(
            'PHPSESSID',
            implode("\n", $response->headerValues('Set-Cookie'))
        );
    }

    public function testWrongMethodIsRejectedWithoutBuildingRuntime(): void
    {
        $response = $this->routes->dispatch($this->request(
            'GET',
            BlogAnalyticsRouteProvider::START_PATH
        ));

        self::assertNotNull($response);
        self::assertSame(405, $response->status());
        self::assertSame('POST', $response->headers()['Allow']);
        self::assertSame(0, $this->factory->calls);
    }

    public function testInvalidCollectionRequestNeverBuildsRuntime(): void
    {
        $response = $this->routes->dispatch(Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => BlogAnalyticsRouteProvider::START_PATH,
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_HOST' => 'localhost:1309',
            ],
            [],
            [],
            [],
            [
                'Origin' => 'https://cross-origin.example',
                'Content-Type' => 'application/json',
            ],
            '{"path":"/noticias/matrix","view_id":"invalid"}'
        ));

        self::assertNotNull($response);
        self::assertSame(400, $response->status());
        self::assertSame(0, $this->factory->calls);
    }

    public function testInvalidSameOriginPayloadNeverBuildsRuntime(): void
    {
        $response = $this->routes->dispatch(Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => BlogAnalyticsRouteProvider::START_PATH,
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_HOST' => 'localhost:1309',
            ],
            [],
            [],
            [],
            [
                'Origin' => 'http://localhost:1309',
                'Content-Type' => 'application/json',
                'Sec-Fetch-Site' => 'same-origin',
            ],
            '{"path":"/noticias/matrix","view_id":"invalid"}'
        ));

        self::assertNotNull($response);
        self::assertSame(400, $response->status());
        self::assertSame(0, $this->factory->calls);
    }

    public function testForgedPageGrantNeverBuildsRuntime(): void
    {
        $forged = 'eyJ2IjoxfQ.' . str_repeat('a', 43);
        $response = $this->routes->dispatch(Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => BlogAnalyticsRouteProvider::START_PATH,
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_HOST' => 'localhost:1309',
            ],
            [],
            [],
            [],
            [
                'Origin' => 'http://localhost:1309',
                'Content-Type' => 'application/json',
                'Sec-Fetch-Site' => 'same-origin',
            ],
            (string) json_encode(['page_grant' => $forged])
        ));

        self::assertNotNull($response);
        self::assertSame(400, $response->status());
        self::assertSame(0, $this->factory->calls);
    }

    public function testValidSignedGrantIsTheOnlyPayloadThatReachesRuntime(): void
    {
        $origin = BlogPublicOrigin::fromEnvironment([
            'RAIZ' => 'http://localhost:1309',
            'DEV_MODE' => '1',
        ]);
        $grant = (new BlogAnalyticsPageGrantCodec(
            SecurityKey::fromRawBytes(str_repeat('k', 32)),
            $origin
        ))->issue(
            '33333333-3333-4333-8333-333333333333',
            '/noticias/matrix'
        );
        $response = $this->routes->dispatch(Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => BlogAnalyticsRouteProvider::START_PATH,
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_HOST' => 'localhost:1309',
            ],
            [],
            [],
            [],
            [
                'Origin' => 'http://localhost:1309',
                'Content-Type' => 'application/json',
                'Sec-Fetch-Site' => 'same-origin',
            ],
            (string) json_encode(['page_grant' => $grant])
        ));

        self::assertNotNull($response);
        self::assertSame(503, $response->status());
        self::assertSame(1, $this->factory->calls);
    }

    private function request(string $method, string $path): Request
    {
        return Request::fromInput(
            [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => $path,
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_HOST' => 'localhost:1309',
            ],
            [],
            [],
            [],
            ['Origin' => 'http://localhost:1309']
        );
    }
}
