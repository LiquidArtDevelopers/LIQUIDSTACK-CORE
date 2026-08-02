<?php

declare(strict_types=1);

namespace App\Core\Blog\Persistence;

use App\Core\Blog\BlogSitemapEntry;

/**
 * Optional read-model extension for published locale equivalents.
 *
 * Keeping this separate from BlogRepositoryInterface preserves compatibility
 * with existing repository adapters while the canonical PDO adapter exposes
 * the richer public SEO projection.
 */
interface BlogPublishedSitemapRepositoryInterface
{
    /** @return list<BlogSitemapEntry> */
    public function publishedSitemapEntriesForPost(
        string $postPublicId,
        int $limit
    ): array;
}
