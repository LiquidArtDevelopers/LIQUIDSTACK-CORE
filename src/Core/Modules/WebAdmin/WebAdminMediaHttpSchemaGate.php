<?php

declare(strict_types=1);

namespace App\Core\Modules\WebAdmin;

use App\Core\Modules\Migrations\MigrationDatabaseConnectionContract;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PDO;
use Throwable;

/** Bounded feature gate for /admin/media; it never blocks base WebAdmin. */
final class WebAdminMediaHttpSchemaGate
{
    private readonly MigrationFeatureGate $migrationGate;

    public function __construct(
        MigrationRegistry $migrationRegistry = new MigrationRegistry(),
        MigrationDatabaseConnectionContract $connectionContract =
            new MigrationDatabaseConnectionContract(),
        ?MigrationFeatureGate $migrationGate = null
    ) {
        $this->migrationGate = $migrationGate
            ?? new MigrationFeatureGate($migrationRegistry, $connectionContract);
    }

    public function isReady(
        PDO $pdo,
        ModuleRegistry $registry,
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
                $registry,
                $scopes,
                WebAdminMigrationRequirements::media()
            )) {
                return false;
            }
            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            foreach (['media_assets', 'media_variants'] as $suffix) {
                $statement = $pdo->query(
                    'SELECT 1 FROM ' . $scope->quotedTable($suffix, $driver)
                    . ' WHERE 1 = 0'
                );
                if ($statement === false) {
                    return false;
                }
            }

            $seed = $pdo->query(
                'SELECT c.code, c.module_id, c.label_key, c.is_delegable, '
                . 'GROUP_CONCAT(r.code) AS roles FROM '
                . $scope->quotedTable('capabilities', $driver) . ' c LEFT JOIN '
                . $scope->quotedTable('role_capabilities', $driver)
                . ' rc ON rc.capability_id = c.id LEFT JOIN '
                . $scope->quotedTable('roles', $driver)
                . " r ON r.id = rc.role_id WHERE c.code IN "
                . "('webadmin.media.upload','webadmin.media.view') "
                . 'GROUP BY c.id, c.code, c.module_id, c.label_key, c.is_delegable '
                . 'ORDER BY c.code'
            )->fetchAll(PDO::FETCH_ASSOC);

            $quotaLock = $pdo->query(
                'SELECT value_text FROM '
                . $scope->quotedTable('state', $driver)
                . " WHERE state_key = 'media.quota_lock'"
            )->fetchAll(PDO::FETCH_COLUMN);

            return $this->validSeeds($seed) && $quotaLock === ['v1'];
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function validSeeds(array $rows): bool
    {
        if (count($rows) !== 2) {
            return false;
        }
        $labels = [
            'webadmin.media.upload' => 'webadmin.capabilities.media_upload',
            'webadmin.media.view' => 'webadmin.capabilities.media_view',
        ];
        foreach ($rows as $row) {
            $code = $row['code'] ?? null;
            $roles = is_string($row['roles'] ?? null)
                ? explode(',', $row['roles']) : [];
            sort($roles, SORT_STRING);
            if (
                !is_string($code)
                || ($row['module_id'] ?? null) !== 'webadmin'
                || ($row['label_key'] ?? null) !== ($labels[$code] ?? null)
                || !in_array($row['is_delegable'] ?? null, [1, '1'], true)
                || $roles !== ['site_admin', 'system_superadmin']
            ) {
                return false;
            }
        }

        return true;
    }
}
