<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

/** One stable, bounded page in the reusable media picker. */
final class MediaPickerPage
{
    /** @param list<MediaPickerItem> $items */
    public function __construct(
        private readonly MediaPickerQuery $query,
        private readonly array $items,
        private readonly bool $hasNext
    ) {
        if (!array_is_list($items)) {
            throw new MediaException('webadmin.media.picker_page_invalid');
        }
        foreach ($items as $item) {
            if (!$item instanceof MediaPickerItem) {
                throw new MediaException('webadmin.media.picker_page_invalid');
            }
        }
        if (count($items) > $query->pageSize()) {
            throw new MediaException('webadmin.media.picker_page_invalid');
        }
    }

    public function query(): MediaPickerQuery { return $this->query; }

    /** @return list<MediaPickerItem> */
    public function items(): array { return $this->items; }

    public function hasNext(): bool { return $this->hasNext; }
    public function hasPrevious(): bool { return $this->query->page() > 1; }
}
