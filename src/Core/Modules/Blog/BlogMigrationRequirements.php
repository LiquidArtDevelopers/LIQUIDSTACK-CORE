<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\Migrations\MigrationFeatureRequirement;

final class BlogMigrationRequirements
{
    /** @var list<string> Public reads keep the exact pre-0019 frontier. */
    private const NORMALIZED_PUBLIC_RUNTIME_MIGRATIONS = [
        '0001_blog_posts',
        '0002_blog_capabilities',
        '0003_blog_categories',
        '0004_blog_category_capabilities',
        '0005_blog_structured_content',
        '0006_blog_sitemap_publication_state',
        '0007_blog_post_tombstones',
        '0008_blog_article_delete_capability',
        '0009_blog_analytics',
        '0010_blog_analytics_view_capability',
        '0011_blog_layout_editor_v2',
        '0012_blog_editor_preferences',
        '0013_blog_settings_manage_capability',
        '0014_blog_private_draft_publication',
        '0015_blog_robots_preferences',
        '0016_blog_url_history',
        '0017_blog_dummy_category',
        '0018_blog_dummy_category_normalization',
    ];

    /**
     * 0019 is an administration-only deployment frontier because only copy
     * POSTs consume its operation registry.
     *
     * @var list<string>
     */
    private const NORMALIZED_ADMIN_RUNTIME_MIGRATIONS = [
        ...self::NORMALIZED_PUBLIC_RUNTIME_MIGRATIONS,
        '0019_blog_copy_operation_idempotency',
    ];

    public static function publicContent(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'blog',
            'blog.public_content',
            self::NORMALIZED_PUBLIC_RUNTIME_MIGRATIONS
        );
    }

    public static function administration(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'blog',
            'blog.administration',
            self::NORMALIZED_ADMIN_RUNTIME_MIGRATIONS
        );
    }

    public static function categoriesPublic(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'blog',
            'blog.categories.public',
            self::NORMALIZED_PUBLIC_RUNTIME_MIGRATIONS
        );
    }

    public static function categoriesAdministration(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'blog',
            'blog.categories.administration',
            self::NORMALIZED_ADMIN_RUNTIME_MIGRATIONS
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

    /** Optional v2 layout companions; v1 remains valid when this is pending. */
    public static function layoutEditor(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'blog',
            'blog.layout_editor.v2',
            [
                '0001_blog_posts',
                '0003_blog_categories',
                '0005_blog_structured_content',
                '0011_blog_layout_editor_v2',
            ]
        );
    }

    /** Optional global defaults; existing editor routes never consume it. */
    public static function editorPreferences(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'blog',
            'blog.editor_preferences',
            [
                '0012_blog_editor_preferences',
                '0013_blog_settings_manage_capability',
            ]
        );
    }

    /** Optional private draft/public head frontier for published articles. */
    public static function privateDraftPublication(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'blog',
            'blog.private_draft_publication',
            [
                '0001_blog_posts',
                '0003_blog_categories',
                '0005_blog_structured_content',
                '0011_blog_layout_editor_v2',
                '0012_blog_editor_preferences',
                '0014_blog_private_draft_publication',
            ]
        );
    }

    /** Optional per-variant robots preferences and immutable snapshots. */
    public static function robotsPreferences(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'blog',
            'blog.robots_preferences',
            [
                '0001_blog_posts',
                '0003_blog_categories',
                '0005_blog_structured_content',
                '0011_blog_layout_editor_v2',
                '0012_blog_editor_preferences',
                '0014_blog_private_draft_publication',
                '0015_blog_robots_preferences',
            ]
        );
    }

    /** Persistent public URL lifecycle: active, temporary 404, 410 or 301. */
    public static function urlHistory(): MigrationFeatureRequirement
    {
        return new MigrationFeatureRequirement(
            'blog',
            'blog.url_history',
            [
                '0001_blog_posts',
                '0016_blog_url_history',
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
