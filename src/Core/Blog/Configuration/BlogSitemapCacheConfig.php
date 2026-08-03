<?php

declare(strict_types=1);

namespace App\Core\Blog\Configuration;

/** Project-owned, non-secret opt-in policy for the private sitemap cache. */
final class BlogSitemapCacheConfig
{
    public const DEFAULT_TTL_SECONDS = 300;
    public const MIN_TTL_SECONDS = 30;
    public const MAX_TTL_SECONDS = 3600;

    public function __construct(
        private readonly bool $enabled = false,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ) {
        if (
            $ttlSeconds < self::MIN_TTL_SECONDS
            || $ttlSeconds > self::MAX_TTL_SECONDS
        ) {
            throw new BlogConfigException(
                'config.sitemap_cache_ttl_invalid',
                'sitemap_cache.ttl_seconds'
            );
        }
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    /** @return array{enabled: bool, ttl_seconds: int} */
    public function toSafeArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'ttl_seconds' => $this->ttlSeconds,
        ];
    }
}
