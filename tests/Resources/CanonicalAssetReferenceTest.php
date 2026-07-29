<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
final class CanonicalAssetReferenceTest extends TestCase
{
    public function testEveryLiteralAssetReferenceIsDistributedByCore(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $references = [];

        foreach ([
            $coreRoot . '/stubs/App',
            $coreRoot . '/resources/js',
            $coreRoot . '/resources/scss',
            $coreRoot . '/src/js',
            $coreRoot . '/src/scss',
        ] as $sourceRoot) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $sourceRoot,
                    RecursiveDirectoryIterator::SKIP_DOTS
                )
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (
                    !$file->isFile()
                    || !in_array(
                        strtolower($file->getExtension()),
                        ['html', 'js', 'json', 'php', 'scss'],
                        true
                    )
                ) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                self::assertIsString($contents);

                if (!preg_match_all(
                    '#assets/(?:img|video)/[A-Za-z0-9_./@-]+\.(?:avif|gif|jpe?g|mp4|png|svg|vtt|webm|webp)#i',
                    $contents,
                    $matches
                )) {
                    continue;
                }

                foreach ($matches[0] as $reference) {
                    $references[$reference][] = $file->getPathname();
                }
            }
        }

        self::assertNotEmpty($references);

        foreach ($references as $reference => $sources) {
            $distributedPath = $coreRoot
                . '/resources/'
                . substr($reference, strlen('assets/'));

            self::assertFileExists(
                $distributedPath,
                sprintf(
                    "%s se referencia desde:\n- %s",
                    $reference,
                    implode("\n- ", array_unique($sources))
                )
            );
        }
    }
}
