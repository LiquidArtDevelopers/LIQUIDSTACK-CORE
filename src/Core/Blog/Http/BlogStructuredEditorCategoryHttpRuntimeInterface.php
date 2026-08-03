<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\StructuredContent\Categories\BlogEditorCategoryCatalogInterface;

/** Additive runtime capability for localized editor category choices. */
interface BlogStructuredEditorCategoryHttpRuntimeInterface extends
    BlogStructuredEditorHttpRuntimeInterface
{
    public function editorCategoryCatalog(): ?BlogEditorCategoryCatalogInterface;
}
