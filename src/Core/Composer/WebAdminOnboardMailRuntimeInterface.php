<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\WebAdmin\Bootstrap\BootstrapInvitationReadiness;
use App\Core\WebAdmin\Outbox\WebAdminOutboxDispatchReport;

/**
 * Narrow mail boundary used by the explicit initial-access workflow.
 *
 * The generic outbox dispatch contract remains available through the parent
 * interface. These methods deliberately scope delivery to the protected
 * bootstrap identities and expose only aggregate readiness.
 */
interface WebAdminOnboardMailRuntimeInterface extends
    WebAdminMailDispatchCommandRuntimeInterface
{
    public function dispatchBootstrapInvitations(): WebAdminOutboxDispatchReport;

    public function bootstrapInvitationReadiness(): BootstrapInvitationReadiness;
}
