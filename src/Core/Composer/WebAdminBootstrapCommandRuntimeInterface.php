<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Modules\Migrations\MigrationDatabasePlan;
use App\Core\WebAdmin\Bootstrap\BootstrapInvitationResendResult;
use App\Core\WebAdmin\Bootstrap\BootstrapResult;

interface WebAdminBootstrapCommandRuntimeInterface
{
    /**
     * Inspects the migration registry and schema without modifying either.
     */
    public function preview(): MigrationDatabasePlan;

    /**
     * Executes only the idempotent WebAdmin identity bootstrap.
     */
    public function bootstrap(): BootstrapResult;

    /**
     * Requeues initial-account invitations only through the explicit CLI mode.
     */
    public function resendInvitations(): BootstrapInvitationResendResult;
}
