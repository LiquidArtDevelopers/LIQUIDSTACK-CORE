<?php

declare(strict_types=1);

namespace App\Core\Commerce\Media;

use App\Core\WebAdmin\Media\MediaStorageInterface;
use App\Core\WebAdmin\Media\MediaStoredVariant;

/** Keeps WebAdmin's private storage key behind the public delivery boundary. */
final class CommercePublicStoredMediaVariant
{
    public function __construct(
        private readonly MediaStoredVariant $variant
    ) {
    }

    public function verifiedFile(
        MediaStorageInterface $storage,
        bool $metadataOnly
    ): CommercePublicMediaFile {
        return $metadataOnly
            ? CommercePublicMediaFile::fromMetadata(
                $this->variant,
                $storage->probeVerified($this->variant)
            )
            : CommercePublicMediaFile::fromPayload(
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
