<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

/** Bounded private-library projection used by feature-owned selectors. */
final class MediaCatalogAsset
{
    private const UUID_V4_PATTERN =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    public function __construct(
        private readonly string $publicId,
        private readonly string $label,
        private readonly int $thumbnailWidth
    ) {
        $characters = preg_match_all('/./us', $label, $matches);
        if (
            preg_match(self::UUID_V4_PATTERN, $publicId) !== 1
            || $label !== trim($label)
            || $label === ''
            || $characters === false
            || $characters > 120
            || preg_match('/[\x00-\x1F\x7F<>]/u', $label) === 1
            || $thumbnailWidth < 1
            || $thumbnailWidth > 2560
        ) {
            throw new MediaException('webadmin.media.catalog_asset_invalid');
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

    public function thumbnailWidth(): int
    {
        return $this->thumbnailWidth;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'public_id' => $this->publicId,
            'label' => '[redacted]',
            'thumbnail_width' => $this->thumbnailWidth,
        ];
    }
}
