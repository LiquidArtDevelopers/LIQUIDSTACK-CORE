<?php

declare(strict_types=1);

namespace App\Core\Blog\Persistence;

/** Optional bounded batch projection for admin locale actions. */
interface BlogPostLocaleBatchCatalogRepositoryInterface
{
    /**
     * @param list<string> $postPublicIds
     * @return array<string, list<string>>
     */
    public function localesForPosts(
        array $postPublicIds,
        int $limitPerPost
    ): array;
}
