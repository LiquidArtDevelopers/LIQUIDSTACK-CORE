<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\Seo\BlogSeoCatalogProjectionService;

/** Optional batch projection used by the private Blog catalog. */
interface BlogSeoCatalogHttpRuntimeInterface
{
    public function seoCatalog(): BlogSeoCatalogProjectionService;
}
