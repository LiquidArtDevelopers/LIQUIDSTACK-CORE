<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicDelivery;

use App\Core\Blog\BlogInput;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;

/** Request-local resolver with a bounded cache for repeated image blocks. */
final class BlogPublicImageResolver implements BlogImageResolverInterface
{
    /** @var array<string, BlogResolvedImage|null> */
    private array $cache = [];
    private readonly string $localizationPublicId;

    public function __construct(
        private readonly BlogPublicMediaRepositoryInterface $repository,
        string $localizationPublicId
    ) {
        try {
            $this->localizationPublicId = BlogInput::publicId(
                $localizationPublicId
            );
        } catch (\Throwable) {
            throw new BlogPublicMediaException();
        }
    }

    public function resolve(string $mediaAssetPublicId): ?BlogResolvedImage
    {
        if (!array_key_exists($mediaAssetPublicId, $this->cache)) {
            $this->cache[$mediaAssetPublicId] =
                $this->repository->resolvePublishedImage(
                    $this->localizationPublicId,
                    $mediaAssetPublicId
                );
        }

        return $this->cache[$mediaAssetPublicId];
    }

    /** @return array<string, string|int> */
    public function __debugInfo(): array
    {
        return [
            'scope' => '[redacted]',
            'cached_assets' => count($this->cache),
        ];
    }
}
