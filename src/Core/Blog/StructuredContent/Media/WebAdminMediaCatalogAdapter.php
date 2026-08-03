<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Media;

use App\Core\Blog\BlogInput;
use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\WebAdmin\Media\MediaCatalogAsset;
use App\Core\WebAdmin\Media\MediaCatalogRepositoryInterface;
use App\Core\WebAdmin\Media\MediaRepositoryInterface;
use Throwable;

/** Adapts the private WebAdmin library without exposing its persistence API. */
final class WebAdminMediaCatalogAdapter implements
    BlogEditorReferencedMediaCatalogInterface
{
    public const MAX_ITEMS = 48;

    public function __construct(
        private readonly MediaRepositoryInterface $repository
    ) {
    }

    public function recent(int $limit): array
    {
        if ($limit < 1 || $limit > self::MAX_ITEMS) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::INVALID_INPUT
            );
        }

        try {
            $items = $this->repository->listPage(1, $limit)->items();
            $result = [];
            foreach ($items as $item) {
                $publicId = $item['public_id'] ?? null;
                $label = $item['label'] ?? null;
                $thumbnailWidth = $item['thumbnail_width'] ?? null;
                if (
                    !is_string($publicId)
                    || !is_string($label)
                    || !is_int($thumbnailWidth)
                ) {
                    throw new BlogStructuredContentException(
                        BlogStructuredContentException::MEDIA_UNAVAILABLE
                    );
                }
                $result[] = new BlogEditorMediaAsset(
                    $publicId,
                    $label,
                    $thumbnailWidth
                );
            }

            return $result;
        } catch (BlogStructuredContentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::MEDIA_UNAVAILABLE
            );
        }
    }

    public function recentIncluding(
        int $limit,
        array $requiredPublicIds
    ): array {
        if (
            !array_is_list($requiredPublicIds)
            || count($requiredPublicIds)
                > MediaCatalogRepositoryInterface::MAX_LOOKUP_ITEMS
        ) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::INVALID_INPUT
            );
        }

        $required = [];
        try {
            foreach ($requiredPublicIds as $publicId) {
                if (!is_string($publicId)) {
                    throw new BlogStructuredContentException(
                        BlogStructuredContentException::INVALID_INPUT
                    );
                }
                $publicId = BlogInput::generatedPublicId($publicId);
                $required[$publicId] = true;
            }
        } catch (BlogStructuredContentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::INVALID_INPUT
            );
        }

        $result = $this->recent($limit);
        $included = [];
        foreach ($result as $asset) {
            $included[$asset->publicId()] = true;
        }

        $missing = [];
        foreach (array_keys($required) as $publicId) {
            if (!isset($included[$publicId])) {
                $missing[] = $publicId;
            }
        }
        if ($missing === []) {
            return $result;
        }

        if (!$this->repository instanceof MediaCatalogRepositoryInterface) {
            return $result;
        }

        try {
            $resolved = $this->repository->catalogAssetsByPublicIds($missing);
            if (!array_is_list($resolved) || count($resolved) !== count($missing)) {
                throw new BlogStructuredContentException(
                    BlogStructuredContentException::MEDIA_UNAVAILABLE
                );
            }

            $byPublicId = [];
            foreach ($resolved as $asset) {
                if (
                    !$asset instanceof MediaCatalogAsset
                    || isset($byPublicId[$asset->publicId()])
                    || !isset($required[$asset->publicId()])
                ) {
                    throw new BlogStructuredContentException(
                        BlogStructuredContentException::MEDIA_UNAVAILABLE
                    );
                }
                $byPublicId[$asset->publicId()] = $asset;
            }

            foreach ($missing as $publicId) {
                $asset = $byPublicId[$publicId] ?? null;
                if (!$asset instanceof MediaCatalogAsset) {
                    throw new BlogStructuredContentException(
                        BlogStructuredContentException::MEDIA_UNAVAILABLE
                    );
                }
                $result[] = new BlogEditorMediaAsset(
                    $asset->publicId(),
                    $asset->label(),
                    $asset->thumbnailWidth()
                );
            }

            return $result;
        } catch (BlogStructuredContentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::MEDIA_UNAVAILABLE
            );
        }
    }
}
