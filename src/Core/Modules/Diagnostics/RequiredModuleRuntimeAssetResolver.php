<?php

declare(strict_types=1);

namespace App\Core\Modules\Diagnostics;

use App\Core\Modules\ModuleDefinition;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Resolves the exact project files required by a module runtime. */
final class RequiredModuleRuntimeAssetResolver
{
    /** @return list<string> project-relative target files */
    public function resolve(ModuleDefinition $definition): array
    {
        $targets = [];

        foreach ($definition->projectFiles() as $entry) {
            if (!$this->isRuntimeOwnedTarget(
                $entry['target'],
                $definition->id()
            )) {
                continue;
            }

            $source = $definition->root()
                . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $entry['source']);
            $this->assertSourcePathIsSafe(
                $definition->root(),
                $source,
                $entry['source']
            );

            if ($entry['type'] === 'file') {
                $this->assertRegularFile($source, $entry['source']);
                $targets[] = $entry['target'];
                continue;
            }

            $directoryFiles = $this->directoryFiles(
                $source,
                $entry['source']
            );
            if ($directoryFiles === []) {
                throw new RuntimeException(sprintf(
                    'El origen de assets runtime %s no contiene ficheros.',
                    $entry['source']
                ));
            }
            foreach ($directoryFiles as $relative) {
                $targets[] = $entry['target'] . '/' . $relative;
            }
        }

        $targets = array_values(array_unique($targets));
        sort($targets, SORT_STRING);

        return $targets;
    }

    private function isRuntimeOwnedTarget(
        string $target,
        string $moduleId
    ): bool {
        foreach ([
            'public/assets/modules/' . $moduleId,
            'src/js/modules/' . $moduleId,
            'src/scss/modules/' . $moduleId,
        ] as $root) {
            if ($target === $root || str_starts_with($target, $root . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function directoryFiles(string $path, string $sourceId): array
    {
        if (!is_dir($path) || is_link($path)) {
            throw new RuntimeException(sprintf(
                'El origen de assets runtime %s no es un directorio regular.',
                $sourceId
            ));
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $path,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new RuntimeException(sprintf(
                    'El origen de assets runtime contiene un enlace: %s.',
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
            if (!$this->isSafeRelativePath($relative)) {
                throw new RuntimeException(sprintf(
                    'El origen de assets runtime %s contiene una ruta inválida.',
                    $sourceId
                ));
            }
            $files[] = $relative;
        }

        sort($files, SORT_STRING);

        return $files;
    }

    private function assertRegularFile(string $path, string $sourceId): void
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException(sprintf(
                'El origen de assets runtime %s no es un fichero regular.',
                $sourceId
            ));
        }
    }

    private function assertSourcePathIsSafe(
        string $moduleRoot,
        string $source,
        string $sourceId
    ): void {
        $cursor = rtrim($moduleRoot, '/\\');
        if ($cursor === '' || is_link($cursor)) {
            throw new RuntimeException(sprintf(
                'El origen de assets runtime %s atraviesa un enlace.',
                $sourceId
            ));
        }

        foreach (explode('/', $sourceId) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor)) {
                throw new RuntimeException(sprintf(
                    'El origen de assets runtime %s atraviesa un enlace.',
                    $sourceId
                ));
            }
        }

        $rootReal = realpath($moduleRoot);
        $sourceReal = realpath($source);
        if ($rootReal === false || $sourceReal === false) {
            return;
        }

        $root = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
        $candidate = str_replace('\\', '/', $sourceReal);
        if (DIRECTORY_SEPARATOR === '\\') {
            $root = strtolower($root);
            $candidate = strtolower($candidate);
        }
        if ($candidate !== rtrim($root, '/')
            && !str_starts_with($candidate . '/', $root)
        ) {
            throw new RuntimeException(sprintf(
                'El origen de assets runtime %s escapa del módulo.',
                $sourceId
            ));
        }
    }

    private function isSafeRelativePath(string $path): bool
    {
        $segments = explode('/', $path);

        return $path !== ''
            && !str_starts_with($path, '/')
            && preg_match('/\A[A-Za-z]:\//', $path) !== 1
            && preg_match('/[\x00-\x1F\x7F:]/', $path) !== 1
            && !in_array('', $segments, true)
            && !in_array('.', $segments, true)
            && !in_array('..', $segments, true);
    }
}
