<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

final class MediaStoredVariant
{
    public function __construct(
        private readonly string $storageKey,
        private readonly int $width,
        private readonly int $height,
        private readonly int $bytes,
        private readonly string $sha256
    ) {
        if (
            $width < 1 || $height < 1 || $bytes < 1
            || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1
        ) {
            throw new MediaException('webadmin.media.variant_invalid');
        }
    }

    public function storageKey(): string { return $this->storageKey; }
    public function width(): int { return $this->width; }
    public function height(): int { return $this->height; }
    public function bytes(): int { return $this->bytes; }
    public function sha256(): string { return $this->sha256; }
}
