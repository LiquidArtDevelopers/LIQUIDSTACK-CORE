<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;

/** Immutable, complete DB snapshot used for CAS and quarantine manifests. */
final class MediaDeletionCandidate
{
    private const UUID_PATTERN =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    /** @var list<array{width:int,height:int,bytes:int,sha256:string,storage_key:string,mime:string,created_at:string}> */
    private readonly array $variants;
    private readonly string $createdAt;
    private readonly string $versionToken;

    /**
     * @param list<array{width:int,height:int,bytes:int,sha256:string,storage_key:string,mime:string,created_at:string}> $variants
     */
    public function __construct(
        private readonly int $assetId,
        private readonly string $publicId,
        private readonly string $label,
        private readonly string $sourceMime,
        private readonly int $sourceWidth,
        private readonly int $sourceHeight,
        private readonly int $sourceBytes,
        private readonly string $sourceSha256,
        private readonly int $authorUserId,
        string $createdAt,
        array $variants
    ) {
        $labelLength = preg_match_all('/./us', $label, $matches);
        if (
            $assetId < 1
            || $authorUserId < 1
            || preg_match(self::UUID_PATTERN, $publicId) !== 1
            || trim($label) !== $label
            || $label === ''
            || $labelLength === false
            || $labelLength > 120
            || preg_match('/[\x00-\x1F\x7F<>]/u', $label) === 1
            || !in_array(
                $sourceMime,
                ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
                true
            )
            || $sourceWidth < 1
            || $sourceWidth > 12_000
            || $sourceHeight < 1
            || $sourceHeight > 12_000
            || $sourceWidth * $sourceHeight > 40_000_000
            || $sourceBytes < 1
            || $sourceBytes > 12_582_912
            || preg_match('/\A[0-9a-f]{64}\z/', $sourceSha256) !== 1
            || !array_is_list($variants)
            || $variants === []
        ) {
            throw new MediaException('webadmin.media.delete_candidate_invalid');
        }

        $this->createdAt = $this->timestamp($createdAt);
        $normalized = [];
        $seenWidths = [];
        foreach ($variants as $variant) {
            $width = $variant['width'] ?? null;
            $height = $variant['height'] ?? null;
            $bytes = $variant['bytes'] ?? null;
            $sha256 = $variant['sha256'] ?? null;
            $storageKey = $variant['storage_key'] ?? null;
            $mime = $variant['mime'] ?? null;
            $variantCreatedAt = $variant['created_at'] ?? null;
            if (
                !is_int($width)
                || $width < 1
                || $width > 2_560
                || isset($seenWidths[$width])
                || !is_int($height)
                || $height < 1
                || $height > 2_560
                || !is_int($bytes)
                || $bytes < 1
                || !is_string($sha256)
                || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1
                || !is_string($storageKey)
                || $storageKey !== substr($publicId, 0, 2) . '/'
                    . $publicId . '/' . $width . '.avif'
                || $mime !== 'image/avif'
                || !is_string($variantCreatedAt)
            ) {
                throw new MediaException(
                    'webadmin.media.delete_candidate_invalid'
                );
            }
            $seenWidths[$width] = true;
            $normalized[] = [
                'width' => $width,
                'height' => $height,
                'bytes' => $bytes,
                'sha256' => $sha256,
                'storage_key' => $storageKey,
                'mime' => $mime,
                'created_at' => $this->timestamp($variantCreatedAt),
            ];
        }
        usort(
            $normalized,
            static fn (array $left, array $right): int =>
                $left['width'] <=> $right['width']
        );
        $this->variants = $normalized;
        $this->versionToken = hash('sha256', $this->canonicalJson(
            $this->assetPayload()
        ));
    }

    public function assetId(): int { return $this->assetId; }
    public function publicId(): string { return $this->publicId; }
    public function versionToken(): string { return $this->versionToken; }

    /** @return list<array{width:int,height:int,bytes:int}> */
    public function variantPresentation(): array
    {
        return array_map(
            static fn (array $variant): array => [
                'width' => $variant['width'],
                'height' => $variant['height'],
                'bytes' => $variant['bytes'],
            ],
            $this->variants
        );
    }

    public function manifest(
        string $requestId,
        string $originalStoragePrefix,
        string $quarantineStoragePrefix,
        string $manifestStorageKey
    ): MediaQuarantineManifest {
        return MediaQuarantineManifest::create(
            $this->publicId,
            $requestId,
            $this->versionToken,
            $originalStoragePrefix,
            $quarantineStoragePrefix,
            $manifestStorageKey,
            $this->assetPayload()
        );
    }

    /** @return array<string, mixed> */
    private function assetPayload(): array
    {
        return [
            'asset' => [
                'id' => $this->assetId,
                'public_id' => $this->publicId,
                'label' => $this->label,
                'source_mime' => $this->sourceMime,
                'source_width' => $this->sourceWidth,
                'source_height' => $this->sourceHeight,
                'source_bytes' => $this->sourceBytes,
                'source_sha256' => $this->sourceSha256,
                'created_by_user_id' => $this->authorUserId,
                'created_at' => $this->createdAt,
            ],
            'variants' => $this->variants,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function canonicalJson(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException) {
            throw new MediaException('webadmin.media.delete_candidate_invalid');
        }
    }

    private function timestamp(string $value): string
    {
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new MediaException('webadmin.media.delete_candidate_invalid');
        }

        return $date->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }
}
