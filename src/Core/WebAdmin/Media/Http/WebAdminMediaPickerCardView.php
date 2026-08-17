<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Http;

use App\Core\WebAdmin\Media\MediaException;
use App\Core\WebAdmin\Media\MediaPickerItem;

/** Exact public-safe JSON/HTML projection for one authenticated picker card. */
final class WebAdminMediaPickerCardView
{
    private readonly string $thumbnailUrl;

    public function __construct(
        private readonly MediaPickerItem $item,
        string $basePath
    ) {
        $basePath = rtrim($basePath, '/');
        if (
            preg_match('#\A/[a-z0-9][a-z0-9/-]*\z#', $basePath) !== 1
            || str_contains($basePath, '//')
        ) {
            throw new MediaException(
                'webadmin.media.picker_presentation_invalid'
            );
        }
        $this->thumbnailUrl = $basePath . '/media/file?'
            . http_build_query([
                'asset' => $item->publicId(),
                'width' => (string) $item->thumbnailWidth(),
            ], '', '&', PHP_QUERY_RFC3986);
    }

    public function publicId(): string { return $this->item->publicId(); }
    public function label(): string { return $this->item->label(); }
    public function thumbnailUrl(): string { return $this->thumbnailUrl; }
    public function sourceWidth(): int { return $this->item->sourceWidth(); }
    public function sourceHeight(): int { return $this->item->sourceHeight(); }
    public function createdAt(): \DateTimeImmutable
    {
        return $this->item->createdAt();
    }

    /** @return list<array{width:int,height:int,bytes:int}> */
    public function variants(): array { return $this->item->variants(); }

    /** @return array<string, mixed> */
    public function toSafeArray(): array
    {
        return [
            'public_id' => $this->publicId(),
            'label' => $this->label(),
            'created_at' => $this->item->createdAt()->format(DATE_ATOM),
            'source_width' => $this->sourceWidth(),
            'source_height' => $this->sourceHeight(),
            'thumbnail' => [
                'width' => $this->item->thumbnailWidth(),
                'url' => $this->thumbnailUrl(),
            ],
            'variants' => $this->variants(),
        ];
    }
}
