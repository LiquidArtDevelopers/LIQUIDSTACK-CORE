<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Categories;

use App\Core\Blog\StructuredContent\Rendering\BlogEditorCategoryOption;

/** Read-only category choices for one localized structured editor. */
interface BlogEditorCategoryCatalogInterface
{
    /** @return list<BlogEditorCategoryOption> */
    public function forPost(string $postPublicId, string $locale): array;
}
