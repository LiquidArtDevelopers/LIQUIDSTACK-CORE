<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Outbox;

use LogicException;

/** @internal Result of examining one bounded outbox candidate. */
final class WebAdminOutboxClaimResult
{
    private const NONE = 'none';
    private const CLAIMED = 'claimed';
    private const TERMINAL_FAILURE = 'terminal_failure';

    private function __construct(
        private readonly string $status,
        private readonly ?WebAdminOutboxLease $lease
    ) {
    }

    public static function none(): self
    {
        return new self(self::NONE, null);
    }

    public static function claimed(WebAdminOutboxLease $lease): self
    {
        return new self(self::CLAIMED, $lease);
    }

    public static function terminalFailure(): self
    {
        return new self(self::TERMINAL_FAILURE, null);
    }

    public function isNone(): bool
    {
        return $this->status === self::NONE;
    }

    public function isTerminalFailure(): bool
    {
        return $this->status === self::TERMINAL_FAILURE;
    }

    public function lease(): WebAdminOutboxLease
    {
        if (!$this->lease instanceof WebAdminOutboxLease) {
            throw new LogicException('The outbox candidate has no lease.');
        }

        return $this->lease;
    }
}
