<?php

declare(strict_types=1);

namespace Tests\Commerce;

use App\Core\Commerce\Http\CommercePublicMediaRoute;
use App\Core\Modules\Commerce\CommercePublicRouteProvider;
use App\Core\Modules\ModuleRuntimeContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CommercePublicRouteProviderTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir() . '/ls-commerce-routes-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->root . '/App/config/modules');
        $this->filesystem->dumpFile(
            $this->root . '/App/config/langs.php',
            "<?php return ['es', 'eu'];\n"
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testPublicPrefixesAreMetadataOnlyAndConfigDriven(): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/commerce.php',
            <<<'PHP'
<?php
return [
    'public' => ['enabled' => true],
    'public_paths' => [
        'es' => '/es/comercio',
        'eu' => '/eu/merkataritza',
    ],
    'inquiry_paths' => [
        'es' => '/es/comercio/lista-interes',
        'eu' => '/eu/merkataritza/interes-zerrenda',
    ],
    'sitemap_path' => '/commerce-sitemap.xml',
];
PHP
        );
        $context = new ModuleRuntimeContext($this->root);

        self::assertSame(
            [
                '/es/comercio',
                '/eu/merkataritza',
                '/commerce-sitemap.xml',
                '/_liquidstack/commerce/media',
            ],
            CommercePublicRouteProvider::publicRoutePrefixes($context)
        );
        self::assertSame(
            ['/commerce-sitemap.xml'],
            CommercePublicRouteProvider::preBootstrapPublicRoutePaths(
                $context
            )
        );
        self::assertSame(
            ['/_liquidstack/commerce/media'],
            CommercePublicRouteProvider::preBootstrapPublicRoutePrefixes(
                $context
            )
        );
    }

    public function testPublicMediaRouteIsFixedAndStrict(): void
    {
        $publicId = '99999999-9999-4999-8999-999999999999';
        $path = CommercePublicMediaRoute::path($publicId, 640);

        self::assertSame(
            '/_liquidstack/commerce/media/' . $publicId . '/640.avif',
            $path
        );
        self::assertSame(
            ['public_id' => $publicId, 'width' => 640],
            CommercePublicMediaRoute::match($path)
        );
        self::assertNull(CommercePublicMediaRoute::match(
            '/_liquidstack/commerce/media/' . $publicId . '/0.avif'
        ));
        self::assertNull(CommercePublicMediaRoute::match(
            '/_liquidstack/commerce/media/not-an-id/640.avif'
        ));
    }
}
