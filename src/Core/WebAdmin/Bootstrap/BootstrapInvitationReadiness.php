<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Bootstrap;

use InvalidArgumentException;

final class BootstrapInvitationReadiness
{
    public const EXPECTED_IDENTITIES = 2;

    public function __construct(
        private readonly int $activeIdentities,
        private readonly int $deliveredInvitations,
        private readonly int $incompleteIdentities
    ) {
        if (
            min(
                $activeIdentities,
                $deliveredInvitations,
                $incompleteIdentities
            ) < 0
            || $activeIdentities
                + $deliveredInvitations
                + $incompleteIdentities !== self::EXPECTED_IDENTITIES
        ) {
            throw new InvalidArgumentException(
                'Invalid WebAdmin bootstrap invitation readiness.'
            );
        }
    }

    public function isReady(): bool
    {
        return $this->incompleteIdentities === 0;
    }

    public function activeIdentities(): int
    {
        return $this->activeIdentities;
    }

    public function deliveredInvitations(): int
    {
        return $this->deliveredInvitations;
    }

    public function incompleteIdentities(): int
    {
        return $this->incompleteIdentities;
    }

    /** @return array<string, int|bool> */
    public function toSafeArray(): array
    {
        return [
            'ready' => $this->isReady(),
            'expected_identities' => self::EXPECTED_IDENTITIES,
            'active_identities' => $this->activeIdentities,
            'delivered_invitations' => $this->deliveredInvitations,
            'incomplete_identities' => $this->incompleteIdentities,
        ];
    }
}
