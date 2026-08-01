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
        ], BlogPublicRouteProvider::publicRoutePrefixes($context));
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
    }
}
