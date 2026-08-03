<?php

declare(strict_types=1);

namespace App\Core\Blog\Sitemap\Cache;

final class BlogSitemapCacheSnapshot
{
    public function __construct(
        private readonly string $xml,
        private readonly string $etag,
        private readonly int $publicRevision,
        private readonly string $generation,
        private readonly string $identityHash,
        private readonly int $createdAt,
        private readonly int $expiresAt
    ) {
        if ($xml === ''
            || strlen($xml) > 50 * 1024 * 1024
            || preg_match('/\A"[0-9a-f]{64}"\z/D', $etag) !== 1
            || $publicRevision < 1
            || preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $generation
            ) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $identityHash) !== 1
            || $createdAt < 1
            || $expiresAt <= $createdAt) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.snapshot_invalid'
            );
        }
    }

    public static function fresh(
        string $xml,
        int $publicRevision,
        string $generation,
        BlogSitemapCacheIdentity $identity,
        int $createdAt,
        int $ttlSeconds
    ): self {
        return new self(
            $xml,
            '"' . hash('sha256', $xml) . '"',
            $publicRevision,
            $generation,
            $identity->hash(),
            $createdAt,
            $createdAt + $ttlSeconds
        );
    }

    public function xml(): string { return $this->xml; }
    public function etag(): string { return $this->etag; }
    public function publicRevision(): int { return $this->publicRevision; }
    public function generation(): string { return $this->generation; }
    public function identityHash(): string { return $this->identityHash; }
    public function createdAt(): int { return $this->createdAt; }
    public function expiresAt(): int { return $this->expiresAt; }
}
