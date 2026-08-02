<?php

declare(strict_types=1);

use App\Core\Blog\Http\BlogPublicHttpRuntime;
use App\Core\Blog\Http\BlogPublicHttpRuntimeFactoryInterface;
use App\Core\Http\Request;
use App\Core\Modules\Blog\BlogPublicRouteProvider;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Routing\ModulePublicRouteCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CountingBlogPublicRuntimeFactoryFixture implements
    BlogPublicHttpRuntimeFactoryInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly ?BlogPublicHttpRuntime $runtime = null
    ) {
    }

    public function create(
        ModuleRuntimeContext $context
    ): BlogPublicHttpRuntime {
        $this->calls++;

        return $this->runtime
            ?? throw new RuntimeException('Runtime must remain lazy.');
    }
}

final class BlogPublicRouteProviderTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-blog-public-provider-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->root . '/App/config/modules');
        $this->filesystem->dumpFile(
            $this->root . '/App/config/langs.php',
            "<?php\nreturn ['es', 'en'];\n"
        );
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/blog.php',
            <<<'PHP'
<?php
return [
    'public_paths' => [
        'es' => '/noticias',
        'en' => '/en/news',
    ],
    'sitemap_path' => '/news-sitemap.xml',
];
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testMetadataUsesProjectPathsWithoutRuntime(): void
    {
        $context = new ModuleRuntimeContext($this->root);

        self::assertSame([
            '/noticias',
            '/en/news',
            '/news-sitemap.xml',
            '/_liquidstack/blog-media',
        ], BlogPublicRouteProvider::publicRoutePrefixes($context));
        self::assertSame(
            ['/news-sitemap.xml'],
            BlogPublicRouteProvider::preBootstrapPublicRoutePaths($context)
        );
    }

    public function testBaseAndMalformedDescendantsFallThroughWithoutPdo(): void
    {
        $context = new ModuleRuntimeContext($this->root);
        $factory = new CountingBlogPublicRuntimeFactoryFixture();
        $routes = new ModulePublicRouteCollection(
            'blog',
            BlogPublicRouteProvider::publicRoutePrefixes($context)
        );
        (new BlogPublicRouteProvider($factory))->registerPublicRoutes(
            $routes,
            $context
        );

        foreach (['/noticias', '/noticias/a/b', '/noticias/Bad-Slug'] as $path) {
            self::assertNull($routes->dispatch(Request::fromServer([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => $path,
                'HTTPS' => 'on',
            ])));
        }
        self::assertSame(0, $factory->calls);

        foreach ([
            '/_liquidstack/blog-media',
            '/_liquidstack/blog-media/not-a-uuid/480.avif',
            '/_liquidstack/blog-media/11111111-1111-4111-8111-111111111111/0.avif',
            '/_liquidstack/blog-media/11111111-1111-4111-8111-111111111111/480.jpg',
        ] as $path) {
            $response = $routes->dispatch(Request::fromServer([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => $path,
                'HTTPS' => 'on',
            ]));
            self::assertNotNull($response);
            self::assertSame(404, $response->status());
            self::assertSame('no-store', $response->headers()['Cache-Control']);
        }
        self::assertSame(0, $factory->calls);
    }

    public function testInvalidConfigurationPublishesNoPrefixes(): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/blog.php',
            "<?php\nreturn ['public_paths' => ['es' => '/bad only']];\n"
        );

        self::assertSame([], BlogPublicRouteProvider::publicRoutePrefixes(
            new ModuleRuntimeContext($this->root)
        ));
        self::assertSame([], BlogPublicRouteProvider::preBootstrapPublicRoutePaths(
            new ModuleRuntimeContext($this->root)
        ));
    }

    public function testInvalidOrTraversingMediaRequestsNeverOpenRuntime(): void
    {
        $context = new ModuleRuntimeContext($this->root);
        $factory = new CountingBlogPublicRuntimeFactoryFixture();
        $routes = new ModulePublicRouteCollection(
            'blog',
            BlogPublicRouteProvider::publicRoutePrefixes($context)
        );
        (new BlogPublicRouteProvider($factory))->registerPublicRoutes(
            $routes,
            $context
        );

        foreach ([
            '/_liquidstack/blog-media/../secret/480.avif',
            '/_liquidstack/blog-media/%2e%2e/secret/480.avif',
            '/_liquidstack/blog-media/11111111-1111-4111-8111-111111111111%2f480.avif',
        ] as $path) {
            self::assertNull($routes->dispatch(Request::fromServer([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => $path,
                'HTTPS' => 'on',
            ])));
        }

        $get = $routes->dispatch(Request::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/_liquidstack/blog-media/bad/480.avif',
            'HTTPS' => 'on',
        ]));
        $head = $routes->dispatch(Request::fromServer([
            'REQUEST_METHOD' => 'HEAD',
            'REQUEST_URI' => '/_liquidstack/blog-media/bad/480.avif',
            'HTTPS' => 'on',
        ]));
        self::assertNotNull($get);
        self::assertNotNull($head);
        self::assertSame(404, $get->status());
        self::assertSame($get->status(), $head->status());
        self::assertSame($get->headers(), $head->headers());
        self::assertSame('Not found', $get->body());
        self::assertSame('', $head->body());

        $write = $routes->dispatch(Request::fromServer([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/_liquidstack/blog-media/bad/480.avif',
            'HTTPS' => 'on',
        ]));
        self::assertNotNull($write);
        self::assertSame(405, $write->status());
        self::assertSame('GET, HEAD', $write->headers()['Allow']);
        self::assertSame(0, $factory->calls);
    }
}
