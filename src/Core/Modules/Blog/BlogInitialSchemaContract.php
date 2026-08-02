<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

/**
 * Independent observable contract for Blog migration 0001.
 *
 * body_text is intentionally plain text: this schema exposes no HTML column,
 * and the future renderer must escape it instead of treating it as markup.
 * Authorship stores stable WebAdmin public UUIDs, never internal user IDs or
 * cross-module foreign keys, so editorial history survives account lifecycle.
 */
final class BlogInitialSchemaContract
{
    /** @return list<string> */
    public static function tableSuffixes(): array
    {
        return ['posts', 'post_localizations'];
    }

    /**
     * @return array<string, list<array{
     *     name: string,
     *     type: string,
     *     not_null: bool,
     *     primary_position: int,
     *     default: ?string
     * }>>
     */
    public static function sqliteColumns(): array
    {
        $now = "strftime('%Y-%m-%d %H:%M:%f000','now')";

        return [
            'posts' => [
                self::sqliteColumn('id', 'INTEGER', false, 1),
                self::sqliteColumn('public_id', 'TEXT', true),
                self::sqliteColumn('created_by_user_public_id', 'TEXT', true),
                self::sqliteColumn('created_at', 'TEXT', true, default: $now),
                self::sqliteColumn('updated_at', 'TEXT', true, default: $now),
            ],
            'post_localizations' => [
                self::sqliteColumn('id', 'INTEGER', false, 1),
                self::sqliteColumn('public_id', 'TEXT', true),
                self::sqliteColumn('post_id', 'INTEGER', true),
                self::sqliteColumn('locale', 'TEXT', true),
                self::sqliteColumn('slug', 'TEXT', false),
                self::sqliteColumn('h1', 'TEXT', true),
                self::sqliteColumn('seo_title', 'TEXT', false),
                self::sqliteColumn('meta_description', 'TEXT', false),
                self::sqliteColumn('excerpt', 'TEXT', false),
                self::sqliteColumn('body_text', 'TEXT', true),
                self::sqliteColumn('status', 'TEXT', true, default: "'draft'"),
                self::sqliteColumn('published_at', 'TEXT', false),
                self::sqliteColumn('lock_version', 'INTEGER', true, default: '1'),
                self::sqliteColumn('created_by_user_public_id', 'TEXT', true),
                self::sqliteColumn('updated_by_user_public_id', 'TEXT', true),
                self::sqliteColumn('created_at', 'TEXT', true, default: $now),
                self::sqliteColumn('updated_at', 'TEXT', true, default: $now),
            ],
        ];
    }

    /**
     * @return array<string, list<array{
     *     name: string,
     *     type: string,
     *     nullable: bool,
     *     unsigned: bool,
     *     length: ?int,
     *     datetime_precision: ?int,
     *     charset: ?string,
     *     collation: ?string,
     *     default: ?string,
     *     extra: string
     * }>>
     */
    public static function mysqlColumns(): array
    {
        $id = static fn (string $name): array => self::mysqlColumn(
            $name,
            'bigint',
            false,
            unsigned: true
        );
        $ascii = static fn (
            string $name,
            string $type,
            int $length,
            bool $nullable = false,
            ?string $default = null
        ): array => self::mysqlColumn(
            $name,
            $type,
            $nullable,
            length: $length,
            charset: 'ascii',
            collation: 'ascii_bin',
            default: $default
        );
        $utf8 = static fn (
            string $name,
            string $type,
            ?int $length,
            bool $nullable
        ): array => self::mysqlColumn(
            $name,
            $type,
            $nullable,
            length: $length,
            charset: 'utf8mb4',
            collation: 'utf8mb4_unicode_ci'
        );
        $date = static fn (
            string $name,
            bool $nullable,
            ?string $default = null
        ): array => self::mysqlColumn(
            $name,
            'datetime',
            $nullable,
            datetimePrecision: 6,
            default: $default
        );
        $now = 'current_timestamp(6)';

        return [
            'posts' => [
                self::mysqlColumn(
                    'id',
                    'bigint',
                    false,
                    unsigned: true,
                    extra: 'auto_increment'
                ),
                $ascii('public_id', 'char', 36),
                $ascii('created_by_user_public_id', 'char', 36),
                $date('created_at', false, $now),
                $date('updated_at', false, $now),
            ],
            'post_localizations' => [
                self::mysqlColumn(
                    'id',
                    'bigint',
                    false,
                    unsigned: true,
                    extra: 'auto_increment'
                ),
                $ascii('public_id', 'char', 36),
                $id('post_id'),
                $ascii('locale', 'varchar', 16),
                $ascii('slug', 'varchar', 190, true),
                $utf8('h1', 'varchar', 255, false),
                $utf8('seo_title', 'varchar', 255, true),
                $utf8('meta_description', 'varchar', 320, true),
                $utf8('excerpt', 'text', 65535, true),
                $utf8('body_text', 'longtext', 4294967295, false),
                $ascii('status', 'varchar', 16, false, "'draft'"),
                $date('published_at', true),
                self::mysqlColumn(
                    'lock_version',
                    'bigint',
                    false,
                    unsigned: true,
                    default: "'1'"
                ),
                $ascii('created_by_user_public_id', 'char', 36),
                $ascii('updated_by_user_public_id', 'char', 36),
                $date('created_at', false, $now),
                $date('updated_at', false, $now),
            ],
        ];
    }

