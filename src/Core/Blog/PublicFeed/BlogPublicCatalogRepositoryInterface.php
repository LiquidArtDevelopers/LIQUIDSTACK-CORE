<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\PublishedPostCard;

/** Read-only persistence boundary for the filtered public Blog catalog. */
interface BlogPublicCatalogRepositoryInterface
{
    /** @return list<PublishedPostCard> */
    public function search(BlogPublicCatalogQuery $query): array;
}
