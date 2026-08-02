<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use App\Core\Modules\ModuleRegistry;
use PDO;
use Throwable;

/** Bounded, fail-closed registry gate scoped to one runtime feature. */
final class MigrationFeatureGate
{
    public function __construct(
        private readonly MigrationRegistry $migrationRegistry =
            new MigrationRegistry(),
        private readonly MigrationDatabaseConnectionContract $connectionContract =
            new MigrationDatabaseConnectionContract()
    ) {
    }

    public function isReady(
        PDO $pdo,
        ModuleRegistry $moduleRegistry,
        MigrationScopeCollection $scopes,
        MigrationFeatureRequirement $requirement
    ): bool {
        try {
            $moduleId = $requirement->moduleId();
            if (!$moduleRegistry->isEnabled($moduleId)) {
                return false;
            }

            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            if ($this->connectionContract->issueCodes($pdo, $driver) !== []) {
                return false;
            }

            $expected = $this->expectedMigrations(
                MigrationCatalog::fromRegistry($moduleRegistry),
                $scopes,
                $driver,
                $moduleId
            );
            if ($expected === null) {
                return false;
            }
            foreach ($requirement->migrationIds() as $migrationId) {
                if (
                    !array_key_exists($migrationId, $expected)
                    || $expected[$migrationId] === null
                ) {
                    return false;
                }
            }

            $applied = $this->appliedMigrations($pdo, $moduleId);
            if ($applied === null) {
                return false;
            }

            $expectedIds = array_keys($expected);
            $appliedIds = array_keys($applied);
            if (
                $appliedIds !== array_slice(
                    $expectedIds,
                    0,
                    count($appliedIds)
                )
            ) {
                return false;
            }

            foreach ($applied as $migrationId => $record) {
                $entry = $expected[$migrationId] ?? null;
                if (
                    $entry === null
                    || $record->checksum()
                        !== $entry['migration']->checksum()
                    || $record->scopeHash() !== $entry['scope_hash']
                ) {
                    return false;
                }
            }
            foreach ($requirement->migrationIds() as $migrationId) {
                if (!isset($applied[$migrationId])) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return null|array<string, null|array{
     *     migration: MigrationDefinition,
     *     scope_hash: string
     * }>
     */
    private function expectedMigrations(
        MigrationCatalog $catalog,
        MigrationScopeCollection $scopes,
        string $driver,
        string $moduleId
    ): ?array {
        $expected = [];
        foreach ($catalog->entries() as $entry) {
            if ($entry['module'] !== $moduleId) {
                continue;
            }

            $migration = $entry['migration'];
            if (array_key_exists($migration->id(), $expected)) {
                return null;
            }
            try {
                $scope = $migration->targetScope($moduleId, $scopes);
                $expected[$migration->id()] = $scope !== null
                    && $migration->isExecutableFor($driver)
                    && $migration->statementsFor($driver, $scope) !== []
                        ? [
                            'migration' => $migration,
                            'scope_hash' => $scope->hash(),
                        ]
                        : null;
            } catch (Throwable) {
                // A broken or unavailable optional feature is not a reason to
                // disable an older boundary. It will still fail if required
                // here or if its registry record claims it was applied.
                $expected[$migration->id()] = null;
            }
        }
        ksort($expected, SORT_STRING);

        return $expected;
    }

    /** @return null|array<string, AppliedMigration> */
    private function appliedMigrations(
        PDO $pdo,
        string $moduleId
    ): ?array {
        $applied = [];
        foreach (
            $this->migrationRegistry->recordedForModule($pdo, $moduleId)
            as $record
        ) {
            if (
                $record->moduleId() !== $moduleId
                || isset($applied[$record->migrationId()])
            ) {
                return null;
            }
            $applied[$record->migrationId()] = $record;
        }
        ksort($applied, SORT_STRING);

        return $applied;
    }
}
