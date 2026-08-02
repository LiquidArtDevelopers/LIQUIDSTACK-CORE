<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

final class MediaFilePayload
{
    public function __construct(
        private readonly string $contents,
        private readonly int $width,
        private readonly int $height
    ) {
    }

    public function contents(): string { return $this->contents; }
    public function width(): int { return $this->width; }
    public function height(): int { return $this->height; }
    public function bytes(): int { return strlen($this->contents); }
}
