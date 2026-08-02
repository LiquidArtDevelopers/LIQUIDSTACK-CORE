<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Editing;

use App\Core\Blog\StructuredContent\Document\BlogDocumentException;

/** One validated media use extracted from the canonical document. */
final class BlogStructuredMediaReference
{
    public const IMAGE = 'image';
    public const COVER = 'cover';
    public const POSTER = 'poster';

    private const UUID_V4_PATTERN =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    public function __construct(
        private readonly string $blockPublicId,
        private readonly string $mediaAssetPublicId,
        private readonly string $role
    ) {
        if (
            preg_match(self::UUID_V4_PATTERN, $blockPublicId) !== 1
            || preg_match(self::UUID_V4_PATTERN, $mediaAssetPublicId) !== 1
            || !in_array($role, [self::IMAGE, self::COVER, self::POSTER], true)
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_DOCUMENT
            );
        }
    }

    public function blockPublicId(): string
    {
        return $this->blockPublicId;
    }

    public function mediaAssetPublicId(): string
    {
        return $this->mediaAssetPublicId;
    }

    public function role(): string
    {
        return $this->role;
    }

    /** @return array{block_public_id: string, media_asset_public_id: string, role: string} */
    public function toArray(): array
    {
        return [
            'block_public_id' => $this->blockPublicId,
            'media_asset_public_id' => $this->mediaAssetPublicId,
            'role' => $this->role,
        ];
    }
}
