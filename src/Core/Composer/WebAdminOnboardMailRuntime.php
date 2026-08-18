<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\WebAdmin\Bootstrap\BootstrapInvitationReadiness;
use App\Core\WebAdmin\Bootstrap\WebAdminBootstrapInvitationAudience;
use App\Core\WebAdmin\Outbox\WebAdminOutboxDispatcher;
use App\Core\WebAdmin\Outbox\WebAdminOutboxDispatchReport;

final class WebAdminOnboardMailRuntime implements
    WebAdminOnboardMailRuntimeInterface
{
    public function __construct(
        private readonly WebAdminOutboxDispatcher $dispatcher,
        private readonly WebAdminBootstrapInvitationAudience $bootstrapAudience
    ) {
    }

    public function dispatch(int $limit): WebAdminOutboxDispatchReport
    {
        return $this->dispatcher->dispatchBatch($limit);
    }

    public function dispatchBootstrapInvitations(): WebAdminOutboxDispatchReport
    {
        $recipientIds = $this->bootstrapAudience
            ->dispatchableRecipientIds();
        if ($recipientIds === []) {
            return new WebAdminOutboxDispatchReport(0, 0, 0, 0, 0, 0);
        }

        return $this->dispatcher->dispatchInvitationsForRecipients(
            $recipientIds
        );
    }

    public function bootstrapInvitationReadiness(): BootstrapInvitationReadiness
    {
        return $this->bootstrapAudience->readiness();
    }
}
