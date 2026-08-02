<?php

declare(strict_types=1);

namespace App\Core\Modules\WebAdmin;

use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Exact, read-only postcondition for WebAdmin media migration 0002. */
final class WebAdminMediaMigrationPostconditionVerifier implements
    MigrationPostconditionVerifierInterface
{
    private const ASSET_COLUMNS = [
        'id',
        'public_id',
        'label',
        'source_mime',
        'source_width',
        'source_height',
        'source_bytes',
        'source_sha256',
        'created_by_user_id',
        'created_at',
    ];
    private const VARIANT_COLUMNS = [
        'id',
        'asset_id',
        'width',
        'height',
        'bytes',
        'sha256',
        'storage_key',
        'mime',
        'created_at',
    ];

    public function __construct(
        private readonly WebAdminMigrationPostconditionVerifier $baseVerifier =
            new WebAdminMigrationPostconditionVerifier()
    ) {
    }

    public function contractVersion(): string
    {
        return 'webadmin-media-schema-v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        return $this->issueCodes($pdo, $scope) === [];
    }

    /** @return list<string> */
    public function issueCodes(PDO $pdo, MigrationScope $scope): array
    {
        try {
            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            $issues = $this->baseVerifier->issueCodes($pdo, $scope);
            if (!$this->tablesAreValid($pdo, $scope, $driver)) {
                $issues[] = 'webadmin.media.schema_invalid';
            }
            if (!$this->foreignKeysAreValid($pdo, $scope, $driver)) {
                $issues[] = 'webadmin.media.foreign_keys_invalid';
            }
            if (!$this->indexesAreValid($pdo, $scope, $driver)) {
                $issues[] = 'webadmin.media.indexes_invalid';
            }
            if (!$this->seedsAreValid($pdo, $scope)) {
                $issues[] = 'webadmin.media.seeds_invalid';
            }
            if (!$this->storedRowsAreValid($pdo, $scope, $driver)) {
                $issues[] = 'webadmin.media.data_integrity_invalid';
            }

            return $issues;
        } catch (Throwable) {
            return ['webadmin.media.schema_metadata_unavailable'];
        }
    }

    private function tablesAreValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $expected = [
            'media_assets' => self::ASSET_COLUMNS,
            'media_variants' => self::VARIANT_COLUMNS,
        ];

        if ($driver === 'sqlite') {
            foreach ($expected as $suffix => $columns) {
                $name = $scope->tableName($suffix);
                $statement = $pdo->prepare(
                    "SELECT type, sql FROM sqlite_master WHERE name = :name"
                );
                $statement->execute(['name' => $name]);
                $object = $statement->fetch(PDO::FETCH_ASSOC);
                if (!is_array($object) || ($object['type'] ?? null) !== 'table'
                    || !is_string($object['sql'] ?? null)) {
                    return false;
                }
                $metadata = $pdo->query(
                    'PRAGMA table_info('
                    . $scope->quotedTable($suffix, 'sqlite') . ')'
                )->fetchAll(PDO::FETCH_ASSOC);
                $actual = array_map(
                    static fn (array $row): string => (string) $row['name'],
                    $metadata
                );
                if ($actual !== $columns
                    || !$this->validSqliteColumns($suffix, $metadata)
                    || !$this->validSqliteTableSql($suffix, $object['sql'])) {
                    return false;
                }
            }

            $trigger = $pdo->prepare(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' "
                . 'AND tbl_name IN (:assets, :variants)'
            );
            $trigger->execute([
                'assets' => $scope->tableName('media_assets'),
                'variants' => $scope->tableName('media_variants'),
            ]);

            return in_array($trigger->fetchColumn(), [0, '0'], true);
        }

        foreach ($expected as $suffix => $columns) {
            $name = $scope->tableName($suffix);
            $table = $pdo->prepare(
                'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                . "AND TABLE_TYPE = 'BASE TABLE'"
            );
            $table->execute(['name' => $name]);
            $row = $table->fetch(PDO::FETCH_ASSOC);
            if (
                !is_array($row)
                || strtoupper((string) ($row['ENGINE'] ?? '')) !== 'INNODB'
                || strtolower((string) ($row['TABLE_COLLATION'] ?? ''))
                    !== 'utf8mb4_unicode_ci'
            ) {
                return false;
            }
            $columnQuery = $pdo->prepare(
                'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, '
                . 'COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH, DATETIME_PRECISION, '
                . 'CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_KEY, EXTRA '
                . 'FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                . 'ORDER BY ORDINAL_POSITION'
            );
            $columnQuery->execute(['name' => $name]);
            $metadata = $columnQuery->fetchAll(PDO::FETCH_ASSOC);
            $actual = array_map(
                static fn (array $row): string =>
                    (string) ($row['COLUMN_NAME'] ?? ''),
                $metadata
            );
            if ($actual !== $columns
                || !$this->validMySqlColumns($suffix, $metadata)) {
                return false;
            }
            $primary = $pdo->prepare(
                'SELECT COLUMN_NAME FROM information_schema.STATISTICS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                . "AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX"
            );
            $primary->execute(['name' => $name]);
            if ($primary->fetchAll(PDO::FETCH_COLUMN) !== ['id']) {
                return false;
            }
        }

        if (!$this->validMySqlChecks($pdo, $scope)) {
            return false;
        }

        $trigger = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS '
            . 'WHERE TRIGGER_SCHEMA = DATABASE() '
            . 'AND EVENT_OBJECT_TABLE IN (:assets, :variants)'
        );
        $trigger->execute([
            'assets' => $scope->tableName('media_assets'),
            'variants' => $scope->tableName('media_variants'),
        ]);

        return in_array($trigger->fetchColumn(), [0, '0'], true);
    }

    private function foreignKeysAreValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        if ($driver === 'sqlite') {
            $assets = $pdo->query(
                'PRAGMA foreign_key_list('
                . $scope->quotedTable('media_assets', 'sqlite') . ')'
            )->fetchAll(PDO::FETCH_ASSOC);
            $variants = $pdo->query(
                'PRAGMA foreign_key_list('
                . $scope->quotedTable('media_variants', 'sqlite') . ')'
            )->fetchAll(PDO::FETCH_ASSOC);

            return count($assets) === 1
                && count($variants) === 1
                && ($assets[0]['from'] ?? null) === 'created_by_user_id'
                && ($assets[0]['table'] ?? null)
                    === $scope->tableName('users')
                && strtoupper((string) ($assets[0]['on_delete'] ?? ''))
                    === 'RESTRICT'
                && ($variants[0]['from'] ?? null) === 'asset_id'
                && ($variants[0]['table'] ?? null)
                    === $scope->tableName('media_assets')
                && strtoupper((string) ($variants[0]['on_delete'] ?? ''))
                    === 'CASCADE';
        }

        $query = $pdo->prepare(
            'SELECT k.TABLE_NAME, k.COLUMN_NAME, '
            . 'k.REFERENCED_TABLE_NAME, r.DELETE_RULE '
            . 'FROM information_schema.KEY_COLUMN_USAGE k '
            . 'JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
            . 'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA '
            . 'AND r.TABLE_NAME = k.TABLE_NAME '
            . 'AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
            . 'WHERE k.CONSTRAINT_SCHEMA = DATABASE() '
            . 'AND k.TABLE_NAME IN (:assets, :variants) '
            . 'AND k.REFERENCED_TABLE_NAME IS NOT NULL '
            . 'ORDER BY k.TABLE_NAME, k.COLUMN_NAME'
        );
        $query->execute([
            'assets' => $scope->tableName('media_assets'),
            'variants' => $scope->tableName('media_variants'),
        ]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);

        return count($rows) === 2
            && $this->containsForeignKey(
                $rows,
                $scope->tableName('media_assets'),
                'created_by_user_id',
                $scope->tableName('users'),
                'RESTRICT'
            )
            && $this->containsForeignKey(
                $rows,
                $scope->tableName('media_variants'),
                'asset_id',
                $scope->tableName('media_assets'),
                'CASCADE'
            );
    }

    private function indexesAreValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $assets = $this->indexSignatures(
            $pdo,
            $scope,
            'media_assets',
            $driver
        );
        $variants = $this->indexSignatures(
            $pdo,
            $scope,
            'media_variants',
            $driver
        );

        sort($assets, SORT_STRING);
        sort($variants, SORT_STRING);

        return $assets === [
            'N:created_at,id',
            'N:created_by_user_id',
            'U:public_id',
        ] && $variants === [
            'N:asset_id',
            'U:asset_id,width',
            'U:storage_key',
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function validSqliteColumns(string $suffix, array $rows): bool
    {
        $expected = $suffix === 'media_assets'
            ? [
                ['id', 'INTEGER', 0, 1],
                ['public_id', 'TEXT', 1, 0],
                ['label', 'TEXT', 1, 0],
                ['source_mime', 'TEXT', 1, 0],
                ['source_width', 'INTEGER', 1, 0],
                ['source_height', 'INTEGER', 1, 0],
                ['source_bytes', 'INTEGER', 1, 0],
                ['source_sha256', 'TEXT', 1, 0],
                ['created_by_user_id', 'INTEGER', 1, 0],
                ['created_at', 'TEXT', 1, 0],
            ]
            : [
                ['id', 'INTEGER', 0, 1],
                ['asset_id', 'INTEGER', 1, 0],
                ['width', 'INTEGER', 1, 0],
                ['height', 'INTEGER', 1, 0],
                ['bytes', 'INTEGER', 1, 0],
                ['sha256', 'TEXT', 1, 0],
                ['storage_key', 'TEXT', 1, 0],
                ['mime', 'TEXT', 1, 0],
                ['created_at', 'TEXT', 1, 0],
            ];
        if (count($rows) !== count($expected)) {
            return false;
        }
        foreach ($expected as $index => [$name, $type, $notNull, $pk]) {
            $row = array_change_key_case($rows[$index], CASE_LOWER);
            if (
                ($row['name'] ?? null) !== $name
                || strtoupper((string) ($row['type'] ?? '')) !== $type
                || (int) ($row['notnull'] ?? -1) !== $notNull
                || (int) ($row['pk'] ?? -1) !== $pk
            ) {
                return false;
            }
            $default = $row['dflt_value'] ?? null;
            if ($name === 'created_at') {
                if (!is_string($default)
                    || !str_contains(strtolower($default), 'strftime')) {
                    return false;
                }
            } elseif ($default !== null) {
                return false;
            }
        }

        return true;
    }

    private function validSqliteTableSql(string $suffix, string $sql): bool
    {
        $sql = $this->normalizeSql($sql);
        $required = $suffix === 'media_assets'
            ? [
                'autoincrement',
                'collatebinary',
                'check(length(public_id)=36)',
                'check(length(label)between1and120)',
                "check(source_mimein('image/jpeg','image/png','image/webp'))",
                'check(source_widthbetween1and12000)',
                'check(source_heightbetween1and12000)',
                'check(source_bytesbetween1and12582912)',
                'check(length(source_sha256)=64)',
                'ondeleterestrict',
                'check((source_width*source_height)<=40000000)',
            ]
            : [
                'autoincrement',
                'collatebinary',
                'check(widthbetween1and2560)',
                'check(heightbetween1and2560)',
                'check(bytes>0)',
                'check(length(sha256)=64)',
                'check(length(storage_key)between1and255)',
                "check(mime='image/avif')",
                'unique(asset_id,width)',
                'ondeletecascade',
            ];
        foreach ($required as $fragment) {
            if (!str_contains($sql, $fragment)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, mixed>> $rows */
    private function validMySqlColumns(string $suffix, array $rows): bool
    {
        $expected = $suffix === 'media_assets'
            ? [
                ['id', 'bigint', true, null, null, null, 'auto_increment'],
                ['public_id', 'char', false, 36, 'ascii', 'ascii_bin', ''],
                ['label', 'varchar', false, 120, 'utf8mb4', 'utf8mb4_unicode_ci', ''],
                ['source_mime', 'varchar', false, 32, 'ascii', 'ascii_bin', ''],
                ['source_width', 'int', true, null, null, null, ''],
                ['source_height', 'int', true, null, null, null, ''],
                ['source_bytes', 'bigint', true, null, null, null, ''],
                ['source_sha256', 'char', false, 64, 'ascii', 'ascii_bin', ''],
                ['created_by_user_id', 'bigint', true, null, null, null, ''],
                ['created_at', 'datetime', false, null, null, null, ''],
            ]
            : [
                ['id', 'bigint', true, null, null, null, 'auto_increment'],
                ['asset_id', 'bigint', true, null, null, null, ''],
                ['width', 'int', true, null, null, null, ''],
                ['height', 'int', true, null, null, null, ''],
                ['bytes', 'bigint', true, null, null, null, ''],
                ['sha256', 'char', false, 64, 'ascii', 'ascii_bin', ''],
                ['storage_key', 'varchar', false, 255, 'ascii', 'ascii_bin', ''],
                ['mime', 'varchar', false, 32, 'ascii', 'ascii_bin', ''],
                ['created_at', 'datetime', false, null, null, null, ''],
            ];
        if (count($rows) !== count($expected)) {
            return false;
        }
        foreach ($expected as $index => [$name, $type, $unsigned, $length, $charset, $collation, $extra]) {
            $row = array_change_key_case($rows[$index], CASE_LOWER);
            $columnType = strtolower((string) ($row['column_type'] ?? ''));
            if (
                ($row['column_name'] ?? null) !== $name
                || strtolower((string) ($row['data_type'] ?? '')) !== $type
                || strtoupper((string) ($row['is_nullable'] ?? '')) !== 'NO'
                || str_contains($columnType, 'unsigned') !== $unsigned
                || ($length === null
                    ? ($row['character_maximum_length'] ?? null) !== null
                    : (int) ($row['character_maximum_length'] ?? -1) !== $length)
                || ($charset === null
                    ? ($row['character_set_name'] ?? null) !== null
                    : strtolower((string) ($row['character_set_name'] ?? '')) !== $charset)
                || ($collation === null
                    ? ($row['collation_name'] ?? null) !== null
                    : strtolower((string) ($row['collation_name'] ?? '')) !== $collation)
                || ($name === 'id'
                    && strtoupper((string) ($row['column_key'] ?? '')) !== 'PRI')
                || !$this->validMySqlExtra(
                    $name,
                    (string) ($row['extra'] ?? ''),
                    $extra
                )
            ) {
                return false;
            }
            $default = $row['column_default'] ?? null;
            if ($name === 'created_at') {
                if ((int) ($row['datetime_precision'] ?? -1) !== 6
                    || !is_string($default)
                    || !str_starts_with(strtolower($default), 'current_timestamp')) {
                    return false;
                }
            } elseif ($default !== null) {
                return false;
            }
        }

        return true;
    }

    private function validMySqlExtra(
        string $column,
        string $actual,
        string $expected
    ): bool {
        $normalized = strtolower(trim($actual));
        $tokens = $normalized === ''
            ? []
            : (preg_split('/\s+/', $normalized) ?: []);
        $expectsAutoIncrement = $expected === 'auto_increment';
        if (
            in_array('auto_increment', $tokens, true)
                !== $expectsAutoIncrement
        ) {
            return false;
        }
        $allowed = $expectsAutoIncrement ? ['auto_increment'] : [];
        if ($column === 'created_at') {
            $allowed[] = 'default_generated';
        }
        foreach ($tokens as $token) {
            if (!in_array($token, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    private function validMySqlChecks(PDO $pdo, MigrationScope $scope): bool
    {
        $query = $pdo->prepare(
            'SELECT tc.TABLE_NAME, tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE '
            . 'FROM information_schema.TABLE_CONSTRAINTS tc JOIN '
            . 'information_schema.CHECK_CONSTRAINTS cc '
            . 'ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA '
            . 'AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
            . "WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.CONSTRAINT_TYPE = 'CHECK' "
            . 'AND tc.TABLE_NAME IN (:assets, :variants)'
        );
        $query->execute([
            'assets' => $scope->tableName('media_assets'),
            'variants' => $scope->tableName('media_variants'),
        ]);
        return $this->validateMySqlCheckRows(
            $scope,
            $query->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /** @param list<array<string, mixed>> $rows */
    private function validateMySqlCheckRows(
        MigrationScope $scope,
        array $rows
    ): bool {
        $actual = [];
        foreach ($rows as $row) {
            $actual[(string) ($row['CONSTRAINT_NAME'] ?? '')] =
                $this->normalizeSql((string) ($row['CHECK_CLAUSE'] ?? ''));
        }
        $expected = [
            $scope->tableName('c_ma_public') => [
                'char_length(public_id)=36',
            ],
            $scope->tableName('c_ma_label') => [
                'char_length(label)between1and120',
            ],
            $scope->tableName('c_ma_mime') => [
                "source_mimein('image/jpeg','image/png','image/webp')",
            ],
            $scope->tableName('c_ma_dims') => [
                'source_widthbetween1and12000andsource_heightbetween1and12000and(source_width*source_height)<=40000000',
                'source_widthbetween1and12000andsource_heightbetween1and12000andsource_width*source_height<=40000000',
            ],
            $scope->tableName('c_ma_bytes') => [
                'source_bytesbetween1and12582912',
            ],
            $scope->tableName('c_ma_hash') => [
                'char_length(source_sha256)=64',
            ],
            $scope->tableName('c_mv_dims') => [
                'widthbetween1and2560andheightbetween1and2560',
            ],
            $scope->tableName('c_mv_bytes') => ['bytes>0'],
            $scope->tableName('c_mv_hash') => [
                'char_length(sha256)=64',
            ],
            $scope->tableName('c_mv_mime') => ["mime='image/avif'"],
            $scope->tableName('c_mv_storage') => [
                'char_length(storage_key)between1and255',
            ],
        ];
        if (count($actual) !== count($expected)) {
            return false;
        }
        foreach ($expected as $name => $acceptedClauses) {
            if (!isset($actual[$name])) {
                return false;
            }
            foreach ($acceptedClauses as $clause) {
                if (str_contains($actual[$name], $clause)) {
                    continue 2;
                }
            }

            return false;
        }

        return true;
    }

    private function normalizeSql(string $sql): string
    {
        $sql = strtolower($sql);
        $sql = (string) preg_replace(
            "/(?<![a-z0-9_])_[a-z0-9]+\s*(?=')/i",
            '',
            $sql
        );
        $sql = str_replace(['`', '"', '[', ']'], '', $sql);
        return (string) preg_replace('/\s+/', '', $sql);
    }

    /** @return list<string> */
    private function indexSignatures(
        PDO $pdo,
        MigrationScope $scope,
        string $suffix,
        string $driver
    ): array {
        $signatures = [];
        if ($driver === 'sqlite') {
            $indexes = $pdo->query(
                'PRAGMA index_list('
                . $scope->quotedTable($suffix, 'sqlite') . ')'
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($indexes as $index) {
                $name = $index['name'] ?? null;
                if (!is_string($name)) {
                    continue;
                }
                $columns = $pdo->query(
                    'PRAGMA index_info("'
                    . str_replace('"', '""', $name) . '")'
                )->fetchAll(PDO::FETCH_ASSOC);
                usort(
                    $columns,
                    static fn (array $a, array $b): int =>
                        (int) $a['seqno'] <=> (int) $b['seqno']
                );
                $signatures[] = ((int) ($index['unique'] ?? 0) === 1
                    ? 'U:' : 'N:') . implode(',', array_map(
                        static fn (array $row): string =>
                            (string) ($row['name'] ?? ''),
                        $columns
                    ));
            }

            return $signatures;
        }

        $query = $pdo->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME '
            . 'FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
            . 'ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $query->execute(['name' => $scope->tableName($suffix)]);
        $grouped = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['INDEX_NAME'] ?? null) === 'PRIMARY') {
                continue;
            }
            $name = (string) ($row['INDEX_NAME'] ?? '');
            $grouped[$name]['unique'] = (int) ($row['NON_UNIQUE'] ?? 1) === 0;
            $grouped[$name]['columns'][] = (string) ($row['COLUMN_NAME'] ?? '');
        }
        foreach ($grouped as $index) {
            $signatures[] = ($index['unique'] ? 'U:' : 'N:')
                . implode(',', $index['columns']);
        }

        return $signatures;
    }

    private function seedsAreValid(PDO $pdo, MigrationScope $scope): bool
    {
        $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
        $query = $pdo->query(
            'SELECT code, module_id, label_key, is_delegable FROM '
            . $scope->quotedTable('capabilities', $driver)
            . " WHERE code IN ('webadmin.media.view', 'webadmin.media.upload') "
            . 'ORDER BY code'
        );
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 2) {
            return false;
        }
        $expected = [
            'webadmin.media.upload' => 'webadmin.capabilities.media_upload',
            'webadmin.media.view' => 'webadmin.capabilities.media_view',
        ];
        foreach ($rows as $row) {
            $code = $row['code'] ?? null;
            if (
                !is_string($code)
                || ($row['module_id'] ?? null) !== 'webadmin'
                || ($row['label_key'] ?? null) !== ($expected[$code] ?? null)
                || !in_array($row['is_delegable'] ?? null, [1, '1'], true)
            ) {
                return false;
            }
        }

        $state = $pdo->query(
            'SELECT value_text FROM '
            . $scope->quotedTable('state', $driver)
            . " WHERE state_key = 'media.quota_lock'"
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($state !== ['v1']) {
            return false;
        }

        $roles = $pdo->query(
            'SELECT r.code AS role_code, c.code AS capability_code FROM '
            . $scope->quotedTable('role_capabilities', $driver) . ' rc '
            . 'JOIN ' . $scope->quotedTable('roles', $driver)
            . ' r ON r.id = rc.role_id JOIN '
            . $scope->quotedTable('capabilities', $driver)
            . " c ON c.id = rc.capability_id WHERE c.code IN "
            . "('webadmin.media.view', 'webadmin.media.upload') "
            . 'ORDER BY r.code, c.code'
        )->fetchAll(PDO::FETCH_ASSOC);

        return $roles === [
            ['role_code' => 'site_admin', 'capability_code' => 'webadmin.media.upload'],
            ['role_code' => 'site_admin', 'capability_code' => 'webadmin.media.view'],
            ['role_code' => 'system_superadmin', 'capability_code' => 'webadmin.media.upload'],
            ['role_code' => 'system_superadmin', 'capability_code' => 'webadmin.media.view'],
        ];
    }

    private function storedRowsAreValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $assets = $scope->quotedTable('media_assets', $driver);
        $variants = $scope->quotedTable('media_variants', $driver);
        $invalidAssets = $pdo->query(
            'SELECT 1 FROM ' . $assets . ' WHERE '
            . "source_mime NOT IN ('image/jpeg','image/png','image/webp') "
            . 'OR source_width NOT BETWEEN 1 AND 12000 '
            . 'OR source_height NOT BETWEEN 1 AND 12000 '
            . 'OR (source_width * source_height) > 40000000 '
            . 'OR source_bytes NOT BETWEEN 1 AND 12582912 '
            . 'OR LENGTH(source_sha256) <> 64 OR LENGTH(public_id) <> 36 '
            . "OR LENGTH(TRIM(label)) < 1 OR LENGTH(label) > 120 LIMIT 1"
        )->fetchColumn();
        $invalidVariants = $pdo->query(
            'SELECT 1 FROM ' . $variants . ' WHERE '
            . "mime <> 'image/avif' OR width NOT BETWEEN 1 AND 2560 "
            . 'OR height NOT BETWEEN 1 AND 2560 OR bytes < 1 '
            . 'OR LENGTH(sha256) <> 64 OR LENGTH(storage_key) NOT BETWEEN 1 AND 255 '
            . 'LIMIT 1'
        )->fetchColumn();

        if ($driver === 'sqlite') {
            $invalidAssetIdentifiers = $pdo->query(
                'SELECT 1 FROM ' . $assets . ' WHERE '
                . 'public_id <> LOWER(public_id) '
                . "OR SUBSTR(public_id, 9, 1) <> '-' "
                . "OR SUBSTR(public_id, 14, 1) <> '-' "
                . "OR SUBSTR(public_id, 19, 1) <> '-' "
                . "OR SUBSTR(public_id, 24, 1) <> '-' "
                . "OR SUBSTR(public_id, 15, 1) <> '4' "
                . "OR SUBSTR(public_id, 20, 1) NOT IN ('8','9','a','b') "
                . "OR REPLACE(public_id, '-', '') GLOB '*[^0-9a-f]*' "
                . "OR source_sha256 <> LOWER(source_sha256) "
                . "OR source_sha256 GLOB '*[^0-9a-f]*' LIMIT 1"
            )->fetchColumn();
            $invalidVariantIdentifiers = $pdo->query(
                'SELECT 1 FROM ' . $variants . ' v JOIN ' . $assets
                . ' a ON a.id = v.asset_id WHERE '
                . "v.sha256 <> LOWER(v.sha256) "
                . "OR v.sha256 GLOB '*[^0-9a-f]*' "
                . "OR v.storage_key <> SUBSTR(a.public_id, 1, 2) || '/' "
                . "|| a.public_id || '/' || CAST(v.width AS TEXT) || '.avif' "
                . 'LIMIT 1'
            )->fetchColumn();
        } else {
            $invalidAssetIdentifiers = $pdo->query(
                'SELECT 1 FROM ' . $assets . ' WHERE '
                . "public_id NOT REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$' "
                . "OR source_sha256 NOT REGEXP '^[0-9a-f]{64}$' LIMIT 1"
            )->fetchColumn();
            $invalidVariantIdentifiers = $pdo->query(
                'SELECT 1 FROM ' . $variants . ' v JOIN ' . $assets
                . ' a ON a.id = v.asset_id WHERE '
                . "v.sha256 NOT REGEXP '^[0-9a-f]{64}$' "
                . "OR v.storage_key <> CONCAT(LEFT(a.public_id, 2), '/', "
                . "a.public_id, '/', v.width, '.avif') LIMIT 1"
            )->fetchColumn();
        }

        return $invalidAssets === false
            && $invalidVariants === false
            && $invalidAssetIdentifiers === false
            && $invalidVariantIdentifiers === false;
    }

    /** @param list<array<string, mixed>> $rows */
    private function containsForeignKey(
        array $rows,
        string $table,
        string $column,
        string $target,
        string $delete
    ): bool {
        foreach ($rows as $row) {
            if (
                ($row['TABLE_NAME'] ?? null) === $table
                && ($row['COLUMN_NAME'] ?? null) === $column
                && ($row['REFERENCED_TABLE_NAME'] ?? null) === $target
                && strtoupper((string) ($row['DELETE_RULE'] ?? '')) === $delete
            ) {
                return true;
            }
        }

        return false;
    }
}
