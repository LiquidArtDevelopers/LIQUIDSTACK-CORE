<?php

declare(strict_types=1);

namespace App\Core\Commerce\Media;

use App\Core\WebAdmin\Media\MediaFileMetadata;
use App\Core\WebAdmin\Media\MediaFilePayload;
use App\Core\WebAdmin\Media\MediaStoredVariant;

/** Verified response data; file bytes and integrity are redacted from debug. */
final class CommercePublicMediaFile
{
    private function __construct(
        private readonly ?string $contents,
        private readonly int $bytes,
        private readonly string $sha256
    ) {
        if (
            $bytes < 1
            || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1
            || ($contents !== null && strlen($contents) !== $bytes)
        ) {
            throw new CommercePublicMediaException();
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
            throw new CommercePublicMediaException();
        }

        return new self(
            $payload->contents(),
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
            throw new CommercePublicMediaException();
        }

        return new self(null, $metadata->bytes(), $variant->sha256());
    }

    public function contents(): string { return $this->contents ?? ''; }
    public function bytes(): int { return $this->bytes; }
    public function etag(): string { return '"' . $this->sha256 . '"'; }

    /** @return array<string, int|string> */
    public function __debugInfo(): array
    {
        return [
            'contents' => '[redacted]',
            'bytes' => $this->bytes,
            'integrity' => '[redacted]',
        ];
    }
}
