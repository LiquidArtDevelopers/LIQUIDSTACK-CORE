<?php

declare(strict_types=1);

namespace App\Core\Blog\Configuration;

final class BlogAnalyticsConfig
{
    public const DEFAULT_RETENTION_DAYS = 90;
    public const MIN_RETENTION_DAYS = 30;
    public const MAX_RETENTION_DAYS = 400;
    public const DEFAULT_SESSION_TIMEOUT_SECONDS = 1800;
    public const MIN_SESSION_TIMEOUT_SECONDS = 300;
    public const MAX_SESSION_TIMEOUT_SECONDS = 28_800;

    public function __construct(
        private readonly bool $enabled = false,
        private readonly int $retentionDays = self::DEFAULT_RETENTION_DAYS,
        private readonly int $sessionTimeoutSeconds =
            self::DEFAULT_SESSION_TIMEOUT_SECONDS,
        private readonly bool $collectInDevelopment = false
    ) {
        if (
            $retentionDays < self::MIN_RETENTION_DAYS
            || $retentionDays > self::MAX_RETENTION_DAYS
        ) {
            throw new BlogConfigException(
                'config.analytics_retention_invalid',
                'analytics.retention_days'
            );
        }
        if (
            $sessionTimeoutSeconds < self::MIN_SESSION_TIMEOUT_SECONDS
            || $sessionTimeoutSeconds > self::MAX_SESSION_TIMEOUT_SECONDS
        ) {
            throw new BlogConfigException(
                'config.analytics_session_timeout_invalid',
                'analytics.session_timeout_seconds'
            );
        }
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function retentionDays(): int
    {
        return $this->retentionDays;
    }

    public function sessionTimeoutSeconds(): int
    {
        return $this->sessionTimeoutSeconds;
    }

    public function collectInDevelopment(): bool
    {
        return $this->collectInDevelopment;
    }

    /** @return array<string, bool|int> */
    public function toSafeArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'retention_days' => $this->retentionDays,
            'session_timeout_seconds' => $this->sessionTimeoutSeconds,
            'collect_in_dev' => $this->collectInDevelopment,
        ];
    }
}
