<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\PublishedPostCard;

/** Read-only persistence boundary for related posts and date archives. */
interface BlogPublicDiscoveryRepositoryInterface
{
    /** @return list<PublishedPostCard> */
    public function relatedPosts(BlogPublicRelatedQuery $query): array;

    /** @return list<PublishedPostCard> */
    public function archivePosts(BlogPublicArchiveQuery $query): array;

    /** @return list<BlogPublicArchivePeriod> */
    public function archivePeriods(BlogPublicArchivePeriodsQuery $query): array;
}
