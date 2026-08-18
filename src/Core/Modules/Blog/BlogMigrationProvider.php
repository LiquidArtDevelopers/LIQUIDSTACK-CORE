<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Blog\Analytics\BlogAnalyticsCapabilities;
use App\Core\Blog\EditorPreferences\BlogSettingsCapabilities;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationProviderInterface;

final class BlogMigrationProvider implements MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'blog';
    }

    public static function migrations(): iterable
    {
        yield MigrationDefinition::sql(
            id: '0001_blog_posts',
            description: 'Crea posts y variantes localizadas del Blog.',
            statementsByDriver: [
                'mysql' => self::mysqlSchemaStatements(),
                'sqlite' => self::sqliteSchemaStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier: new BlogMigrationPostconditionVerifier(),
            preconditionVerifier: new BlogInitialNamespacePrecondition()
        );

        yield MigrationDefinition::sql(
            id: '0002_blog_capabilities',
            description: 'Registra capacidades delegables del Blog en WebAdmin.',
            statementsByDriver: [
                'mysql' => self::mysqlCapabilityStatements(),
                'sqlite' => self::sqliteCapabilityStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier: new BlogCapabilitySeedPostcondition(),
            targetScopeModuleId: 'webadmin'
        );

        yield MigrationDefinition::sql(
            id: '0003_blog_categories',
            description: 'Crea categorias localizadas y su relacion con posts.',
            statementsByDriver: [
                'mysql' => self::mysqlCategoryStatements(),
                'sqlite' => self::sqliteCategoryStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogCategoryMigrationPostconditionVerifier(),
            supersedesPostconditions: ['0001_blog_posts']
        );

        yield MigrationDefinition::sql(
            id: '0004_blog_category_capabilities',
            description: 'Registra capacidades delegables de categorias.',
            statementsByDriver: [
                'mysql' => self::mysqlCategoryCapabilityStatements(),
                'sqlite' => self::sqliteCategoryCapabilityStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogCategoryCapabilitySeedPostcondition(),
            supersedesPostconditions: ['0002_blog_capabilities'],
            targetScopeModuleId: 'webadmin'
        );

        yield MigrationDefinition::sql(
            id: '0005_blog_structured_content',
            description: 'Crea documentos estructurados y revisiones del Blog.',
            statementsByDriver: [
                'mysql' => self::mysqlStructuredContentStatements(),
                'sqlite' => self::sqliteStructuredContentStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogStructuredContentMigrationPostconditionVerifier(),
            supersedesPostconditions: [
                '0001_blog_posts',
                '0003_blog_categories',
            ]
        );

        yield MigrationDefinition::sql(
            id: '0006_blog_sitemap_publication_state',
            description: 'Crea la revision publica estable del sitemap Blog.',
            statementsByDriver: [
                'mysql' => self::mysqlSitemapStateStatements(),
                'sqlite' => self::sqliteSitemapStateStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogSitemapStateMigrationPostconditionVerifier(),
            supersedesPostconditions: [
                '0001_blog_posts',
                '0003_blog_categories',
                '0005_blog_structured_content',
            ]
        );

        yield MigrationDefinition::sql(
            id: '0007_blog_post_tombstones',
            description: 'Crea la papelera recuperable de variantes Blog.',
            statementsByDriver: [
                'mysql' => self::mysqlPostTombstoneStatements(),
                'sqlite' => self::sqlitePostTombstoneStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogPostTombstoneMigrationPostconditionVerifier(),
            supersedesPostconditions: [
                '0001_blog_posts',
                '0003_blog_categories',
                '0005_blog_structured_content',
                '0006_blog_sitemap_publication_state',
            ]
        );

        yield MigrationDefinition::sql(
            id: '0008_blog_article_delete_capability',
            description: 'Registra la capacidad delegable de papelera Blog.',
            statementsByDriver: [
                'mysql' => self::mysqlArticleDeleteCapabilityStatements(),
                'sqlite' => self::sqliteArticleDeleteCapabilityStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogArticleDeleteCapabilitySeedPostcondition(),
            supersedesPostconditions: [
                '0002_blog_capabilities',
                '0004_blog_category_capabilities',
            ],
            targetScopeModuleId: 'webadmin'
        );

        yield MigrationDefinition::sql(
            id: '0009_blog_analytics',
            description: 'Crea metricas propias y consentidas del Blog.',
            statementsByDriver: [
                'mysql' => self::mysqlAnalyticsStatements(),
                'sqlite' => self::sqliteAnalyticsStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogAnalyticsMigrationPostconditionVerifier(),
            supersedesPostconditions: [
                '0001_blog_posts',
                '0003_blog_categories',
                '0005_blog_structured_content',
                '0006_blog_sitemap_publication_state',
                '0007_blog_post_tombstones',
            ]
        );

        yield MigrationDefinition::sql(
            id: '0010_blog_analytics_view_capability',
            description: 'Registra la capacidad de consulta de analitica Blog.',
            statementsByDriver: [
                'mysql' => self::mysqlAnalyticsCapabilityStatements(),
                'sqlite' => self::sqliteAnalyticsCapabilityStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogAnalyticsCapabilitySeedPostcondition(),
            supersedesPostconditions: [
                '0002_blog_capabilities',
                '0004_blog_category_capabilities',
                '0008_blog_article_delete_capability',
            ],
            targetScopeModuleId: 'webadmin'
        );

        yield MigrationDefinition::sql(
            id: '0011_blog_layout_editor_v2',
            description: 'Crea companions aditivos para el editor layout v2.',
            statementsByDriver: [
                'mysql' => self::mysqlLayoutEditorStatements(),
                'sqlite' => self::sqliteLayoutEditorStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogLayoutEditorMigrationPostconditionVerifier(),
            supersedesPostconditions: [
                '0001_blog_posts',
                '0003_blog_categories',
                '0005_blog_structured_content',
                '0006_blog_sitemap_publication_state',
                '0007_blog_post_tombstones',
                '0009_blog_analytics',
            ]
        );

        yield MigrationDefinition::sql(
            id: '0012_blog_editor_preferences',
            description: 'Crea preferencias globales opcionales del editor.',
            statementsByDriver: [
                'mysql' => self::mysqlEditorPreferencesStatements(),
                'sqlite' => self::sqliteEditorPreferencesStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogEditorPreferencesMigrationPostconditionVerifier(),
            supersedesPostconditions: [
                '0001_blog_posts',
                '0003_blog_categories',
                '0005_blog_structured_content',
                '0006_blog_sitemap_publication_state',
                '0007_blog_post_tombstones',
                '0009_blog_analytics',
                '0011_blog_layout_editor_v2',
            ]
        );

        yield MigrationDefinition::sql(
            id: '0013_blog_settings_manage_capability',
            description: 'Registra la capacidad protegida de ajustes Blog.',
            statementsByDriver: [
                'mysql' => self::mysqlSettingsCapabilityStatements(),
                'sqlite' => self::sqliteSettingsCapabilityStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogSettingsCapabilitySeedPostcondition(),
            supersedesPostconditions: [
                '0002_blog_capabilities',
                '0004_blog_category_capabilities',
                '0008_blog_article_delete_capability',
                '0010_blog_analytics_view_capability',
            ],
            targetScopeModuleId: 'webadmin'
        );

        yield MigrationDefinition::sql(
            id: '0014_blog_private_draft_publication',
            description: 'Crea borradores privados y cabezas de publicacion.',
            statementsByDriver: [
                'mysql' => self::mysqlPrivateDraftPublicationStatements(),
                'sqlite' => self::sqlitePrivateDraftPublicationStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogPrivateDraftPublicationMigrationPostconditionVerifier(),
            supersedesPostconditions: [
                '0001_blog_posts',
                '0003_blog_categories',
                '0005_blog_structured_content',
                '0006_blog_sitemap_publication_state',
                '0007_blog_post_tombstones',
                '0009_blog_analytics',
                '0011_blog_layout_editor_v2',
                '0012_blog_editor_preferences',
            ]
        );

        yield MigrationDefinition::sql(
            id: '0015_blog_robots_preferences',
            description: 'Persiste index y follow por variante del Blog.',
            statementsByDriver: [
                'mysql' => self::mysqlRobotsPreferencesStatements(),
                'sqlite' => self::sqliteRobotsPreferencesStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogRobotsPreferencesMigrationPostconditionVerifier(),
            supersedesPostconditions: [
                '0001_blog_posts',
                '0003_blog_categories',
                '0005_blog_structured_content',
                '0006_blog_sitemap_publication_state',
                '0007_blog_post_tombstones',
                '0009_blog_analytics',
                '0011_blog_layout_editor_v2',
                '0012_blog_editor_preferences',
                '0014_blog_private_draft_publication',
            ]
        );

        yield MigrationDefinition::sql(
            id: '0016_blog_url_history',
            description: 'Persiste el ciclo SEO de URLs publicadas del Blog.',
            statementsByDriver: [
                'mysql' => self::mysqlUrlHistoryStatements(),
                'sqlite' => self::sqliteUrlHistoryStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogUrlHistoryMigrationPostconditionVerifier(),
            supersedesPostconditions: [
                '0001_blog_posts',
                '0003_blog_categories',
                '0005_blog_structured_content',
                '0006_blog_sitemap_publication_state',
                '0007_blog_post_tombstones',
                '0009_blog_analytics',
                '0011_blog_layout_editor_v2',
                '0012_blog_editor_preferences',
                '0014_blog_private_draft_publication',
                '0015_blog_robots_preferences',
            ]
        );

        yield MigrationDefinition::sql(
            id: '0017_blog_dummy_category',
            description: 'Reserva la categoria interna Dummy del Blog.',
            statementsByDriver: [
                'mysql' => self::mysqlDummyCategoryStatements(),
                'sqlite' => self::sqliteDummyCategoryStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier: new BlogDummyCategorySeedPostcondition()
        );

        yield MigrationDefinition::sql(
            id: '0018_blog_dummy_category_normalization',
            description: 'Normaliza asignaciones Dummy heredadas del Blog.',
            statementsByDriver: [
                'mysql' => self::mysqlDummyCategoryNormalizationStatements(),
                'sqlite' => self::sqliteDummyCategoryNormalizationStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogDummyCategoryNormalizationPostcondition(),
            supersedesPostconditions: [
                '0016_blog_url_history',
                '0017_blog_dummy_category',
            ]
        );

        yield MigrationDefinition::sql(
            id: '0019_blog_copy_operation_idempotency',
            description: 'Hace idempotentes las copias editoriales del Blog.',
            statementsByDriver: [
                'mysql' => self::mysqlCopyOperationStatements(),
                'sqlite' => self::sqliteCopyOperationStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogCopyOperationMigrationPostconditionVerifier(),
            supersedesPostconditions: [
                '0001_blog_posts',
                '0003_blog_categories',
                '0005_blog_structured_content',
                '0006_blog_sitemap_publication_state',
                '0007_blog_post_tombstones',
                '0009_blog_analytics',
                '0011_blog_layout_editor_v2',
                '0012_blog_editor_preferences',
                '0014_blog_private_draft_publication',
                '0015_blog_robots_preferences',
                '0016_blog_url_history',
                '0017_blog_dummy_category',
                '0018_blog_dummy_category_normalization',
            ]
        );

        yield MigrationDefinition::sql(
            id: '0020_blog_tags',
            description: 'Crea el vocabulario localizado de etiquetas Blog.',
            statementsByDriver: [
                'mysql' => self::mysqlTagStatements(),
                'sqlite' => self::sqliteTagStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogTagSchemaMigrationPostconditionVerifier(1),
            supersedesPostconditions: self::tagSchemaSupersessions(1)
        );

        yield MigrationDefinition::sql(
            id: '0021_blog_localization_tags',
            description: 'Crea las asignaciones publicadas de etiquetas.',
            statementsByDriver: [
                'mysql' => self::mysqlLocalizationTagStatements(),
                'sqlite' => self::sqliteLocalizationTagStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogTagSchemaMigrationPostconditionVerifier(2),
            supersedesPostconditions: self::tagSchemaSupersessions(2)
        );

        yield MigrationDefinition::sql(
            id: '0022_blog_tag_assignment_heads',
            description: 'Versiona las asignaciones publicadas de etiquetas.',
            statementsByDriver: [
                'mysql' => self::mysqlTagAssignmentHeadStatements(),
                'sqlite' => self::sqliteTagAssignmentHeadStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogTagSchemaMigrationPostconditionVerifier(3),
            supersedesPostconditions: self::tagSchemaSupersessions(3)
        );

        yield MigrationDefinition::sql(
            id: '0023_blog_tag_assignment_workspaces',
            description: 'Crea el CAS privado de etiquetas por variante.',
            statementsByDriver: [
                'mysql' => self::mysqlTagAssignmentWorkspaceStatements(),
                'sqlite' => self::sqliteTagAssignmentWorkspaceStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogTagSchemaMigrationPostconditionVerifier(4),
            supersedesPostconditions: self::tagSchemaSupersessions(4)
        );

        yield MigrationDefinition::sql(
            id: '0024_blog_tag_assignment_workspace_items',
            description: 'Persiste el conjunto privado de etiquetas.',
            statementsByDriver: [
                'mysql' => self::mysqlTagAssignmentWorkspaceItemStatements(),
                'sqlite' => self::sqliteTagAssignmentWorkspaceItemStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new BlogTagSchemaMigrationPostconditionVerifier(5),
            supersedesPostconditions: self::tagSchemaSupersessions(5)
        );

        yield MigrationDefinition::sql(
            id: '0025_blog_tag_capabilities',
            description: 'Registra capacidades delegables de etiquetas.',
            statementsByDriver: [
                'mysql' => self::mysqlTagCapabilityStatements(),
                'sqlite' => self::sqliteTagCapabilityStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier: new BlogTagCapabilitySeedPostcondition(),
            targetScopeModuleId: 'webadmin'
        );
    }

    /** @return list<string> */
    private static function tagSchemaSupersessions(int $stage): array
    {
        if ($stage < 1 || $stage > 5) {
            throw new \InvalidArgumentException('Invalid Blog tag schema stage.');
        }
        $ids = [
            '0001_blog_posts',
            '0003_blog_categories',
            '0005_blog_structured_content',
            '0006_blog_sitemap_publication_state',
            '0007_blog_post_tombstones',
            '0009_blog_analytics',
            '0011_blog_layout_editor_v2',
            '0012_blog_editor_preferences',
            '0014_blog_private_draft_publication',
            '0015_blog_robots_preferences',
            '0016_blog_url_history',
            '0017_blog_dummy_category',
            '0018_blog_dummy_category_normalization',
            '0019_blog_copy_operation_idempotency',
        ];
        foreach (array_slice([
            '0020_blog_tags',
            '0021_blog_localization_tags',
            '0022_blog_tag_assignment_heads',
            '0023_blog_tag_assignment_workspaces',
        ], 0, $stage - 1) as $id) {
            $ids[] = $id;
        }

        return $ids;
    }

    /** @return list<string> */
    private static function mysqlTagStatements(): array
    {
        return [<<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:tags}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `locale` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `slug` VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `normalized_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `lock_version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `created_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `updated_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL,
    `updated_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY {{table:ux_bt_public}} (`public_id`),
    UNIQUE KEY {{table:ux_bt_locale_slug}} (`locale`, `slug`),
    UNIQUE KEY {{table:ux_bt_locale_hash}} (`locale`, `normalized_sha256`),
    KEY {{table:ix_bt_locale_name}} (`locale`, `name`),
    CONSTRAINT {{table:c_bt_public}} CHECK (`public_id` REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT {{table:c_bt_locale}} CHECK (CHAR_LENGTH(`locale`) BETWEEN 2 AND 16 AND `locale` = LOWER(`locale`) AND `locale` = TRIM(`locale`)),
    CONSTRAINT {{table:c_bt_slug}} CHECK (CHAR_LENGTH(`slug`) BETWEEN 1 AND 190 AND `slug` REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$'),
    CONSTRAINT {{table:c_bt_name}} CHECK (CHAR_LENGTH(TRIM(`name`)) BETWEEN 1 AND 64 AND OCTET_LENGTH(`name`) <= 255),
    CONSTRAINT {{table:c_bt_hash}} CHECK (`normalized_sha256` REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT {{table:c_bt_lock}} CHECK (`lock_version` > 0),
    CONSTRAINT {{table:c_bt_created_actor}} CHECK (`created_by_user_public_id` REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT {{table:c_bt_updated_actor}} CHECK (`updated_by_user_public_id` REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT {{table:c_bt_time}} CHECK (`updated_at` >= `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL];
    }

    /** @return list<string> */
    private static function sqliteTagStatements(): array
    {
        return [<<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:tags}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "public_id" TEXT COLLATE BINARY NOT NULL CHECK (length("public_id") = 36 AND "public_id" = lower("public_id")),
    "locale" TEXT COLLATE BINARY NOT NULL CHECK (length("locale") BETWEEN 2 AND 16 AND "locale" = lower("locale") AND "locale" = trim("locale")),
    "slug" TEXT COLLATE BINARY NOT NULL CHECK (length("slug") BETWEEN 1 AND 190 AND "slug" = lower("slug") AND "slug" = trim("slug") AND "slug" NOT GLOB '*[^a-z0-9-]*' AND "slug" NOT LIKE '-%' AND "slug" NOT LIKE '%-' AND "slug" NOT LIKE '%--%'),
    "name" TEXT NOT NULL CHECK (length(trim("name")) BETWEEN 1 AND 64 AND length(CAST("name" AS BLOB)) <= 255),
    "normalized_sha256" TEXT COLLATE BINARY NOT NULL CHECK (length("normalized_sha256") = 64 AND "normalized_sha256" = lower("normalized_sha256") AND "normalized_sha256" NOT GLOB '*[^0-9a-f]*'),
    "lock_version" INTEGER NOT NULL DEFAULT 1 CHECK ("lock_version" > 0),
    "created_by_user_public_id" TEXT COLLATE BINARY NOT NULL CHECK (length("created_by_user_public_id") = 36 AND "created_by_user_public_id" = lower("created_by_user_public_id")),
    "updated_by_user_public_id" TEXT COLLATE BINARY NOT NULL CHECK (length("updated_by_user_public_id") = 36 AND "updated_by_user_public_id" = lower("updated_by_user_public_id")),
    "created_at" TEXT NOT NULL,
    "updated_at" TEXT NOT NULL CHECK ("updated_at" >= "created_at")
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_bt_public}} ON {{table:tags}} ("public_id")',
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_bt_locale_slug}} ON {{table:tags}} ("locale", "slug")',
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_bt_locale_hash}} ON {{table:tags}} ("locale", "normalized_sha256")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_bt_locale_name}} ON {{table:tags}} ("locale", "name")',
        ];
    }

    /** @return list<string> */
    private static function mysqlLocalizationTagStatements(): array
    {
        return [<<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:localization_tags}} (
    `localization_id` BIGINT UNSIGNED NOT NULL,
    `tag_id` BIGINT UNSIGNED NOT NULL,
    `assigned_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`localization_id`, `tag_id`),
    KEY {{table:ix_blt_tag}} (`tag_id`, `localization_id`),
    CONSTRAINT {{table:f_blt_localization}} FOREIGN KEY (`localization_id`) REFERENCES {{table:post_localizations}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_blt_tag}} FOREIGN KEY (`tag_id`) REFERENCES {{table:tags}} (`id`) ON DELETE RESTRICT,
    CONSTRAINT {{table:c_blt_actor}} CHECK (`assigned_by_user_public_id` REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL];
    }

    /** @return list<string> */
    private static function sqliteLocalizationTagStatements(): array
    {
        return [<<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:localization_tags}} (
    "localization_id" INTEGER NOT NULL REFERENCES {{table:post_localizations}} ("id") ON DELETE CASCADE,
    "tag_id" INTEGER NOT NULL REFERENCES {{table:tags}} ("id") ON DELETE RESTRICT,
    "assigned_by_user_public_id" TEXT COLLATE BINARY NOT NULL CHECK (length("assigned_by_user_public_id") = 36 AND "assigned_by_user_public_id" = lower("assigned_by_user_public_id")),
    "created_at" TEXT NOT NULL,
    PRIMARY KEY ("localization_id", "tag_id")
) WITHOUT ROWID
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_blt_tag}} ON {{table:localization_tags}} ("tag_id", "localization_id")',
        ];
    }

    /** @return list<string> */
    private static function mysqlTagAssignmentHeadStatements(): array
    {
        return [<<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:tag_assignment_heads}} (
    `localization_id` BIGINT UNSIGNED NOT NULL,
    `assignment_version` BIGINT UNSIGNED NOT NULL,
    `updated_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `updated_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`localization_id`),
    CONSTRAINT {{table:f_btah_localization}} FOREIGN KEY (`localization_id`) REFERENCES {{table:post_localizations}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_btah_version}} CHECK (`assignment_version` > 0),
    CONSTRAINT {{table:c_btah_actor}} CHECK (`updated_by_user_public_id` REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL];
    }

    /** @return list<string> */
    private static function sqliteTagAssignmentHeadStatements(): array
    {
        return [<<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:tag_assignment_heads}} (
    "localization_id" INTEGER NOT NULL PRIMARY KEY REFERENCES {{table:post_localizations}} ("id") ON DELETE CASCADE,
    "assignment_version" INTEGER NOT NULL CHECK ("assignment_version" > 0),
    "updated_by_user_public_id" TEXT COLLATE BINARY NOT NULL CHECK (length("updated_by_user_public_id") = 36 AND "updated_by_user_public_id" = lower("updated_by_user_public_id")),
    "updated_at" TEXT NOT NULL
) WITHOUT ROWID
SQL];
    }

    /** @return list<string> */
    private static function mysqlTagAssignmentWorkspaceStatements(): array
    {
        return [<<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:tag_assignment_workspaces}} (
    `localization_id` BIGINT UNSIGNED NOT NULL,
    `base_assignment_version` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `workspace_version` BIGINT UNSIGNED NOT NULL,
    `created_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `updated_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL,
    `updated_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`localization_id`),
    CONSTRAINT {{table:f_btaw_localization}} FOREIGN KEY (`localization_id`) REFERENCES {{table:post_localizations}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_btaw_base}} CHECK (`base_assignment_version` >= 0),
    CONSTRAINT {{table:c_btaw_workspace}} CHECK (`workspace_version` > 0),
    CONSTRAINT {{table:c_btaw_created_actor}} CHECK (`created_by_user_public_id` REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT {{table:c_btaw_updated_actor}} CHECK (`updated_by_user_public_id` REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT {{table:c_btaw_time}} CHECK (`updated_at` >= `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL];
    }

    /** @return list<string> */
    private static function sqliteTagAssignmentWorkspaceStatements(): array
    {
        return [<<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:tag_assignment_workspaces}} (
    "localization_id" INTEGER NOT NULL PRIMARY KEY REFERENCES {{table:post_localizations}} ("id") ON DELETE CASCADE,
    "base_assignment_version" INTEGER NOT NULL DEFAULT 0 CHECK ("base_assignment_version" >= 0),
    "workspace_version" INTEGER NOT NULL CHECK ("workspace_version" > 0),
    "created_by_user_public_id" TEXT COLLATE BINARY NOT NULL CHECK (length("created_by_user_public_id") = 36 AND "created_by_user_public_id" = lower("created_by_user_public_id")),
    "updated_by_user_public_id" TEXT COLLATE BINARY NOT NULL CHECK (length("updated_by_user_public_id") = 36 AND "updated_by_user_public_id" = lower("updated_by_user_public_id")),
    "created_at" TEXT NOT NULL,
    "updated_at" TEXT NOT NULL CHECK ("updated_at" >= "created_at")
) WITHOUT ROWID
SQL];
    }

    /** @return list<string> */
    private static function mysqlTagAssignmentWorkspaceItemStatements(): array
    {
        return [<<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:tag_assignment_workspace_items}} (
    `localization_id` BIGINT UNSIGNED NOT NULL,
    `tag_id` BIGINT UNSIGNED NOT NULL,
    `assigned_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`localization_id`, `tag_id`),
    KEY {{table:ix_btawi_tag}} (`tag_id`, `localization_id`),
    CONSTRAINT {{table:f_btawi_workspace}} FOREIGN KEY (`localization_id`) REFERENCES {{table:tag_assignment_workspaces}} (`localization_id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_btawi_tag}} FOREIGN KEY (`tag_id`) REFERENCES {{table:tags}} (`id`) ON DELETE RESTRICT,
    CONSTRAINT {{table:c_btawi_actor}} CHECK (`assigned_by_user_public_id` REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL];
    }

    /** @return list<string> */
    private static function sqliteTagAssignmentWorkspaceItemStatements(): array
    {
        return [<<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:tag_assignment_workspace_items}} (
    "localization_id" INTEGER NOT NULL REFERENCES {{table:tag_assignment_workspaces}} ("localization_id") ON DELETE CASCADE,
    "tag_id" INTEGER NOT NULL REFERENCES {{table:tags}} ("id") ON DELETE RESTRICT,
    "assigned_by_user_public_id" TEXT COLLATE BINARY NOT NULL CHECK (length("assigned_by_user_public_id") = 36 AND "assigned_by_user_public_id" = lower("assigned_by_user_public_id")),
    "created_at" TEXT NOT NULL,
    PRIMARY KEY ("localization_id", "tag_id")
) WITHOUT ROWID
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_btawi_tag}} ON {{table:tag_assignment_workspace_items}} ("tag_id", "localization_id")',
        ];
    }

    /** @return list<string> */
    private static function mysqlTagCapabilityStatements(): array
    {
        return [<<<'SQL'
INSERT IGNORE INTO {{table:capabilities}} (`module_id`, `code`, `label_key`, `is_delegable`) VALUES
    ('blog', 'blog.tags.view', 'blog.capabilities.tags_view', 1),
    ('blog', 'blog.tags.edit', 'blog.capabilities.tags_edit', 1)
SQL,
            <<<'SQL'
INSERT INTO {{table:role_capabilities}} (`role_id`, `capability_id`)
SELECT `r`.`id`, `c`.`id` FROM {{table:roles}} AS `r`
CROSS JOIN {{table:capabilities}} AS `c`
WHERE `r`.`code` IN ('system_superadmin', 'site_admin')
    AND `r`.`is_protected` = 1
    AND `c`.`module_id` = 'blog'
    AND `c`.`code` IN ('blog.tags.view', 'blog.tags.edit')
    AND `c`.`is_delegable` = 1
ON DUPLICATE KEY UPDATE `capability_id` = VALUES(`capability_id`)
SQL];
    }

    /** @return list<string> */
    private static function sqliteTagCapabilityStatements(): array
    {
        return [<<<'SQL'
INSERT INTO {{table:capabilities}} ("module_id", "code", "label_key", "is_delegable") VALUES
    ('blog', 'blog.tags.view', 'blog.capabilities.tags_view', 1),
    ('blog', 'blog.tags.edit', 'blog.capabilities.tags_edit', 1)
ON CONFLICT("code") DO NOTHING
SQL,
            <<<'SQL'
INSERT INTO {{table:role_capabilities}} ("role_id", "capability_id")
SELECT "r"."id", "c"."id" FROM {{table:roles}} AS "r"
CROSS JOIN {{table:capabilities}} AS "c"
WHERE "r"."code" IN ('system_superadmin', 'site_admin')
    AND "r"."is_protected" = 1
    AND "c"."module_id" = 'blog'
    AND "c"."code" IN ('blog.tags.view', 'blog.tags.edit')
    AND "c"."is_delegable" = 1
ON CONFLICT("role_id", "capability_id") DO NOTHING
SQL];
    }

    /** @return list<string> */
    private static function mysqlCopyOperationStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:copy_operations}} (
    `request_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `payload_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `actor_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `operation` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `source_post_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `source_locale` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `destination_locale` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `expected_lock_version` BIGINT UNSIGNED NOT NULL,
    `result_post_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `result_locale` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `completed_at` DATETIME(6) NULL,
    PRIMARY KEY (`request_public_id`),
    CONSTRAINT {{table:c_co_request}} CHECK (
        `request_public_id` REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
    ),
    CONSTRAINT {{table:c_co_payload}} CHECK (
        `payload_sha256` REGEXP '^[0-9a-f]{64}$'
    ),
    CONSTRAINT {{table:c_co_actor}} CHECK (CHAR_LENGTH(`actor_public_id`) = 36),
    CONSTRAINT {{table:c_co_operation}} CHECK (
        `operation` IN ('duplicate_post', 'add_locale')
    ),
    CONSTRAINT {{table:c_co_source}} CHECK (
        CHAR_LENGTH(`source_post_public_id`) = 36
    ),
    CONSTRAINT {{table:c_co_locales}} CHECK (
        CHAR_LENGTH(`source_locale`) BETWEEN 2 AND 16
        AND CHAR_LENGTH(`destination_locale`) BETWEEN 2 AND 16
    ),
    CONSTRAINT {{table:c_co_version}} CHECK (`expected_lock_version` > 0),
    CONSTRAINT {{table:c_co_result}} CHECK (
        (`result_post_public_id` IS NULL AND `result_locale` IS NULL AND `completed_at` IS NULL)
        OR (`result_post_public_id` IS NOT NULL AND `result_locale` = `destination_locale`
            AND `completed_at` IS NOT NULL AND `completed_at` >= `created_at`)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqliteCopyOperationStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:copy_operations}} (
    "request_public_id" TEXT COLLATE BINARY NOT NULL PRIMARY KEY CHECK (
        length("request_public_id") = 36
        AND "request_public_id" = lower("request_public_id")
    ),
    "payload_sha256" TEXT COLLATE BINARY NOT NULL CHECK (
        length("payload_sha256") = 64
        AND "payload_sha256" NOT GLOB '*[^0-9a-f]*'
    ),
    "actor_public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("actor_public_id") = 36),
    "operation" TEXT COLLATE BINARY NOT NULL
        CHECK ("operation" IN ('duplicate_post', 'add_locale')),
    "source_post_public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("source_post_public_id") = 36),
    "source_locale" TEXT COLLATE BINARY NOT NULL
        CHECK (length("source_locale") BETWEEN 2 AND 16),
    "destination_locale" TEXT COLLATE BINARY NOT NULL
        CHECK (length("destination_locale") BETWEEN 2 AND 16),
    "expected_lock_version" INTEGER NOT NULL
        CHECK ("expected_lock_version" > 0),
    "result_post_public_id" TEXT COLLATE BINARY NULL,
    "result_locale" TEXT COLLATE BINARY NULL,
    "created_at" TEXT NOT NULL
        DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "completed_at" TEXT NULL,
    CHECK (
        ("result_post_public_id" IS NULL AND "result_locale" IS NULL
            AND "completed_at" IS NULL)
        OR ("result_post_public_id" IS NOT NULL
            AND "result_locale" = "destination_locale"
            AND "completed_at" IS NOT NULL
            AND "completed_at" >= "created_at")
    )
) WITHOUT ROWID
SQL,
        ];
    }

    /** @return list<string> */
    private static function mysqlDummyCategoryNormalizationStatements(): array
    {
        return [
            <<<'SQL'
INSERT IGNORE INTO {{table:post_categories}} (
    `public_id`, `post_id`, `category_id`, `assigned_by_user_public_id`
)
SELECT
    CONCAT(
        SUBSTRING(
            '89abcdef01234567',
            LOCATE(
                SUBSTRING(LOWER(canonical_post.`public_id`), 1, 1),
                '0123456789abcdef'
            ),
            1
        ),
        SUBSTRING(LOWER(canonical_post.`public_id`), 2)
    ),
    legacy_posts.`post_id`, canonical_category.`id`,
    '00000000-0000-4000-8000-000000000001'
FROM (
    SELECT DISTINCT legacy_relation.`post_id`
    FROM {{table:post_categories}} legacy_relation
    JOIN {{table:category_locales}} legacy_locale
        ON legacy_locale.`category_id` = legacy_relation.`category_id`
    WHERE legacy_locale.`slug` = 'dummy'
) legacy_posts
JOIN {{table:posts}} canonical_post
    ON canonical_post.`id` = legacy_posts.`post_id`
JOIN {{table:categories}} canonical_category
    ON canonical_category.`public_id` =
        '00000000-0000-4000-8000-000000000017'
WHERE NOT EXISTS (
    SELECT 1 FROM {{table:post_categories}} canonical_relation
    WHERE canonical_relation.`post_id` = legacy_posts.`post_id`
    AND canonical_relation.`category_id` = canonical_category.`id`
)
SQL,
            <<<'SQL'
INSERT IGNORE INTO {{table:category_assignment_workspace_items}} (
    `post_id`, `category_id`, `assigned_by_user_public_id`, `created_at`
)
SELECT
    legacy_workspaces.`post_id`, canonical_category.`id`,
    '00000000-0000-4000-8000-000000000001',
    legacy_workspaces.`created_at`
FROM (
    SELECT legacy_item.`post_id`, MIN(legacy_item.`created_at`) AS `created_at`
    FROM {{table:category_assignment_workspace_items}} legacy_item
    JOIN {{table:category_locales}} legacy_locale
        ON legacy_locale.`category_id` = legacy_item.`category_id`
    WHERE legacy_locale.`slug` = 'dummy'
    GROUP BY legacy_item.`post_id`
) legacy_workspaces
JOIN {{table:categories}} canonical_category
    ON canonical_category.`public_id` =
        '00000000-0000-4000-8000-000000000017'
WHERE NOT EXISTS (
    SELECT 1 FROM {{table:category_assignment_workspace_items}}
        canonical_item
    WHERE canonical_item.`post_id` = legacy_workspaces.`post_id`
    AND canonical_item.`category_id` = canonical_category.`id`
)
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqliteDummyCategoryNormalizationStatements(): array
    {
        return [
            <<<'SQL'
INSERT INTO {{table:post_categories}} (
    "public_id", "post_id", "category_id", "assigned_by_user_public_id"
)
SELECT
    substr(
        '89abcdef01234567',
        instr(
            '0123456789abcdef',
            substr(lower(canonical_post."public_id"), 1, 1)
        ),
        1
    ) || substr(lower(canonical_post."public_id"), 2),
    legacy_posts."post_id", canonical_category."id",
    '00000000-0000-4000-8000-000000000001'
FROM (
    SELECT DISTINCT legacy_relation."post_id"
    FROM {{table:post_categories}} legacy_relation
    JOIN {{table:category_locales}} legacy_locale
        ON legacy_locale."category_id" = legacy_relation."category_id"
    WHERE legacy_locale."slug" = 'dummy'
) legacy_posts
JOIN {{table:posts}} canonical_post
    ON canonical_post."id" = legacy_posts."post_id"
JOIN {{table:categories}} canonical_category
    ON canonical_category."public_id" =
        '00000000-0000-4000-8000-000000000017'
WHERE NOT EXISTS (
    SELECT 1 FROM {{table:post_categories}} canonical_relation
    WHERE canonical_relation."post_id" = legacy_posts."post_id"
    AND canonical_relation."category_id" = canonical_category."id"
)
ON CONFLICT("post_id", "category_id") DO NOTHING
SQL,
            <<<'SQL'
INSERT INTO {{table:category_assignment_workspace_items}} (
    "post_id", "category_id", "assigned_by_user_public_id", "created_at"
)
SELECT
    legacy_workspaces."post_id", canonical_category."id",
    '00000000-0000-4000-8000-000000000001',
    legacy_workspaces."created_at"
FROM (
    SELECT legacy_item."post_id", MIN(legacy_item."created_at") AS "created_at"
    FROM {{table:category_assignment_workspace_items}} legacy_item
    JOIN {{table:category_locales}} legacy_locale
        ON legacy_locale."category_id" = legacy_item."category_id"
    WHERE legacy_locale."slug" = 'dummy'
    GROUP BY legacy_item."post_id"
) legacy_workspaces
JOIN {{table:categories}} canonical_category
    ON canonical_category."public_id" =
        '00000000-0000-4000-8000-000000000017'
WHERE NOT EXISTS (
    SELECT 1 FROM {{table:category_assignment_workspace_items}}
        canonical_item
    WHERE canonical_item."post_id" = legacy_workspaces."post_id"
    AND canonical_item."category_id" = canonical_category."id"
)
ON CONFLICT("post_id", "category_id") DO NOTHING
SQL,
        ];
    }

    /** @return list<string> */
    private static function mysqlDummyCategoryStatements(): array
    {
        return [
            <<<'SQL'
INSERT IGNORE INTO {{table:categories}}
    (`public_id`, `created_by_user_public_id`)
VALUES (
    '00000000-0000-4000-8000-000000000017',
    '00000000-0000-4000-8000-000000000001'
)
SQL,
            <<<'SQL'
INSERT IGNORE INTO {{table:category_locales}} (
    `public_id`, `category_id`, `locale`, `slug`, `name`,
    `created_by_user_public_id`, `updated_by_user_public_id`
)
SELECT
    '00000000-0000-4000-8000-000000000117', c.`id`, 'und', 'dummy',
    'Dummy (interno)',
    '00000000-0000-4000-8000-000000000001',
    '00000000-0000-4000-8000-000000000001'
FROM {{table:categories}} c
WHERE c.`public_id` = '00000000-0000-4000-8000-000000000017'
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqliteDummyCategoryStatements(): array
    {
        return [
            <<<'SQL'
INSERT INTO {{table:categories}}
    ("public_id", "created_by_user_public_id")
SELECT
    '00000000-0000-4000-8000-000000000017',
    '00000000-0000-4000-8000-000000000001'
WHERE NOT EXISTS (
    SELECT 1 FROM {{table:categories}}
    WHERE "public_id" = '00000000-0000-4000-8000-000000000017'
)
SQL,
            <<<'SQL'
INSERT INTO {{table:category_locales}} (
    "public_id", "category_id", "locale", "slug", "name",
    "created_by_user_public_id", "updated_by_user_public_id"
)
SELECT
    '00000000-0000-4000-8000-000000000117', c."id", 'und', 'dummy',
    'Dummy (interno)',
    '00000000-0000-4000-8000-000000000001',
    '00000000-0000-4000-8000-000000000001'
FROM {{table:categories}} c
WHERE c."public_id" = '00000000-0000-4000-8000-000000000017'
AND NOT EXISTS (
    SELECT 1 FROM {{table:category_locales}}
    WHERE "public_id" = '00000000-0000-4000-8000-000000000117'
)
SQL,
        ];
    }

    /** @return list<string> */
    private static function mysqlUrlHistoryStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:url_history}} (
    `localization_id` BIGINT UNSIGNED NOT NULL,
    `locale` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `slug` VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `state` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `replacement_localization_id` BIGINT UNSIGNED NULL,
    `created_at` DATETIME(6) NOT NULL,
    `updated_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`locale`, `slug`),
    KEY {{table:ix_uh_owner}} (`localization_id`, `state`),
    KEY {{table:ix_uh_target}} (`replacement_localization_id`),
    CONSTRAINT {{table:f_uh_owner}} FOREIGN KEY (`localization_id`)
        REFERENCES {{table:post_localizations}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_uh_target}} FOREIGN KEY (`replacement_localization_id`)
        REFERENCES {{table:post_localizations}} (`id`) ON DELETE RESTRICT,
    CONSTRAINT {{table:c_uh_locale}} CHECK (
        CHAR_LENGTH(`locale`) BETWEEN 2 AND 16
        AND `locale` = LOWER(`locale`) AND `locale` = TRIM(`locale`)
    ),
    CONSTRAINT {{table:c_uh_slug}} CHECK (
        CHAR_LENGTH(TRIM(`slug`)) > 0 AND `slug` = LOWER(`slug`)
        AND `slug` = TRIM(`slug`)
    ),
    CONSTRAINT {{table:c_uh_state}} CHECK (`state` IN (
        'active', 'temporary_not_found', 'gone', 'redirect'
    )),
    CONSTRAINT {{table:c_uh_target_state}} CHECK (
        (`state` = 'redirect' AND `replacement_localization_id` IS NOT NULL)
        OR (`state` <> 'redirect' AND `replacement_localization_id` IS NULL)
    ),
    CONSTRAINT {{table:c_uh_time}} CHECK (`updated_at` >= `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
INSERT IGNORE INTO {{table:url_history}}
    (`localization_id`, `locale`, `slug`, `state`,
     `replacement_localization_id`, `created_at`, `updated_at`)
SELECT `id`, `locale`, `slug`, 'active', NULL, `updated_at`, `updated_at`
FROM {{table:post_localizations}}
WHERE `status` = 'published' AND `slug` IS NOT NULL
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqliteUrlHistoryStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:url_history}} (
    "localization_id" INTEGER NOT NULL
        REFERENCES {{table:post_localizations}} ("id") ON DELETE CASCADE,
    "locale" TEXT COLLATE BINARY NOT NULL CHECK (
        length("locale") BETWEEN 2 AND 16
        AND "locale" = lower("locale") AND "locale" = trim("locale")
    ),
    "slug" TEXT COLLATE BINARY NOT NULL CHECK (
        length(trim("slug")) > 0 AND "slug" = lower("slug")
        AND "slug" = trim("slug")
    ),
    "state" TEXT COLLATE BINARY NOT NULL CHECK ("state" IN (
        'active', 'temporary_not_found', 'gone', 'redirect'
    )),
    "replacement_localization_id" INTEGER NULL
        REFERENCES {{table:post_localizations}} ("id") ON DELETE RESTRICT,
    "created_at" TEXT NOT NULL,
    "updated_at" TEXT NOT NULL,
    PRIMARY KEY ("locale", "slug"),
    CHECK (
        ("state" = 'redirect' AND "replacement_localization_id" IS NOT NULL)
        OR ("state" <> 'redirect' AND "replacement_localization_id" IS NULL)
    ),
    CHECK ("updated_at" >= "created_at")
) WITHOUT ROWID
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_uh_owner}} '
                . 'ON {{table:url_history}} ("localization_id", "state")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_uh_target}} '
                . 'ON {{table:url_history}} ("replacement_localization_id")',
            <<<'SQL'
INSERT INTO {{table:url_history}}
    ("localization_id", "locale", "slug", "state",
     "replacement_localization_id", "created_at", "updated_at")
SELECT "id", "locale", "slug", 'active', NULL, "updated_at", "updated_at"
FROM {{table:post_localizations}}
WHERE "status" = 'published' AND "slug" IS NOT NULL
ON CONFLICT("locale", "slug") DO NOTHING
SQL,
        ];
    }

    /** @return list<string> */
    private static function mysqlRobotsPreferencesStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:robots_settings}} (
    `localization_id` BIGINT UNSIGNED NOT NULL,
    `allow_index` TINYINT UNSIGNED NOT NULL,
    `allow_follow` TINYINT UNSIGNED NOT NULL,
    `settings_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (`localization_id`),
    CONSTRAINT {{table:f_rs_localization}} FOREIGN KEY (`localization_id`)
        REFERENCES {{table:post_localizations}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_rs_index}} CHECK (`allow_index` IN (0, 1)),
    CONSTRAINT {{table:c_rs_follow}} CHECK (`allow_follow` IN (0, 1)),
    CONSTRAINT {{table:c_rs_hash}} CHECK (
        `settings_sha256` REGEXP '^[0-9a-f]{64}$'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:revision_robots}} (
    `revision_id` BIGINT UNSIGNED NOT NULL,
    `allow_index` TINYINT UNSIGNED NOT NULL,
    `allow_follow` TINYINT UNSIGNED NOT NULL,
    `settings_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (`revision_id`),
    CONSTRAINT {{table:f_rr_revision}} FOREIGN KEY (`revision_id`)
        REFERENCES {{table:content_revisions}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_rr_index}} CHECK (`allow_index` IN (0, 1)),
    CONSTRAINT {{table:c_rr_follow}} CHECK (`allow_follow` IN (0, 1)),
    CONSTRAINT {{table:c_rr_hash}} CHECK (
        `settings_sha256` REGEXP '^[0-9a-f]{64}$'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqliteRobotsPreferencesStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:robots_settings}} (
    "localization_id" INTEGER NOT NULL PRIMARY KEY
        REFERENCES {{table:post_localizations}} ("id") ON DELETE CASCADE,
    "allow_index" INTEGER NOT NULL CHECK ("allow_index" IN (0, 1)),
    "allow_follow" INTEGER NOT NULL CHECK ("allow_follow" IN (0, 1)),
    "settings_sha256" TEXT COLLATE BINARY NOT NULL CHECK (
        length("settings_sha256") = 64
        AND "settings_sha256" NOT GLOB '*[^0-9a-f]*'
    )
) WITHOUT ROWID
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:revision_robots}} (
    "revision_id" INTEGER NOT NULL PRIMARY KEY
        REFERENCES {{table:content_revisions}} ("id") ON DELETE CASCADE,
    "allow_index" INTEGER NOT NULL CHECK ("allow_index" IN (0, 1)),
    "allow_follow" INTEGER NOT NULL CHECK ("allow_follow" IN (0, 1)),
    "settings_sha256" TEXT COLLATE BINARY NOT NULL CHECK (
        length("settings_sha256") = 64
        AND "settings_sha256" NOT GLOB '*[^0-9a-f]*'
    )
) WITHOUT ROWID
SQL,
        ];
    }

    /** @return list<string> */
    private static function mysqlPrivateDraftPublicationStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:editorial_workspaces}} (
    `localization_id` BIGINT UNSIGNED NOT NULL,
    `draft_revision_id` BIGINT UNSIGNED NULL,
    `base_publication_version` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `created_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `updated_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL,
    `updated_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`localization_id`),
    UNIQUE KEY {{table:ux_ew_revision}} (`draft_revision_id`),
    CONSTRAINT {{table:f_ew_localization}} FOREIGN KEY (`localization_id`)
        REFERENCES {{table:post_localizations}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_ew_revision}} FOREIGN KEY (`draft_revision_id`)
        REFERENCES {{table:content_revisions}} (`id`) ON DELETE RESTRICT,
    CONSTRAINT {{table:c_ew_created_actor}} CHECK (
        `created_by_user_public_id` REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
    ),
    CONSTRAINT {{table:c_ew_updated_actor}} CHECK (
        `updated_by_user_public_id` REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
    ),
    CONSTRAINT {{table:c_ew_time}} CHECK (`updated_at` >= `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:category_assignment_heads}} (
    `post_id` BIGINT UNSIGNED NOT NULL,
    `assignment_version` BIGINT UNSIGNED NOT NULL,
    `updated_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `updated_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`post_id`),
    CONSTRAINT {{table:f_cah_post}} FOREIGN KEY (`post_id`)
        REFERENCES {{table:posts}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_cah_version}} CHECK (`assignment_version` > 0),
    CONSTRAINT {{table:c_cah_actor}} CHECK (
        `updated_by_user_public_id` REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:category_assignment_workspaces}} (
    `post_id` BIGINT UNSIGNED NOT NULL,
    `base_assignment_version` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `workspace_version` BIGINT UNSIGNED NOT NULL,
    `created_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `updated_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL,
    `updated_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`post_id`),
    CONSTRAINT {{table:f_caw_post}} FOREIGN KEY (`post_id`)
        REFERENCES {{table:posts}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_caw_version}} CHECK (`workspace_version` > 0),
    CONSTRAINT {{table:c_caw_created_actor}} CHECK (
        `created_by_user_public_id` REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
    ),
    CONSTRAINT {{table:c_caw_updated_actor}} CHECK (
        `updated_by_user_public_id` REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
    ),
    CONSTRAINT {{table:c_caw_time}} CHECK (`updated_at` >= `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:category_assignment_workspace_items}} (
    `post_id` BIGINT UNSIGNED NOT NULL,
    `category_id` BIGINT UNSIGNED NOT NULL,
    `assigned_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`post_id`, `category_id`),
    KEY {{table:ix_cawi_category}} (`category_id`),
    CONSTRAINT {{table:f_cawi_workspace}} FOREIGN KEY (`post_id`)
        REFERENCES {{table:category_assignment_workspaces}} (`post_id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_cawi_category}} FOREIGN KEY (`category_id`)
        REFERENCES {{table:categories}} (`id`) ON DELETE RESTRICT,
    CONSTRAINT {{table:c_cawi_actor}} CHECK (
        `assigned_by_user_public_id` REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:publication_heads}} (
    `localization_id` BIGINT UNSIGNED NOT NULL,
    `revision_id` BIGINT UNSIGNED NOT NULL,
    `publication_version` BIGINT UNSIGNED NOT NULL,
    `published_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `published_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`localization_id`),
    UNIQUE KEY {{table:ux_ph_revision}} (`revision_id`),
    CONSTRAINT {{table:f_ph_localization}} FOREIGN KEY (`localization_id`)
        REFERENCES {{table:post_localizations}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_ph_revision}} FOREIGN KEY (`revision_id`)
        REFERENCES {{table:content_revisions}} (`id`) ON DELETE RESTRICT,
    CONSTRAINT {{table:c_ph_version}} CHECK (`publication_version` > 0),
    CONSTRAINT {{table:c_ph_actor}} CHECK (
        `published_by_user_public_id` REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqlitePrivateDraftPublicationStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:editorial_workspaces}} (
    "localization_id" INTEGER NOT NULL PRIMARY KEY
        REFERENCES {{table:post_localizations}} ("id") ON DELETE CASCADE,
    "draft_revision_id" INTEGER NULL UNIQUE
        REFERENCES {{table:content_revisions}} ("id") ON DELETE RESTRICT,
    "base_publication_version" INTEGER NOT NULL DEFAULT 0
        CHECK ("base_publication_version" >= 0),
    "created_by_user_public_id" TEXT COLLATE BINARY NOT NULL CHECK (
        length("created_by_user_public_id") = 36
        AND "created_by_user_public_id" = lower("created_by_user_public_id")
    ),
    "updated_by_user_public_id" TEXT COLLATE BINARY NOT NULL CHECK (
        length("updated_by_user_public_id") = 36
        AND "updated_by_user_public_id" = lower("updated_by_user_public_id")
    ),
    "created_at" TEXT NOT NULL,
    "updated_at" TEXT NOT NULL CHECK ("updated_at" >= "created_at")
) WITHOUT ROWID
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:category_assignment_heads}} (
    "post_id" INTEGER NOT NULL PRIMARY KEY
        REFERENCES {{table:posts}} ("id") ON DELETE CASCADE,
    "assignment_version" INTEGER NOT NULL
        CHECK ("assignment_version" > 0),
    "updated_by_user_public_id" TEXT COLLATE BINARY NOT NULL CHECK (
        length("updated_by_user_public_id") = 36
        AND "updated_by_user_public_id" = lower("updated_by_user_public_id")
    ),
    "updated_at" TEXT NOT NULL
) WITHOUT ROWID
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:category_assignment_workspaces}} (
    "post_id" INTEGER NOT NULL PRIMARY KEY
        REFERENCES {{table:posts}} ("id") ON DELETE CASCADE,
    "base_assignment_version" INTEGER NOT NULL DEFAULT 0
        CHECK ("base_assignment_version" >= 0),
    "workspace_version" INTEGER NOT NULL
        CHECK ("workspace_version" > 0),
    "created_by_user_public_id" TEXT COLLATE BINARY NOT NULL CHECK (
        length("created_by_user_public_id") = 36
        AND "created_by_user_public_id" = lower("created_by_user_public_id")
    ),
    "updated_by_user_public_id" TEXT COLLATE BINARY NOT NULL CHECK (
        length("updated_by_user_public_id") = 36
        AND "updated_by_user_public_id" = lower("updated_by_user_public_id")
    ),
    "created_at" TEXT NOT NULL,
    "updated_at" TEXT NOT NULL CHECK ("updated_at" >= "created_at")
) WITHOUT ROWID
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:category_assignment_workspace_items}} (
    "post_id" INTEGER NOT NULL
        REFERENCES {{table:category_assignment_workspaces}} ("post_id") ON DELETE CASCADE,
    "category_id" INTEGER NOT NULL
        REFERENCES {{table:categories}} ("id") ON DELETE RESTRICT,
    "assigned_by_user_public_id" TEXT COLLATE BINARY NOT NULL CHECK (
        length("assigned_by_user_public_id") = 36
        AND "assigned_by_user_public_id" = lower("assigned_by_user_public_id")
    ),
    "created_at" TEXT NOT NULL,
    PRIMARY KEY ("post_id", "category_id")
) WITHOUT ROWID
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_cawi_category}} ON '
                . '{{table:category_assignment_workspace_items}} ("category_id")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:publication_heads}} (
    "localization_id" INTEGER NOT NULL PRIMARY KEY
        REFERENCES {{table:post_localizations}} ("id") ON DELETE CASCADE,
    "revision_id" INTEGER NOT NULL UNIQUE
        REFERENCES {{table:content_revisions}} ("id") ON DELETE RESTRICT,
    "publication_version" INTEGER NOT NULL
        CHECK ("publication_version" > 0),
    "published_by_user_public_id" TEXT COLLATE BINARY NOT NULL CHECK (
        length("published_by_user_public_id") = 36
        AND "published_by_user_public_id" = lower("published_by_user_public_id")
    ),
    "published_at" TEXT NOT NULL
) WITHOUT ROWID
SQL,
        ];
    }

    /** @return list<string> */
    private static function mysqlEditorPreferencesStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:editor_preferences}} (
    `scope_key` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `schema_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `preferences_json` LONGTEXT NOT NULL,
    `preferences_bytes` SMALLINT UNSIGNED NOT NULL,
    `preferences_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `lock_version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `updated_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL,
    `updated_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`scope_key`),
    CONSTRAINT {{table:c_ep_scope}} CHECK (`scope_key` = 'global'),
    CONSTRAINT {{table:c_ep_schema}} CHECK (`schema_version` = 1),
    CONSTRAINT {{table:c_ep_bytes}} CHECK (`preferences_bytes` BETWEEN 1 AND 4096),
    CONSTRAINT {{table:c_ep_hash}} CHECK (`preferences_sha256` REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT {{table:c_ep_lock}} CHECK (`lock_version` > 0),
    CONSTRAINT {{table:c_ep_actor}} CHECK (
        `updated_by_user_public_id` REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
    ),
    CONSTRAINT {{table:c_ep_time}} CHECK (`updated_at` >= `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqliteEditorPreferencesStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:editor_preferences}} (
    "scope_key" TEXT COLLATE BINARY NOT NULL PRIMARY KEY
        CHECK ("scope_key" = 'global'),
    "schema_version" INTEGER NOT NULL DEFAULT 1
        CHECK ("schema_version" = 1),
    "preferences_json" TEXT NOT NULL,
    "preferences_bytes" INTEGER NOT NULL
        CHECK ("preferences_bytes" BETWEEN 1 AND 4096),
    "preferences_sha256" TEXT COLLATE BINARY NOT NULL CHECK (
        length("preferences_sha256") = 64
        AND "preferences_sha256" NOT GLOB '*[^0-9a-f]*'
    ),
    "lock_version" INTEGER NOT NULL DEFAULT 1
        CHECK ("lock_version" > 0),
    "updated_by_user_public_id" TEXT COLLATE BINARY NOT NULL CHECK (
        length("updated_by_user_public_id") = 36
        AND "updated_by_user_public_id" = lower("updated_by_user_public_id")
    ),
    "created_at" TEXT NOT NULL,
    "updated_at" TEXT NOT NULL CHECK ("updated_at" >= "created_at")
) WITHOUT ROWID
SQL,
        ];
    }

    /** @return list<string> */
    private static function mysqlSettingsCapabilityStatements(): array
    {
        $code = BlogSettingsCapabilities::MANAGE;
        $label = BlogSettingsCapabilities::MANAGE_LABEL;

        return [
            "INSERT IGNORE INTO {{table:capabilities}} "
                . "(`module_id`, `code`, `label_key`, `is_delegable`) "
                . "VALUES ('blog', '{$code}', '{$label}', 0)",
            "INSERT INTO {{table:role_capabilities}} "
                . "(`role_id`, `capability_id`) SELECT `r`.`id`, `c`.`id` "
                . "FROM {{table:roles}} AS `r` CROSS JOIN "
                . "{{table:capabilities}} AS `c` WHERE `r`.`code` IN "
                . "('site_admin', 'system_superadmin') AND "
                . "`r`.`is_protected` = 1 AND `r`.`is_delegable` = 0 "
                . "AND `c`.`module_id` = 'blog' AND `c`.`code` = '{$code}' "
                . "AND `c`.`label_key` = '{$label}' "
                . "AND `c`.`is_delegable` = 0 ON DUPLICATE KEY UPDATE "
                . "`role_id` = VALUES(`role_id`)",
        ];
    }

    /** @return list<string> */
    private static function sqliteSettingsCapabilityStatements(): array
    {
        $code = BlogSettingsCapabilities::MANAGE;
        $label = BlogSettingsCapabilities::MANAGE_LABEL;

        return [
            "INSERT INTO {{table:capabilities}} "
                . "(\"module_id\", \"code\", \"label_key\", \"is_delegable\") "
                . "VALUES ('blog', '{$code}', '{$label}', 0) "
                . 'ON CONFLICT("code") DO NOTHING',
            "INSERT INTO {{table:role_capabilities}} "
                . "(\"role_id\", \"capability_id\") SELECT \"r\".\"id\", "
                . "\"c\".\"id\" FROM {{table:roles}} AS \"r\" CROSS JOIN "
                . "{{table:capabilities}} AS \"c\" WHERE \"r\".\"code\" IN "
                . "('site_admin', 'system_superadmin') AND "
                . "\"r\".\"is_protected\" = 1 AND \"r\".\"is_delegable\" = 0 "
                . "AND \"c\".\"module_id\" = 'blog' AND \"c\".\"code\" = "
                . "'{$code}' AND \"c\".\"label_key\" = '{$label}' AND "
                . "\"c\".\"is_delegable\" = 0 "
                . 'ON CONFLICT("role_id", "capability_id") DO NOTHING',
        ];
    }

    /** @return list<string> */
    private static function mysqlLayoutEditorStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:content_layout_docs}} (
    `document_id` BIGINT UNSIGNED NOT NULL,
    `schema_version` SMALLINT UNSIGNED NOT NULL DEFAULT 2,
    `template_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `document_json` LONGTEXT NOT NULL,
    `document_bytes` INT UNSIGNED NOT NULL,
    `document_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `snapshot_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (`document_id`),
    CONSTRAINT {{table:f_cld_document}} FOREIGN KEY (`document_id`)
        REFERENCES {{table:content_docs}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_cld_schema}} CHECK (`schema_version` = 2),
    CONSTRAINT {{table:c_cld_template}} CHECK (
        CHAR_LENGTH(`template_key`) BETWEEN 1 AND 64
        AND `template_key` = LOWER(`template_key`)
        AND `template_key` = TRIM(`template_key`)
        AND `template_key` REGEXP '^[a-z][a-z0-9_-]{0,63}$'
    ),
    CONSTRAINT {{table:c_cld_bytes}} CHECK (
        `document_bytes` BETWEEN 1 AND 300000
    ),
    CONSTRAINT {{table:c_cld_doc_hash}} CHECK (
        `document_sha256` REGEXP '^[0-9a-f]{64}$'
    ),
    CONSTRAINT {{table:c_cld_snap_hash}} CHECK (
        `snapshot_sha256` REGEXP '^[0-9a-f]{64}$'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:content_layout_revisions}} (
    `revision_id` BIGINT UNSIGNED NOT NULL,
    `schema_version` SMALLINT UNSIGNED NOT NULL DEFAULT 2,
    `template_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `document_json` LONGTEXT NOT NULL,
    `document_bytes` INT UNSIGNED NOT NULL,
    `document_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `snapshot_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (`revision_id`),
    CONSTRAINT {{table:f_clr_revision}} FOREIGN KEY (`revision_id`)
        REFERENCES {{table:content_revisions}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_clr_schema}} CHECK (`schema_version` = 2),
    CONSTRAINT {{table:c_clr_template}} CHECK (
        CHAR_LENGTH(`template_key`) BETWEEN 1 AND 64
        AND `template_key` = LOWER(`template_key`)
        AND `template_key` = TRIM(`template_key`)
        AND `template_key` REGEXP '^[a-z][a-z0-9_-]{0,63}$'
    ),
    CONSTRAINT {{table:c_clr_bytes}} CHECK (
        `document_bytes` BETWEEN 1 AND 300000
    ),
    CONSTRAINT {{table:c_clr_doc_hash}} CHECK (
        `document_sha256` REGEXP '^[0-9a-f]{64}$'
    ),
    CONSTRAINT {{table:c_clr_snap_hash}} CHECK (
        `snapshot_sha256` REGEXP '^[0-9a-f]{64}$'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqliteLayoutEditorStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:content_layout_docs}} (
    "document_id" INTEGER NOT NULL PRIMARY KEY
        REFERENCES {{table:content_docs}} ("id") ON DELETE CASCADE,
    "schema_version" INTEGER NOT NULL DEFAULT 2
        CHECK ("schema_version" = 2),
    "template_key" TEXT COLLATE BINARY NOT NULL CHECK (
        length("template_key") BETWEEN 1 AND 64
        AND "template_key" = lower("template_key")
        AND "template_key" = trim("template_key")
        AND substr("template_key", 1, 1) GLOB '[a-z]'
        AND "template_key" NOT GLOB '*[^a-z0-9_-]*'
    ),
    "document_json" TEXT NOT NULL,
    "document_bytes" INTEGER NOT NULL
        CHECK ("document_bytes" BETWEEN 1 AND 300000),
    "document_sha256" TEXT COLLATE BINARY NOT NULL CHECK (
        length("document_sha256") = 64
        AND "document_sha256" NOT GLOB '*[^0-9a-f]*'
    ),
    "snapshot_sha256" TEXT COLLATE BINARY NOT NULL CHECK (
        length("snapshot_sha256") = 64
        AND "snapshot_sha256" NOT GLOB '*[^0-9a-f]*'
    )
) WITHOUT ROWID
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:content_layout_revisions}} (
    "revision_id" INTEGER NOT NULL PRIMARY KEY
        REFERENCES {{table:content_revisions}} ("id") ON DELETE CASCADE,
    "schema_version" INTEGER NOT NULL DEFAULT 2
        CHECK ("schema_version" = 2),
    "template_key" TEXT COLLATE BINARY NOT NULL CHECK (
        length("template_key") BETWEEN 1 AND 64
        AND "template_key" = lower("template_key")
        AND "template_key" = trim("template_key")
        AND substr("template_key", 1, 1) GLOB '[a-z]'
        AND "template_key" NOT GLOB '*[^a-z0-9_-]*'
    ),
    "document_json" TEXT NOT NULL,
    "document_bytes" INTEGER NOT NULL
        CHECK ("document_bytes" BETWEEN 1 AND 300000),
    "document_sha256" TEXT COLLATE BINARY NOT NULL CHECK (
        length("document_sha256") = 64
        AND "document_sha256" NOT GLOB '*[^0-9a-f]*'
    ),
    "snapshot_sha256" TEXT COLLATE BINARY NOT NULL CHECK (
        length("snapshot_sha256") = 64
        AND "snapshot_sha256" NOT GLOB '*[^0-9a-f]*'
    )
) WITHOUT ROWID
SQL,
        ];
    }

    /** @return list<string> */
    private static function mysqlAnalyticsStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:analytics_sessions}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `visitor_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `landing_localization_id` BIGINT UNSIGNED NOT NULL,
    `is_returning` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `pageview_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `engagement_msec` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `started_at` DATETIME(6) NOT NULL,
    `last_activity_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_blog_as_session` (`session_hash`),
    KEY `idx_blog_as_visitor_time` (`visitor_hash`, `started_at`),
    KEY `idx_blog_as_landing_time` (`landing_localization_id`, `started_at`),
    KEY `idx_blog_as_activity` (`last_activity_at`),
    CONSTRAINT {{table:f_as_landing}} FOREIGN KEY (`landing_localization_id`)
        REFERENCES {{table:post_localizations}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_as_session}} CHECK (
        CHAR_LENGTH(`session_hash`) = 64 AND `session_hash` = LOWER(`session_hash`)
    ),
    CONSTRAINT {{table:c_as_visitor}} CHECK (
        CHAR_LENGTH(`visitor_hash`) = 64 AND `visitor_hash` = LOWER(`visitor_hash`)
    ),
    CONSTRAINT {{table:c_as_returning}} CHECK (`is_returning` IN (0, 1)),
    CONSTRAINT {{table:c_as_time}} CHECK (`last_activity_at` >= `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:analytics_views}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `session_id` BIGINT UNSIGNED NOT NULL,
    `localization_id` BIGINT UNSIGNED NOT NULL,
    `engagement_msec` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `last_sequence` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `started_at` DATETIME(6) NOT NULL,
    `last_activity_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_blog_av_public` (`public_id`),
    KEY `idx_blog_av_local_time` (`localization_id`, `started_at`),
    KEY `idx_blog_av_session_time` (`session_id`, `started_at`),
    CONSTRAINT {{table:f_av_session}} FOREIGN KEY (`session_id`)
        REFERENCES {{table:analytics_sessions}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_av_local}} FOREIGN KEY (`localization_id`)
        REFERENCES {{table:post_localizations}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_av_public}} CHECK (
        CHAR_LENGTH(`public_id`) = 36 AND `public_id` = LOWER(`public_id`)
    ),
    CONSTRAINT {{table:c_av_time}} CHECK (`last_activity_at` >= `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqliteAnalyticsStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:analytics_sessions}} (
    "id" INTEGER PRIMARY KEY,
    "session_hash" TEXT COLLATE BINARY NOT NULL CHECK (
        length("session_hash") = 64
        AND "session_hash" = lower("session_hash")
        AND "session_hash" NOT GLOB '*[^0-9a-f]*'
    ),
    "visitor_hash" TEXT COLLATE BINARY NOT NULL CHECK (
        length("visitor_hash") = 64
        AND "visitor_hash" = lower("visitor_hash")
        AND "visitor_hash" NOT GLOB '*[^0-9a-f]*'
    ),
    "landing_localization_id" INTEGER NOT NULL
        REFERENCES {{table:post_localizations}} ("id") ON DELETE CASCADE,
    "is_returning" INTEGER NOT NULL DEFAULT 0
        CHECK ("is_returning" IN (0, 1)),
    "pageview_count" INTEGER NOT NULL DEFAULT 0
        CHECK ("pageview_count" >= 0),
    "engagement_msec" INTEGER NOT NULL DEFAULT 0
        CHECK ("engagement_msec" >= 0),
    "started_at" TEXT NOT NULL,
    "last_activity_at" TEXT NOT NULL
        CHECK ("last_activity_at" >= "started_at")
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_as_session}} '
                . 'ON {{table:analytics_sessions}} ("session_hash")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_as_visitor_time}} '
                . 'ON {{table:analytics_sessions}} '
                . '("visitor_hash", "started_at")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_as_landing_time}} '
                . 'ON {{table:analytics_sessions}} '
                . '("landing_localization_id", "started_at")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_as_activity}} '
                . 'ON {{table:analytics_sessions}} ("last_activity_at")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:analytics_views}} (
    "id" INTEGER PRIMARY KEY,
    "public_id" TEXT COLLATE BINARY NOT NULL CHECK (
        length("public_id") = 36
        AND "public_id" = lower("public_id")
    ),
    "session_id" INTEGER NOT NULL
        REFERENCES {{table:analytics_sessions}} ("id") ON DELETE CASCADE,
    "localization_id" INTEGER NOT NULL
        REFERENCES {{table:post_localizations}} ("id") ON DELETE CASCADE,
    "engagement_msec" INTEGER NOT NULL DEFAULT 0
        CHECK ("engagement_msec" >= 0),
    "last_sequence" INTEGER NOT NULL DEFAULT 0
        CHECK ("last_sequence" >= 0),
    "started_at" TEXT NOT NULL,
    "last_activity_at" TEXT NOT NULL
        CHECK ("last_activity_at" >= "started_at")
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_av_public}} '
                . 'ON {{table:analytics_views}} ("public_id")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_av_local_time}} '
                . 'ON {{table:analytics_views}} '
                . '("localization_id", "started_at")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_av_session_time}} '
                . 'ON {{table:analytics_views}} '
                . '("session_id", "started_at")',
        ];
    }

    /** @return list<string> */
    private static function mysqlAnalyticsCapabilityStatements(): array
    {
        $code = BlogAnalyticsCapabilities::VIEW;
        $label = BlogAnalyticsCapabilities::VIEW_LABEL;

        return [
            "INSERT IGNORE INTO {{table:capabilities}} "
                . "(`module_id`, `code`, `label_key`, `is_delegable`) "
                . "VALUES ('blog', '{$code}', '{$label}', 1)",
            "INSERT INTO {{table:role_capabilities}} "
                . "(`role_id`, `capability_id`) SELECT `r`.`id`, `c`.`id` "
                . "FROM {{table:roles}} AS `r` CROSS JOIN "
                . "{{table:capabilities}} AS `c` WHERE `r`.`code` IN "
                . "('system_superadmin', 'site_admin') AND "
                . "`r`.`is_protected` = 1 AND `c`.`module_id` = 'blog' "
                . "AND `c`.`code` = '{$code}' AND `c`.`label_key` = "
                . "'{$label}' AND `c`.`is_delegable` = 1 "
                . "ON DUPLICATE KEY UPDATE `role_id` = VALUES(`role_id`)",
        ];
    }

    /** @return list<string> */
    private static function sqliteAnalyticsCapabilityStatements(): array
    {
        $code = BlogAnalyticsCapabilities::VIEW;
        $label = BlogAnalyticsCapabilities::VIEW_LABEL;

        return [
            "INSERT INTO {{table:capabilities}} "
                . "(\"module_id\", \"code\", \"label_key\", \"is_delegable\") "
                . "VALUES ('blog', '{$code}', '{$label}', 1) "
                . 'ON CONFLICT("code") DO NOTHING',
            "INSERT INTO {{table:role_capabilities}} "
                . "(\"role_id\", \"capability_id\") SELECT \"r\".\"id\", "
                . "\"c\".\"id\" FROM {{table:roles}} AS \"r\" CROSS JOIN "
                . "{{table:capabilities}} AS \"c\" WHERE \"r\".\"code\" IN "
                . "('system_superadmin', 'site_admin') AND "
                . "\"r\".\"is_protected\" = 1 AND \"c\".\"module_id\" = "
                . "'blog' AND \"c\".\"code\" = '{$code}' AND "
                . "\"c\".\"label_key\" = '{$label}' AND "
                . "\"c\".\"is_delegable\" = 1 "
                . 'ON CONFLICT("role_id", "capability_id") DO NOTHING',
        ];
    }

    /** @return list<string> */
    private static function mysqlPostTombstoneStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:post_tombstones}} (
    `post_localization_id` BIGINT UNSIGNED NOT NULL,
    `trashed_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `trashed_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`post_localization_id`),
    KEY `idx_blog_post_tombstone_time` (`trashed_at`, `post_localization_id`),
    CONSTRAINT {{table:f_pt_localization}} FOREIGN KEY (`post_localization_id`)
        REFERENCES {{table:post_localizations}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_pt_actor}} CHECK (
        CHAR_LENGTH(`trashed_by_user_public_id`) = 36
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqlitePostTombstoneStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:post_tombstones}} (
    "post_localization_id" INTEGER NOT NULL PRIMARY KEY
        REFERENCES {{table:post_localizations}} ("id") ON DELETE CASCADE,
    "trashed_by_user_public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("trashed_by_user_public_id") = 36),
    "trashed_at" TEXT NOT NULL
) WITHOUT ROWID
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_pt_time}} '
                . 'ON {{table:post_tombstones}} '
                . '("trashed_at", "post_localization_id")',
        ];
    }

    /** @return list<string> */
    private static function mysqlArticleDeleteCapabilityStatements(): array
    {
        return [
            <<<'SQL'
INSERT IGNORE INTO {{table:capabilities}}
    (`module_id`, `code`, `label_key`, `is_delegable`)
VALUES
    ('blog', 'blog.articles.delete', 'blog.capabilities.articles_delete', 1)
SQL,
            <<<'SQL'
INSERT INTO {{table:role_capabilities}} (`role_id`, `capability_id`)
SELECT `r`.`id`, `c`.`id`
FROM {{table:roles}} AS `r`
CROSS JOIN {{table:capabilities}} AS `c`
WHERE `r`.`code` IN ('system_superadmin', 'site_admin')
    AND `r`.`is_protected` = 1
    AND `c`.`module_id` = 'blog'
    AND `c`.`code` = 'blog.articles.delete'
    AND `c`.`label_key` = 'blog.capabilities.articles_delete'
    AND `c`.`is_delegable` = 1
ON DUPLICATE KEY UPDATE
    `role_id` = VALUES(`role_id`)
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqliteArticleDeleteCapabilityStatements(): array
    {
        return [
            <<<'SQL'
INSERT INTO {{table:capabilities}}
    ("module_id", "code", "label_key", "is_delegable")
VALUES
    ('blog', 'blog.articles.delete', 'blog.capabilities.articles_delete', 1)
ON CONFLICT("code") DO NOTHING
SQL,
            <<<'SQL'
INSERT INTO {{table:role_capabilities}} ("role_id", "capability_id")
SELECT "r"."id", "c"."id"
FROM {{table:roles}} AS "r"
CROSS JOIN {{table:capabilities}} AS "c"
WHERE "r"."code" IN ('system_superadmin', 'site_admin')
    AND "r"."is_protected" = 1
    AND "c"."module_id" = 'blog'
    AND "c"."code" = 'blog.articles.delete'
    AND "c"."label_key" = 'blog.capabilities.articles_delete'
    AND "c"."is_delegable" = 1
ON CONFLICT("role_id", "capability_id") DO NOTHING
SQL,
        ];
    }

    /** @return list<string> */
    private static function mysqlSitemapStateStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:sitemap_state}} (
    `state_key` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `public_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `cache_generation` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`state_key`),
    CONSTRAINT {{table:c_ss_key}} CHECK (`state_key` = 'sitemap'),
    CONSTRAINT {{table:c_ss_revision}} CHECK (`public_revision` > 0),
    CONSTRAINT {{table:c_ss_generation}} CHECK (
        `cache_generation` IS NULL OR (
            CHAR_LENGTH(`cache_generation`) = 36
            AND `cache_generation` = LOWER(`cache_generation`)
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
INSERT INTO {{table:sitemap_state}}
    (`state_key`, `public_revision`, `cache_generation`)
VALUES ('sitemap', 1, NULL)
ON DUPLICATE KEY UPDATE `state_key` = VALUES(`state_key`)
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqliteSitemapStateStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:sitemap_state}} (
    "state_key" TEXT COLLATE BINARY NOT NULL PRIMARY KEY
        CHECK ("state_key" = 'sitemap'),
    "public_revision" INTEGER NOT NULL DEFAULT 1
        CHECK ("public_revision" > 0),
    "cache_generation" TEXT COLLATE BINARY NULL CHECK (
        "cache_generation" IS NULL OR (
            length("cache_generation") = 36
            AND "cache_generation" = lower("cache_generation")
        )
    ),
    "updated_at" TEXT NOT NULL
        DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
) WITHOUT ROWID
SQL,
            <<<'SQL'
INSERT INTO {{table:sitemap_state}}
    ("state_key", "public_revision", "cache_generation")
VALUES ('sitemap', 1, NULL)
ON CONFLICT("state_key") DO NOTHING
SQL,
        ];
    }

    /** @return list<string> */
    private static function mysqlSchemaStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:posts}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_blog_posts_public` (`public_id`),
    KEY `idx_blog_posts_author` (`created_by_user_public_id`),
    CONSTRAINT {{table:c_po_public}} CHECK (CHAR_LENGTH(`public_id`) = 36),
    CONSTRAINT {{table:c_po_author}} CHECK (CHAR_LENGTH(`created_by_user_public_id`) = 36)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:post_localizations}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `post_id` BIGINT UNSIGNED NOT NULL,
    `locale` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `slug` VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `h1` VARCHAR(255) NOT NULL,
    `seo_title` VARCHAR(255) NULL,
    `meta_description` VARCHAR(320) NULL,
    `excerpt` TEXT NULL,
    `body_text` LONGTEXT NOT NULL,
    `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
    `published_at` DATETIME(6) NULL,
    `lock_version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `created_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `updated_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_blog_local_public` (`public_id`),
    UNIQUE KEY `uq_blog_post_locale` (`post_id`, `locale`),
    UNIQUE KEY `uq_blog_locale_slug` (`locale`, `slug`),
    KEY `idx_blog_local_state` (`status`, `published_at`),
    CONSTRAINT {{table:f_pl_post}} FOREIGN KEY (`post_id`)
        REFERENCES {{table:posts}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_pl_public}} CHECK (CHAR_LENGTH(`public_id`) = 36),
    CONSTRAINT {{table:c_pl_locale}} CHECK (
        CHAR_LENGTH(`locale`) BETWEEN 2 AND 16
        AND `locale` = LOWER(`locale`)
        AND `locale` = TRIM(`locale`)
    ),
    CONSTRAINT {{table:c_pl_slug}} CHECK (
        `slug` IS NULL OR (
            CHAR_LENGTH(TRIM(`slug`)) > 0 AND `slug` = LOWER(`slug`)
            AND `slug` = TRIM(`slug`)
        )
    ),
    CONSTRAINT {{table:c_pl_h1}} CHECK (CHAR_LENGTH(TRIM(`h1`)) > 0),
    CONSTRAINT {{table:c_pl_status}} CHECK (`status` IN ('draft', 'published')),
    CONSTRAINT {{table:c_pl_publish}} CHECK (
        (`status` = 'draft' AND `published_at` IS NULL)
        OR (
            `status` = 'published'
            AND `published_at` IS NOT NULL
            AND `slug` IS NOT NULL
            AND `seo_title` IS NOT NULL
            AND CHAR_LENGTH(TRIM(`seo_title`)) > 0
            AND `meta_description` IS NOT NULL
            AND CHAR_LENGTH(TRIM(`meta_description`)) > 0
            AND `excerpt` IS NOT NULL
            AND CHAR_LENGTH(TRIM(`excerpt`)) > 0
            AND CHAR_LENGTH(TRIM(`body_text`)) > 0
        )
    ),
    CONSTRAINT {{table:c_pl_lock}} CHECK (`lock_version` > 0),
    CONSTRAINT {{table:c_pl_created}} CHECK (
        CHAR_LENGTH(`created_by_user_public_id`) = 36
    ),
    CONSTRAINT {{table:c_pl_updated}} CHECK (
        CHAR_LENGTH(`updated_by_user_public_id`) = 36
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqliteSchemaStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:posts}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("public_id") = 36),
    "created_by_user_public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("created_by_user_public_id") = 36),
    "created_at" TEXT NOT NULL
        DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL
        DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_po_public}} '
                . 'ON {{table:posts}} ("public_id")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_po_author}} '
                . 'ON {{table:posts}} ("created_by_user_public_id")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:post_localizations}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("public_id") = 36),
    "post_id" INTEGER NOT NULL
        REFERENCES {{table:posts}} ("id") ON DELETE CASCADE,
    "locale" TEXT COLLATE BINARY NOT NULL
        CHECK (
            length("locale") BETWEEN 2 AND 16
            AND "locale" = lower("locale")
            AND "locale" = trim("locale")
        ),
    "slug" TEXT COLLATE BINARY NULL
        CHECK (
            "slug" IS NULL
            OR (
                length(trim("slug")) > 0
                AND "slug" = lower("slug")
                AND "slug" = trim("slug")
            )
        ),
    "h1" TEXT NOT NULL CHECK (length(trim("h1")) > 0),
    "seo_title" TEXT NULL,
    "meta_description" TEXT NULL,
    "excerpt" TEXT NULL,
    "body_text" TEXT NOT NULL,
    "status" TEXT COLLATE BINARY NOT NULL DEFAULT 'draft'
        CHECK ("status" IN ('draft', 'published')),
    "published_at" TEXT NULL,
    "lock_version" INTEGER NOT NULL DEFAULT 1 CHECK ("lock_version" > 0),
    "created_by_user_public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("created_by_user_public_id") = 36),
    "updated_by_user_public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("updated_by_user_public_id") = 36),
    "created_at" TEXT NOT NULL
        DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL
        DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    CHECK (
        ("status" = 'draft' AND "published_at" IS NULL)
        OR (
            "status" = 'published'
            AND "published_at" IS NOT NULL
            AND "slug" IS NOT NULL
            AND "seo_title" IS NOT NULL
            AND length(trim("seo_title")) > 0
            AND "meta_description" IS NOT NULL
            AND length(trim("meta_description")) > 0
            AND "excerpt" IS NOT NULL
            AND length(trim("excerpt")) > 0
            AND length(trim("body_text")) > 0
        )
    )
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_pl_public}} '
                . 'ON {{table:post_localizations}} ("public_id")',
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_pl_post_locale}} '
                . 'ON {{table:post_localizations}} ("post_id", "locale")',
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_pl_locale_slug}} '
                . 'ON {{table:post_localizations}} ("locale", "slug")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_pl_state}} '
                . 'ON {{table:post_localizations}} ("status", "published_at")',
        ];
    }

    /** @return list<string> */
    private static function mysqlCapabilityStatements(): array
    {
        return [
            <<<'SQL'
INSERT IGNORE INTO {{table:capabilities}}
    (`module_id`, `code`, `label_key`, `is_delegable`)
VALUES
    ('blog', 'blog.articles.view', 'blog.capabilities.articles_view', 1),
    ('blog', 'blog.articles.edit', 'blog.capabilities.articles_edit', 1),
    ('blog', 'blog.articles.publish', 'blog.capabilities.articles_publish', 1)
SQL,
            <<<'SQL'
INSERT INTO {{table:role_capabilities}} (`role_id`, `capability_id`)
SELECT `r`.`id`, `c`.`id`
FROM {{table:roles}} AS `r`
CROSS JOIN {{table:capabilities}} AS `c`
WHERE `r`.`code` IN ('system_superadmin', 'site_admin')
    AND `r`.`is_protected` = 1
    AND `c`.`code` IN (
        'blog.articles.view',
        'blog.articles.edit',
        'blog.articles.publish'
    )
    AND `c`.`module_id` = 'blog'
    AND `c`.`is_delegable` = 1
    AND (
        (`c`.`code` = 'blog.articles.view'
            AND `c`.`label_key` = 'blog.capabilities.articles_view')
        OR (`c`.`code` = 'blog.articles.edit'
            AND `c`.`label_key` = 'blog.capabilities.articles_edit')
        OR (`c`.`code` = 'blog.articles.publish'
            AND `c`.`label_key` = 'blog.capabilities.articles_publish')
    )
ON DUPLICATE KEY UPDATE
    `role_id` = VALUES(`role_id`)
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqliteCapabilityStatements(): array
    {
        return [
            <<<'SQL'
INSERT INTO {{table:capabilities}}
    ("module_id", "code", "label_key", "is_delegable")
VALUES
    ('blog', 'blog.articles.view', 'blog.capabilities.articles_view', 1),
    ('blog', 'blog.articles.edit', 'blog.capabilities.articles_edit', 1),
    ('blog', 'blog.articles.publish', 'blog.capabilities.articles_publish', 1)
ON CONFLICT("code") DO NOTHING
SQL,
            <<<'SQL'
INSERT INTO {{table:role_capabilities}} ("role_id", "capability_id")
SELECT "r"."id", "c"."id"
FROM {{table:roles}} AS "r"
CROSS JOIN {{table:capabilities}} AS "c"
WHERE "r"."code" IN ('system_superadmin', 'site_admin')
    AND "r"."is_protected" = 1
    AND "c"."code" IN (
        'blog.articles.view',
        'blog.articles.edit',
        'blog.articles.publish'
    )
    AND "c"."module_id" = 'blog'
    AND "c"."is_delegable" = 1
    AND (
        ("c"."code" = 'blog.articles.view'
            AND "c"."label_key" = 'blog.capabilities.articles_view')
        OR ("c"."code" = 'blog.articles.edit'
            AND "c"."label_key" = 'blog.capabilities.articles_edit')
        OR ("c"."code" = 'blog.articles.publish'
            AND "c"."label_key" = 'blog.capabilities.articles_publish')
    )
ON CONFLICT("role_id", "capability_id") DO NOTHING
SQL,
        ];
    }

    /** @return list<string> */
    private static function mysqlCategoryStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:categories}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_blog_categories_public` (`public_id`),
    KEY `idx_blog_categories_author` (`created_by_user_public_id`),
    CONSTRAINT {{table:c_ca_public}} CHECK (CHAR_LENGTH(`public_id`) = 36),
    CONSTRAINT {{table:c_ca_author}} CHECK (CHAR_LENGTH(`created_by_user_public_id`) = 36)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:category_locales}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `category_id` BIGINT UNSIGNED NOT NULL,
    `locale` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `slug` VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `lock_version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `created_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `updated_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_blog_category_local_public` (`public_id`),
    UNIQUE KEY `uq_blog_category_locale` (`category_id`, `locale`),
    UNIQUE KEY `uq_blog_category_locale_slug` (`locale`, `slug`),
    KEY `idx_blog_category_name` (`locale`, `name`),
    CONSTRAINT {{table:f_cl_category}} FOREIGN KEY (`category_id`)
        REFERENCES {{table:categories}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_cl_public}} CHECK (CHAR_LENGTH(`public_id`) = 36),
    CONSTRAINT {{table:c_cl_locale}} CHECK (
        CHAR_LENGTH(`locale`) BETWEEN 2 AND 16
        AND `locale` = LOWER(`locale`)
        AND `locale` = TRIM(`locale`)
    ),
    CONSTRAINT {{table:c_cl_slug}} CHECK (
        CHAR_LENGTH(TRIM(`slug`)) > 0
        AND `slug` = LOWER(`slug`)
        AND `slug` = TRIM(`slug`)
    ),
    CONSTRAINT {{table:c_cl_name}} CHECK (CHAR_LENGTH(TRIM(`name`)) > 0),
    CONSTRAINT {{table:c_cl_lock}} CHECK (`lock_version` > 0),
    CONSTRAINT {{table:c_cl_created}} CHECK (
        CHAR_LENGTH(`created_by_user_public_id`) = 36
    ),
    CONSTRAINT {{table:c_cl_updated}} CHECK (
        CHAR_LENGTH(`updated_by_user_public_id`) = 36
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:post_categories}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `post_id` BIGINT UNSIGNED NOT NULL,
    `category_id` BIGINT UNSIGNED NOT NULL,
    `assigned_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_blog_post_category_public` (`public_id`),
    UNIQUE KEY `uq_blog_post_category_pair` (`post_id`, `category_id`),
    KEY `idx_blog_post_category_category` (`category_id`, `post_id`),
    CONSTRAINT {{table:f_pc_post}} FOREIGN KEY (`post_id`)
        REFERENCES {{table:posts}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_pc_category}} FOREIGN KEY (`category_id`)
        REFERENCES {{table:categories}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_pc_public}} CHECK (CHAR_LENGTH(`public_id`) = 36),
    CONSTRAINT {{table:c_pc_actor}} CHECK (
        CHAR_LENGTH(`assigned_by_user_public_id`) = 36
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqliteCategoryStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:categories}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "public_id" TEXT COLLATE BINARY NOT NULL CHECK (length("public_id") = 36),
    "created_by_user_public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("created_by_user_public_id") = 36),
    "created_at" TEXT NOT NULL
        DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL
        DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_ca_public}} '
                . 'ON {{table:categories}} ("public_id")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_ca_author}} '
                . 'ON {{table:categories}} ("created_by_user_public_id")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:category_locales}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "public_id" TEXT COLLATE BINARY NOT NULL CHECK (length("public_id") = 36),
    "category_id" INTEGER NOT NULL
        REFERENCES {{table:categories}} ("id") ON DELETE CASCADE,
    "locale" TEXT COLLATE BINARY NOT NULL CHECK (
        length("locale") BETWEEN 2 AND 16
        AND "locale" = lower("locale")
        AND "locale" = trim("locale")
    ),
    "slug" TEXT COLLATE BINARY NOT NULL CHECK (
        length(trim("slug")) > 0
        AND "slug" = lower("slug")
        AND "slug" = trim("slug")
    ),
    "name" TEXT NOT NULL CHECK (length(trim("name")) > 0),
    "lock_version" INTEGER NOT NULL DEFAULT 1 CHECK ("lock_version" > 0),
    "created_by_user_public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("created_by_user_public_id") = 36),
    "updated_by_user_public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("updated_by_user_public_id") = 36),
    "created_at" TEXT NOT NULL
        DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL
        DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_cl_public}} '
                . 'ON {{table:category_locales}} ("public_id")',
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_cl_cat_locale}} '
                . 'ON {{table:category_locales}} ("category_id", "locale")',
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_cl_locale_slug}} '
                . 'ON {{table:category_locales}} ("locale", "slug")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_cl_name}} '
                . 'ON {{table:category_locales}} ("locale", "name")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:post_categories}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "public_id" TEXT COLLATE BINARY NOT NULL CHECK (length("public_id") = 36),
    "post_id" INTEGER NOT NULL
        REFERENCES {{table:posts}} ("id") ON DELETE CASCADE,
    "category_id" INTEGER NOT NULL
        REFERENCES {{table:categories}} ("id") ON DELETE CASCADE,
    "assigned_by_user_public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("assigned_by_user_public_id") = 36),
    "created_at" TEXT NOT NULL
        DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL
        DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_pc_public}} '
                . 'ON {{table:post_categories}} ("public_id")',
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_pc_pair}} '
                . 'ON {{table:post_categories}} ("post_id", "category_id")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_pc_category}} '
                . 'ON {{table:post_categories}} ("category_id", "post_id")',
        ];
    }

    /** @return list<string> */
    private static function mysqlCategoryCapabilityStatements(): array
    {
        return [
            <<<'SQL'
INSERT IGNORE INTO {{table:capabilities}}
    (`module_id`, `code`, `label_key`, `is_delegable`)
VALUES
    ('blog', 'blog.categories.view', 'blog.capabilities.categories_view', 1),
    ('blog', 'blog.categories.edit', 'blog.capabilities.categories_edit', 1)
SQL,
            <<<'SQL'
INSERT INTO {{table:role_capabilities}} (`role_id`, `capability_id`)
SELECT `r`.`id`, `c`.`id`
FROM {{table:roles}} AS `r`
CROSS JOIN {{table:capabilities}} AS `c`
WHERE `r`.`code` IN ('system_superadmin', 'site_admin')
    AND `r`.`is_protected` = 1
    AND `c`.`code` IN ('blog.categories.view', 'blog.categories.edit')
    AND `c`.`module_id` = 'blog'
    AND `c`.`is_delegable` = 1
    AND (
        (`c`.`code` = 'blog.categories.view'
            AND `c`.`label_key` = 'blog.capabilities.categories_view')
        OR (`c`.`code` = 'blog.categories.edit'
            AND `c`.`label_key` = 'blog.capabilities.categories_edit')
    )
ON DUPLICATE KEY UPDATE
    `role_id` = VALUES(`role_id`)
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqliteCategoryCapabilityStatements(): array
    {
        return [
            <<<'SQL'
INSERT INTO {{table:capabilities}}
    ("module_id", "code", "label_key", "is_delegable")
VALUES
    ('blog', 'blog.categories.view', 'blog.capabilities.categories_view', 1),
    ('blog', 'blog.categories.edit', 'blog.capabilities.categories_edit', 1)
ON CONFLICT("code") DO NOTHING
SQL,
            <<<'SQL'
INSERT INTO {{table:role_capabilities}} ("role_id", "capability_id")
SELECT "r"."id", "c"."id"
FROM {{table:roles}} AS "r"
CROSS JOIN {{table:capabilities}} AS "c"
WHERE "r"."code" IN ('system_superadmin', 'site_admin')
    AND "r"."is_protected" = 1
    AND "c"."code" IN ('blog.categories.view', 'blog.categories.edit')
    AND "c"."module_id" = 'blog'
    AND "c"."is_delegable" = 1
    AND (
        ("c"."code" = 'blog.categories.view'
            AND "c"."label_key" = 'blog.capabilities.categories_view')
        OR ("c"."code" = 'blog.categories.edit'
            AND "c"."label_key" = 'blog.capabilities.categories_edit')
    )
ON CONFLICT("role_id", "capability_id") DO NOTHING
SQL,
        ];
    }

    /** @return list<string> */
    private static function mysqlStructuredContentStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:content_docs}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `localization_id` BIGINT UNSIGNED NOT NULL,
    `schema_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `template_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `document_json` LONGTEXT NOT NULL,
    `document_bytes` INT UNSIGNED NOT NULL,
    `document_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `body_text_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `snapshot_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `updated_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_blog_content_docs_public` (`public_id`),
    UNIQUE KEY `uq_blog_content_docs_local` (`localization_id`),
    KEY `idx_blog_content_docs_updated` (`updated_at`),
    CONSTRAINT {{table:f_cd_localization}} FOREIGN KEY (`localization_id`)
        REFERENCES {{table:post_localizations}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_cd_public}} CHECK (CHAR_LENGTH(`public_id`) = 36),
    CONSTRAINT {{table:c_cd_schema}} CHECK (`schema_version` = 1),
    CONSTRAINT {{table:c_cd_template}} CHECK (
        CHAR_LENGTH(`template_key`) BETWEEN 1 AND 64
        AND `template_key` = LOWER(`template_key`)
        AND `template_key` = TRIM(`template_key`)
        AND `template_key` REGEXP '^[a-z][a-z0-9_-]{0,63}$'
    ),
    CONSTRAINT {{table:c_cd_bytes}} CHECK (
        `document_bytes` BETWEEN 1 AND 300000
    ),
    CONSTRAINT {{table:c_cd_doc_hash}} CHECK (
        `document_sha256` REGEXP '^[0-9a-f]{64}$'
    ),
    CONSTRAINT {{table:c_cd_body_hash}} CHECK (
        `body_text_sha256` REGEXP '^[0-9a-f]{64}$'
    ),
    CONSTRAINT {{table:c_cd_snap_hash}} CHECK (
        `snapshot_sha256` REGEXP '^[0-9a-f]{64}$'
    ),
    CONSTRAINT {{table:c_cd_created}} CHECK (
        CHAR_LENGTH(`created_by_user_public_id`) = 36
    ),
    CONSTRAINT {{table:c_cd_updated}} CHECK (
        CHAR_LENGTH(`updated_by_user_public_id`) = 36
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:content_revisions}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `localization_id` BIGINT UNSIGNED NOT NULL,
    `revision_number` BIGINT UNSIGNED NOT NULL,
    `variant_lock_version` BIGINT UNSIGNED NOT NULL,
    `schema_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `template_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `document_json` LONGTEXT NOT NULL,
    `document_bytes` INT UNSIGNED NOT NULL,
    `document_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `body_text_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `snapshot_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `h1` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `seo_title` VARCHAR(255) NULL,
    `meta_description` VARCHAR(320) NULL,
    `excerpt` TEXT NULL,
    `body_text` LONGTEXT NOT NULL,
    `created_by_user_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_blog_content_revisions_public` (`public_id`),
    UNIQUE KEY `uq_blog_content_revisions_number`
        (`localization_id`, `revision_number`),
    UNIQUE KEY `uq_blog_content_revisions_variant`
        (`localization_id`, `variant_lock_version`),
    KEY `idx_blog_content_revisions_time` (`created_at`),
    CONSTRAINT {{table:f_cr_localization}} FOREIGN KEY (`localization_id`)
        REFERENCES {{table:post_localizations}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_cr_public}} CHECK (CHAR_LENGTH(`public_id`) = 36),
    CONSTRAINT {{table:c_cr_revision}} CHECK (`revision_number` > 0),
    CONSTRAINT {{table:c_cr_variant}} CHECK (`variant_lock_version` > 0),
    CONSTRAINT {{table:c_cr_schema}} CHECK (`schema_version` = 1),
    CONSTRAINT {{table:c_cr_template}} CHECK (
        CHAR_LENGTH(`template_key`) BETWEEN 1 AND 64
        AND `template_key` = LOWER(`template_key`)
        AND `template_key` = TRIM(`template_key`)
        AND `template_key` REGEXP '^[a-z][a-z0-9_-]{0,63}$'
    ),
    CONSTRAINT {{table:c_cr_bytes}} CHECK (
        `document_bytes` BETWEEN 1 AND 300000
    ),
    CONSTRAINT {{table:c_cr_doc_hash}} CHECK (
        `document_sha256` REGEXP '^[0-9a-f]{64}$'
    ),
    CONSTRAINT {{table:c_cr_body_hash}} CHECK (
        `body_text_sha256` REGEXP '^[0-9a-f]{64}$'
    ),
    CONSTRAINT {{table:c_cr_snap_hash}} CHECK (
        `snapshot_sha256` REGEXP '^[0-9a-f]{64}$'
    ),
    CONSTRAINT {{table:c_cr_h1}} CHECK (CHAR_LENGTH(TRIM(`h1`)) > 0),
    CONSTRAINT {{table:c_cr_slug}} CHECK (
        `slug` IS NULL OR (
            CHAR_LENGTH(TRIM(`slug`)) > 0
            AND `slug` = LOWER(`slug`)
            AND `slug` = TRIM(`slug`)
        )
    ),
    CONSTRAINT {{table:c_cr_created}} CHECK (
        CHAR_LENGTH(`created_by_user_public_id`) = 36
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:content_media}} (
    `document_id` BIGINT UNSIGNED NOT NULL,
    `block_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `media_asset_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `role` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`document_id`, `block_public_id`, `role`),
    KEY `idx_blog_content_media_asset` (`media_asset_public_id`),
    CONSTRAINT {{table:f_cm_document}} FOREIGN KEY (`document_id`)
        REFERENCES {{table:content_docs}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_cm_block}} CHECK (
        CHAR_LENGTH(`block_public_id`) = 36
    ),
    CONSTRAINT {{table:c_cm_asset}} CHECK (
        CHAR_LENGTH(`media_asset_public_id`) = 36
    ),
    CONSTRAINT {{table:c_cm_role}} CHECK (
        `role` IN ('image', 'cover', 'poster')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:revision_media}} (
    `revision_id` BIGINT UNSIGNED NOT NULL,
    `block_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `media_asset_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `role` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`revision_id`, `block_public_id`, `role`),
    KEY `idx_blog_revision_media_asset` (`media_asset_public_id`),
    CONSTRAINT {{table:f_rm_revision}} FOREIGN KEY (`revision_id`)
        REFERENCES {{table:content_revisions}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_rm_block}} CHECK (
        CHAR_LENGTH(`block_public_id`) = 36
    ),
    CONSTRAINT {{table:c_rm_asset}} CHECK (
        CHAR_LENGTH(`media_asset_public_id`) = 36
    ),
    CONSTRAINT {{table:c_rm_role}} CHECK (
        `role` IN ('image', 'cover', 'poster')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqliteStructuredContentStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:content_docs}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("public_id") = 36),
    "localization_id" INTEGER NOT NULL
        REFERENCES {{table:post_localizations}} ("id") ON DELETE CASCADE,
    "schema_version" INTEGER NOT NULL DEFAULT 1
        CHECK ("schema_version" = 1),
    "template_key" TEXT COLLATE BINARY NOT NULL CHECK (
        length("template_key") BETWEEN 1 AND 64
        AND "template_key" = lower("template_key")
        AND "template_key" = trim("template_key")
        AND substr("template_key", 1, 1) GLOB '[a-z]'
        AND "template_key" NOT GLOB '*[^a-z0-9_-]*'
    ),
    "document_json" TEXT NOT NULL,
    "document_bytes" INTEGER NOT NULL
        CHECK ("document_bytes" BETWEEN 1 AND 300000),
    "document_sha256" TEXT COLLATE BINARY NOT NULL CHECK (
        length("document_sha256") = 64
        AND "document_sha256" NOT GLOB '*[^0-9a-f]*'
    ),
    "body_text_sha256" TEXT COLLATE BINARY NOT NULL CHECK (
        length("body_text_sha256") = 64
        AND "body_text_sha256" NOT GLOB '*[^0-9a-f]*'
    ),
    "snapshot_sha256" TEXT COLLATE BINARY NOT NULL CHECK (
        length("snapshot_sha256") = 64
        AND "snapshot_sha256" NOT GLOB '*[^0-9a-f]*'
    ),
    "created_by_user_public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("created_by_user_public_id") = 36),
    "updated_by_user_public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("updated_by_user_public_id") = 36),
    "created_at" TEXT NOT NULL
        DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL
        DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_cd_public}} '
                . 'ON {{table:content_docs}} ("public_id")',
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_cd_local}} '
                . 'ON {{table:content_docs}} ("localization_id")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_cd_updated}} '
                . 'ON {{table:content_docs}} ("updated_at")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:content_revisions}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("public_id") = 36),
    "localization_id" INTEGER NOT NULL
        REFERENCES {{table:post_localizations}} ("id") ON DELETE CASCADE,
    "revision_number" INTEGER NOT NULL CHECK ("revision_number" > 0),
    "variant_lock_version" INTEGER NOT NULL
        CHECK ("variant_lock_version" > 0),
    "schema_version" INTEGER NOT NULL DEFAULT 1
        CHECK ("schema_version" = 1),
    "template_key" TEXT COLLATE BINARY NOT NULL CHECK (
        length("template_key") BETWEEN 1 AND 64
        AND "template_key" = lower("template_key")
        AND "template_key" = trim("template_key")
        AND substr("template_key", 1, 1) GLOB '[a-z]'
        AND "template_key" NOT GLOB '*[^a-z0-9_-]*'
    ),
    "document_json" TEXT NOT NULL,
    "document_bytes" INTEGER NOT NULL
        CHECK ("document_bytes" BETWEEN 1 AND 300000),
    "document_sha256" TEXT COLLATE BINARY NOT NULL CHECK (
        length("document_sha256") = 64
        AND "document_sha256" NOT GLOB '*[^0-9a-f]*'
    ),
    "body_text_sha256" TEXT COLLATE BINARY NOT NULL CHECK (
        length("body_text_sha256") = 64
        AND "body_text_sha256" NOT GLOB '*[^0-9a-f]*'
    ),
    "snapshot_sha256" TEXT COLLATE BINARY NOT NULL CHECK (
        length("snapshot_sha256") = 64
        AND "snapshot_sha256" NOT GLOB '*[^0-9a-f]*'
    ),
    "h1" TEXT NOT NULL CHECK (length(trim("h1")) > 0),
    "slug" TEXT COLLATE BINARY NULL CHECK (
        "slug" IS NULL OR (
            length(trim("slug")) > 0
            AND "slug" = lower("slug")
            AND "slug" = trim("slug")
        )
    ),
    "seo_title" TEXT NULL,
    "meta_description" TEXT NULL,
    "excerpt" TEXT NULL,
    "body_text" TEXT NOT NULL,
    "created_by_user_public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("created_by_user_public_id") = 36),
    "created_at" TEXT NOT NULL
        DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_cr_public}} '
                . 'ON {{table:content_revisions}} ("public_id")',
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_cr_loc_rev}} '
                . 'ON {{table:content_revisions}} '
                . '("localization_id", "revision_number")',
            'CREATE UNIQUE INDEX IF NOT EXISTS {{table:ux_cr_loc_variant}} '
                . 'ON {{table:content_revisions}} '
                . '("localization_id", "variant_lock_version")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_cr_time}} '
                . 'ON {{table:content_revisions}} ("created_at")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:content_media}} (
    "document_id" INTEGER NOT NULL
        REFERENCES {{table:content_docs}} ("id") ON DELETE CASCADE,
    "block_public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("block_public_id") = 36),
    "media_asset_public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("media_asset_public_id") = 36),
    "role" TEXT COLLATE BINARY NOT NULL
        CHECK ("role" IN ('image', 'cover', 'poster')),
    "created_at" TEXT NOT NULL
        DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    PRIMARY KEY ("document_id", "block_public_id", "role")
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_cm_asset}} '
                . 'ON {{table:content_media}} ("media_asset_public_id")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:revision_media}} (
    "revision_id" INTEGER NOT NULL
        REFERENCES {{table:content_revisions}} ("id") ON DELETE CASCADE,
    "block_public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("block_public_id") = 36),
    "media_asset_public_id" TEXT COLLATE BINARY NOT NULL
        CHECK (length("media_asset_public_id") = 36),
    "role" TEXT COLLATE BINARY NOT NULL
        CHECK ("role" IN ('image', 'cover', 'poster')),
    "created_at" TEXT NOT NULL
        DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    PRIMARY KEY ("revision_id", "block_public_id", "role")
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_rm_asset}} '
                . 'ON {{table:revision_media}} ("media_asset_public_id")',
        ];
    }
}
