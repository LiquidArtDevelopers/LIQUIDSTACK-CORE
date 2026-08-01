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
}
