<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

/** Optional private picker capability without widening feature-owned catalogs. */
interface MediaPickerCatalogRepositoryInterface extends
    MediaCatalogRepositoryInterface
{
    public function pickerPage(MediaPickerQuery $query): MediaPickerPage;
}
