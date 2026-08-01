<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Outbox;

use InvalidArgumentException;

final class WebAdminOutboxDispatchReport
{
    public function __construct(
        private readonly int $examined,
        private readonly int $claimed,
        private readonly int $sent,
        private readonly int $retryScheduled,
        private readonly int $permanentlyFailed,
        private readonly int $fenced
    ) {
        $counts = [
            $examined,
            $claimed,
            $sent,
            $retryScheduled,
            $permanentlyFailed,
            $fenced,
        ];
        if (
            min($counts) < 0
            || $claimed > $examined
            || $sent + $retryScheduled + $permanentlyFailed + $fenced
                !== $examined
        ) {
            throw new InvalidArgumentException(
                'Invalid WebAdmin outbox dispatch report.'
            );
        }
    }

    public function examined(): int
    {
        return $this->examined;
    }

    public function claimed(): int
    {
        return $this->claimed;
    }

    public function sent(): int
    {
        return $this->sent;
    }

    public function retryScheduled(): int
    {
        return $this->retryScheduled;
    }

    public function permanentlyFailed(): int
    {
        return $this->permanentlyFailed;
    }

    public function fenced(): int
    {
        return $this->fenced;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'examined' => $this->examined,
            'claimed' => $this->claimed,
            'sent' => $this->sent,
            'retry_scheduled' => $this->retryScheduled,
            'permanently_failed' => $this->permanentlyFailed,
            'fenced' => $this->fenced,
        ];
    }
}
