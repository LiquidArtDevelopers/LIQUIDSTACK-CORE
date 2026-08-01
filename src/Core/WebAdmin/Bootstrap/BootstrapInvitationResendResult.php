<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Bootstrap;

use InvalidArgumentException;

final class BootstrapInvitationResendResult
{
    public function __construct(
        private readonly int $queuedInvites,
        private readonly int $skippedIdentities
    ) {
        if ($queuedInvites < 0 || $skippedIdentities < 0) {
            throw new InvalidArgumentException(
                'Invalid bootstrap invitation resend result.'
            );
        }
    }

    public function queuedInvites(): int
    {
        return $this->queuedInvites;
    }

    public function skippedIdentities(): int
    {
        return $this->skippedIdentities;
    }

    public function changed(): bool
    {
        return $this->queuedInvites > 0;
    }

    /** @return array{changed: bool, queued_invites: int, skipped_identities: int} */
    public function toSafeArray(): array
    {
        return [
            'changed' => $this->changed(),
            'queued_invites' => $this->queuedInvites,
            'skipped_identities' => $this->skippedIdentities,
        ];
    }
}
