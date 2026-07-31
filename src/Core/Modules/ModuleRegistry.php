<?php

declare(strict_types=1);

namespace App\Core\Modules;

use RuntimeException;

final class ModuleRegistry
{
    private function __construct(
        private readonly ModuleSelection $selection
    ) {
    }

    public static function forProject(
        string $projectRoot,
        ?string $coreRoot = null
    ): self {
        $coreRoot ??= dirname(__DIR__, 3);
        $catalog = ModuleCatalog::fromCoreRoot($coreRoot);

        return new self(ModuleSelection::fromComposerJson(
            $catalog,
            rtrim($projectRoot, '/\\') . '/composer.json'
        ));
    }

    public function selection(): ModuleSelection
    {
        return $this->selection;
    }

    public function isEnabled(string $id): bool
    {
        return $this->selection->isEnabled($id);
    }

    /**
     * @return list<array{module: string, class: string}>
     */
    public function providers(string $type): array
    {
        $providers = [];

        foreach ($this->selection->enabledDefinitions() as $definition) {
            foreach ($definition->providers($type) as $className) {
                if (
                    !class_exists($className)
                    || !is_subclass_of(
                        $className,
                        ModuleProviderInterface::class
                    )
                ) {
                    throw new RuntimeException(sprintf(
                        'El provider %s del módulo %s no implementa %s.',
                        $className,
                        $definition->id(),
                        ModuleProviderInterface::class
                    ));
                }
                if ($className::moduleId() !== $definition->id()) {
                    throw new RuntimeException(sprintf(
                        'El provider %s declara el módulo %s, pero está registrado en %s.',
                        $className,
                        $className::moduleId(),
                        $definition->id()
                    ));
                }

                $providers[] = [
                    'module' => $definition->id(),
                    'class' => $className,
                ];
            }
        }

        return $providers;
    }
}
