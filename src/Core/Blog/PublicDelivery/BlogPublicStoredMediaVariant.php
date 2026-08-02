<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicDelivery;

use App\Core\WebAdmin\Media\MediaStorageInterface;
use App\Core\WebAdmin\Media\MediaStoredVariant;

/** Keeps the private storage key behind a redacted public-delivery boundary. */
final class BlogPublicStoredMediaVariant
{
    public function __construct(
        private readonly MediaStoredVariant $variant
    ) {
    }

    public function verifiedFile(
        MediaStorageInterface $storage,
        bool $metadataOnly
    ): BlogPublicMediaFile {
        return $metadataOnly
            ? BlogPublicMediaFile::fromMetadata(
                $this->variant,
                $storage->probeVerified($this->variant)
            )
            : BlogPublicMediaFile::fromPayload(
                $this->variant,
                $storage->readVerified($this->variant)
            );
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'variant' => '[redacted]',
            'storage_key' => '[redacted]',
        ];
    }
}