    /**
     * Semantic secondary indexes; names differ between drivers and prefixes.
     *
     * @return array<string, list<array{unique: bool, columns: list<string>}>>
     */
    public static function indexes(): array
    {
        return [
            'posts' => [
                self::index(true, ['public_id']),
                self::index(false, ['created_by_user_public_id']),
            ],
            'post_localizations' => [
                self::index(true, ['public_id']),
                self::index(true, ['post_id', 'locale']),
                self::index(true, ['locale', 'slug']),
                self::index(false, ['status', 'published_at']),
            ],
        ];
    }

    /**
     * @return array<string, list<array{
     *     from: string,
     *     target_suffix: string,
     *     target_column: string,
     *     on_update: string,
     *     on_delete: string
     * }>>
     */
    public static function foreignKeys(): array
    {
        return [
            'posts' => [],
            'post_localizations' => [[
                'from' => 'post_id',
                'target_suffix' => 'posts',
                'target_column' => 'id',
                'on_update' => 'NO ACTION',
                'on_delete' => 'CASCADE',
            ]],
        ];
    }

    /** @return array<string, list<string>> */
    public static function sqliteChecks(): array
    {
        return [
            'posts' => [
                'length(public_id)=36',
                'length(created_by_user_public_id)=36',
            ],
            'post_localizations' => [
                'length(public_id)=36',
                'length(locale) BETWEEN 2 AND 16 AND locale = lower(locale) '
                    . 'AND locale = trim(locale)',
                'slug IS NULL OR (length(trim(slug)) > 0 '
                    . 'AND slug = lower(slug) AND slug = trim(slug))',
                'length(trim(h1))>0',
                "status IN ('draft', 'published')",
                "(status = 'draft' AND published_at IS NULL) OR "
                    . "(status = 'published' AND published_at IS NOT NULL "
                    . 'AND slug IS NOT NULL AND seo_title IS NOT NULL '
                    . 'AND length(trim(seo_title)) > 0 '
                    . 'AND meta_description IS NOT NULL '
                    . 'AND length(trim(meta_description)) > 0 '
                    . 'AND excerpt IS NOT NULL '
                    . 'AND length(trim(excerpt)) > 0 '
                    . 'AND length(trim(body_text)) > 0)',
                'lock_version>0',
                'length(created_by_user_public_id)=36',
                'length(updated_by_user_public_id)=36',
            ],
        ];
    }

    /** @return array<string, array<string, string>> */
    public static function mysqlChecks(): array
    {
        return [
            'posts' => [
                'c_po_public' => 'char_length(public_id)=36',
                'c_po_author' => 'char_length(created_by_user_public_id)=36',
            ],
            'post_localizations' => [
                'c_pl_public' => 'char_length(public_id)=36',
                'c_pl_locale' => 'char_length(locale) BETWEEN 2 AND 16 '
                    . 'AND locale = lower(locale) AND locale = trim(locale)',
                'c_pl_slug' => 'slug IS NULL OR ('
                    . 'char_length(trim(slug)) > 0 '
                    . 'AND slug = lower(slug) AND slug = trim(slug))',
                'c_pl_h1' => 'char_length(trim(h1))>0',
                'c_pl_status' => "status IN ('draft', 'published')",
                'c_pl_publish' =>
                    "(status = 'draft' AND published_at IS NULL) OR "
                    . "(status = 'published' AND published_at IS NOT NULL "
                    . 'AND slug IS NOT NULL AND seo_title IS NOT NULL '
                    . 'AND char_length(trim(seo_title)) > 0 '
                    . 'AND meta_description IS NOT NULL '
                    . 'AND char_length(trim(meta_description)) > 0 '
                    . 'AND excerpt IS NOT NULL '
                    . 'AND char_length(trim(excerpt)) > 0 '
                    . 'AND char_length(trim(body_text)) > 0)',
                'c_pl_lock' => 'lock_version>0',
                'c_pl_created' => 'char_length(created_by_user_public_id)=36',
                'c_pl_updated' => 'char_length(updated_by_user_public_id)=36',
            ],
        ];
    }

    /** @return array<string, list<string>> */
    public static function sqliteBinaryTextColumns(): array
    {
        return [
            'posts' => ['public_id', 'created_by_user_public_id'],
            'post_localizations' => [
                'public_id',
                'locale',
                'slug',
                'status',
                'created_by_user_public_id',
                'updated_by_user_public_id',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function sqliteColumn(
        string $name,
        string $type,
        bool $notNull,
        int $primaryPosition = 0,
        ?string $default = null
    ): array {
        return [
            'name' => $name,
            'type' => $type,
            'not_null' => $notNull,
            'primary_position' => $primaryPosition,
            'default' => $default,
        ];
    }

    /** @return array<string, mixed> */
    private static function mysqlColumn(
        string $name,
        string $type,
        bool $nullable,
        bool $unsigned = false,
        ?int $length = null,
        ?int $datetimePrecision = null,
        ?string $charset = null,
        ?string $collation = null,
        ?string $default = null,
        string $extra = ''
    ): array {
        return [
            'name' => $name,
            'type' => $type,
            'nullable' => $nullable,
            'unsigned' => $unsigned,
            'length' => $length,
            'datetime_precision' => $datetimePrecision,
            'charset' => $charset,
            'collation' => $collation,
            'default' => $default,
            'extra' => $extra,
        ];
    }

    /** @param list<string> $columns @return array{unique: bool, columns: list<string>} */
    private static function index(bool $unique, array $columns): array
    {
        return ['unique' => $unique, 'columns' => $columns];
    }
}
