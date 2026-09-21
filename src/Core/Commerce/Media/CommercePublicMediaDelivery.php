<?php

declare(strict_types=1);

namespace App\Core\Commerce\Media;

use App\Core\WebAdmin\Media\MediaStorageInterface;

final class CommercePublicMediaDelivery
{
    public function __construct(
        private readonly PdoCommercePublicMediaRepository $repository,
        private readonly MediaStorageInterface $storage
    ) {
    }

    public function file(
        string $mediaAssetPublicId,
        int $width,
        bool $metadataOnly
    ): ?CommercePublicMediaFile {
        $variant = $this->repository->publishedVariant(
            $mediaAssetPublicId,
            $width
        );
        if ($variant === null) {
            return null;
        }

        return $variant->verifiedFile($this->storage, $metadataOnly);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'repository' => '[redacted]',
            'storage' => '[redacted]',
        ];
    }
}
