<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Authentication;

use InvalidArgumentException;

final class LoginRateLimitPolicy
{
    public const DEFAULT_WINDOW_SECONDS = 900;
    public const DEFAULT_IDENTIFIER_FAILURES = 5;
    public const DEFAULT_IP_FAILURES = 20;
    public const DEFAULT_BLOCK_SECONDS = 900;
    public const DEFAULT_PREAUTH_ISSUE_LIMIT = 60;

    public function __construct(
        private readonly int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
        private readonly int $identifierFailureLimit = self::DEFAULT_IDENTIFIER_FAILURES,
        private readonly int $ipFailureLimit = self::DEFAULT_IP_FAILURES,
        private readonly int $blockSeconds = self::DEFAULT_BLOCK_SECONDS,
        private readonly int $preAuthenticationIssueLimit =
            self::DEFAULT_PREAUTH_ISSUE_LIMIT
    ) {
        if (
            $windowSeconds < 1
            || $windowSeconds > 86400
            || $identifierFailureLimit < 1
            || $identifierFailureLimit > 1000
            || $ipFailureLimit < 1
            || $ipFailureLimit > 10000
            || $blockSeconds < 1
            || $blockSeconds > 86400
            || $preAuthenticationIssueLimit < 1
            || $preAuthenticationIssueLimit > 10000
        ) {
            throw new InvalidArgumentException(
                'Invalid WebAdmin login rate-limit policy.'
            );
        }
    }

    public function windowSeconds(): int
    {
        return $this->windowSeconds;
    }

    public function identifierFailureLimit(): int
    {
        return $this->identifierFailureLimit;
    }

    public function ipFailureLimit(): int
    {
        return $this->ipFailureLimit;
    }

    public function blockSeconds(): int
    {
        return $this->blockSeconds;
    }

    public function preAuthenticationIssueLimit(): int
    {
        return $this->preAuthenticationIssueLimit;
    }
}
