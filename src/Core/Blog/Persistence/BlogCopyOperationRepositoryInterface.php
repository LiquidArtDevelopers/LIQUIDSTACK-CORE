<?php

declare(strict_types=1);

namespace App\Core\Blog\Persistence;

use DateTimeImmutable;

/** Additive persistence contract enabled by Blog migration 0019. */
interface BlogCopyOperationRepositoryInterface
{
    /**
     * Reserves the globally unique request inside the active transaction.
     * A completed, byte-for-byte equivalent request returns its destination.
     * A reused request ID with any different canonical field fails closed.
     */
    public function reserveCopyOperation(
        string $requestPublicId,
        string $payloadSha256,
        string $actorPublicId,
        string $operation,
        string $sourcePostPublicId,
        string $sourceLocale,
        string $destinationLocale,
        int $expectedLockVersion,
        DateTimeImmutable $now
    ): ?BlogCopyOperationResult;

    public function completeCopyOperation(
        string $requestPublicId,
        string $payloadSha256,
        string $resultPostPublicId,
        string $resultLocale,
        DateTimeImmutable $now
    ): void;
}
