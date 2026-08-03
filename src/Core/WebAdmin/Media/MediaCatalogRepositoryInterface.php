<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

/** Optional bounded catalog lookup for feature-owned media selectors. */
interface MediaCatalogRepositoryInterface extends MediaRepositoryInterface
{
    public const MAX_LOOKUP_ITEMS = 200;

    /**
     * @param list<string> $publicIds
     * @return list<MediaCatalogAsset>
     */
    public function catalogAssetsByPublicIds(array $publicIds): array;
}
