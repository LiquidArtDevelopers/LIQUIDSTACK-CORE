<?php

declare(strict_types=1);

namespace App\Core\Modules\WebAdmin;

use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\Migrations\MigrationDatabaseConnectionContract;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\Migrations\MigrationScopeCollection;
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
    private readonly MigrationFeatureGate $migrationGate;

    public function __construct(
        MigrationRegistry $migrationRegistry = new MigrationRegistry(),
        MigrationDatabaseConnectionContract $connectionContract =
            new MigrationDatabaseConnectionContract(),
        private readonly WebAdminCanonicalSeedVerifier $seedVerifier =
            new WebAdminCanonicalSeedVerifier(),
        ?MigrationFeatureGate $migrationGate = null
    ) {
        $this->migrationGate = $migrationGate
            ?? new MigrationFeatureGate(
                $migrationRegistry,
                $connectionContract
            );
    }

    public function isReady(
        PDO $pdo,
        ModuleRegistry $moduleRegistry,
        MigrationScope $scope
    ): bool {
        try {
            if ($scope->moduleId() !== 'webadmin') {
                return false;
            }
            $scopes = MigrationScopeCollection::fromTablePrefixes([
                'webadmin' => $scope->tablePrefix(),
            ]);
            if (!$this->migrationGate->isReady(
                $pdo,
                $moduleRegistry,
                $scopes,
                WebAdminMigrationRequirements::runtime()
            )) {
                return false;
            }

            return $this->seedVerifier->verify(
                $pdo,
                $scope,
                MigrationDatabaseDriver::fromPdo($pdo)->value
            );
        } catch (Throwable) {
            return false;
        }
    }
}
