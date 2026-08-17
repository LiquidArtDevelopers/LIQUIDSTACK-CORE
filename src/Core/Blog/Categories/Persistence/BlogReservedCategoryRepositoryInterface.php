<?php

declare(strict_types=1);

namespace App\Core\Blog\Categories\Persistence;

/** Read-only identity contract for a reserved internal category. */
interface BlogReservedCategoryRepositoryInterface
{
    public function reservedCategoryPublicId(string $slug): ?string;

    public function categoryHasReservedSlug(
        string $categoryPublicId,
        string $slug
    ): bool;
}
