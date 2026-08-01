<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\CredentialAction;

use InvalidArgumentException;

final class CredentialActionRateLimitPolicy
{
    public const DEFAULT_WINDOW_SECONDS = 3600;
    public const DEFAULT_IDENTIFIER_REQUESTS = 3;
    public const DEFAULT_IP_REQUESTS = 20;
    public const DEFAULT_BLOCK_SECONDS = 3600;

    public function __construct(
        private readonly int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
        private readonly int $identifierRequestLimit =
            self::DEFAULT_IDENTIFIER_REQUESTS,
        private readonly int $ipRequestLimit = self::DEFAULT_IP_REQUESTS,
        private readonly int $blockSeconds = self::DEFAULT_BLOCK_SECONDS
    ) {
        if (
            $windowSeconds < 1
            || $windowSeconds > 86400
            || $identifierRequestLimit < 1
            || $identifierRequestLimit > 1000
            || $ipRequestLimit < 1
            || $ipRequestLimit > 10000
            || $blockSeconds < 1
            || $blockSeconds > 86400
        ) {
            throw new InvalidArgumentException(
                'Invalid WebAdmin credential-action rate-limit policy.'
            );
        }
    }

    public function windowSeconds(): int
    {
        return $this->windowSeconds;
    }

    public function identifierRequestLimit(): int
    {
        return $this->identifierRequestLimit;
    }

    public function ipRequestLimit(): int
    {
        return $this->ipRequestLimit;
    }

    public function blockSeconds(): int
    {
        return $this->blockSeconds;
    }
}
