<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Bootstrap;

use InvalidArgumentException;

final class BootstrapResult
{
    public const COMPLETED = 'completed';
    public const ALREADY_COMPLETED = 'already_completed';

    private function __construct(
        private readonly string $status,
        private readonly int $createdAccounts,
        private readonly int $reconciledAccounts,
        private readonly int $queuedInvites
    ) {
        if (
            !in_array($status, [self::COMPLETED, self::ALREADY_COMPLETED], true)
            || $createdAccounts < 0
            || $reconciledAccounts < 0
            || $queuedInvites < 0
        ) {
            throw new InvalidArgumentException('Invalid bootstrap result.');
        }
    }

    public static function completed(
        int $createdAccounts,
        int $reconciledAccounts,
        int $queuedInvites
    ): self {
        return new self(
            self::COMPLETED,
            $createdAccounts,
            $reconciledAccounts,
            $queuedInvites
        );
    }

    public static function alreadyCompleted(): self
    {
        return new self(self::ALREADY_COMPLETED, 0, 0, 0);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function changed(): bool
    {
        return $this->status === self::COMPLETED;
    }

    public function createdAccounts(): int
    {
        return $this->createdAccounts;
    }

    public function reconciledAccounts(): int
    {
        return $this->reconciledAccounts;
    }

    public function queuedInvites(): int
    {
        return $this->queuedInvites;
    }

    /** @return array<string, int|string|bool> */
    public function toSafeArray(): array
    {
        return [
            'status' => $this->status,
            'changed' => $this->changed(),
            'created_accounts' => $this->createdAccounts,
            'reconciled_accounts' => $this->reconciledAccounts,
            'queued_invites' => $this->queuedInvites,
        ];
    }
}
