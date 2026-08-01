<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\UserManagement;

use InvalidArgumentException;

final class EditorMutationResult
{
    public const APPLIED = 'applied';
    public const UNCHANGED = 'unchanged';
    public const ALREADY_QUEUED = 'already_queued';
    public const DENIED = 'denied';
    public const NOT_FOUND = 'not_found';
    public const INVALID = 'invalid';
    public const STATE_CONFLICT = 'state_conflict';

    private const OPERATIONS = [
        'replace_capabilities',
        'resend_invitation',
        'suspend',
        'resume',
    ];

    public function __construct(
        private readonly string $operation,
        private readonly string $status,
        private readonly ?string $targetPublicId = null,
        private readonly int $affectedCapabilities = 0
    ) {
        if (
            !in_array($operation, self::OPERATIONS, true)
            || !in_array($status, [
                self::APPLIED,
                self::UNCHANGED,
                self::ALREADY_QUEUED,
                self::DENIED,
                self::NOT_FOUND,
                self::INVALID,
                self::STATE_CONFLICT,
            ], true)
            || $affectedCapabilities < 0
        ) {
            throw new InvalidArgumentException('Invalid editor mutation result.');
        }
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function changed(): bool
    {
        return $this->status === self::APPLIED;
    }

    public function targetPublicId(): ?string
    {
        return $this->targetPublicId;
    }

    public function affectedCapabilities(): int
    {
        return $this->affectedCapabilities;
    }
}
