<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Blog\Sitemap\Cache\BlogSitemapCacheInitializationResult;

interface BlogSitemapCacheInitCommandRuntimeInterface
{
    public function initialize(): BlogSitemapCacheInitializationResult;
}
