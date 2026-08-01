<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleRegistry;
use RuntimeException;

final class MigrationCatalog
{
    /**
     * @param list<array{
     *     module: string,
     *     provider: class-string<MigrationProviderInterface>,
     *     migration: MigrationDefinition
     * }> $entries
     */
    private function __construct(
        private readonly array $entries,
        private readonly array $activeModuleIds
    ) {
    }

    public static function fromRegistry(ModuleRegistry $registry): self
    {
        $grouped = [];
        $moduleOrder = [];
        $seen = [];

        foreach ($registry->providers('migrations') as $providerEntry) {
            $module = $providerEntry['module'];
            $provider = $providerEntry['class'];

            if (!isset($grouped[$module])) {
                $grouped[$module] = [];
                $moduleOrder[] = $module;
            }

            if (!is_subclass_of($provider, MigrationProviderInterface::class)) {
                throw new RuntimeException(sprintf(
                    'El provider de migraciones %s del módulo %s no implementa %s.',
                    $provider,
                    $module,
                    MigrationProviderInterface::class
                ));
            }

            foreach ($provider::migrations() as $migration) {
                if (!$migration instanceof MigrationDefinition) {
                    throw new RuntimeException(sprintf(
                        'El provider %s del módulo %s devolvió una migración inválida.',
                        $provider,
                        $module
                    ));
                }

                $key = $module . ':' . $migration->id();
                if (isset($seen[$key])) {
                    throw new RuntimeException(sprintf(
                        'La migración %s está duplicada en el módulo %s.',
                        $migration->id(),
                        $module
                    ));
                }

                $seen[$key] = true;
                $grouped[$module][] = [
                    'module' => $module,
                    'provider' => $provider,
                    'migration' => $migration,
                ];
            }
        }

        $entries = [];
        foreach ($moduleOrder as $module) {
            usort(
                $grouped[$module],
                static fn (array $left, array $right): int =>
                    strcmp(
                        $left['migration']->id(),
                        $right['migration']->id()
                    )
            );
            self::assertInitialPreconditionOnly(
                $module,
                $grouped[$module]
            );
            self::assertValidPostconditionSupersessions(
                $module,
                $grouped[$module]
            );
            array_push($entries, ...$grouped[$module]);
        }

        $activeDefinitions = [];
        foreach ($registry->selection()->enabledDefinitions() as $definition) {
            $activeDefinitions[$definition->id()] = $definition;
        }
        self::assertValidTargetScopes($entries, $activeDefinitions);

        return new self(
            $entries,
            $registry->selection()->enabledIds()
        );
    }

    /**
     * Cross-scope migrations may only move backwards through the active
     * dependency graph. This keeps provider order meaningful and prevents one
     * module from mutating an unrelated or later module namespace.
     *
     * @param list<array{
     *     module: string,
     *     provider: class-string<MigrationProviderInterface>,
     *     migration: MigrationDefinition
     * }> $entries
     * @param array<string, ModuleDefinition> $activeDefinitions
     */
    private static function assertValidTargetScopes(
        array $entries,
        array $activeDefinitions
    ): void {
        $dependenciesByModule = [];

        foreach ($entries as $entry) {
            $module = $entry['module'];
            $migration = $entry['migration'];
            $target = $migration->targetScopeModuleId();
            if ($target === null || $target === $module) {
                continue;
            }

            $dependenciesByModule[$module] ??=
                self::transitiveDependencies($module, $activeDefinitions);
            if (
                !isset($activeDefinitions[$target])
                || !isset($dependenciesByModule[$module][$target])
            ) {
                throw new RuntimeException(sprintf(
                    'La migraci\u00f3n %s del m\u00f3dulo %s solo puede usar el scope propio o el de una dependencia transitiva activa; %s no es un destino permitido.',
                    $migration->id(),
                    $module,
                    $target
                ));
            }
        }
    }

    /**
     * @param array<string, ModuleDefinition> $activeDefinitions
     * @return array<string, true>
     */
    private static function transitiveDependencies(
        string $module,
        array $activeDefinitions
    ): array {
        $definition = $activeDefinitions[$module] ?? null;
        if (!$definition instanceof ModuleDefinition) {
            return [];
        }

        $resolved = [];
        $pending = $definition->dependencies();
        while ($pending !== []) {
            $dependency = array_pop($pending);
            if (!is_string($dependency) || isset($resolved[$dependency])) {
                continue;
            }

            $dependencyDefinition = $activeDefinitions[$dependency] ?? null;
            if (!$dependencyDefinition instanceof ModuleDefinition) {
                continue;
            }

            $resolved[$dependency] = true;
            array_push($pending, ...$dependencyDefinition->dependencies());
        }

        return $resolved;
    }

    /**
     * @param list<array{
     *     module: string,
     *     provider: class-string<MigrationProviderInterface>,
     *     migration: MigrationDefinition
     * }> $entries
     */
    private static function assertInitialPreconditionOnly(
        string $module,
        array $entries
    ): void {
        foreach ($entries as $position => $entry) {
            if (
                $position > 0
                && $entry['migration']->preconditionVerifier() !== null
            ) {
                throw new RuntimeException(sprintf(
                    'La precondicion de %s del modulo %s solo puede declararse en su migracion inicial.',
                    $entry['migration']->id(),
                    $module
                ));
            }
        }
    }

    /**
     * @return list<array{
     *     module: string,
     *     provider: class-string<MigrationProviderInterface>,
     *     migration: MigrationDefinition
     * }>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return list<string> */
    public function activeModuleIds(): array
    {
        return $this->activeModuleIds;
    }

    public function plan(): MigrationPlan
    {
        return MigrationPlan::fromCatalog($this);
    }

    /**
     * @param list<array{
     *     module: string,
     *     provider: class-string<MigrationProviderInterface>,
     *     migration: MigrationDefinition
     * }> $entries
     */
    private static function assertValidPostconditionSupersessions(
        string $module,
        array $entries
    ): void {
        $positions = [];
        $definitions = [];
        foreach ($entries as $position => $entry) {
            $id = $entry['migration']->id();
            $positions[$id] = $position;
            $definitions[$id] = $entry['migration'];
        }

        foreach ($entries as $position => $entry) {
            $migration = $entry['migration'];
            foreach ($migration->supersededPostconditionIds() as $targetId) {
                $target = $definitions[$targetId] ?? null;
                if (
                    !$target instanceof MigrationDefinition
                    || ($positions[$targetId] ?? $position) >= $position
                    || $target->postconditionVerifier() === null
                ) {
                    throw new RuntimeException(sprintf(
                        'La migracion %s del modulo %s declara una supersesion invalida.',
                        $migration->id(),
                        $module
                    ));
                }
            }
        }
    }
}
