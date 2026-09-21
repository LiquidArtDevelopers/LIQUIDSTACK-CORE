<?php

declare(strict_types=1);

namespace Tests\Commerce;

use App\Core\Commerce\BasketLine;
use App\Core\Commerce\BasketSnapshot;
use App\Core\Commerce\CommerceBasketService;
use App\Core\Commerce\Configuration\CommerceConfig;
use App\Core\Commerce\CommercePublicProduct;
use App\Core\Commerce\Http\CommercePublicHttpController;
use App\Core\Commerce\Http\CommercePublicHttpRuntime;
use App\Core\Commerce\Http\CommercePublicItemRenderer;
use App\Core\Commerce\Http\CommerceBasketCookie;
use App\Core\Commerce\LocalizedProduct;
use App\Core\Commerce\Money;
use App\Core\Commerce\Persistence\CommerceCatalogRepositoryInterface;
use App\Core\Commerce\Persistence\CommerceBasketRepositoryInterface;
use App\Core\Commerce\ProductAvailabilityStatus;
use App\Core\Commerce\ProductEditorialStatus;
use App\Core\Commerce\ProductPathResolution;
use App\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class CommercePublicHttpControllerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ls-commerce-view-'
            . bin2hex(random_bytes(8));
        mkdir($this->root . '/App/views', 0777, true);
        file_put_contents(
            $this->root . '/App/views/commerce-item.php',
            '<?php echo htmlspecialchars($commerceItemPage->product()->title(), ENT_QUOTES, "UTF-8");'
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/App/views/commerce-item.php');
        @rmdir($this->root . '/App/views');
        @rmdir($this->root . '/App');
        @rmdir($this->root);
    }

    public function testItRendersAResolvedProjectOwnedItemView(): void
    {
        $product = $this->product('/es/comercio/mesas/mesa-modular');
        $catalog = $this->createMock(
            CommerceCatalogRepositoryInterface::class
        );
        $catalog->expects(self::once())
            ->method('resolvePublicPath')
            ->willReturn(ProductPathResolution::found(
                $product,
                (string) $product->publicPath()
            ));
        $catalog->expects(self::once())
            ->method('publicProduct')
            ->with($product->publicId(), 'es', 'es')
            ->willReturn($this->projection($product));
        $catalog->expects(self::once())
            ->method('localizedProduct')
            ->willReturn($product);
        $controller = $this->controller($catalog);

        $response = $controller->item(
            'es',
            '/es/comercio/mesas/mesa-modular'
        );

        self::assertNotNull($response);
        self::assertSame(200, $response->status());
        self::assertSame('Mesa modular', $response->body());
        self::assertSame(
            'index, follow',
            $response->headers()['X-Robots-Tag'] ?? null
        );
    }

    public function testItPreservesHistoricPathAsPermanentRedirect(): void
    {
        $product = $this->product('/es/comercio/mesas/mesa-modular');
        $catalog = $this->createMock(
            CommerceCatalogRepositoryInterface::class
        );
        $catalog->method('resolvePublicPath')->willReturn(
            ProductPathResolution::redirect(
                $product,
                (string) $product->publicPath()
            )
        );

        $response = $this->controller($catalog)->item(
            'es',
            '/es/comercio/mesas/mesa-antigua'
        );

        self::assertNotNull($response);
        self::assertSame(301, $response->status());
        self::assertSame(
            '/es/comercio/mesas/mesa-modular',
            $response->headers()['Location'] ?? null
        );
    }

    public function testItProjectsTheOpaqueServerBasketWithoutPublicCaching(): void
    {
        $product = $this->product('/es/comercio/mesas/mesa-modular');
        $catalog = $this->createMock(
            CommerceCatalogRepositoryInterface::class
        );
        $catalog->method('resolvePublicPath')->willReturn(
            ProductPathResolution::found(
                $product,
                (string) $product->publicPath()
            )
        );
        $catalog->method('publicProduct')->willReturn(
            $this->projection($product)
        );
        $catalog->method('localizedProduct')->willReturn($product);
        $token = str_repeat('A', 43);
        $basketRepository = $this->createMock(
            CommerceBasketRepositoryInterface::class
        );
        $basketRepository->expects(self::once())
            ->method('snapshot')
            ->with($token, 'es', self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(new BasketSnapshot(
                '10000000-0000-4000-8000-000000000001',
                $token,
                'es',
                'open',
                new \DateTimeImmutable('+1 hour'),
                [new BasketLine($product, 1)]
            ));
        file_put_contents(
            $this->root . '/App/views/commerce-item.php',
            '<?php echo count($commerceItemPage->basketProductIds());'
        );

        $response = $this->controller(
            $catalog,
            new CommerceBasketService($basketRepository, 'es'),
            new CommerceBasketCookie('test_basket', false, 3600)
        )->item('es', '/es/comercio/mesas/mesa-modular', $token);

        self::assertNotNull($response);
        self::assertSame('1', $response->body());
        self::assertSame(
            'private, no-cache, must-revalidate',
            $response->headers()['Cache-Control'] ?? null
        );
    }

    public function testSitemapExcludesFallbackVariantsAndSupportsEtag(): void
    {
        $translated = $this->product('/es/comercio/mesas/mesa-modular');
        $fallback = new LocalizedProduct(
            '6de1c2cc-c4e0-4df2-a950-4fb9cd756ca7',
            null,
            'es',
            'eu',
            true,
            'Fallback',
            'fallback',
            null,
            null,
            null,
            null,
            ProductEditorialStatus::ACTIVE,
            ProductAvailabilityStatus::AVAILABLE,
            null,
            '/es/comercio/fallback',
            1
        );
        $catalog = $this->createMock(
            CommerceCatalogRepositoryInterface::class
        );
        $catalog->expects(self::once())
            ->method('listPublished')
            ->with('es', 'es', 100, 0)
            ->willReturn([$translated, $fallback]);
        $controller = $this->controller($catalog);

        $first = $controller->sitemap(Request::fromInput([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/commerce-sitemap.xml',
        ]));

        self::assertSame(200, $first->status());
        self::assertStringContainsString(
            'https://example.test/es/comercio/mesas/mesa-modular',
            $first->body()
        );
        self::assertStringContainsString(
            '<loc>https://example.test/es/comercio</loc>',
            $first->body()
        );
        self::assertStringNotContainsString('/fallback', $first->body());
        self::assertNotSame('', $first->headers()['ETag'] ?? '');
    }

    private function controller(
        CommerceCatalogRepositoryInterface $catalog,
        ?CommerceBasketService $baskets = null,
        ?CommerceBasketCookie $basketCookie = null
    ): CommercePublicHttpController {
        $config = new CommerceConfig(
            true,
            ['es' => '/es/comercio'],
            ['es' => '/es/comercio/lista-interes'],
            '/commerce-sitemap.xml',
            CommerceConfig::TRANSACTION_MODE_INQUIRY,
            'shared',
            'ls_commerce_',
            20,
            2_592_000,
            false,
            5,
            'test',
            'es'
        );

        return new CommercePublicHttpController(
            new CommercePublicHttpRuntime(
                $config,
                $catalog,
                'https://example.test',
                null,
                $baskets,
                $basketCookie
            ),
            new CommercePublicItemRenderer($this->root)
        );
    }

    private function product(string $path): LocalizedProduct
    {
        return new LocalizedProduct(
            '6de1c2cc-c4e0-4df2-a950-4fb9cd756ca7',
            'MESA-01',
            'es',
            'es',
            false,
            'Mesa modular',
            'mesa-modular',
            'Una mesa adaptable.',
            'Descripci&oacute;n',
            'Mesa modular',
            'Mesa modular adaptable.',
            ProductEditorialStatus::ACTIVE,
            ProductAvailabilityStatus::AVAILABLE,
            new Money(125_000, 'EUR'),
            $path,
            1
        );
    }

    private function projection(
        LocalizedProduct $product
    ): CommercePublicProduct {
        return new CommercePublicProduct($product, [], [], [], []);
    }
}
