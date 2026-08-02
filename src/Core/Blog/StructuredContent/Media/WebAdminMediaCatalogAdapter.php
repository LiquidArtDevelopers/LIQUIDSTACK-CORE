<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Media;

use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\WebAdmin\Media\MediaException;
use App\Core\WebAdmin\Media\MediaRepositoryInterface;
use Throwable;

/** Adapts the private WebAdmin library without exposing its persistence API. */
final class WebAdminMediaCatalogAdapter implements
    BlogEditorMediaCatalogInterface
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
                if (!is_string($publicId) || !is_string($label)) {
                    throw new BlogStructuredContentException(
                        BlogStructuredContentException::MEDIA_UNAVAILABLE
                    );
                }
                $result[] = new BlogEditorMediaAsset($publicId, $label);
            }

            return $result;
        } catch (BlogStructuredContentException $exception) {
            throw $exception;
        } catch (MediaException|Throwable) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::MEDIA_UNAVAILABLE
            );
        }
    }
}
