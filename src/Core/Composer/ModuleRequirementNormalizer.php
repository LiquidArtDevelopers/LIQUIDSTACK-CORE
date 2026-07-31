<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Modules\ModuleCatalog;

final class ModuleRequirementNormalizer
{
    /**
     * Composer intenta descubrir un paquete sin constraint antes de que el
     * solver pueda ver que liquidstack/core lo reemplaza. Añadir `:*` a los
     * selectores lógicos evita esa búsqueda sin alterar constraints explícitos.
     *
     * @param list<mixed> $packages
     * @return list<mixed>
     */
    public static function normalize(
        array $packages,
        ?string $coreRoot = null
    ): array
    {
        $hasLiquidStackCandidate = false;
        foreach ($packages as $package) {
            if (
                is_string($package)
                && str_starts_with(strtolower($package), 'liquidstack/')
                && !str_contains($package, ':')
                && !str_contains($package, '=')
            ) {
                $hasLiquidStackCandidate = true;
                break;
            }
        }
        if (!$hasLiquidStackCandidate) {
            return $packages;
        }

        $catalog = ModuleCatalog::fromCoreRoot(
            $coreRoot ?? dirname(__DIR__, 3)
        );
        $selectors = [];
        foreach ($catalog->all() as $definition) {
            $selectors[$definition->packageName()] = true;
        }

        foreach ($packages as $index => $package) {
            if (!is_string($package)) {
                continue;
            }

            $canonical = strtolower($package);
            if (isset($selectors[$canonical])) {
                $packages[$index] = $canonical . ':*';
            }
        }

        return $packages;
    }
}
