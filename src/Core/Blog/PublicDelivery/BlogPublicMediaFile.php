<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicDelivery;

use App\Core\WebAdmin\Media\MediaFileMetadata;
use App\Core\WebAdmin\Media\MediaFilePayload;
use App\Core\WebAdmin\Media\MediaStoredVariant;

/** Verified response data. File bytes are deliberately redacted from debug. */
final class BlogPublicMediaFile
{
    private function __construct(
        private readonly ?string $contents,
        private readonly int $width,
        private readonly int $height,
        private readonly int $bytes,
        private readonly string $sha256
    ) {
        if (
            $width < 1 || $height < 1 || $bytes < 1
            || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1
            || ($contents !== null && strlen($contents) !== $bytes)
        ) {
            throw new BlogPublicMediaException();
        }
    }

    public static function fromPayload(
        MediaStoredVariant $variant,
        MediaFilePayload $payload
    ): self {
        if (
            $variant->width() !== $payload->width()
            || $variant->height() !== $payload->height()
            || $variant->bytes() !== $payload->bytes()
        ) {
            throw new BlogPublicMediaException();
        }

        return new self(
            $payload->contents(),
            $payload->width(),
            $payload->height(),
            $payload->bytes(),
            $variant->sha256()
        );
    }

    public static function fromMetadata(
        MediaStoredVariant $variant,
        MediaFileMetadata $metadata
    ): self {
        if (
            $variant->width() !== $metadata->width()
            || $variant->height() !== $metadata->height()
            || $variant->bytes() !== $metadata->bytes()
        ) {
            throw new BlogPublicMediaException();
        }

        return new self(
            null,
            $metadata->width(),
            $metadata->height(),
            $metadata->bytes(),
            $variant->sha256()
        );
    }

    public function contents(): string
    {
        return $this->contents ?? '';
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function etag(): string
    {
        return '"' . $this->sha256 . '"';
    }

    /** @return array<string, int|string> */
    public function __debugInfo(): array
    {
        return [
            'contents' => '[redacted]',
            'width' => $this->width,
            'height' => $this->height,
            'bytes' => $this->bytes,
            'integrity' => '[redacted]',
        ];
    }
}
