<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

final class MediaFileMetadata
{
    public function __construct(
        private readonly int $width,
        private readonly int $height,
        private readonly int $bytes
    ) {
        if ($width < 1 || $height < 1 || $bytes < 1) {
            throw new MediaException('webadmin.media.file_integrity_failed');
        }
    }

    public function width(): int { return $this->width; }
    public function height(): int { return $this->height; }
    public function bytes(): int { return $this->bytes; }
}
