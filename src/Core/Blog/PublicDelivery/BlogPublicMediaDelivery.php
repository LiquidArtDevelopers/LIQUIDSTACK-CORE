<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicDelivery;

use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\WebAdmin\Media\MediaStorageInterface;

final class BlogPublicMediaDelivery
{
    public function __construct(
        private readonly BlogPublicMediaRepositoryInterface $repository,
        private readonly MediaStorageInterface $storage
    ) {
    }

    public function imageResolver(
        string $localizationPublicId
    ): BlogImageResolverInterface {
        return new BlogPublicImageResolver(
            $this->repository,
            $localizationPublicId
        );
    }

    public function file(
        string $mediaAssetPublicId,
        int $width,
        bool $metadataOnly
    ): ?BlogPublicMediaFile {
        $variant = $this->repository->publishedVariant(
            $mediaAssetPublicId,
            $width
        );
        if ($variant === null) {
            return null;
        }

        return $variant->verifiedFile($this->storage, $metadataOnly);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'repository' => '[redacted]',
            'storage' => '[redacted]',
        ];
    }
}
