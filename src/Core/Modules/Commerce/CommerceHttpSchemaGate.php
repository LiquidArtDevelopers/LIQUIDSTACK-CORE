<?php

declare(strict_types=1);

namespace App\Core\Modules\Commerce;

use App\Core\Modules\Migrations\MigrationDatabaseConnectionContract;
use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationRuntimeTableShapeProbe;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PDO;
use Throwable;

final class CommerceHttpSchemaGate
{
    private readonly MigrationFeatureGate $migrationGate;

    public function __construct(
        MigrationRegistry $registry = new MigrationRegistry(),
        MigrationDatabaseConnectionContract $connectionContract =
            new MigrationDatabaseConnectionContract(),
        private readonly CommerceCapabilitySeedPostcondition
            $capabilityVerifier = new CommerceCapabilitySeedPostcondition(),
        ?MigrationFeatureGate $migrationGate = null,
        private readonly MigrationRuntimeTableShapeProbe $shapeProbe =
            new MigrationRuntimeTableShapeProbe()
    ) {
        $this->migrationGate = $migrationGate
            ?? new MigrationFeatureGate($registry, $connectionContract);
    }

    public function isPublicCatalogReady(
        PDO $pdo,
        ModuleRegistry $registry,
        MigrationScopeCollection $scopes
    ): bool {
        return $this->isReadyFor(
            $pdo,
            $registry,
            $scopes,
            false,
            false
        );
    }

    public function isPublicInquiryReady(
        PDO $pdo,
        ModuleRegistry $registry,
        MigrationScopeCollection $scopes
    ): bool {
        return $this->isReadyFor(
            $pdo,
            $registry,
            $scopes,
            true,
            false
        );
    }

    public function isAdministrationReady(
        PDO $pdo,
        ModuleRegistry $registry,
        MigrationScopeCollection $scopes
    ): bool {
        return $this->isReadyFor(
            $pdo,
            $registry,
            $scopes,
            true,
            true
        );
    }

    private function isReadyFor(
        PDO $pdo,
        ModuleRegistry $registry,
        MigrationScopeCollection $scopes,
        bool $withEngagement,
        bool $withCapabilities
    ): bool {
        try {
            $scope = $scopes->get('commerce');
            if ($scope === null) {
                return false;
            }
            $requirement = $withCapabilities
                ? CommerceMigrationRequirements::administration()
                : ($withEngagement
                    ? CommerceMigrationRequirements::publicInquiry()
                    : CommerceMigrationRequirements::publicCatalog());
            if (!$this->migrationGate->isReady(
                $pdo,
                $registry,
                $scopes,
                $requirement
            )) {
                return false;
            }
            if (!$this->shapeProbe->hasColumns(
                $pdo,
                $scope,
                $withEngagement
                    ? CommerceSchemaContract::allTables()
                    : CommerceSchemaContract::catalogTables()
            )) {
                return false;
            }
            if (!$withCapabilities) {
                return true;
            }
            $webAdminScope = $scopes->get('webadmin');

            return $webAdminScope !== null
                && $this->capabilityVerifier->verify($pdo, $webAdminScope);
        } catch (Throwable) {
            return false;
        }
    }
}
