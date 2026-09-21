<?php

declare(strict_types=1);

namespace App\Core\Commerce\Outbox;

use App\Core\WebAdmin\Security\OpaqueSecret;
use LogicException;

final class CommerceOutboxLease
{
    private readonly OpaqueSecret $recipientEmail;
    private readonly OpaqueSecret $payloadJson;
    private readonly OpaqueSecret $lockToken;

    public function __construct(
        private readonly int $id,
        private readonly int $attempt,
        private readonly string $audience,
        private readonly string $templateKey,
        string $recipientEmail,
        string $payloadJson,
        string $lockToken
    ) {
        $this->recipientEmail = OpaqueSecret::fromString($recipientEmail);
        $this->payloadJson = OpaqueSecret::fromString($payloadJson);
        $this->lockToken = OpaqueSecret::fromString($lockToken);
    }

    public function id(): int { return $this->id; }
    public function attempt(): int { return $this->attempt; }
    public function audience(): string { return $this->audience; }
    public function templateKey(): string { return $this->templateKey; }
    public function recipientEmail(): string { return $this->recipientEmail->reveal(); }
    public function payloadJson(): string { return $this->payloadJson->reveal(); }
    public function lockToken(): string { return $this->lockToken->reveal(); }

    /** @return array<string, string|int> */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'attempt' => $this->attempt,
            'audience' => $this->audience,
            'template' => $this->templateKey,
            'recipient' => '[redacted]',
            'payload' => '[redacted]',
            'lock_token' => '[redacted]',
        ];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new LogicException('Commerce outbox leases cannot be serialized.');
    }
}
