<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\Migrations\MigrationFeatureRequirement;

final class BlogMigrationRequirements
{
    public static function publicContent(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'blog',
            'blog.public_content',
            ['0001_blog_posts']
        );
    }

    public static function administration(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'blog',
            'blog.administration',
            [
                '0001_blog_posts',
                '0002_blog_capabilities',
            ]
        );
    }

    public static function categoriesPublic(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'blog',
            'blog.categories.public',
            [
                '0001_blog_posts',
                '0003_blog_categories',
            ]
        );
    }

    public static function categoriesAdministration(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'blog',
            'blog.categories.administration',
            [
                '0001_blog_posts',
                '0002_blog_capabilities',
                '0003_blog_categories',
                '0004_blog_category_capabilities',
            ]
        );
    }

    /** Pure Blog schema boundary for structured documents and revisions. */
    public static function structuredContent(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'blog',
            'blog.structured_content',
            [
                '0001_blog_posts',
                '0003_blog_categories',
                '0005_blog_structured_content',
            ]
        );
    }

    /** Backwards-compatible internal alias for the private feature gate. */
    public static function categories(): MigrationFeatureRequirement
    {
        return self::categoriesAdministration();
    }
}
