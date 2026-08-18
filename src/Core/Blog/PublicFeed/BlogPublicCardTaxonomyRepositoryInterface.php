<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

/** Optional constant-query capability for category and tag enriched cards. */
interface BlogPublicCardTaxonomyRepositoryInterface extends
    BlogPublicCardCategoryRepositoryInterface
{
    public function taxonomiesForCards(
        BlogPublicCardTaxonomyQuery $query
    ): BlogPublicCardTaxonomyBatch;
}
