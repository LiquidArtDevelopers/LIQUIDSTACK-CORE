<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use InvalidArgumentException;

final class MediaAssetPage
{
    /**
     * @param list<array{
     *   public_id: string,label: string,source_width: int,source_height: int,
     *   created_at: string,thumbnail_width: int
     * }> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly int $page,
        private readonly bool $hasNext
    ) {
        if (!array_is_list($items) || $page < 1) {
            throw new InvalidArgumentException('Invalid media page.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function items(): array { return $this->items; }
    public function page(): int { return $this->page; }
    public function hasNext(): bool { return $this->hasNext; }
}
