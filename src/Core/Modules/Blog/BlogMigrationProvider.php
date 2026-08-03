<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

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
