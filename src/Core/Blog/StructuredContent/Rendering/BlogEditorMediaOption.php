<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use InvalidArgumentException;

/** Safe, presentation-only media choice for one image block. */
final class BlogEditorMediaOption
{
    private const MAX_THUMBNAIL_WIDTH = 2560;

    private const UUID_V4_PATTERN =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    public function __construct(
        private readonly string $publicId,
        private readonly string $label,
        private readonly ?string $thumbnailUrl = null
    ) {
        $characters = preg_match_all('/./us', $label, $matches);
        if (
            preg_match(self::UUID_V4_PATTERN, $publicId) !== 1
            || trim($label) !== $label
            || $label === ''
            || $characters === false
            || $characters > 120
            || preg_match('/[\x00-\x1F\x7F<>]/u', $label) === 1
            || !$this->validThumbnailUrl($thumbnailUrl, $publicId)
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog editor media option.'
            );
        }
    }

    public function publicId(): string
    {
        return $this->publicId;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * Same-origin, authenticated WebAdmin media URL, when available.
     *
     * The URL remains raw for transport and must still be escaped for its
     * output context by the renderer.
     */
    public function thumbnailUrl(): ?string
    {
        return $this->thumbnailUrl;
    }

    private function validThumbnailUrl(
        ?string $thumbnailUrl,
        string $publicId
    ): bool {
        if ($thumbnailUrl === null) {
            return true;
        }
        if (
            strlen($thumbnailUrl) > 2048
            || preg_match('/[\x00-\x20\x7F]/', $thumbnailUrl) === 1
        ) {
            return false;
        }
        $matched = preg_match(
            '#\A(?:/[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+/media/file'
                . '\?asset=' . preg_quote($publicId, '#')
                . '&width=([1-9][0-9]{0,3})\z#',
            $thumbnailUrl,
            $matches
        );

        return $matched === 1
            && isset($matches[1])
            && (int) $matches[1] <= self::MAX_THUMBNAIL_WIDTH;
    }
}
