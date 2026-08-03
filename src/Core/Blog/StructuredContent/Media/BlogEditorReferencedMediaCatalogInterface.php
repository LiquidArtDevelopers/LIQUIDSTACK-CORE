<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Media;

/** Optional catalog capability that preserves current document references. */
interface BlogEditorReferencedMediaCatalogInterface extends
    BlogEditorMediaCatalogInterface
{
    /**
     * Returns the bounded recent catalog plus every requested existing asset.
     *
     * @param list<string> $requiredPublicIds
     * @return list<BlogEditorMediaAsset>
     */
    public function recentIncluding(
        int $limit,
        array $requiredPublicIds
    ): array;
}
