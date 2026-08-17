<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Usage;

use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleProviderInterface;
use App\Core\Modules\ModuleRegistry;
use PDO;

interface MediaUsageModuleProviderInterface extends ModuleProviderInterface
{
    /** Null means the enabled module cannot safely inspect its schema yet. */
    public function createMediaUsageProvider(
        PDO $pdo,
        ModuleRegistry $registry,
        MigrationScopeCollection $scopes
    ): ?MediaUsageProviderInterface;
}
