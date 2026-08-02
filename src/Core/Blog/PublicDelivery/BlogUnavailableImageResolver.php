<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicDelivery;

use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;

/** Fail-closed resolver used when the optional media boundary is unavailable. */
final class BlogUnavailableImageResolver implements BlogImageResolverInterface
{
    public function resolve(string $mediaAssetPublicId): ?BlogResolvedImage
    {
        return null;
    }
}
