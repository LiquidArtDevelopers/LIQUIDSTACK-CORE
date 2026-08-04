<?php

declare(strict_types=1);

namespace App\Core\Blog\Persistence;

use App\Core\Blog\BlogPostSummary;
use App\Core\Blog\BlogPostVariant;
use DateTimeImmutable;

/** Additive persistence contract enabled only after Blog migration 0007. */
interface BlogEditorialActionRepositoryInterface
{
    public function postTombstonesEnabled(): bool;

    public function lockTrashedVariant(
        string $postPublicId,
        string $locale
    ): ?BlogPostVariant;

    public function insertTombstone(
        string $localizationPublicId,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void;

    public function deleteTombstone(string $localizationPublicId): bool;

    public function bumpVariantLock(
        string $localizationPublicId,
        int $expectedLockVersion,
        string $actorPublicId,
        DateTimeImmutable $now
    ): bool;

    /** @return list<BlogPostSummary> */
    public function listTrashedSummaries(int $limit, int $offset): array;

    /** @return list<string> */
    public function assignedCategoryPublicIds(
        string $postPublicId,
        int $limit
    ): array;

    /** @param array<string, string> $assignmentPublicIdsByCategory */
    public function insertCategoryAssignments(
        string $postPublicId,
        array $assignmentPublicIdsByCategory,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void;
}
