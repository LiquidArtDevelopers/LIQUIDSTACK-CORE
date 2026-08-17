<?php

declare(strict_types=1);

namespace App\Core\Blog\Preview;

/** Project-owned bridge to the same Vite/theme entry used by public posts. */
interface BlogPreviewAssetAdapterInterface
{
    public function resolve(BlogPreviewAssetContext $context): BlogPreviewAssetSet;
}
