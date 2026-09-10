<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StarterNeutralityContractTest extends TestCase
{
    public function testLegacyMegamenuUsesOnlyExplicitProjectOfficeData(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__, 2)
                . '/stubs/App/controllers/navMegamenu01.php'
        );

        self::assertStringContainsString(
            "is_array(\$params['offices'] ?? null)",
            $controller
        );
        self::assertStringContainsString(
            "? \$params['offices']",
            $controller
        );
        self::assertStringContainsString(
            ': [];',
            $controller
        );
        self::assertStringNotContainsString("'label' =>", $controller);
        self::assertStringNotContainsString("'tels' =>", $controller);
        self::assertStringNotContainsString('maps.app.goo.gl', $controller);
    }

    public function testHelperExamplesUseOnlyReservedExampleDomain(): void
    {
        $helpers = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Core/Support/helpers.php'
        );

        self::assertStringNotContainsString(
            'jaramaautoescuela',
            strtolower($helpers)
        );
        self::assertStringNotContainsString('bilbao', strtolower($helpers));
        self::assertStringContainsString('https://example.com', $helpers);
    }

    public function testOptionalGlobalMediaDoesNotResolveEmptySourcesToRoot(): void
    {
        $root = dirname(__DIR__, 2);
        $navigation = (string) file_get_contents(
            $root . '/stubs/App/controllers/navMegamenu01.php'
        );
        $footerController = (string) file_get_contents(
            $root . '/stubs/App/controllers/footerInfo01.php'
        );
        $footerTemplate = (string) file_get_contents(
            $root . '/stubs/App/templates/_footerInfo01.html'
        );

        self::assertStringContainsString(
            "\$href === '' || \$imageSource === ''",
            $navigation
        );
        self::assertStringContainsString(
            "\$col2SocialItems === ''",
            $navigation
        );
        self::assertStringContainsString(
            "\$params['public_link_keys'] ?? []",
            $navigation
        );
        self::assertStringContainsString(
            "\$params['show_private_access'] ?? true",
            $navigation
        );
        self::assertStringContainsString(
            "\$resolvedImageSource === ''",
            $footerController
        );
        self::assertStringContainsString('{img-01}', $footerTemplate);
        self::assertStringNotContainsString(
            'src="{img-01-src}"',
            $footerTemplate
        );
    }

    public function testCanonicalStarterCopyContainsNoRealLocalFallback(): void
    {
        $root = dirname(__DIR__, 2);
        $paths = [
            $root . '/stubs/App/controllers/navMegamenu01.php',
            $root . '/stubs/App/controllers/hero03.php',
            $root . '/stubs/App/config/languages/templates/es.json',
            $root . '/stubs/App/config/languages/templates/eu.json',
            $root . '/stubs/App/config/languages/templates/en.json',
        ];
        $copy = '';
        foreach ($paths as $path) {
            $copy .= strtolower((string) file_get_contents($path));
        }

        foreach ([
            'bizkaia',
            'bilbao',
            'barcelona',
            'donostia',
            'hegaztien pasealekua',
            'liquid art developers',
            'lad framework templates',
            'vida del club',
            'socios y socias',
            'socio',
            'dni vigente',
            'psicotécnico',
            'prácticas obligatorias',
            'maps.app.goo.gl',
            'ejemplo.com',
        ] as $term) {
            self::assertStringNotContainsString($term, $copy);
        }
    }

    public function testLegalCopyPrefersTheCanonicalBusinessAddressKey(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/resources/js/_terminos.js'
        );

        $canonical = strpos($source, 'VITE_BUSINESS_ADDRESS');
        $legacy = strpos($source, 'VITE_BUSINESS_ADRESS');
        self::assertIsInt($canonical);
        self::assertIsInt($legacy);
        self::assertLessThan($legacy, $canonical);
    }

    public function testPasswordTemplatesContainNoCustomerBranding(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['es', 'eu'] as $language) {
            $template = (string) file_get_contents(
                "{$root}/stubs/App/templates/{$language}/remember-password.html"
            );

            self::assertStringNotContainsString(
                'atleticosansebastian',
                strtolower($template)
            );
            self::assertStringNotContainsString('ATSS', $template);
            self::assertStringContainsString('{link}', $template);
            self::assertStringContainsString(
                '{nombreDestinatario}',
                $template
            );
        }
    }

    public function testManagedPhpStubsDoNotEmitAnUtf8Bom(): void
    {
        $root = dirname(__DIR__, 2) . '/stubs/App';
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $handle = fopen($file->getPathname(), 'rb');
            self::assertNotFalse($handle, $file->getPathname());
            $prefix = fread($handle, 3);
            fclose($handle);

            self::assertNotSame(
                "\xEF\xBB\xBF",
                $prefix,
                $file->getPathname()
                    . ' no debe emitir un BOM antes de las cabeceras HTTP.'
            );
        }
    }
}
