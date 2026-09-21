<?php

declare(strict_types=1);

namespace App\Core\Modules\Commerce;

use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationProviderInterface;

final class CommerceMigrationProvider implements MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'commerce';
    }

    public static function migrations(): iterable
    {
        yield MigrationDefinition::sql(
            id: '0001_commerce_catalog',
            description: 'Crea catalogo, taxonomias y atributos Commerce.',
            statementsByDriver: [
                'mysql' => self::mysqlCatalogStatements(),
                'sqlite' => self::sqliteCatalogStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new CommerceMigrationPostconditionVerifier(),
            preconditionVerifier: new CommerceInitialNamespacePrecondition()
        );

        yield MigrationDefinition::sql(
            id: '0002_commerce_inquiries',
            description: 'Crea cestas, solicitudes, outbox y agregados.',
            statementsByDriver: [
                'mysql' => self::mysqlEngagementStatements(),
                'sqlite' => self::sqliteEngagementStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier:
                new CommerceMigrationPostconditionVerifier(true),
            supersedesPostconditions: ['0001_commerce_catalog']
        );

        yield MigrationDefinition::sql(
            id: '0003_commerce_capabilities',
            description: 'Registra capacidades Commerce en WebAdmin.',
            statementsByDriver: [
                'mysql' => self::mysqlCapabilityStatements(),
                'sqlite' => self::sqliteCapabilityStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier: new CommerceCapabilitySeedPostcondition(),
            targetScopeModuleId: 'webadmin'
        );
    }

    /** @return list<string> */
    private static function mysqlCatalogStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:categories}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `parent_id` BIGINT UNSIGNED NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `lock_version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_commerce_categories_public` (`public_id`),
    KEY `idx_commerce_categories_parent` (`parent_id`, `sort_order`, `id`),
    CONSTRAINT {{table:f_cat_parent}} FOREIGN KEY (`parent_id`)
        REFERENCES {{table:categories}} (`id`) ON DELETE RESTRICT,
    CONSTRAINT {{table:c_cat_public}} CHECK (CHAR_LENGTH(`public_id`) = 36),
    CONSTRAINT {{table:c_cat_lock}} CHECK (`lock_version` > 0),
    CONSTRAINT {{table:c_cat_sort}} CHECK (`sort_order` <= 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:category_localizations}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id` BIGINT UNSIGNED NOT NULL,
    `locale` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `name` VARCHAR(255) NULL,
    `slug` VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `translation_status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `lock_version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_commerce_category_locale` (`category_id`, `locale`),
    UNIQUE KEY `uq_commerce_category_slug` (`locale`, `slug`),
    CONSTRAINT {{table:f_cl_category}} FOREIGN KEY (`category_id`)
        REFERENCES {{table:categories}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_cl_status}} CHECK (`translation_status` IN ('source', 'translated', 'fallback')),
    CONSTRAINT {{table:c_cl_lock}} CHECK (`lock_version` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:products}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `sku` VARCHAR(190) NULL,
    `editorial_status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
    `availability_status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'available',
    `price_minor` BIGINT UNSIGNED NULL,
    `currency` CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `canonical_category_id` BIGINT UNSIGNED NULL,
    `lock_version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_commerce_products_public` (`public_id`),
    UNIQUE KEY `uq_commerce_products_sku` (`sku`),
    KEY `idx_commerce_products_state` (`editorial_status`, `availability_status`),
    KEY `idx_commerce_products_category` (`canonical_category_id`),
    CONSTRAINT {{table:f_product_category}} FOREIGN KEY (`canonical_category_id`)
        REFERENCES {{table:categories}} (`id`) ON DELETE SET NULL,
    CONSTRAINT {{table:c_product_public}} CHECK (CHAR_LENGTH(`public_id`) = 36),
    CONSTRAINT {{table:c_product_editorial}} CHECK (`editorial_status` IN ('draft', 'active', 'inactive', 'archived')),
    CONSTRAINT {{table:c_product_availability}} CHECK (`availability_status` IN ('available', 'reserved', 'sold', 'unavailable')),
    CONSTRAINT {{table:c_product_money}} CHECK ((`price_minor` IS NULL AND `currency` IS NULL) OR (`price_minor` IS NOT NULL AND `currency` REGEXP '^[A-Z]{3}$')),
    CONSTRAINT {{table:c_product_lock}} CHECK (`lock_version` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:product_localizations}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `locale` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `title` VARCHAR(255) NULL,
    `slug` VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `summary` TEXT NULL,
    `description` LONGTEXT NULL,
    `seo_title` VARCHAR(255) NULL,
    `seo_description` VARCHAR(320) NULL,
    `translation_status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `public_path` VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `lock_version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_commerce_product_locale` (`product_id`, `locale`),
    UNIQUE KEY `uq_commerce_product_slug` (`locale`, `slug`),
    UNIQUE KEY `uq_commerce_product_path` (`public_path`(190)),
    CONSTRAINT {{table:f_pl_product}} FOREIGN KEY (`product_id`)
        REFERENCES {{table:products}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_pl_status}} CHECK (`translation_status` IN ('source', 'translated', 'fallback')),
    CONSTRAINT {{table:c_pl_lock}} CHECK (`lock_version` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:product_categories}} (
    `product_id` BIGINT UNSIGNED NOT NULL,
    `category_id` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`product_id`, `category_id`),
    KEY `idx_commerce_pc_category` (`category_id`, `product_id`),
    CONSTRAINT {{table:f_pc_product}} FOREIGN KEY (`product_id`)
        REFERENCES {{table:products}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_pc_category}} FOREIGN KEY (`category_id`)
        REFERENCES {{table:categories}} (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:tags}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_commerce_tags_public` (`public_id`),
    CONSTRAINT {{table:c_tag_public}} CHECK (CHAR_LENGTH(`public_id`) = 36)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:tag_localizations}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tag_id` BIGINT UNSIGNED NOT NULL,
    `locale` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `name` VARCHAR(255) NULL,
    `slug` VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `translation_status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_commerce_tag_locale` (`tag_id`, `locale`),
    UNIQUE KEY `uq_commerce_tag_slug` (`locale`, `slug`),
    CONSTRAINT {{table:f_tl_tag}} FOREIGN KEY (`tag_id`)
        REFERENCES {{table:tags}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_tl_status}} CHECK (`translation_status` IN ('source', 'translated', 'fallback'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:product_tags}} (
    `product_id` BIGINT UNSIGNED NOT NULL,
    `tag_id` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`product_id`, `tag_id`),
    KEY `idx_commerce_pt_tag` (`tag_id`, `product_id`),
    CONSTRAINT {{table:f_pt_product}} FOREIGN KEY (`product_id`)
        REFERENCES {{table:products}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_pt_tag}} FOREIGN KEY (`tag_id`)
        REFERENCES {{table:tags}} (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:attributes}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `category_id` BIGINT UNSIGNED NULL,
    `unit` VARCHAR(32) NULL,
    `is_filterable` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_commerce_attributes_public` (`public_id`),
    UNIQUE KEY `uq_commerce_attributes_code` (`code`),
    KEY `idx_commerce_attributes_category` (`category_id`, `sort_order`),
    CONSTRAINT {{table:f_attribute_category}} FOREIGN KEY (`category_id`)
        REFERENCES {{table:categories}} (`id`) ON DELETE SET NULL,
    CONSTRAINT {{table:c_attribute_type}} CHECK (`type` IN ('text', 'number', 'boolean', 'select', 'multiselect', 'date')),
    CONSTRAINT {{table:c_attribute_filter}} CHECK (`is_filterable` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:attribute_localizations}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `attribute_id` BIGINT UNSIGNED NOT NULL,
    `locale` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `name` VARCHAR(255) NULL,
    `translation_status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_commerce_attribute_locale` (`attribute_id`, `locale`),
    CONSTRAINT {{table:f_al_attribute}} FOREIGN KEY (`attribute_id`)
        REFERENCES {{table:attributes}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_al_status}} CHECK (`translation_status` IN ('source', 'translated', 'fallback'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:attribute_options}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `attribute_id` BIGINT UNSIGNED NOT NULL,
    `code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_commerce_options_public` (`public_id`),
    UNIQUE KEY `uq_commerce_options_code` (`attribute_id`, `code`),
    CONSTRAINT {{table:f_option_attribute}} FOREIGN KEY (`attribute_id`)
        REFERENCES {{table:attributes}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:attribute_option_localizations}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `option_id` BIGINT UNSIGNED NOT NULL,
    `locale` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `label` VARCHAR(255) NULL,
    `translation_status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_commerce_option_locale` (`option_id`, `locale`),
    CONSTRAINT {{table:f_aol_option}} FOREIGN KEY (`option_id`)
        REFERENCES {{table:attribute_options}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_aol_status}} CHECK (`translation_status` IN ('source', 'translated', 'fallback'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:product_attribute_values}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `attribute_id` BIGINT UNSIGNED NOT NULL,
    `locale` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    `text_value` TEXT NULL,
    `number_value` DECIMAL(20,6) NULL,
    `boolean_value` TINYINT UNSIGNED NULL,
    `date_value` DATE NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_commerce_product_attribute` (`product_id`, `attribute_id`, `locale`),
    KEY `idx_commerce_pav_attribute` (`attribute_id`, `product_id`),
    CONSTRAINT {{table:f_pav_product}} FOREIGN KEY (`product_id`)
        REFERENCES {{table:products}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_pav_attribute}} FOREIGN KEY (`attribute_id`)
        REFERENCES {{table:attributes}} (`id`) ON DELETE RESTRICT,
    CONSTRAINT {{table:c_pav_boolean}} CHECK (`boolean_value` IS NULL OR `boolean_value` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:product_attribute_value_options}} (
    `value_id` BIGINT UNSIGNED NOT NULL,
    `option_id` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`value_id`, `option_id`),
    KEY `idx_commerce_pavo_option` (`option_id`, `value_id`),
    CONSTRAINT {{table:f_pavo_value}} FOREIGN KEY (`value_id`)
        REFERENCES {{table:product_attribute_values}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_pavo_option}} FOREIGN KEY (`option_id`)
        REFERENCES {{table:attribute_options}} (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:product_media}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `media_asset_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `role` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_commerce_product_media` (`product_id`, `media_asset_public_id`),
    KEY `idx_commerce_media_asset` (`media_asset_public_id`),
    KEY `idx_commerce_media_order` (`product_id`, `role`, `sort_order`),
    CONSTRAINT {{table:f_pm_product}} FOREIGN KEY (`product_id`)
        REFERENCES {{table:products}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_pm_asset}} CHECK (CHAR_LENGTH(`media_asset_public_id`) = 36),
    CONSTRAINT {{table:c_pm_role}} CHECK (`role` IN ('cover', 'gallery'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:product_media_localizations}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `media_id` BIGINT UNSIGNED NOT NULL,
    `locale` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `alt_text` VARCHAR(500) NULL,
    `caption` TEXT NULL,
    `translation_status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_commerce_media_locale` (`media_id`, `locale`),
    CONSTRAINT {{table:f_pml_media}} FOREIGN KEY (`media_id`)
        REFERENCES {{table:product_media}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_pml_status}} CHECK (`translation_status` IN ('source', 'translated', 'fallback')),
    CONSTRAINT {{table:c_pml_content}} CHECK (
        (`translation_status` = 'fallback' AND `alt_text` IS NULL AND `caption` IS NULL)
        OR (`translation_status` IN ('source', 'translated')
            AND `alt_text` IS NOT NULL
            AND CHAR_LENGTH(TRIM(`alt_text`)) BETWEEN 1 AND 500
            AND (`caption` IS NULL OR CHAR_LENGTH(`caption`) <= 2000))
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:url_history}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_localization_id` BIGINT UNSIGNED NOT NULL,
    `old_path` VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `new_path` VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_commerce_url_old` (`old_path`(190)),
    KEY `idx_commerce_url_localization` (`product_localization_id`, `created_at`),
    CONSTRAINT {{table:f_url_localization}} FOREIGN KEY (`product_localization_id`)
        REFERENCES {{table:product_localizations}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }

    /** @return list<string> */
    private static function mysqlEngagementStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:baskets}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `token_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `locale` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open',
    `expires_at` DATETIME(6) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_commerce_baskets_public` (`public_id`),
    UNIQUE KEY `uq_commerce_baskets_token` (`token_sha256`),
    KEY `idx_commerce_baskets_expiry` (`status`, `expires_at`),
    CONSTRAINT {{table:c_basket_status}} CHECK (`status` IN ('open', 'submitted', 'expired'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:basket_items}} (
    `basket_id` BIGINT UNSIGNED NOT NULL,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`basket_id`, `product_id`),
    KEY `idx_commerce_bi_product` (`product_id`, `basket_id`),
    CONSTRAINT {{table:f_bi_basket}} FOREIGN KEY (`basket_id`)
        REFERENCES {{table:baskets}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_bi_product}} FOREIGN KEY (`product_id`)
        REFERENCES {{table:products}} (`id`) ON DELETE RESTRICT,
    CONSTRAINT {{table:c_bi_quantity}} CHECK (`quantity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:inquiries}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `operation_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `payload_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `basket_id` BIGINT UNSIGNED NULL,
    `locale` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `contact_name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(320) NOT NULL,
    `phone` VARCHAR(64) NULL,
    `message` TEXT NULL,
    `privacy_version` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_commerce_inquiries_public` (`public_id`),
    UNIQUE KEY `uq_commerce_inquiries_operation` (`operation_id`),
    UNIQUE KEY `uq_commerce_inquiries_basket` (`basket_id`),
    KEY `idx_commerce_inquiries_time` (`created_at`),
    CONSTRAINT {{table:f_inquiry_basket}} FOREIGN KEY (`basket_id`)
        REFERENCES {{table:baskets}} (`id`) ON DELETE SET NULL,
    CONSTRAINT {{table:c_inquiry_hash}} CHECK (`payload_sha256` REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:inquiry_lines}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `inquiry_id` BIGINT UNSIGNED NOT NULL,
    `product_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `sku` VARCHAR(190) NULL,
    `requested_locale` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `resolved_locale` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `public_path` VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `cover_media_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
    `unit_price_minor` BIGINT UNSIGNED NULL,
    `currency` CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `availability_status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_commerce_line_product` (`inquiry_id`, `product_public_id`),
    CONSTRAINT {{table:f_line_inquiry}} FOREIGN KEY (`inquiry_id`)
        REFERENCES {{table:inquiries}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_line_quantity}} CHECK (`quantity` > 0),
    CONSTRAINT {{table:c_line_availability}} CHECK (`availability_status` IN ('available', 'reserved', 'sold', 'unavailable'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:inquiry_outbox}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `inquiry_id` BIGINT UNSIGNED NOT NULL,
    `audience` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `recipient_email` VARCHAR(320) NOT NULL,
    `template_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `payload_json` LONGTEXT NOT NULL,
    `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `available_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `locked_at` DATETIME(6) NULL,
    `lock_token` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `sent_at` DATETIME(6) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_commerce_outbox_public` (`public_id`),
    UNIQUE KEY `uq_commerce_outbox_audience` (`inquiry_id`, `audience`),
    KEY `idx_commerce_outbox_dispatch` (`status`, `available_at`),
    CONSTRAINT {{table:f_outbox_inquiry}} FOREIGN KEY (`inquiry_id`)
        REFERENCES {{table:inquiries}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_outbox_audience}} CHECK (`audience` IN ('requester', 'admin')),
    CONSTRAINT {{table:c_outbox_status}} CHECK (`status` IN ('pending', 'processing', 'sent', 'failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:inquiry_rate_limits}} (
    `action` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `subject_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `window_started_at` DATETIME(6) NOT NULL,
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`action`, `subject_hash`),
    KEY `idx_commerce_rate_updated` (`updated_at`),
    CONSTRAINT {{table:c_rate_hash}} CHECK (`subject_hash` REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT {{table:c_rate_attempts}} CHECK (`attempts` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:product_inquiry_stats}} (
    `product_id` BIGINT UNSIGNED NOT NULL,
    `inquiry_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`product_id`),
    CONSTRAINT {{table:f_stats_product}} FOREIGN KEY (`product_id`)
        REFERENCES {{table:products}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqliteCatalogStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:categories}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "public_id" TEXT COLLATE BINARY NOT NULL UNIQUE CHECK (length("public_id") = 36),
    "parent_id" INTEGER NULL REFERENCES {{table:categories}} ("id") ON DELETE RESTRICT,
    "sort_order" INTEGER NOT NULL DEFAULT 0 CHECK ("sort_order" >= 0 AND "sort_order" <= 10000),
    "lock_version" INTEGER NOT NULL DEFAULT 1 CHECK ("lock_version" > 0),
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    CHECK ("parent_id" IS NULL OR "parent_id" <> "id")
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_cat_parent}} ON {{table:categories}} ("parent_id", "sort_order", "id")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:category_localizations}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "category_id" INTEGER NOT NULL REFERENCES {{table:categories}} ("id") ON DELETE CASCADE,
    "locale" TEXT COLLATE BINARY NOT NULL,
    "name" TEXT NULL,
    "slug" TEXT COLLATE BINARY NULL,
    "translation_status" TEXT COLLATE BINARY NOT NULL CHECK ("translation_status" IN ('source', 'translated', 'fallback')),
    "lock_version" INTEGER NOT NULL DEFAULT 1 CHECK ("lock_version" > 0),
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    UNIQUE ("category_id", "locale"),
    UNIQUE ("locale", "slug")
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:products}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "public_id" TEXT COLLATE BINARY NOT NULL UNIQUE CHECK (length("public_id") = 36),
    "sku" TEXT NULL UNIQUE,
    "editorial_status" TEXT COLLATE BINARY NOT NULL DEFAULT 'draft' CHECK ("editorial_status" IN ('draft', 'active', 'inactive', 'archived')),
    "availability_status" TEXT COLLATE BINARY NOT NULL DEFAULT 'available' CHECK ("availability_status" IN ('available', 'reserved', 'sold', 'unavailable')),
    "price_minor" INTEGER NULL CHECK ("price_minor" IS NULL OR "price_minor" >= 0),
    "currency" TEXT COLLATE BINARY NULL,
    "canonical_category_id" INTEGER NULL REFERENCES {{table:categories}} ("id") ON DELETE SET NULL,
    "lock_version" INTEGER NOT NULL DEFAULT 1 CHECK ("lock_version" > 0),
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    CHECK (("price_minor" IS NULL AND "currency" IS NULL) OR ("price_minor" IS NOT NULL AND length("currency") = 3 AND "currency" = upper("currency")))
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_product_state}} ON {{table:products}} ("editorial_status", "availability_status")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:product_localizations}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "product_id" INTEGER NOT NULL REFERENCES {{table:products}} ("id") ON DELETE CASCADE,
    "locale" TEXT COLLATE BINARY NOT NULL,
    "title" TEXT NULL,
    "slug" TEXT COLLATE BINARY NULL,
    "summary" TEXT NULL,
    "description" TEXT NULL,
    "seo_title" TEXT NULL,
    "seo_description" TEXT NULL,
    "translation_status" TEXT COLLATE BINARY NOT NULL CHECK ("translation_status" IN ('source', 'translated', 'fallback')),
    "public_path" TEXT COLLATE BINARY NULL UNIQUE,
    "lock_version" INTEGER NOT NULL DEFAULT 1 CHECK ("lock_version" > 0),
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    UNIQUE ("product_id", "locale"),
    UNIQUE ("locale", "slug")
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:product_categories}} (
    "product_id" INTEGER NOT NULL REFERENCES {{table:products}} ("id") ON DELETE CASCADE,
    "category_id" INTEGER NOT NULL REFERENCES {{table:categories}} ("id") ON DELETE RESTRICT,
    PRIMARY KEY ("product_id", "category_id")
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_pc_category}} ON {{table:product_categories}} ("category_id", "product_id")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:tags}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "public_id" TEXT COLLATE BINARY NOT NULL UNIQUE CHECK (length("public_id") = 36),
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:tag_localizations}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "tag_id" INTEGER NOT NULL REFERENCES {{table:tags}} ("id") ON DELETE CASCADE,
    "locale" TEXT COLLATE BINARY NOT NULL,
    "name" TEXT NULL,
    "slug" TEXT COLLATE BINARY NULL,
    "translation_status" TEXT COLLATE BINARY NOT NULL CHECK ("translation_status" IN ('source', 'translated', 'fallback')),
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    UNIQUE ("tag_id", "locale"),
    UNIQUE ("locale", "slug")
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:product_tags}} (
    "product_id" INTEGER NOT NULL REFERENCES {{table:products}} ("id") ON DELETE CASCADE,
    "tag_id" INTEGER NOT NULL REFERENCES {{table:tags}} ("id") ON DELETE RESTRICT,
    PRIMARY KEY ("product_id", "tag_id")
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_pt_tag}} ON {{table:product_tags}} ("tag_id", "product_id")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:attributes}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "public_id" TEXT COLLATE BINARY NOT NULL UNIQUE CHECK (length("public_id") = 36),
    "code" TEXT COLLATE BINARY NOT NULL UNIQUE,
    "type" TEXT COLLATE BINARY NOT NULL CHECK ("type" IN ('text', 'number', 'boolean', 'select', 'multiselect', 'date')),
    "category_id" INTEGER NULL REFERENCES {{table:categories}} ("id") ON DELETE SET NULL,
    "unit" TEXT NULL,
    "is_filterable" INTEGER NOT NULL DEFAULT 0 CHECK ("is_filterable" IN (0, 1)),
    "sort_order" INTEGER NOT NULL DEFAULT 0 CHECK ("sort_order" >= 0),
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:attribute_localizations}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "attribute_id" INTEGER NOT NULL REFERENCES {{table:attributes}} ("id") ON DELETE CASCADE,
    "locale" TEXT COLLATE BINARY NOT NULL,
    "name" TEXT NULL,
    "translation_status" TEXT COLLATE BINARY NOT NULL CHECK ("translation_status" IN ('source', 'translated', 'fallback')),
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    UNIQUE ("attribute_id", "locale")
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:attribute_options}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "public_id" TEXT COLLATE BINARY NOT NULL UNIQUE CHECK (length("public_id") = 36),
    "attribute_id" INTEGER NOT NULL REFERENCES {{table:attributes}} ("id") ON DELETE CASCADE,
    "code" TEXT COLLATE BINARY NOT NULL,
    "sort_order" INTEGER NOT NULL DEFAULT 0 CHECK ("sort_order" >= 0),
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    UNIQUE ("attribute_id", "code")
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:attribute_option_localizations}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "option_id" INTEGER NOT NULL REFERENCES {{table:attribute_options}} ("id") ON DELETE CASCADE,
    "locale" TEXT COLLATE BINARY NOT NULL,
    "label" TEXT NULL,
    "translation_status" TEXT COLLATE BINARY NOT NULL CHECK ("translation_status" IN ('source', 'translated', 'fallback')),
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    UNIQUE ("option_id", "locale")
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:product_attribute_values}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "product_id" INTEGER NOT NULL REFERENCES {{table:products}} ("id") ON DELETE CASCADE,
    "attribute_id" INTEGER NOT NULL REFERENCES {{table:attributes}} ("id") ON DELETE RESTRICT,
    "locale" TEXT COLLATE BINARY NOT NULL DEFAULT '',
    "text_value" TEXT NULL,
    "number_value" NUMERIC NULL,
    "boolean_value" INTEGER NULL CHECK ("boolean_value" IS NULL OR "boolean_value" IN (0, 1)),
    "date_value" TEXT NULL,
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    UNIQUE ("product_id", "attribute_id", "locale")
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:product_attribute_value_options}} (
    "value_id" INTEGER NOT NULL REFERENCES {{table:product_attribute_values}} ("id") ON DELETE CASCADE,
    "option_id" INTEGER NOT NULL REFERENCES {{table:attribute_options}} ("id") ON DELETE RESTRICT,
    PRIMARY KEY ("value_id", "option_id")
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:product_media}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "product_id" INTEGER NOT NULL REFERENCES {{table:products}} ("id") ON DELETE CASCADE,
    "media_asset_public_id" TEXT COLLATE BINARY NOT NULL CHECK (length("media_asset_public_id") = 36),
    "role" TEXT COLLATE BINARY NOT NULL CHECK ("role" IN ('cover', 'gallery')),
    "sort_order" INTEGER NOT NULL DEFAULT 0 CHECK ("sort_order" >= 0),
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    UNIQUE ("product_id", "media_asset_public_id")
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_pm_asset}} ON {{table:product_media}} ("media_asset_public_id")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:product_media_localizations}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "media_id" INTEGER NOT NULL REFERENCES {{table:product_media}} ("id") ON DELETE CASCADE,
    "locale" TEXT COLLATE BINARY NOT NULL,
    "alt_text" TEXT NULL,
    "caption" TEXT NULL,
    "translation_status" TEXT COLLATE BINARY NOT NULL CHECK ("translation_status" IN ('source', 'translated', 'fallback')),
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    UNIQUE ("media_id", "locale"),
    CHECK (
        ("translation_status" = 'fallback' AND "alt_text" IS NULL AND "caption" IS NULL)
        OR ("translation_status" IN ('source', 'translated')
            AND "alt_text" IS NOT NULL
            AND length(trim("alt_text")) BETWEEN 1 AND 500
            AND ("caption" IS NULL OR length("caption") <= 2000))
    )
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:url_history}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "product_localization_id" INTEGER NOT NULL REFERENCES {{table:product_localizations}} ("id") ON DELETE CASCADE,
    "old_path" TEXT COLLATE BINARY NOT NULL UNIQUE,
    "new_path" TEXT COLLATE BINARY NOT NULL,
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
)
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqliteEngagementStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:baskets}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "public_id" TEXT COLLATE BINARY NOT NULL UNIQUE CHECK (length("public_id") = 36),
    "token_sha256" TEXT COLLATE BINARY NOT NULL UNIQUE CHECK (length("token_sha256") = 64),
    "locale" TEXT COLLATE BINARY NOT NULL,
    "status" TEXT COLLATE BINARY NOT NULL DEFAULT 'open' CHECK ("status" IN ('open', 'submitted', 'expired')),
    "expires_at" TEXT NOT NULL,
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_basket_expiry}} ON {{table:baskets}} ("status", "expires_at")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:basket_items}} (
    "basket_id" INTEGER NOT NULL REFERENCES {{table:baskets}} ("id") ON DELETE CASCADE,
    "product_id" INTEGER NOT NULL REFERENCES {{table:products}} ("id") ON DELETE RESTRICT,
    "quantity" INTEGER NOT NULL DEFAULT 1 CHECK ("quantity" > 0),
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    PRIMARY KEY ("basket_id", "product_id")
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:inquiries}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "public_id" TEXT COLLATE BINARY NOT NULL UNIQUE CHECK (length("public_id") = 36),
    "operation_id" TEXT COLLATE BINARY NOT NULL UNIQUE CHECK (length("operation_id") = 36),
    "payload_sha256" TEXT COLLATE BINARY NOT NULL CHECK (length("payload_sha256") = 64),
    "basket_id" INTEGER NULL UNIQUE REFERENCES {{table:baskets}} ("id") ON DELETE SET NULL,
    "locale" TEXT COLLATE BINARY NOT NULL,
    "contact_name" TEXT NOT NULL,
    "email" TEXT NOT NULL,
    "phone" TEXT NULL,
    "message" TEXT NULL,
    "privacy_version" TEXT COLLATE BINARY NOT NULL,
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:inquiry_lines}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "inquiry_id" INTEGER NOT NULL REFERENCES {{table:inquiries}} ("id") ON DELETE CASCADE,
    "product_public_id" TEXT COLLATE BINARY NOT NULL CHECK (length("product_public_id") = 36),
    "sku" TEXT NULL,
    "requested_locale" TEXT COLLATE BINARY NOT NULL,
    "resolved_locale" TEXT COLLATE BINARY NOT NULL,
    "title" TEXT NOT NULL,
    "public_path" TEXT COLLATE BINARY NOT NULL,
    "cover_media_public_id" TEXT COLLATE BINARY NULL CHECK ("cover_media_public_id" IS NULL OR length("cover_media_public_id") = 36),
    "quantity" INTEGER NOT NULL DEFAULT 1 CHECK ("quantity" > 0),
    "unit_price_minor" INTEGER NULL CHECK ("unit_price_minor" IS NULL OR "unit_price_minor" >= 0),
    "currency" TEXT COLLATE BINARY NULL,
    "availability_status" TEXT COLLATE BINARY NOT NULL CHECK ("availability_status" IN ('available', 'reserved', 'sold', 'unavailable')),
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    UNIQUE ("inquiry_id", "product_public_id")
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:inquiry_outbox}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "public_id" TEXT COLLATE BINARY NOT NULL UNIQUE CHECK (length("public_id") = 36),
    "inquiry_id" INTEGER NOT NULL REFERENCES {{table:inquiries}} ("id") ON DELETE CASCADE,
    "audience" TEXT COLLATE BINARY NOT NULL CHECK ("audience" IN ('requester', 'admin')),
    "recipient_email" TEXT NOT NULL,
    "template_key" TEXT COLLATE BINARY NOT NULL,
    "payload_json" TEXT NOT NULL,
    "status" TEXT COLLATE BINARY NOT NULL DEFAULT 'pending' CHECK ("status" IN ('pending', 'processing', 'sent', 'failed')),
    "attempts" INTEGER NOT NULL DEFAULT 0 CHECK ("attempts" >= 0),
    "available_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "locked_at" TEXT NULL,
    "lock_token" TEXT COLLATE BINARY NULL,
    "sent_at" TEXT NULL,
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    UNIQUE ("inquiry_id", "audience")
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_outbox_dispatch}} ON {{table:inquiry_outbox}} ("status", "available_at")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:inquiry_rate_limits}} (
    "action" TEXT COLLATE BINARY NOT NULL,
    "subject_hash" TEXT COLLATE BINARY NOT NULL CHECK (length("subject_hash") = 64),
    "attempts" INTEGER NOT NULL DEFAULT 1 CHECK ("attempts" > 0),
    "window_started_at" TEXT NOT NULL,
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    PRIMARY KEY ("action", "subject_hash")
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_rate_updated}} ON {{table:inquiry_rate_limits}} ("updated_at")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:product_inquiry_stats}} (
    "product_id" INTEGER PRIMARY KEY REFERENCES {{table:products}} ("id") ON DELETE CASCADE,
    "inquiry_count" INTEGER NOT NULL DEFAULT 0 CHECK ("inquiry_count" >= 0),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
)
SQL,
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
    ('commerce', 'commerce.products.view', 'commerce.capabilities.products_view', 1),
    ('commerce', 'commerce.products.edit', 'commerce.capabilities.products_edit', 1),
    ('commerce', 'commerce.products.publish', 'commerce.capabilities.products_publish', 1),
    ('commerce', 'commerce.products.archive', 'commerce.capabilities.products_archive', 1),
    ('commerce', 'commerce.taxonomies.view', 'commerce.capabilities.taxonomies_view', 1),
    ('commerce', 'commerce.taxonomies.edit', 'commerce.capabilities.taxonomies_edit', 1),
    ('commerce', 'commerce.inquiries.view', 'commerce.capabilities.inquiries_view', 1),
    ('commerce', 'commerce.inquiries.manage', 'commerce.capabilities.inquiries_manage', 1),
    ('commerce', 'commerce.settings.manage', 'commerce.capabilities.settings_manage', 0)
