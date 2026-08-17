<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use DateTimeImmutable;

/** Public-safe private catalog projection. Storage and database internals stay out. */
final class MediaPickerItem
{
    private const UUID_V4_PATTERN =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    /** @var list<array{width:int,height:int,bytes:int}> */
    private readonly array $variants;

    /**
     * @param list<array{width:int,height:int,bytes:int}> $variants
     */
    public function __construct(
        private readonly string $publicId,
        private readonly string $label,
        private readonly int $sourceWidth,
        private readonly int $sourceHeight,
        private readonly DateTimeImmutable $createdAt,
        private readonly int $thumbnailWidth,
        array $variants
    ) {
        $characters = preg_match_all('/./us', $label, $matches);
        if (
            preg_match(self::UUID_V4_PATTERN, $publicId) !== 1
            || trim($label) !== $label
            || $label === ''
            || $characters === false
            || $characters > 120
            || preg_match('/[\x00-\x1F\x7F<>]/u', $label) === 1
            || $sourceWidth < 1
            || $sourceWidth > 12_000
            || $sourceHeight < 1
            || $sourceHeight > 12_000
            || $thumbnailWidth < 1
            || $thumbnailWidth > 2_560
            || !array_is_list($variants)
            || $variants === []
        ) {
            throw new MediaException('webadmin.media.picker_item_invalid');
        }
        $normalized = [];
        $previousWidth = 0;
        foreach ($variants as $variant) {
            if (
                !is_array($variant)
                || array_keys($variant) !== ['width', 'height', 'bytes']
                || !is_int($variant['width'])
                || !is_int($variant['height'])
                || !is_int($variant['bytes'])
                || $variant['width'] <= $previousWidth
                || $variant['width'] > 2_560
                || $variant['height'] < 1
                || $variant['height'] > 12_000
                || $variant['bytes'] < 1
            ) {
                throw new MediaException('webadmin.media.picker_item_invalid');
            }
            $normalized[] = $variant;
            $previousWidth = $variant['width'];
        }
        if ($normalized[0]['width'] !== $thumbnailWidth) {
            throw new MediaException('webadmin.media.picker_item_invalid');
        }
        $this->variants = $normalized;
    }

    public function publicId(): string { return $this->publicId; }
    public function label(): string { return $this->label; }
    public function sourceWidth(): int { return $this->sourceWidth; }
    public function sourceHeight(): int { return $this->sourceHeight; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function thumbnailWidth(): int { return $this->thumbnailWidth; }

    /** @return list<array{width:int,height:int,bytes:int}> */
    public function variants(): array { return $this->variants; }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'public_id' => $this->publicId,
            'label' => '[redacted]',
            'source_width' => $this->sourceWidth,
            'source_height' => $this->sourceHeight,
            'thumbnail_width' => $this->thumbnailWidth,
            'variant_count' => count($this->variants),
        ];
    }
}
