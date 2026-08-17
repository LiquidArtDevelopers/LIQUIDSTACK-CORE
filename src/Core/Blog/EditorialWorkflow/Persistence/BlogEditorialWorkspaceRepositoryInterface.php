<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorialWorkflow\Persistence;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\EditorialWorkflow\BlogEditorialVariantState;
use App\Core\Blog\EditorialWorkflow\BlogEditorialWorkspaceState;
use App\Core\Blog\EditorialWorkflow\BlogPublicationHeadState;
use DateTimeImmutable;

/** Additive persistence boundary enabled only after Blog migration 0014. */
interface BlogEditorialWorkspaceRepositoryInterface
{
    public function variantState(
        string $postPublicId,
        string $locale,
        bool $lock = false
    ): ?BlogEditorialVariantState;

    public function workspace(
        string $localizationPublicId,
        bool $lock = false
    ): ?BlogEditorialWorkspaceState;

    public function publicationHead(
        string $localizationPublicId,
        bool $lock = false
    ): ?BlogPublicationHeadState;

    public function categoryAssignmentVersion(
        string $postPublicId,
        bool $lock = false
    ): int;

    public function categoryWorkspaceVersion(
        string $postPublicId,
        bool $lock = false
    ): int;

    public function advancePrivateLock(
        string $localizationPublicId,
        int $expectedLockVersion,
        string $actorPublicId
    ): bool;

    public function storeDraftRevision(
        string $localizationPublicId,
        string $revisionPublicId,
        int $basePublicationVersion,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void;

    /** null means categories still inherit the live public set. */
    public function workspaceCategoryPublicIds(
        string $postPublicId
    ): ?array;

    public function categoryHasWorkspaceReference(
        string $categoryPublicId,
        bool $lock = false
    ): bool;

    /** @return list<string> */
    public function liveCategoryPublicIds(string $postPublicId): array;

    /** @param list<string> $categoryPublicIds */
    public function replaceWorkspaceCategories(
        string $postPublicId,
        array $categoryPublicIds,
        int $expectedWorkspaceVersion,
        int $baseAssignmentVersion,
        string $actorPublicId,
        DateTimeImmutable $now
    ): int;

    public function clearCategoryWorkspace(string $postPublicId): void;

    public function promoteCategoryAssignments(
        string $postPublicId,
        int $expectedAssignmentVersion,
        string $actorPublicId,
        DateTimeImmutable $now
    ): int;

    /**
     * Atomically replaces the public compatibility row and advances its lock.
     */
    public function publishSnapshot(
        string $localizationPublicId,
        int $expectedLockVersion,
        string $expectedStatus,
        BlogDraft $draft,
        string $actorPublicId,
        DateTimeImmutable $now
    ): bool;

    public function unpublishSnapshot(
        string $localizationPublicId,
        int $expectedLockVersion,
        BlogDraft $draft,
        string $actorPublicId,
        DateTimeImmutable $now
    ): bool;

    /**
     * @param array<string, string> $assignmentPublicIdsByCategory
     */
    public function replaceLiveCategories(
        string $postPublicId,
        array $assignmentPublicIdsByCategory,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void;

    public function promotePublicationHead(
        string $localizationPublicId,
        string $revisionPublicId,
        int $expectedPublicationVersion,
        string $actorPublicId,
        DateTimeImmutable $now
    ): int;

    public function clearWorkspace(string $localizationPublicId): void;

    /** Removes both private workspace and public head before unpublishing. */
    public function retirePublicationState(string $localizationPublicId): void;
}
