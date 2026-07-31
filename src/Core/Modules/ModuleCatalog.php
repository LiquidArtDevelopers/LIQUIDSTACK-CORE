<?php

declare(strict_types=1);

namespace App\Core\Modules;

use RuntimeException;

final class ModuleCatalog
{
    /**
     * @param array<string, ModuleDefinition> $definitions
     * @param array<string, string> $packages
     */
    private function __construct(
        private readonly array $definitions,
        private readonly array $packages
    ) {
    }

    public static function fromCoreRoot(string $coreRoot): self
    {
        return self::fromModulesRoot(
            rtrim($coreRoot, '/\\') . '/modules'
        );
    }

    public static function fromModulesRoot(string $modulesRoot): self
    {
        if (!is_dir($modulesRoot) || is_link($modulesRoot)) {
            throw new RuntimeException(sprintf(
                'No existe el directorio regular de módulos %s.',
                $modulesRoot
            ));
        }

        $manifestPaths = glob(
            rtrim($modulesRoot, '/\\') . '/*/module.json'
        );
        if (!is_array($manifestPaths)) {
            throw new RuntimeException(sprintf(
                'No se pudo recorrer el catálogo de módulos %s.',
                $modulesRoot
            ));
        }

        sort($manifestPaths, SORT_STRING);
        $definitions = [];
        $packages = [];

        foreach ($manifestPaths as $manifestPath) {
            $definition = ModuleDefinition::fromManifest($manifestPath);
            $id = $definition->id();
            $packageName = $definition->packageName();

            if (isset($definitions[$id])) {
                throw new RuntimeException(sprintf(
                    'El módulo %s está duplicado.',
                    $id
                ));
            }
            if (isset($packages[$packageName])) {
                throw new RuntimeException(sprintf(
                    'El selector Composer %s está duplicado.',
                    $packageName
                ));
            }

            $definitions[$id] = $definition;
            $packages[$packageName] = $id;
        }

        foreach ($definitions as $definition) {
            foreach ($definition->dependencies() as $dependency) {
                if (!isset($definitions[$dependency])) {
                    throw new RuntimeException(sprintf(
                        'El módulo %s depende del módulo desconocido %s.',
                        $definition->id(),
                        $dependency
                    ));
                }
            }
        }

        self::assertAcyclic($definitions);

        return new self($definitions, $packages);
    }

    /**
     * @return array<string, ModuleDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    public function get(string $id): ModuleDefinition
    {
        if (!isset($this->definitions[$id])) {
            throw new RuntimeException(sprintf(
                'El módulo %s no existe en el catálogo.',
                $id
            ));
        }

        return $this->definitions[$id];
    }

    public function forPackage(string $packageName): ?ModuleDefinition
    {
        $id = $this->packages[strtolower($packageName)] ?? null;

        return is_string($id) ? $this->definitions[$id] : null;
    }

    /**
     * @param array<string, ModuleDefinition> $definitions
     */
    private static function assertAcyclic(array $definitions): void
    {
        $resolved = [];
        $visiting = [];

        $visit = static function (
            string $id,
            array $trail
        ) use (&$visit, &$resolved, &$visiting, $definitions): void {
            if (isset($resolved[$id])) {
                return;
            }
            if (isset($visiting[$id])) {
                $trail[] = $id;
                throw new RuntimeException(sprintf(
                    'Dependencia circular entre módulos: %s.',
                    implode(' -> ', $trail)
                ));
            }

            $visiting[$id] = true;
            $trail[] = $id;
            foreach ($definitions[$id]->dependencies() as $dependency) {
                $visit($dependency, $trail);
            }
            unset($visiting[$id]);
            $resolved[$id] = true;
        };

        foreach (array_keys($definitions) as $id) {
            $visit($id, []);
        }
    }
}
