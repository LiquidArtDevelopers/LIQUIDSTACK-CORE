<?php

declare(strict_types=1);

use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Routing\BlogRoutePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogRoutePolicyTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-blog-route-policy-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir([
            $this->root . '/App/config/routes',
            $this->root . '/public',
        ]);
        $this->writeRoutes('get.php', []);
        $this->writeRoutes('post.php', []);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testValidBlogRoutesAreReady(): void
    {
        $resolution = $this->resolve();

        self::assertTrue($resolution->isReady());
        self::assertSame([], $resolution->issues());
        self::assertSame([], $resolution->collisions());
    }

    public function testExactStaticBlogIndexIsAllowedBecauseLegacyWins(): void
    {
        $this->writeRoutes('get.php', ['/noticias']);

        self::assertTrue($this->resolve()->isReady());
    }

    public function testSitemapStaticRouteAndPublicFileAreBlocked(): void
    {
        $this->writeRoutes('get.php', ['/blog-sitemap.xml']);
        $this->filesystem->dumpFile(
            $this->root . '/public/blog-sitemap.xml',
            '<urlset/>'
        );

        $resolution = $this->resolve();

        self::assertFalse($resolution->isReady());
        self::assertSame(
            ['/blog-sitemap.xml'],
            array_column($resolution->collisions(), 'route')
        );
        self::assertContains([
            'code' => 'public_file.route_collision',
            'key' => 'sitemap_path',
        ], $resolution->issues());
    }

    public function testDynamicStaticRouteCatalogFailsClosed(): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/App/config/routes/get.php',
            <<<'PHP'
<?php
$route = '/somewhere';
return [$route => ['view' => 'dynamic.php']];
PHP
        );

        $resolution = $this->resolve();

        self::assertFalse($resolution->isReady());
        self::assertContains([
            'code' => 'route_file.dynamic_key',
            'key' => 'App/config/routes/get.php',
        ], $resolution->issues());
    }

    public function testMissingWebAdminClaimAndOverlapsBlockOnlyBlog(): void
    {
        $missing = $this->resolve(null);
        self::assertFalse($missing->isReady());
        self::assertContains([
            'code' => 'webadmin.route_unavailable',
            'key' => 'dependency.webadmin',
        ], $missing->issues());

        $overlapping = $this->resolve('/noticias/admin');
        self::assertFalse($overlapping->isReady());
        self::assertContains([
            'code' => 'config.webadmin_route_collision',
            'key' => 'public_paths.es',
        ], $overlapping->issues());
    }

    private function resolve(
        ?string $webAdminPrefix = '/admin'
    ): \App\Core\Blog\Routing\BlogRouteResolution {
        return (new BlogRoutePolicy())->resolve(
            $this->root,
            new BlogConfig(
                ['es' => '/noticias', 'en' => '/en/news'],
                '/blog-sitemap.xml',
                'ls_blog_',
                'fixture'
            ),
            $webAdminPrefix
        );
    }

    /** @param list<string> $routes */
    private function writeRoutes(string $file, array $routes): void
    {
        $values = [];
        foreach ($routes as $route) {
            $values[$route] = ['view' => 'fixture.php'];
        }
        $this->filesystem->dumpFile(
            $this->root . '/App/config/routes/' . $file,
            "<?php\n\nreturn " . var_export($values, true) . ";\n"
        );
    }
}
