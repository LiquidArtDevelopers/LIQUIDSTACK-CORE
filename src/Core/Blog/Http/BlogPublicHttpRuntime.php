<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\PublicDelivery\BlogPublicMediaDelivery;
use App\Core\Blog\PublicDelivery\BlogPublicMediaFile;
use App\Core\Blog\PublicDelivery\BlogUnavailableImageResolver;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredContentRepositoryInterface;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredDocumentRecord;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;

final class BlogPublicHttpRuntime
{
    public function __construct(
        private readonly BlogConfig $config,
        private readonly BlogPublicOrigin $origin,
        private readonly BlogService $service,
        private readonly ?BlogStructuredContentRepositoryInterface
            $structuredContent = null,
        private readonly ?BlogPublicMediaDelivery $mediaDelivery = null
    ) {
    }

    public function config(): BlogConfig
    {
        return $this->config;
    }

    public function origin(): BlogPublicOrigin
    {
        return $this->origin;
    }

    public function service(): BlogService
    {
        return $this->service;
    }

    public function structuredDocument(
        string $localizationPublicId
    ): ?BlogStructuredDocumentRecord {
        return $this->structuredContent?->current($localizationPublicId);
    }

    public function imageResolver(
        string $localizationPublicId
    ): BlogImageResolverInterface {
        return $this->mediaDelivery?->imageResolver($localizationPublicId)
            ?? new BlogUnavailableImageResolver();
    }

    public function mediaFile(
        string $mediaAssetPublicId,
        int $width,
        bool $metadataOnly
    ): ?BlogPublicMediaFile {
        return $this->mediaDelivery?->file(
            $mediaAssetPublicId,
            $width,
            $metadataOnly
        );
    }

    /** @return array<string, string|bool> */
    public function __debugInfo(): array
    {
        return [
            'config' => '[redacted]',
            'origin' => '[redacted]',
            'structured_content' => $this->structuredContent !== null,
            'public_media' => $this->mediaDelivery !== null,
        ];
    }
}
