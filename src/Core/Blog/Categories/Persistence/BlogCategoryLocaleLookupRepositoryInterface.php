<?php

declare(strict_types=1);

namespace App\Core\Blog\Categories\Persistence;

/** Optional optimized read model for the localized category aggregate. */
interface BlogCategoryLocaleLookupRepositoryInterface
{
    /** @return null|list<string> */
    public function categoryLocales(string $categoryPublicId): ?array;
}
