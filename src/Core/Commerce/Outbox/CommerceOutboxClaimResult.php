<?php

declare(strict_types=1);

namespace App\Core\Commerce\Outbox;

use LogicException;

final class CommerceOutboxClaimResult
{
    private function __construct(
        private readonly string $status,
        private readonly ?CommerceOutboxLease $lease = null
    ) {
    }

    public static function none(): self { return new self('none'); }
    public static function terminalFailure(): self { return new self('terminal'); }
    public static function claimed(CommerceOutboxLease $lease): self
    {
        return new self('claimed', $lease);
    }

    public function isNone(): bool { return $this->status === 'none'; }
    public function isTerminalFailure(): bool { return $this->status === 'terminal'; }

    public function lease(): CommerceOutboxLease
    {
        if (!$this->lease instanceof CommerceOutboxLease) {
            throw new LogicException('The Commerce outbox result has no lease.');
        }

        return $this->lease;
    }
}
