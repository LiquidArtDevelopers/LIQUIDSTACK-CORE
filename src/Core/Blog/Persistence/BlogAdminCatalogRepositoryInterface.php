<?php

declare(strict_types=1);

namespace App\Core\Blog\Persistence;

use App\Core\Blog\Admin\BlogAdminCatalogQuery;
use App\Core\Blog\BlogPostSummary;

interface BlogAdminCatalogRepositoryInterface
{
    /** @return list<BlogPostSummary> */
    public function searchSummaries(BlogAdminCatalogQuery $query): array;
}
