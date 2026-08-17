<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicIndex;

use App\Core\Blog\PublicFeed\BlogPublicArchivePeriodsQuery;
use App\Core\Blog\PublicFeed\BlogPublicArchiveQuery;
use App\Core\Blog\PublicFeed\BlogPublicCatalogQuery;

/** Narrow public read port required by the index composition service. */
interface BlogPublicIndexFeedInterface
{
    /** @return list<array{locale:string,slug:string,name:string,count:int}> */
    public function filters(string $locale): array;

    /** @return list<array<string, mixed>> */
    public function cardsForQuery(BlogPublicCatalogQuery $query): array;

    /** @return list<array<string, mixed>> */
    public function cardsForArchive(BlogPublicArchiveQuery $query): array;

    /** @return list<array{locale:string,year:int,month:int,count:int}> */
    public function archivePeriods(
        BlogPublicArchivePeriodsQuery $query
    ): array;
}
