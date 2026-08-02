<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use InvalidArgumentException;

final class ProcessedMediaUpload
{
    /** @var list<ProcessedMediaVariant> */
    private readonly array $variants;

    /** @param list<ProcessedMediaVariant> $variants */
    public function __construct(
        private readonly string $sourceMime,
        private readonly int $sourceWidth,
        private readonly int $sourceHeight,
        private readonly int $sourceBytes,
        private readonly string $sourceSha256,
        array $variants
    ) {
        if (
            !in_array($sourceMime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $sourceWidth < 1 || $sourceWidth > 12000
            || $sourceHeight < 1 || $sourceHeight > 12000
            || ($sourceWidth * $sourceHeight) > 40_000_000
            || $sourceBytes < 1 || $sourceBytes > 12_582_912
            || preg_match('/\A[a-f0-9]{64}\z/', $sourceSha256) !== 1
            || $variants === []
            || !array_is_list($variants)
        ) {
            throw new InvalidArgumentException('Invalid processed media upload.');
        }
        $seen = [];
        foreach ($variants as $variant) {
            if (
                !$variant instanceof ProcessedMediaVariant
                || isset($seen[$variant->width()])
            ) {
                throw new InvalidArgumentException('Invalid media variant set.');
            }
            $seen[$variant->width()] = true;
        }
        ksort($seen, SORT_NUMERIC);
        $ordered = [];
        foreach (array_keys($seen) as $width) {
            foreach ($variants as $variant) {
                if ($variant->width() === $width) {
                    $ordered[] = $variant;
                    break;
                }
            }
        }
        $this->variants = $ordered;
    }

    public function sourceMime(): string { return $this->sourceMime; }
    public function sourceWidth(): int { return $this->sourceWidth; }
    public function sourceHeight(): int { return $this->sourceHeight; }
    public function sourceBytes(): int { return $this->sourceBytes; }
    public function sourceSha256(): string { return $this->sourceSha256; }
    /** @return list<ProcessedMediaVariant> */
    public function variants(): array { return $this->variants; }
    public function variantBytes(): int
    {
        return array_sum(array_map(
            static fn (ProcessedMediaVariant $variant): int => $variant->bytes(),
            $this->variants
        ));
    }
}
