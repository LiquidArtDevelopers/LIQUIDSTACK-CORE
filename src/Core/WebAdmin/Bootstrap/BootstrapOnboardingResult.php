<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Bootstrap;

use App\Core\WebAdmin\Outbox\WebAdminOutboxDispatchReport;

final class BootstrapOnboardingResult
{
    public function __construct(
        private readonly BootstrapResult $bootstrap,
        private readonly WebAdminOutboxDispatchReport $dispatch,
        private readonly BootstrapInvitationReadiness $readiness
    ) {
    }

    public function bootstrap(): BootstrapResult
    {
        return $this->bootstrap;
    }

    public function dispatch(): WebAdminOutboxDispatchReport
    {
        return $this->dispatch;
    }

    public function readiness(): BootstrapInvitationReadiness
    {
        return $this->readiness;
    }

    public function isReady(): bool
    {
        return $this->readiness->isReady()
            && $this->dispatch->retryScheduled() === 0
            && $this->dispatch->permanentlyFailed() === 0
            && $this->dispatch->fenced() === 0;
    }

    /** @return array<string, mixed> */
    public function toSafeArray(): array
    {
        return [
            'bootstrap' => $this->bootstrap->toSafeArray(),
            'dispatch' => $this->dispatch->toArray(),
            'readiness' => $this->readiness->toSafeArray(),
        ];
    }
}
