<?php

declare(strict_types=1);

use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Configuration\BlogSitemapCacheConfig;
use App\Core\Blog\Http\BlogPublicHttpRuntimeException;
use App\Core\Blog\Sitemap\Cache\BlogSitemapCacheIdentity;
use App\Core\Blog\Sitemap\Cache\BlogSitemapCacheSnapshot;
use App\Core\Blog\Sitemap\Cache\PrivateBlogSitemapCacheStorage;
use App\Core\Blog\Sitemap\Delivery\BlogSitemapDeliveryService;
use App\Core\Blog\Sitemap\Delivery\BlogSitemapHttpController;
use App\Core\Blog\Sitemap\Delivery\BlogSitemapSourceUnavailable;
use App\Core\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogSitemapDeliveryServiceTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;
    private PrivateBlogSitemapCacheStorage $storage;
    private BlogSitemapCacheIdentity $identity;
    private string $xml;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir() . '/ls-blog-delivery-test-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->root);
        $environment = [
            'RAIZ' => 'http://localhost:1309',
            'DEV_MODE' => '1',
        ];
        $this->storage = PrivateBlogSitemapCacheStorage::forProject(
            $this->root,
            $environment
        );
        $generation = $this->storage->initialize()->generation();
        $config = new BlogConfig(
            ['es' => '/blog'],
            '/blog-sitemap.xml',
            'ls_blog_',
            'test',
            'shared',
            'es',
            null,
            new BlogSitemapCacheConfig(true, 300)
        );
        $this->identity = BlogSitemapCacheIdentity::fromContract(
            $config,
            BlogPublicOrigin::fromEnvironment($environment)
        );
        $this->xml = "<?xml version=\"1.0\"?><urlset></urlset>\n";
        $snapshot = BlogSitemapCacheSnapshot::fresh(
            $this->xml,
            1,
            $generation,
            $this->identity,
            time(),
            300
        );
        $lease = $this->storage->acquireExclusive();
        $this->storage->promote($lease, $snapshot);
        $lease->release();
    }

    protected function tearDown(): void
    {
        if (isset($this->filesystem, $this->root)) {
            $this->filesystem->remove($this->root);
        }
    }

    public function testTransientSourceOutageUsesObservableValidLkgAndEtag(): void
    {
        $service = new BlogSitemapDeliveryService(
            static function (): never {
                throw new BlogSitemapSourceUnavailable();
            },
            $this->storage,
            $this->identity
        );
        $controller = new BlogSitemapHttpController($service);
        $response = $controller->handle(Request::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/blog-sitemap.xml',
        ]));
        self::assertSame(200, $response->status());
        self::assertSame($this->xml, $response->body());
        self::assertSame(
            'stale-cache',
            $response->headers()['X-LiquidStack-Sitemap-Source'] ?? null
        );
        self::assertArrayHasKey('Warning', $response->headers());

        $notModified = $controller->handle(Request::fromServer([
            'REQUEST_METHOD' => 'HEAD',
            'REQUEST_URI' => '/blog-sitemap.xml',
            'HTTP_IF_NONE_MATCH' => $response->headers()['ETag'],
        ]));
        self::assertSame(304, $notModified->status());
        self::assertSame('', $notModified->body());
        self::assertArrayNotHasKey('Content-Length', $notModified->headers());
    }

    public function testUnclassifiedFailureNeverFallsBackToOldContent(): void
    {
        $service = new BlogSitemapDeliveryService(
            static function (): never {
                throw new RuntimeException('schema drift');
            },
            $this->storage,
            $this->identity
        );
        $this->expectException(BlogPublicHttpRuntimeException::class);
        $service->document();
    }
}
