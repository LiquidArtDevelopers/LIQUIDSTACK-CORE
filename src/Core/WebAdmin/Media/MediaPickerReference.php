<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

/** Minimal SSR fallback value that keeps an existing selection preservable. */
final class MediaPickerReference
{
    private const UUID_V4_PATTERN =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    public function __construct(
        private readonly string $publicId,
        private readonly string $label,
        private readonly ?string $thumbnailUrl = null
    ) {
        $characters = preg_match_all('/./us', $label, $matches);
        if (
            preg_match(self::UUID_V4_PATTERN, $publicId) !== 1
            || trim($label) !== $label
            || $label === ''
            || $characters === false
            || $characters > 120
            || preg_match('/[\x00-\x1F\x7F<>]/u', $label) === 1
            || !$this->validThumbnailUrl($thumbnailUrl)
        ) {
            throw new MediaException(
                'webadmin.media.picker_reference_invalid'
            );
        }
    }

    public function publicId(): string { return $this->publicId; }
    public function label(): string { return $this->label; }
    public function thumbnailUrl(): ?string { return $this->thumbnailUrl; }

    private function validThumbnailUrl(?string $url): bool
    {
        return $url === null || (
            strlen($url) <= 2_048
            && preg_match('/[\x00-\x20\x7F]/', $url) !== 1
            && preg_match(
                '#\A/[a-z0-9][a-z0-9/-]*/media/file\?asset='
                    . preg_quote($this->publicId, '#')
                    . '&width=[1-9][0-9]{0,3}\z#',
                $url
            ) === 1
        );
    }
}
