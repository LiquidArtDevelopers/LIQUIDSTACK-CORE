<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use App\Core\WebAdmin\Authorization\WebAdminAuthorizedActor;
use DateTimeImmutable;

interface MediaRepositoryInterface
{
    public function transaction(callable $operation): mixed;

    public function listPage(int $page, int $pageSize): MediaAssetPage;

    public function findVariant(string $publicId, int $width): ?MediaStoredVariant;

    public function totalVariantBytes(): int;

    public function lockQuota(): void;

    public function consumeRateLimit(
        string $action,
        string $subjectHash,
        DateTimeImmutable $now,
        int $windowSeconds,
        int $maximumAttempts
    ): bool;

    public function insertAsset(
        string $publicId,
        string $label,
        ProcessedMediaUpload $processed,
        int $authorUserId,
        DateTimeImmutable $createdAt
    ): int;

    public function insertVariant(
        int $assetId,
        ProcessedMediaVariant $variant,
        string $storageKey,
        DateTimeImmutable $createdAt
    ): void;

    public function auditCreated(
        WebAdminAuthorizedActor $actor,
        string $requestId,
        string $publicId,
        ?string $ipHash,
        DateTimeImmutable $occurredAt
    ): void;

    /** @return list<string> */
    public function publicIds(int $limit): array;
}
