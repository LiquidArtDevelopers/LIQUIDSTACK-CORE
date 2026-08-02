<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use InvalidArgumentException;

/** DB-backed variant expected while adopting a pre-marker v1.13 storage. */
final class LegacyMediaStorageVariant
{
    public function __construct(
        private readonly string $publicId,
        private readonly MediaStoredVariant $variant
    ) {
        if (preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $publicId
        ) !== 1) {
            throw new InvalidArgumentException(
                'Invalid legacy media storage variant.'
            );
        }
    }

    public function publicId(): string
    {
        return $this->publicId;
    }

    public function variant(): MediaStoredVariant
    {
        return $this->variant;
    }
}
