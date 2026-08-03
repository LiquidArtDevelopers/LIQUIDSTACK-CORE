<?php

declare(strict_types=1);

namespace App\Core\Blog\Sitemap\Persistence;

final class BlogSitemapState
{
    public function __construct(
        private readonly int $publicRevision,
        private readonly ?string $cacheGeneration
    ) {
        if ($publicRevision < 1) {
            throw new BlogSitemapStateException();
        }
        if ($cacheGeneration !== null && preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
            $cacheGeneration
        ) !== 1) {
            throw new BlogSitemapStateException();
        }
    }

    public function publicRevision(): int
    {
        return $this->publicRevision;
    }

    public function cacheGeneration(): ?string
    {
        return $this->cacheGeneration;
    }

    public function cacheIsActive(): bool
    {
        return $this->cacheGeneration !== null;
    }
}
