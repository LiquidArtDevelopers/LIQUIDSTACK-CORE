<?php

declare(strict_types=1);

namespace App\Core\Blog\Categories\Persistence;

/** Optional destructive boundary; callers must fail closed when unavailable. */
interface BlogCategoryDeletionRepositoryInterface
{
    public function categoryHasAssignments(string $categoryPublicId): bool;

    public function deleteLocalization(
        string $localizationPublicId,
        int $expectedLockVersion
    ): bool;

    public function localizationCount(string $categoryPublicId): int;

    public function deleteCategory(string $categoryPublicId): bool;
}
