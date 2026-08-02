<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Media;

interface BlogEditorMediaCatalogInterface
{
    /** @return list<BlogEditorMediaAsset> */
    public function recent(int $limit): array;
}
