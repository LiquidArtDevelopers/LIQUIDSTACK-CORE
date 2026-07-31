<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StarterNeutralityContractTest extends TestCase
{
    public function testLegacyMegamenuCanDisableInheritedOfficeData(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__, 2)
                . '/stubs/App/controllers/navMegamenu01.php'
        );

        self::assertStringContainsString(
            "array_key_exists('offices', \$params)",
            $controller
        );
        self::assertStringContainsString(
            "is_array(\$params['offices'])",
            $controller
        );
        self::assertStringContainsString(
            "? \$params['offices']",
            $controller
        );
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
