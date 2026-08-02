<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

/** Resolves a media UUID without exposing persistence or raw HTML to the renderer. */
interface BlogImageResolverInterface
{
    public function resolve(string $mediaAssetPublicId): ?BlogResolvedImage;
}
