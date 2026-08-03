<?php

declare(strict_types=1);

namespace App\Core\Blog\Sitemap\Cache;

final class BlogSitemapCacheLease
{
    /** @param resource $handle */
    public function __construct(
        private $handle,
        private readonly bool $exclusive
    ) {
        if (!is_resource($handle)) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.lock_failed'
            );
        }
    }

    public function isExclusive(): bool
    {
        return $this->exclusive;
    }

    public function isActive(): bool
    {
        return is_resource($this->handle);
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }
        @flock($this->handle, LOCK_UN);
        @fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
