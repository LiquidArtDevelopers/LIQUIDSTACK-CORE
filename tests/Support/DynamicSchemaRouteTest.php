<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DynamicSchemaRouteTest extends TestCase
{
    public function testUnknownDynamicUrlCannotBorrowFirstRouteAlternates(): void
    {
        $previousRoutes = $GLOBALS['arrayRutasGet'] ?? null;
        $hadRoot = array_key_exists('RAIZ', $_ENV);
        $previousRoot = $_ENV['RAIZ'] ?? null;
        $GLOBALS['arrayRutasGet'] = [
            'es' => [
                '/es/templates' => ['content' => 'templates'],
                '/es/noticias' => ['content' => 'noticias'],
                '/es/noticias/pagina/{page}' => [
                    'content' => 'noticias',
                    'sitemap' => false,
                ],
            ],
            'en' => [
                '/en/templates' => ['content' => 'templates'],
                '/en/news' => ['content' => 'noticias'],
                '/en/news/page/{page}' => [
                    'content' => 'noticias',
                    'sitemap' => false,
                ],
            ],
        ];
        $_ENV['RAIZ'] = 'https://example.test';

        try {
            $markup = schemaWebPageAccessibility(
                'es',
                '/es/noticias/pagina/2',
                'Noticias — Página 2',
                'Segunda página del índice.'
            );
        } finally {
            if ($previousRoutes === null) {
                unset($GLOBALS['arrayRutasGet']);
            } else {
                $GLOBALS['arrayRutasGet'] = $previousRoutes;
            }
            if ($hadRoot) {
                $_ENV['RAIZ'] = $previousRoot;
            } else {
                unset($_ENV['RAIZ']);
            }
        }

        self::assertStringContainsString(
            'https://example.test/es/noticias/pagina/2',
            $markup
        );
        self::assertStringNotContainsString('hasPart', $markup);
        self::assertStringNotContainsString('/es/templates', $markup);
        self::assertStringNotContainsString('/en/templates', $markup);
    }
}
