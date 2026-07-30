<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TranslationHrefContractTest extends TestCase
{
    public function testTranslationRuntimePreservesNonLocalizedHrefForms(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/resources/js/_traducciones.js'
        );

        self::assertStringContainsString(
            'resolveLocalizedHref(pathOrigin, idioma, rawHref)',
            $source
        );

        foreach (['"/"', '"#"', '"?"', '"//"'] as $prefix) {
            self::assertStringContainsString(
                "href.startsWith({$prefix})",
                $source
            );
        }

        self::assertStringContainsString(
            '/^[a-z][a-z\\d+.-]*:/i.test(href)',
            $source
        );
        self::assertStringContainsString(
            'return `${pathOrigin}/${lang}/${href}`;',
            $source
        );
        self::assertSame(
            4,
            substr_count($source, 'this.resolveLocalizedHref('),
            'El helper debe usarse en los cuatro flujos de traducción'
        );
    }
}
