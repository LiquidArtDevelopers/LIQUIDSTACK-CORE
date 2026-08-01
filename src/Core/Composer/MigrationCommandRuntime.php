<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Modules\Migrations\MigrationApplyOptions;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDatabasePlan;
use App\Core\Modules\Migrations\MigrationDatabasePlanner;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationRunResult;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use PDO;

final class MigrationCommandRuntime
{
    public function __construct(
        private readonly PDO $connection,
        private readonly MigrationCatalog $catalog,
        private readonly MigrationScopeCollection $scopes,
        private readonly MigrationDatabasePlanner $planner = new MigrationDatabasePlanner(),
        private readonly MigrationRunner $runner = new MigrationRunner()
    ) {
    }

    public function preview(): MigrationDatabasePlan
    {
        return $this->planner->plan(
            $this->connection,
            $this->catalog,
            $this->scopes
        );
    }

    public function apply(MigrationApplyOptions $options): MigrationRunResult
    {
        return $this->runner->apply(
            $this->connection,
            $this->catalog,
            $this->scopes,
            $options
        );
    }

    /** @return list<string> */
    public function activeModuleIds(): array
    {
        return $this->catalog->activeModuleIds();
    }
}
