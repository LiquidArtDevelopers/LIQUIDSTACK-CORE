<?php

declare(strict_types=1);

namespace App\Core\Blog\Sitemap;

use App\Core\Blog\Sitemap\Cache\BlogSitemapCacheLease;
use App\Core\Blog\Sitemap\Persistence\BlogSitemapState;

final class BlogSitemapPublicationFence
{
    public function __construct(
        private readonly BlogSitemapState $state,
        private readonly BlogSitemapCacheLease $lease
    ) {
    }

    public function state(): BlogSitemapState
    {
        return $this->state;
    }

    public function release(): void
    {
        $this->lease->release();
    }
}