SQL,
            <<<'SQL'
INSERT INTO {{table:role_capabilities}} (`role_id`, `capability_id`)
SELECT `r`.`id`, `c`.`id`
FROM {{table:roles}} AS `r`
CROSS JOIN {{table:capabilities}} AS `c`
WHERE `r`.`code` IN ('system_superadmin', 'site_admin')
    AND `r`.`is_protected` = 1
    AND `c`.`module_id` = 'commerce'
    AND `c`.`code` IN (
        'commerce.products.view', 'commerce.products.edit',
        'commerce.products.publish', 'commerce.products.archive',
        'commerce.taxonomies.view', 'commerce.taxonomies.edit',
        'commerce.inquiries.view', 'commerce.inquiries.manage',
        'commerce.settings.manage'
    )
ON DUPLICATE KEY UPDATE `role_id` = VALUES(`role_id`)
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
    ('commerce', 'commerce.products.view', 'commerce.capabilities.products_view', 1),
    ('commerce', 'commerce.products.edit', 'commerce.capabilities.products_edit', 1),
    ('commerce', 'commerce.products.publish', 'commerce.capabilities.products_publish', 1),
    ('commerce', 'commerce.products.archive', 'commerce.capabilities.products_archive', 1),
    ('commerce', 'commerce.taxonomies.view', 'commerce.capabilities.taxonomies_view', 1),
    ('commerce', 'commerce.taxonomies.edit', 'commerce.capabilities.taxonomies_edit', 1),
    ('commerce', 'commerce.inquiries.view', 'commerce.capabilities.inquiries_view', 1),
    ('commerce', 'commerce.inquiries.manage', 'commerce.capabilities.inquiries_manage', 1),
    ('commerce', 'commerce.settings.manage', 'commerce.capabilities.settings_manage', 0)
ON CONFLICT("code") DO NOTHING
SQL,
            <<<'SQL'
INSERT INTO {{table:role_capabilities}} ("role_id", "capability_id")
SELECT "r"."id", "c"."id"
FROM {{table:roles}} AS "r"
CROSS JOIN {{table:capabilities}} AS "c"
WHERE "r"."code" IN ('system_superadmin', 'site_admin')
    AND "r"."is_protected" = 1
    AND "c"."module_id" = 'commerce'
    AND "c"."code" IN (
        'commerce.products.view', 'commerce.products.edit',
        'commerce.products.publish', 'commerce.products.archive',
        'commerce.taxonomies.view', 'commerce.taxonomies.edit',
        'commerce.inquiries.view', 'commerce.inquiries.manage',
        'commerce.settings.manage'
    )
ON CONFLICT("role_id", "capability_id") DO NOTHING
SQL,
        ];
    }
}
