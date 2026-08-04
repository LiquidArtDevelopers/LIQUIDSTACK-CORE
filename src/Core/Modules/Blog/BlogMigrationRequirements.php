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

    /** Optional cache state; it must never gate uncached public Blog. */
    public static function sitemapCache(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'blog',
            'blog.sitemap_cache',
            [
                '0001_blog_posts',
                '0002_blog_capabilities',
                '0003_blog_categories',
                '0004_blog_category_capabilities',
                '0005_blog_structured_content',
                '0006_blog_sitemap_publication_state',
            ]
        );
    }

    /** Optional, consent-gated public collection boundary. */
    public static function analyticsCollection(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'blog',
            'blog.analytics.collection',
            [
                '0001_blog_posts',
                '0009_blog_analytics',
            ]
        );
    }

    /** Optional private reporting boundary, including its WebAdmin grant. */
    public static function analyticsAdministration(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'blog',
            'blog.analytics.administration',
            [
                '0001_blog_posts',
                '0002_blog_capabilities',
                '0009_blog_analytics',
                '0010_blog_analytics_view_capability',
            ]
        );
    }

    /** Recoverable article trash storage; capability 0008 is cross-scope. */
    public static function postTombstones(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'blog',
            'blog.post_tombstones',
            [
                '0001_blog_posts',
                '0003_blog_categories',
                '0005_blog_structured_content',
                '0006_blog_sitemap_publication_state',
                '0007_blog_post_tombstones',
            ]
        );
    }

    /** Full private editorial-action contract across Blog and WebAdmin. */
    public static function editorialActions(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'blog',
            'blog.editorial_actions',
            [
                '0001_blog_posts',
                '0002_blog_capabilities',
                '0003_blog_categories',
                '0004_blog_category_capabilities',
                '0005_blog_structured_content',
                '0006_blog_sitemap_publication_state',
                '0007_blog_post_tombstones',
                '0008_blog_article_delete_capability',
            ]
        );
    }

    /** Backwards-compatible internal alias for the private feature gate. */
    public static function categories(): MigrationFeatureRequirement
    {
        return self::categoriesAdministration();
    }
}
