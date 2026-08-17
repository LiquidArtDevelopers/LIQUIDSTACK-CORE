<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

/** Optional batch capability for category-enriched public cards. */
interface BlogPublicCardCategoryRepositoryInterface
{
    /** @return array<string, list<BlogPublicCardCategory>> */
    public function categoriesForCards(
        BlogPublicCardCategoryQuery $query
    ): array;
}
