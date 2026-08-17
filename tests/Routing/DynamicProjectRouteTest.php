<?php

declare(strict_types=1);

use App\Core\Routing\DynamicProjectRoute;
use PHPUnit\Framework\TestCase;

final class DynamicProjectRouteTest extends TestCase
{
    public function testItMatchesOnlyWholeDynamicPathSegments(): void
    {
        self::assertSame([
            'route' => '/es/noticias/pagina/{page}',
            'params' => ['page' => '24'],
        ], DynamicProjectRoute::match('/es/noticias/pagina/24', [
            '/es/noticias',
            '/es/noticias/pagina/{page}',
        ]));

        self::assertNull(DynamicProjectRoute::match(
            '/es/noticias/pagina/24/extra',
            ['/es/noticias/pagina/{page}']
        ));
        self::assertNull(DynamicProjectRoute::match(
            '/es/noticias/pagina/',
            ['/es/noticias/pagina/{page}']
        ));
        self::assertNull(DynamicProjectRoute::match(
            '/es/noticias/pagina/24?order=newest',
            ['/es/noticias/pagina/{page}']
        ));
    }

    public function testItPreservesValuesForTheOwningViewToValidate(): void
    {
        self::assertSame([
            'route' => '/en/news/page/{page}',
            'params' => ['page' => 'second'],
        ], DynamicProjectRoute::match('/en/news/page/second', [
            '/en/news/page/{page}',
        ]));
    }

    public function testItRejectsAmbiguousOrMalformedPatterns(): void
    {
        self::assertNull(DynamicProjectRoute::match(
            '/es/noticias/pagina/2',
            ['/es/noticias/pagina/{page}/{page}']
        ));
        self::assertNull(DynamicProjectRoute::match(
            "/es/noticias/pagina/\0",
            ['/es/noticias/pagina/{page}']
        ));

        $this->expectException(RuntimeException::class);
        DynamicProjectRoute::match('/es/noticias/pagina/2', [
            '/es/noticias/pagina/{page}',
            '/es/noticias/{section}/{number}',
        ]);
    }
}
