<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Modules\ModuleSelection;
use Composer\IO\IOInterface;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ModuleProjectFileSynchronizer
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly IOInterface $io
    ) {
    }

    public function queue(
        ModuleSelection $selection,
        ManagedFileSynchronizer $synchronizer
    ): void {
        $preparedModules = [];
        $selectionIsValid = true;

        foreach ($selection->enabledDefinitions() as $definition) {
            $prepared = [];
            $moduleIsValid = true;

            foreach ($definition->projectFiles() as $entry) {
                $source = $definition->root()
                    . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $entry['source']);

                $validSource = $entry['type'] === 'file'
                    ? is_file($source)
                    : is_dir($source);
                if (
                    !$validSource
                    || !$this->isSafeExistingSource(
                        $definition->root(),
                        $source,
                        $entry['source']
                    )
                ) {
                    $this->missingSource($definition->id(), $source);
                    $moduleIsValid = false;
                    continue;
                }

                if ($entry['type'] === 'file') {
                    $moduleIsValid = $this->prepareFile(
                        $definition->id(),
                        $definition->root(),
                        $entry,
                        $source,
                        $entry['source'],
                        $entry['target'],
                        $prepared
                    ) && $moduleIsValid;
                    continue;
                }

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(
                        $source,
                        FilesystemIterator::SKIP_DOTS
                    ),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $item) {
                    $relative = str_replace(
                        '\\',
                        '/',
                        $iterator->getSubPathName()
                    );

                    if ($item->isLink()) {
                        $this->missingSource(
                            $definition->id(),
                            $item->getPathname()
                        );
                        $moduleIsValid = false;
                        continue;
                    }
                    if (!$item->isFile()) {
                        continue;
                    }

                    $moduleIsValid = $this->prepareFile(
                        $definition->id(),
                        $definition->root(),
                        $entry,
                        $item->getPathname(),
                        $entry['source'] . '/' . $relative,
                        $entry['target'] . '/' . $relative,
                        $prepared
                    ) && $moduleIsValid;
                }
            }

            if (!$moduleIsValid) {
                $selectionIsValid = false;
            }

            $preparedModules[$definition->id()] = $prepared;
        }

        if (!$selectionIsValid) {
            $this->io->writeError(
                '<warning>No se publica ningún fichero de los módulos seleccionados para evitar una instalación parcial o una dependencia incompleta.</warning>'
            );
            return;
        }

        foreach ($preparedModules as $prepared) {
            foreach ($prepared as $item) {
                $entry = $item['entry'];
                $synchronizer->queueFile(
                    $item['source'],
                    $item['target'],
                    $item['source_id'],
                    $item['target_id'],
                    $entry['policy'],
                    $entry['group'],
                    $entry['track_state']
                );
            }
        }
    }

    /**
     * @param array{
     *     source: string,
     *     target: string,
     *     type: 'file'|'dir',
     *     policy: 'managed_hash'|'install_if_missing'|'merge_json_additive',
     *     group: string,
     *     track_state: bool
     * } $entry
     * @param list<array{
     *     entry: array<string, mixed>,
     *     source: string,
     *     target: string,
     *     source_id: string,
     *     target_id: string
     * }> $prepared
     */
    private function prepareFile(
        string $moduleId,
        string $moduleRoot,
        array $entry,
        string $source,
        string $sourceRelative,
        string $targetRelative,
        array &$prepared
    ): bool {
        if (
            !is_file($source)
            || !$this->isSafeExistingSource(
                $moduleRoot,
                $source,
                $sourceRelative
            )
        ) {
            $this->missingSource($moduleId, $source);
            return false;
        }

        if ($this->hasUnsafeTargetComponent($targetRelative)) {
            $this->linkedTarget($moduleId, $targetRelative);
            return false;
        }

        $prepared[] = [
            'entry' => $entry,
            'source' => $source,
            'target' => rtrim($this->projectRoot, '/\\')
                . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $targetRelative),
            'source_id' => 'modules/'
                . $moduleId
                . '/'
                . $sourceRelative,
            'target_id' => $targetRelative,
        ];

        return true;
    }

    private function missingSource(string $moduleId, string $source): void
    {
        $this->io->writeError(sprintf(
            '<warning>Se omite un fichero del módulo %s porque su origen no existe o no es regular: %s</warning>',
            $moduleId,
            $source
        ));
    }

    private function linkedTarget(
        string $moduleId,
        string $target
    ): void {
        $this->io->writeError(sprintf(
            '<warning>Se omite un fichero del módulo %s porque el destino contiene un enlace: %s</warning>',
            $moduleId,
            $target
        ));
    }

    private function isSafeExistingSource(
        string $moduleRoot,
        string $source,
        string $relativePath
    ): bool {
        $current = rtrim($moduleRoot, '/\\');
        if (is_link($current)) {
            return false;
        }

        foreach (explode('/', $relativePath) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                return false;
            }
        }

        $resolvedRoot = realpath($moduleRoot);
        $resolvedSource = realpath($source);

        return is_string($resolvedRoot)
            && is_string($resolvedSource)
            && $this->isWithin($resolvedRoot, $resolvedSource);
    }

    private function hasUnsafeTargetComponent(string $relativePath): bool
    {
        $current = rtrim($this->projectRoot, '/\\');
        $resolvedRoot = realpath($current);
        if (!is_string($resolvedRoot)) {
            return true;
        }

        foreach (explode('/', $relativePath) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                return true;
            }
            if (file_exists($current)) {
                $resolvedCurrent = realpath($current);
                if (
                    !is_string($resolvedCurrent)
                    || !$this->isWithin($resolvedRoot, $resolvedCurrent)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isWithin(string $root, string $path): bool
    {
        $normalize = static function (string $value): string {
            $value = rtrim(str_replace('\\', '/', $value), '/');

            return PHP_OS_FAMILY === 'Windows'
                ? strtolower($value)
                : $value;
        };
        $root = $normalize($root);
        $path = $normalize($path);

        return $path === $root || str_starts_with($path, $root . '/');
    }
}
