<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use InvalidArgumentException;

final class ProcessedMediaVariant
{
    public function __construct(
        private readonly int $width,
        private readonly int $height,
        private readonly int $bytes,
        private readonly string $sha256,
        private readonly string $fileName
    ) {
        if (
            $width < 1 || $width > 2560
            || $height < 1 || $height > 2560
            || $bytes < 1
            || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1
            || $fileName !== $width . '.avif'
        ) {
            throw new InvalidArgumentException('Invalid processed media variant.');
        }
    }

    public function width(): int { return $this->width; }
    public function height(): int { return $this->height; }
    public function bytes(): int { return $this->bytes; }
    public function sha256(): string { return $this->sha256; }
    public function fileName(): string { return $this->fileName; }
}
