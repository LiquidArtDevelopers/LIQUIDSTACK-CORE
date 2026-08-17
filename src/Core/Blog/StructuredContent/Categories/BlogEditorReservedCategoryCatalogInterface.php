<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Categories;

/** Optional projection for internal category policy in the editor. */
interface BlogEditorReservedCategoryCatalogInterface
{
    public function reservedCategoryAssigned(
        string $postPublicId,
        string $locale
    ): bool;
}
