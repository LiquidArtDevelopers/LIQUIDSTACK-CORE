<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WebAdminSourceEncodingTest extends TestCase
{
    public function testNewOperationalSourcesRemainCanonicalUtf8(): void
    {
        $root = dirname(__DIR__, 2);
        $paths = [
            'src/Core/Database',
            'src/Core/Environment',
            'src/Core/Http',
            'src/Core/Modules/Diagnostics',
            'src/Core/Modules/Migrations',
            'src/Core/Modules/WebAdmin',
            'src/Core/Routing',
            'src/Core/WebAdmin',
            'src/Core/Composer/Command',
        ];
        $files = [];
        foreach ($paths as $relative) {
            $directory = $root . '/' . $relative;
            self::assertDirectoryExists($directory);
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS
                )
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        foreach (glob($root . '/docs/webadmin-*.md') ?: [] as $file) {
            $files[] = $file;
        }

        self::assertNotEmpty($files);
        foreach (array_values(array_unique($files)) as $file) {
            $contents = file_get_contents($file);
            self::assertIsString($contents, $file);
            self::assertSame(1, preg_match('//u', $contents), $file);
            self::assertDoesNotMatchRegularExpression(
                '/[\x{00C3}\x{00C2}\x{FFFD}]|\x{00E2}\x{20AC}/u',
                $contents,
                $file
            );
        }
    }
}
