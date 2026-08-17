<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

/** Narrow public read port required by reusable collection composition. */
interface BlogPublicCollectionFeedInterface
{
    /** @return list<array<string, mixed>> */
    public function cardsForResource(BlogPublicResourceQuery $query): array;
}
