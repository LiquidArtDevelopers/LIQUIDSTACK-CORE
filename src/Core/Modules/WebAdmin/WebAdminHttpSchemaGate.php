<?php

declare(strict_types=1);

namespace App\Core\Modules\WebAdmin;

use App\Core\Modules\Migrations\AppliedMigration;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDatabaseConnectionContract;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\ModuleRegistry;
use PDO;
use Throwable;

/**
 * Fail-closed, bounded readiness gate for WebAdmin HTTP requests.
 *
 * It proves the active module/catalog/registry contract and the small set of
 * canonical authorization seeds needed by the runtime. Exhaustive DDL,
 * constraint and stored-row auditing is intentionally left to migrate/doctor
 * so ordinary HTTP requests never scan the complete thirteen-table schema.
 */
final class WebAdminHttpSchemaGate
{
    private const MODULE = 'webadmin';

    public function __construct(
        private readonly MigrationRegistry $migrationRegistry =
            new MigrationRegistry(),
        private readonly MigrationDatabaseConnectionContract $connectionContract =
            new MigrationDatabaseConnectionContract(),
        private readonly WebAdminCanonicalSeedVerifier $seedVerifier =
            new WebAdminCanonicalSeedVerifier()
    ) {
    }

    public function isReady(
        PDO $pdo,
        ModuleRegistry $moduleRegistry,
        MigrationScope $scope
    ): bool {
        try {
            if (
                !$moduleRegistry->isEnabled(self::MODULE)
                || $scope->moduleId() !== self::MODULE
            ) {
                return false;
            }

            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            if ($this->connectionContract->issueCodes($pdo, $driver) !== []) {
                return false;
            }

            $expected = $this->expectedMigrations(
                MigrationCatalog::fromRegistry($moduleRegistry),
                $scope,
                $driver
            );
            if ($expected === []) {
                return false;
            }

            $applied = $this->appliedMigrations($pdo);
            if (
                $applied === null
                || array_keys($applied) !== array_keys($expected)
            ) {
                return false;
            }

            foreach ($expected as $id => $migration) {
                $record = $applied[$id];
                if (
                    $record->checksum() !== $migration->checksum()
                    || $record->scopeHash() !== $scope->hash()
                ) {
                    return false;
                }
            }

            return $this->seedVerifier->verify($pdo, $scope, $driver);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, MigrationDefinition>
     */
    private function expectedMigrations(
        MigrationCatalog $catalog,
        MigrationScope $scope,
        string $driver
    ): array {
        $expected = [];
        foreach ($catalog->entries() as $entry) {
            if ($entry['module'] !== self::MODULE) {
                continue;
            }

            $migration = $entry['migration'];
            if (
                !$migration->isExecutableFor($driver)
                || $migration->statementsFor($driver, $scope) === []
                || isset($expected[$migration->id()])
            ) {
                return [];
            }
            $expected[$migration->id()] = $migration;
        }
        ksort($expected, SORT_STRING);

        return $expected;
    }

    /** @return null|array<string, AppliedMigration> */
    private function appliedMigrations(PDO $pdo): ?array
    {
        $applied = [];
        foreach (
            $this->migrationRegistry->recordedForModule($pdo, self::MODULE)
            as $record
        ) {
            if (
                $record->moduleId() !== self::MODULE
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
