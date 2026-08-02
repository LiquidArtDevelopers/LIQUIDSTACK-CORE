<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

interface MediaStorageInterface
{
    public function createStagingDirectory(): string;

    public function promote(string $stagingDirectory, string $publicId): void;

    public function removeStaging(string $stagingDirectory): void;

    public function removeAsset(string $publicId): void;

    public function storageKey(string $publicId, int $width): string;

    public function readVerified(MediaStoredVariant $variant): MediaFilePayload;

    /** Verifies by streaming metadata/hash without materializing file bytes. */
    public function probeVerified(MediaStoredVariant $variant): MediaFileMetadata;

    /**
     * @param ?list<string> $knownPublicIds null keeps the DB-backed orphan
     *        scan explicitly unchecked
     * @return array{ready: bool,status: string,orphan_count: ?int,orphan_scan_status: string,staging_count: int}
     */
    public function diagnostic(?array $knownPublicIds = null): array;
}
