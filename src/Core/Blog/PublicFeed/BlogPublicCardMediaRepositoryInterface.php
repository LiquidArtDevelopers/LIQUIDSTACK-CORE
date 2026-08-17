<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

/** Optional constant-query capability for responsive public card media. */
interface BlogPublicCardMediaRepositoryInterface
{
    /** @return array<string, BlogPublicCardThumbnail> */
    public function thumbnailsForCards(
        BlogPublicCardMediaQuery $query
    ): array;
}
