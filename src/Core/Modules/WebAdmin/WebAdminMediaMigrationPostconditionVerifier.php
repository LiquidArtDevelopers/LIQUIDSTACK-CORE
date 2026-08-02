<?php

declare(strict_types=1);

namespace App\Core\Modules\WebAdmin;

use App\Core\Database\MySqlServerCapabilities;
use App\Core\Database\SqlCheckExpressionCanonicalizer;
use App\Core\Database\SqliteIndexSignature;
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
        // Constraint identifiers are intentionally not contractual. The
        // published postcondition is the exact structural FK signature.
        $expected = [
            'media_assets' => [
                'created_by_user_id>users.id>NO ACTION>RESTRICT',
            ],
            'media_variants' => [
                'asset_id>media_assets.id>NO ACTION>CASCADE',
            ],
        ];
        foreach ($expected as $suffix => $contracts) {
            if ($driver === 'sqlite') {
                $rows = $pdo->query(
                    'PRAGMA foreign_key_list('
                    . $scope->quotedTable($suffix, 'sqlite') . ')'
                )->fetchAll(PDO::FETCH_ASSOC);
                $actual = array_map(
                    fn (array $row): string =>
                        $this->foreignKeySignature($row, $scope, true),
                    $rows
                );
            } else {
                $query = $pdo->prepare(
                    'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, '
                    . 'k.REFERENCED_COLUMN_NAME, k.ORDINAL_POSITION, '
                    . 'k.REFERENCED_TABLE_SCHEMA = DATABASE() AS SAME_SCHEMA, '
                    . 'r.UPDATE_RULE, r.DELETE_RULE '
                    . 'FROM information_schema.KEY_COLUMN_USAGE k '
                    . 'JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
                    . 'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA '
                    . 'AND r.TABLE_NAME = k.TABLE_NAME '
                    . 'AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
                    . 'WHERE k.CONSTRAINT_SCHEMA = DATABASE() '
                    . 'AND k.TABLE_NAME = :table '
                    . 'AND k.REFERENCED_TABLE_NAME IS NOT NULL '
                    . 'ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION'
                );
                $query->execute(['table' => $scope->tableName($suffix)]);
                $actual = array_map(
                    fn (array $row): string =>
                        $this->foreignKeySignature($row, $scope, false),
                    $query->fetchAll(PDO::FETCH_ASSOC)
                );
            }
            sort($actual, SORT_STRING);
            sort($contracts, SORT_STRING);
            if ($actual !== $contracts) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $row */
    private function foreignKeySignature(
        array $row,
        MigrationScope $scope,
        bool $sqlite
    ): string {
        if ($sqlite) {
            if ((int) ($row['seq'] ?? -1) !== 0) {
                return 'invalid';
            }
        } elseif (
            (int) ($row['ORDINAL_POSITION'] ?? 0) !== 1
            || !in_array(
                $row['SAME_SCHEMA'] ?? null,
                [1, '1', true],
                true
            )
        ) {
            return 'invalid';
        }

        $target = (string) ($sqlite
            ? ($row['table'] ?? '')
            : ($row['REFERENCED_TABLE_NAME'] ?? ''));
        $targetSuffix = match ($target) {
            $scope->tableName('users') => 'users',
            $scope->tableName('media_assets') => 'media_assets',
            default => 'invalid',
        };
        $updateRule = strtoupper((string) ($sqlite
            ? ($row['on_update'] ?? '')
            : ($row['UPDATE_RULE'] ?? '')));
        if ($updateRule === 'RESTRICT') {
            $updateRule = 'NO ACTION';
        }

        return strtolower((string) ($sqlite
            ? ($row['from'] ?? '')
            : ($row['COLUMN_NAME'] ?? '')))
            . '>' . $targetSuffix . '.'
            . strtolower((string) ($sqlite
                ? ($row['to'] ?? '')
                : ($row['REFERENCED_COLUMN_NAME'] ?? '')))
            . '>' . $updateRule . '>'
            . strtoupper((string) ($sqlite
                ? ($row['on_delete'] ?? '')
                : ($row['DELETE_RULE'] ?? '')));
    }

    private function indexesAreValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        // Index identifiers are intentionally not contractual. Engines assign
        // implicit names differently; uniqueness, order and columns are exact.
        $serverVersion = null;
        if ($driver === 'mysql') {
            $serverVersion = $pdo->query('SELECT VERSION()')->fetchColumn();
            if (!is_string($serverVersion)) {
                return false;
            }
        }
        $assets = $this->indexSignatures(
            $pdo,
            $scope,
            'media_assets',
            $driver,
            $serverVersion
        );
        $variants = $this->indexSignatures(
            $pdo,
            $scope,
            'media_variants',
            $driver,
            $serverVersion
        );

        sort($assets, SORT_STRING);
        sort($variants, SORT_STRING);

        return $assets === [
            'N:created_at,id',
            'N:created_by_user_id',
            'P:id',
            'U:public_id',
        ] && $variants === [
            'N:asset_id',
            'P:id',
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
                if (
                    !is_string($default)
                    || SqlCheckExpressionCanonicalizer::compact($default)
                        !== "strftime('%Y-%m-%d %H:%M:%f000','now')"
                ) {
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
        $actualChecks = $this->sqliteCheckExpressions($sql);
        $expectedChecks = $this->expectedSqliteChecks($suffix);
        if ($actualChecks === null || $expectedChecks === null) {
            return false;
        }
        sort($actualChecks, SORT_STRING);
        sort($expectedChecks, SORT_STRING);
        if ($actualChecks !== $expectedChecks) {
            return false;
        }

        $normalized = $this->normalizeSql($sql);

        return substr_count($normalized, 'autoincrement') === 1
            && substr_count($normalized, 'collatebinary') === 3;
    }

    /** @return list<string>|null */
    private function expectedSqliteChecks(string $suffix): ?array
    {
        $expressions = match ($suffix) {
            'media_assets' => [
                'length(public_id) = 36',
                'length(label) BETWEEN 1 AND 120',
                "source_mime IN ('image/jpeg', 'image/png', 'image/webp')",
                'source_width BETWEEN 1 AND 12000',
                'source_height BETWEEN 1 AND 12000',
                'source_bytes BETWEEN 1 AND 12582912',
                'length(source_sha256) = 64',
                '(source_width * source_height) <= 40000000',
            ],
            'media_variants' => [
                'width BETWEEN 1 AND 2560',
                'height BETWEEN 1 AND 2560',
                'bytes > 0',
                'length(sha256) = 64',
                'length(storage_key) BETWEEN 1 AND 255',
                "mime = 'image/avif'",
            ],
            default => null,
        };
        if ($expressions === null) {
            return null;
        }

        return array_map(
            [SqlCheckExpressionCanonicalizer::class, 'canonicalize'],
            $expressions
        );
    }

    /**
     * Extracts real CHECK clauses, never fragments inside literals/comments.
     * Comments are rejected because sqlite_master must describe the exact
     * published schema rather than merely contain recognizable text.
     *
     * @return list<string>|null
     */
    private function sqliteCheckExpressions(string $sql): ?array
    {
        $expressions = [];
        $length = strlen($sql);
        for ($position = 0; $position < $length;) {
            if ($this->startsSqlComment($sql, $position)) {
                return null;
            }
            $character = $sql[$position];
            if (in_array($character, ["'", '"', '`', '['], true)) {
                $position = $this->positionAfterSqlQuotedValue(
                    $sql,
                    $position
                ) ?? -1;
                if ($position < 0) {
                    return null;
                }
                continue;
            }
            if (
                !ctype_alpha($character)
                && $character !== '_'
            ) {
                $position++;
                continue;
            }
            $wordStart = $position++;
            while (
                $position < $length
                && (
                    ctype_alnum($sql[$position])
                    || $sql[$position] === '_'
                )
            ) {
                $position++;
            }
            if (
                strtolower(substr($sql, $wordStart, $position - $wordStart))
                    !== 'check'
            ) {
                continue;
            }
            while (
                $position < $length
                && ctype_space($sql[$position])
            ) {
                $position++;
            }
            if (($sql[$position] ?? null) !== '(') {
                return null;
            }
            $closing = $this->sqliteClosingParenthesis($sql, $position);
            if ($closing === null) {
                return null;
            }
            $expression = SqlCheckExpressionCanonicalizer::canonicalize(
                substr($sql, $position + 1, $closing - $position - 1)
            );
            if ($expression === '') {
                return null;
            }
            $expressions[] = $expression;
            $position = $closing + 1;
        }

        return $expressions;
    }

    private function sqliteClosingParenthesis(
        string $sql,
        int $openingPosition
    ): ?int {
        $length = strlen($sql);
        $depth = 0;
        for ($position = $openingPosition; $position < $length;) {
            if ($this->startsSqlComment($sql, $position)) {
                return null;
            }
            $character = $sql[$position];
            if (in_array($character, ["'", '"', '`', '['], true)) {
                $position = $this->positionAfterSqlQuotedValue(
                    $sql,
                    $position
                ) ?? -1;
                if ($position < 0) {
                    return null;
                }
                continue;
            }
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
                if ($depth === 0) {
                    return $position;
                }
                if ($depth < 0) {
                    return null;
                }
            }
            $position++;
        }

        return null;
    }

    private function startsSqlComment(string $sql, int $position): bool
    {
        return in_array(substr($sql, $position, 2), ['--', '/*'], true);
    }

    private function positionAfterSqlQuotedValue(
        string $sql,
        int $openingPosition
    ): ?int {
        $opening = $sql[$openingPosition] ?? '';
        $closing = $opening === '[' ? ']' : $opening;
        $length = strlen($sql);
        for ($position = $openingPosition + 1; $position < $length;) {
            if ($sql[$position] !== $closing) {
                $position++;
                continue;
            }
            if (
                $opening !== '['
                && ($sql[$position + 1] ?? null) === $closing
            ) {
                $position += 2;
                continue;
            }

            return $position + 1;
        }

        return null;
    }

    /** @param list<array<string, mixed>> $rows */
    private function validMySqlColumns(string $suffix, array $rows): bool
    {
        $expected = $suffix === 'media_assets'
            ? [
                ['id', 'bigint', ['bigint unsigned', 'bigint(20) unsigned'], null, null, null, 'auto_increment'],
                ['public_id', 'char', ['char(36)'], 36, 'ascii', 'ascii_bin', ''],
                ['label', 'varchar', ['varchar(120)'], 120, 'utf8mb4', 'utf8mb4_unicode_ci', ''],
                ['source_mime', 'varchar', ['varchar(32)'], 32, 'ascii', 'ascii_bin', ''],
                ['source_width', 'int', ['int unsigned', 'int(10) unsigned'], null, null, null, ''],
                ['source_height', 'int', ['int unsigned', 'int(10) unsigned'], null, null, null, ''],
                ['source_bytes', 'bigint', ['bigint unsigned', 'bigint(20) unsigned'], null, null, null, ''],
                ['source_sha256', 'char', ['char(64)'], 64, 'ascii', 'ascii_bin', ''],
                ['created_by_user_id', 'bigint', ['bigint unsigned', 'bigint(20) unsigned'], null, null, null, ''],
                ['created_at', 'datetime', ['datetime(6)'], null, null, null, ''],
            ]
            : [
                ['id', 'bigint', ['bigint unsigned', 'bigint(20) unsigned'], null, null, null, 'auto_increment'],
                ['asset_id', 'bigint', ['bigint unsigned', 'bigint(20) unsigned'], null, null, null, ''],
                ['width', 'int', ['int unsigned', 'int(10) unsigned'], null, null, null, ''],
                ['height', 'int', ['int unsigned', 'int(10) unsigned'], null, null, null, ''],
                ['bytes', 'bigint', ['bigint unsigned', 'bigint(20) unsigned'], null, null, null, ''],
                ['sha256', 'char', ['char(64)'], 64, 'ascii', 'ascii_bin', ''],
                ['storage_key', 'varchar', ['varchar(255)'], 255, 'ascii', 'ascii_bin', ''],
                ['mime', 'varchar', ['varchar(32)'], 32, 'ascii', 'ascii_bin', ''],
                ['created_at', 'datetime', ['datetime(6)'], null, null, null, ''],
            ];
        if (count($rows) !== count($expected)) {
            return false;
        }
        foreach ($expected as $index => [$name, $type, $columnTypes, $length, $charset, $collation, $extra]) {
            $row = array_change_key_case($rows[$index], CASE_LOWER);
            $columnType = strtolower(trim((string) (
                preg_replace(
                    '/\s+/',
                    ' ',
                    (string) ($row['column_type'] ?? '')
                ) ?? ''
            )));
            if (
                ($row['column_name'] ?? null) !== $name
                || strtolower((string) ($row['data_type'] ?? '')) !== $type
                || !in_array($columnType, $columnTypes, true)
                || strtoupper((string) ($row['is_nullable'] ?? '')) !== 'NO'
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
                    || strtolower(trim($default)) !== 'current_timestamp(6)') {
                    return false;
                }
            } elseif (
                $default !== null
                || ($row['datetime_precision'] ?? null) !== null
            ) {
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
        $serverVersion = $pdo->query('SELECT VERSION()')->fetchColumn();
        if (!is_string($serverVersion)) {
            return false;
        }
        $isMariaDb = MySqlServerCapabilities::isMariaDb($serverVersion);
        $query = $pdo->prepare(
            'SELECT tc.TABLE_NAME, tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE, '
            . ($isMariaDb ? "'YES'" : 'tc.ENFORCED')
            . ' AS ENFORCED '
            . 'FROM information_schema.TABLE_CONSTRAINTS tc JOIN '
            . 'information_schema.CHECK_CONSTRAINTS cc '
            . 'ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA '
            . 'AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
            . ($isMariaDb ? 'AND cc.TABLE_NAME = tc.TABLE_NAME ' : '')
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
            if (strtoupper((string) ($row['ENFORCED'] ?? '')) !== 'YES') {
                return false;
            }
            $actual[(string) ($row['CONSTRAINT_NAME'] ?? '')] =
                SqlCheckExpressionCanonicalizer::canonicalize(
                    (string) ($row['CHECK_CLAUSE'] ?? '')
                );
        }
        $expected = [
            $scope->tableName('c_ma_public') => [
                'char_length(public_id) = 36',
            ],
            $scope->tableName('c_ma_label') => [
                'char_length(label) BETWEEN 1 AND 120',
            ],
            $scope->tableName('c_ma_mime') => [
                "source_mime IN ('image/jpeg', 'image/png', 'image/webp')",
            ],
            $scope->tableName('c_ma_dims') => [
                'source_width BETWEEN 1 AND 12000 '
                    . 'AND source_height BETWEEN 1 AND 12000 '
                    . 'AND (source_width * source_height) <= 40000000',
                'source_width BETWEEN 1 AND 12000 '
                    . 'AND source_height BETWEEN 1 AND 12000 '
                    . 'AND source_width * source_height <= 40000000',
            ],
            $scope->tableName('c_ma_bytes') => [
                'source_bytes BETWEEN 1 AND 12582912',
            ],
            $scope->tableName('c_ma_hash') => [
                'char_length(source_sha256)=64',
            ],
            $scope->tableName('c_mv_dims') => [
                'width BETWEEN 1 AND 2560 AND height BETWEEN 1 AND 2560',
            ],
            $scope->tableName('c_mv_bytes') => ['bytes>0'],
            $scope->tableName('c_mv_hash') => [
                'char_length(sha256)=64',
            ],
            $scope->tableName('c_mv_mime') => ["mime='image/avif'"],
            $scope->tableName('c_mv_storage') => [
                'char_length(storage_key) BETWEEN 1 AND 255',
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
                if (
                    $actual[$name]
                        === SqlCheckExpressionCanonicalizer::canonicalize(
                            $clause
                        )
                ) {
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
        string $driver,
        ?string $serverVersion = null
    ): array {
        $signatures = [];
        if ($driver === 'sqlite') {
            $indexes = $pdo->query(
                'PRAGMA index_list('
                . $scope->quotedTable($suffix, 'sqlite') . ')'
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($indexes as $index) {
                $signature = SqliteIndexSignature::fromPragmaRow(
                    $pdo,
                    $index,
                    ['c', 'u']
                );
                if (!is_string($signature)) {
                    return ['invalid'];
                }
                [$kind, $columns] = explode(':', $signature, 2);
                $signatures[] = ($kind === '1' ? 'U:' : 'N:') . $columns;
            }
            $signatures[] = 'P:id';

            return $signatures;
        }

        if (!is_string($serverVersion)) {
            return ['invalid'];
        }
        $ignoredExpression = MySqlServerCapabilities::isMariaDb($serverVersion)
            ? (MySqlServerCapabilities::supportsIgnoredIndexes($serverVersion)
                ? 'IGNORED'
                : "'NO' AS IGNORED")
            : "CASE WHEN IS_VISIBLE = 'YES' THEN 'NO' "
                . "ELSE 'YES' END AS IGNORED";
        $query = $pdo->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, '
            . 'INDEX_TYPE, SUB_PART, COLLATION, ' . $ignoredExpression . ' '
            . 'FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
            . 'ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $query->execute(['name' => $scope->tableName($suffix)]);
        return $this->mysqlIndexSignaturesFromRows(
            $query->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function mysqlIndexSignaturesFromRows(array $rows): array
    {
        $grouped = [];
        $signatures = [];
        foreach ($rows as $row) {
            $name = (string) ($row['INDEX_NAME'] ?? '');
            $column = (string) ($row['COLUMN_NAME'] ?? '');
            $sequence = (int) ($row['SEQ_IN_INDEX'] ?? 0);
            $nonUnique = $row['NON_UNIQUE'] ?? null;
            $primary = strtoupper($name) === 'PRIMARY';
            if (
                $name === ''
                || $column === ''
                || $sequence < 1
                || !in_array($nonUnique, [0, '0', 1, '1'], true)
                || strtoupper((string) ($row['INDEX_TYPE'] ?? '')) !== 'BTREE'
                || ($row['SUB_PART'] ?? null) !== null
                || strtoupper((string) ($row['COLLATION'] ?? '')) !== 'A'
                || strtoupper((string) ($row['IGNORED'] ?? '')) !== 'NO'
                || ($primary && (int) $nonUnique !== 0)
                || isset($grouped[$name]['columns'][$sequence])
                || (isset($grouped[$name]['unique'])
                    && $grouped[$name]['unique'] !== ((int) $nonUnique === 0))
                || (isset($grouped[$name]['primary'])
                    && $grouped[$name]['primary'] !== $primary)
            ) {
                return ['invalid'];
            }
            $grouped[$name]['unique'] = (int) $nonUnique === 0;
            $grouped[$name]['primary'] = $primary;
            $grouped[$name]['columns'][$sequence] = strtolower($column);
        }
        foreach ($grouped as $index) {
            ksort($index['columns'], SORT_NUMERIC);
            if (
                array_keys($index['columns'])
                    !== range(1, count($index['columns']))
            ) {
                return ['invalid'];
            }
            $signatures[] = ($index['primary']
                ? 'P:'
                : ($index['unique'] ? 'U:' : 'N:'))
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
        $users = $scope->quotedTable('users', $driver);
        $orphanAssets = $pdo->query(
            'SELECT 1 FROM ' . $assets . ' AS a LEFT JOIN ' . $users
            . ' AS u ON u.id = a.created_by_user_id '
            . 'WHERE u.id IS NULL LIMIT 1'
        )->fetchColumn();
        $orphanVariants = $pdo->query(
            'SELECT 1 FROM ' . $variants . ' AS v LEFT JOIN ' . $assets
            . ' AS a ON a.id = v.asset_id '
            . 'WHERE a.id IS NULL LIMIT 1'
        )->fetchColumn();
        if ($orphanAssets !== false || $orphanVariants !== false) {
            return false;
        }
        $labelLength = $driver === 'mysql' ? 'CHAR_LENGTH' : 'LENGTH';
        $invalidAssets = $pdo->query(
            'SELECT 1 FROM ' . $assets . ' WHERE '
            . "source_mime NOT IN ('image/jpeg','image/png','image/webp') "
            . 'OR source_width NOT BETWEEN 1 AND 12000 '
            . 'OR source_height NOT BETWEEN 1 AND 12000 '
            . 'OR (source_width * source_height) > 40000000 '
            . 'OR source_bytes NOT BETWEEN 1 AND 12582912 '
            . 'OR LENGTH(source_sha256) <> 64 OR LENGTH(public_id) <> 36 '
            . 'OR ' . $labelLength . '(TRIM(label)) < 1 OR '
            . $labelLength . '(label) > 120 LIMIT 1'
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
}
