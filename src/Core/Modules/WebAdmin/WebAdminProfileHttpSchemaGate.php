<?php

declare(strict_types=1);

namespace App\Core\Modules\WebAdmin;

use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\Migrations\MigrationRuntimeTableShapeProbe;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PDO;
use Throwable;

final class WebAdminProfileHttpSchemaGate
{
    /** @var array<string, list<string>> */
    private const RUNTIME_TABLES = [
        'user_profiles' => [
            'user_id', 'time_zone', 'lock_version', 'updated_by_user_id',
            'updated_at',
        ],
    ];

    public function __construct(
        private readonly MigrationFeatureGate $featureGate =
            new MigrationFeatureGate(),
        private readonly MigrationRuntimeTableShapeProbe $shapeProbe =
            new MigrationRuntimeTableShapeProbe()
    ) {
    }

    public function isReady(
        PDO $pdo,
        ModuleRegistry $registry,
        MigrationScopeCollection $scopes
    ): bool {
        try {
            $scope = $scopes->get('webadmin');
            return $scope !== null
                && $this->featureGate->isReady(
                    $pdo,
                    $registry,
                    $scopes,
                    WebAdminMigrationRequirements::profilePreferences()
                )
                && $this->shapeProbe->hasColumns(
                    $pdo,
                    $scope,
                    self::RUNTIME_TABLES
                );
        } catch (Throwable) {
            return false;
        }
    }
}
