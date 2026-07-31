<?php

declare(strict_types=1);

namespace App\Core\Modules;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ModulePublishedSourceFinder
{
    /**
     * @return array<string, string> source ID => absolute path
     */
    public static function currentManagedFiles(ModuleCatalog $catalog): array
    {
        $files = [];

        foreach ($catalog->all() as $definition) {
            foreach ($definition->projectFiles() as $entry) {
                if ($entry['policy'] !== 'managed_hash') {
                    continue;
                }

                $source = $definition->root()
                    . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $entry['source']);
                $sourceId = 'modules/'
                    . $definition->id()
                    . '/'
                    . $entry['source'];

                if ($entry['type'] === 'file') {
                    self::assertRegularFile($source, $sourceId);
                    $files[$sourceId] = $source;
                    continue;
                }

                if (!is_dir($source) || is_link($source)) {
                    throw new RuntimeException(sprintf(
                        'El origen modular gestionado %s no es un directorio regular.',
                        $sourceId
                    ));
                }

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(
                        $source,
                        FilesystemIterator::SKIP_DOTS
                    ),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $item) {
                    if ($item->isLink()) {
                        throw new RuntimeException(sprintf(
                            'El origen modular gestionado contiene un enlace: %s.',
                            $item->getPathname()
                        ));
                    }
                    if (!$item->isFile()) {
                        continue;
                    }

                    $relative = str_replace(
                        '\\',
                        '/',
                        $iterator->getSubPathName()
                    );
                    $files[$sourceId . '/' . $relative] = $item->getPathname();
                }
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    private static function assertRegularFile(
        string $path,
        string $sourceId
    ): void {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException(sprintf(
                'El origen modular gestionado %s no es un fichero regular.',
                $sourceId
            ));
        }
    }
}
