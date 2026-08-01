<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Outbox;

use App\Core\WebAdmin\Security\OpaqueSecret;
use LogicException;

/** @internal A process-local delivery lease; neither secret is serializable. */
final class WebAdminOutboxLease
{
    private readonly OpaqueSecret $recipientEmail;

    public function __construct(
        private readonly int $outboxId,
        private readonly int $actionTokenId,
        private readonly int $attempt,
        private readonly string $kind,
        #[\SensitiveParameter] string $recipientEmail,
        private readonly string $locale,
        private readonly OpaqueSecret $leaseToken,
        private readonly OpaqueSecret $actionToken
    ) {
        $this->recipientEmail = OpaqueSecret::fromString($recipientEmail);
    }

    public function outboxId(): int
    {
        return $this->outboxId;
    }

    public function actionTokenId(): int
    {
        return $this->actionTokenId;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function recipientEmail(): string
    {
        return $this->recipientEmail->reveal();
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function revealLeaseToken(): string
    {
        return $this->leaseToken->reveal();
    }

    public function revealActionToken(): string
    {
        return $this->actionToken->reveal();
    }

    /** @return array<string, int|string> */
    public function __debugInfo(): array
    {
        return [
            'outbox_id' => $this->outboxId,
            'action_token_id' => $this->actionTokenId,
            'attempt' => $this->attempt,
            'kind' => $this->kind,
            'recipient' => '[redacted]',
            'lease_token' => '[redacted]',
            'action_token' => '[redacted]',
        ];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new LogicException('WebAdmin outbox leases cannot be serialized.');
    }

    private function __clone()
    {
    }
}
