<?php

declare(strict_types=1);

namespace App\Core\Blog\Categories;

/** Canonical identity and fail-closed rules for internal Blog categories. */
final class BlogReservedCategoryPolicy
{
    public const DUMMY_SLUG = 'dummy';
    public const DUMMY_LOCALE = 'und';
    public const DUMMY_NAME = 'Dummy (interno)';
    public const DUMMY_CATEGORY_PUBLIC_ID =
        '00000000-0000-4000-8000-000000000017';
    public const DUMMY_LOCALIZATION_PUBLIC_ID =
        '00000000-0000-4000-8000-000000000117';
    public const SYSTEM_ACTOR_PUBLIC_ID =
        '00000000-0000-4000-8000-000000000001';

    public static function isDummySlug(string $slug): bool
    {
        return $slug === self::DUMMY_SLUG;
    }
}
