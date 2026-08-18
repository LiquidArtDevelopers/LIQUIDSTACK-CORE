<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Modules\Migrations\MigrationDatabasePlan;
use App\Core\WebAdmin\Bootstrap\BootstrapOnboardingResult;

interface WebAdminOnboardCommandRuntimeInterface
{
    /** Inspects migration state without modifying it. */
    public function preview(): MigrationDatabasePlan;

    /**
     * Reconciles the bootstrap identities, delivers only their pending
     * invitations and verifies the resulting initial-access state.
     */
    public function onboard(): BootstrapOnboardingResult;
}
