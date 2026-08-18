<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Modules\Migrations\MigrationDatabasePlan;
use App\Core\WebAdmin\Bootstrap\BootstrapOnboardingResult;
use App\Core\WebAdmin\Outbox\WebAdminOutboxDispatchReport;

final class WebAdminOnboardCommandRuntime implements
    WebAdminOnboardCommandRuntimeInterface
{
    public function __construct(
        private readonly WebAdminBootstrapCommandRuntimeInterface $bootstrapRuntime,
        private readonly WebAdminOnboardMailRuntimeInterface $mailRuntime
    ) {
    }

    public function preview(): MigrationDatabasePlan
    {
        return $this->bootstrapRuntime->preview();
    }

    public function onboard(): BootstrapOnboardingResult
    {
        $bootstrap = $this->bootstrapRuntime->bootstrap();
        $readiness = $this->mailRuntime->bootstrapInvitationReadiness();
        $dispatch = new WebAdminOutboxDispatchReport(0, 0, 0, 0, 0, 0);

        // A successful rerun must be observational: do not claim or recreate
        // mail work after both protected identities already have access.
        if (!$readiness->isReady()) {
            $dispatch = $this->mailRuntime->dispatchBootstrapInvitations();
            $readiness = $this->mailRuntime
                ->bootstrapInvitationReadiness();
        }

        return new BootstrapOnboardingResult(
            $bootstrap,
            $dispatch,
            $readiness
        );
    }
}
