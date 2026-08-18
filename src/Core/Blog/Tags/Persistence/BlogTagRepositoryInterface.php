<?php

declare(strict_types=1);

namespace App\Core\Blog\Tags\Persistence;

use App\Core\Blog\EditorialWorkflow\BlogEditorialVariantState;
use App\Core\Blog\Tags\BlogTag;
use App\Core\Blog\Tags\BlogTagAssignmentWorkspaceState;
use DateTimeImmutable;
use PDO;

/** Shared private tag boundary used by assignment, publication and copy. */
interface BlogTagRepositoryInterface
{
    /** @template T @param callable(PDO): T $operation @return T */
    public function transactional(callable $operation): mixed;

    public function variantState(
        string $postPublicId,
        string $locale,
        bool $lock = false
    ): ?BlogEditorialVariantState;

    public function tagByIdentity(
        string $locale,
        string $normalizedSha256,
        bool $lock = false
    ): ?BlogTag;

    public function tagBySlug(
        string $locale,
        string $slug,
        bool $lock = false
    ): ?BlogTag;

    public function insertTag(
        string $publicId,
        string $locale,
        string $slug,
        string $name,
        string $normalizedSha256,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void;

    /** @return list<BlogTag> */
    public function liveTags(string $localizationPublicId): array;

    /** null means there is no private workspace. @return null|list<BlogTag> */
    public function workspaceTags(string $localizationPublicId): ?array;

    public function assignmentVersion(
        string $localizationPublicId,
        bool $lock = false
    ): int;

    public function workspaceState(
        string $localizationPublicId,
        bool $lock = false
    ): ?BlogTagAssignmentWorkspaceState;

    /** @param list<string> $tagPublicIds */
    public function replaceWorkspace(
        string $localizationPublicId,
        array $tagPublicIds,
        int $expectedWorkspaceVersion,
        int $baseAssignmentVersion,
        string $actorPublicId,
        DateTimeImmutable $now
    ): int;

    public function clearWorkspace(string $localizationPublicId): void;

    /** @param list<string> $tagPublicIds */
    public function replaceLiveTags(
        string $localizationPublicId,
        array $tagPublicIds,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void;

    public function promoteAssignments(
        string $localizationPublicId,
        int $expectedAssignmentVersion,
        string $actorPublicId,
        DateTimeImmutable $now
    ): int;
}
