<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDatabasePlan;
use App\Core\Modules\Migrations\MigrationDatabasePlanner;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\WebAdmin\Bootstrap\BootstrapInvitationResendResult;
use App\Core\WebAdmin\Bootstrap\BootstrapResult;
use App\Core\WebAdmin\Bootstrap\WebAdminBootstrapService;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use PDO;

final class WebAdminBootstrapCommandRuntime implements
    WebAdminBootstrapCommandRuntimeInterface
{
    /**
     * @param array<string, mixed> $bootstrapEnvironment
     */
    public function __construct(
        private readonly PDO $connection,
        private readonly MigrationCatalog $catalog,
        private readonly MigrationScopeCollection $scopes,
        private readonly array $bootstrapEnvironment,
        private readonly WebAdminConfig $config,
        private readonly MigrationDatabasePlanner $planner = new MigrationDatabasePlanner()
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

    public function bootstrap(): BootstrapResult
    {
        return (new WebAdminBootstrapService(
            $this->connection,
            $this->config
        ))->bootstrap($this->bootstrapEnvironment);
    }

    public function resendInvitations(): BootstrapInvitationResendResult
    {
        return (new WebAdminBootstrapService(
            $this->connection,
            $this->config
        ))->resendInvitations();
    }
}
