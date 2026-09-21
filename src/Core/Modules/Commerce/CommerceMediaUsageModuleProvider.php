<?php

declare(strict_types=1);

namespace App\Core\Modules\Commerce;

use App\Core\Commerce\Media\PdoCommerceMediaUsageProvider;
use App\Core\Commerce\Persistence\CommerceTableNames;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use App\Core\WebAdmin\Media\Usage\MediaUsageModuleProviderInterface;
use App\Core\WebAdmin\Media\Usage\MediaUsageProviderInterface;
use PDO;

final class CommerceMediaUsageModuleProvider implements
    MediaUsageModuleProviderInterface
{
    public static function moduleId(): string
    {
        return 'commerce';
    }

    public function createMediaUsageProvider(
        PDO $pdo,
        ModuleRegistry $registry,
        MigrationScopeCollection $scopes
    ): ?MediaUsageProviderInterface {
        $scope = $scopes->get(self::moduleId());
        if (
            $scope === null
            || !(new CommerceHttpSchemaGate())->isPublicCatalogReady(
                $pdo,
                $registry,
                $scopes
            )
        ) {
            return null;
        }

        return new PdoCommerceMediaUsageProvider(
            $pdo,
            CommerceTableNames::fromPdo($pdo, $scope->tablePrefix())
        );
    }
}
