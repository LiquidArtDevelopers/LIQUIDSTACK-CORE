<?php

declare(strict_types=1);

use App\Core\Blog\BlogException;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Routing\BlogPublicationRouteGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogPublicationRouteGuardTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;
    private BlogConfig $config;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-blog-publication-route-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir([
            $this->root . '/App/config/routes',
            $this->root . '/public/noticias',
        ]);
        $this->writeRoutes('get.php', []);
        $this->writeRoutes('post.php', []);
        $this->config = new BlogConfig(
            ['es' => '/noticias'],
            '/blog-sitemap.xml',
            'ls_blog_',
            'fixture'
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testAvailableDynamicUrlPasses(): void
    {
        (new BlogPublicationRouteGuard())->assertAvailable(
            $this->root,
            $this->config,
            'es',
            'matrix'
        );
        self::addToAssertionCount(1);
    }

    public function testExactStaticGetOrPostAndPublicFileConflict(): void
    {
        foreach (['get.php', 'post.php'] as $file) {
            $this->writeRoutes($file, ['/noticias/matrix']);
            try {
                $this->guard();
                self::fail('Static routes must block publication.');
            } catch (BlogException $exception) {
                self::assertSame(
                    BlogException::SLUG_CONFLICT,
                    $exception->issueCode()
                );
            }
            $this->writeRoutes($file, []);
        }

        $this->filesystem->dumpFile(
            $this->root . '/public/noticias/matrix',
            'project-owned'
        );
        try {
            $this->guard();
            self::fail('A public file must block publication.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::SLUG_CONFLICT,
                $exception->issueCode()
            );
        }
    }

    public function testStaticIndexDoesNotBlockDescendantButDynamicCatalogDoes(): void
    {
        $this->writeRoutes('get.php', ['/noticias']);
        $this->guard();
        self::addToAssertionCount(1);

        $this->filesystem->dumpFile(
            $this->root . '/App/config/routes/get.php',
            <<<'PHP'
<?php
$path = '/noticias/matrix';
return [$path => ['view' => 'fixture.php']];
PHP
        );
        try {
            $this->guard();
            self::fail('An incomplete route catalog must fail closed.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::STORAGE_UNAVAILABLE,
                $exception->issueCode()
            );
        }
    }

    private function guard(): void
    {
        (new BlogPublicationRouteGuard())->assertAvailable(
            $this->root,
            $this->config,
            'es',
            'matrix'
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
            "<?php\nreturn " . var_export($values, true) . ";\n"
        );
    }
}
