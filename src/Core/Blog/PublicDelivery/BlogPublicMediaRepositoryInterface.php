<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicDelivery;

use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;

interface BlogPublicMediaRepositoryInterface
{
    public function resolvePublishedImage(
        string $localizationPublicId,
        string $mediaAssetPublicId
    ): ?BlogResolvedImage;

    public function publishedVariant(
        string $mediaAssetPublicId,
        int $width
    ): ?BlogPublicStoredMediaVariant;
}
