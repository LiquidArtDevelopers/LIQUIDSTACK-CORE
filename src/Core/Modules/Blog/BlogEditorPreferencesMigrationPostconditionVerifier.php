<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Blog\EditorPreferences\BlogEditorPreferencesCodec;
use App\Core\Database\MySqlColumnDefaultNormalizer;
use App\Core\Database\MySqlServerCapabilities;
use App\Core\Database\SqlCheckExpressionCanonicalizer;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Exact read-only postcondition for optional migration 0012. */
final class BlogEditorPreferencesMigrationPostconditionVerifier implements
    MigrationPostconditionVerifierInterface
{
    private readonly BlogLayoutEditorMigrationPostconditionVerifier
        $baseVerifier;

    public function __construct(
        ?BlogLayoutEditorMigrationPostconditionVerifier $baseVerifier = null,
        private readonly MySqlColumnDefaultNormalizer $defaultNormalizer =
            new MySqlColumnDefaultNormalizer(),
        private readonly BlogEditorPreferencesCodec $codec =
            new BlogEditorPreferencesCodec(),
        bool $expectPrivateDraftPublicationExtension = false
    ) {
        $this->baseVerifier = $baseVerifier
            ?? new BlogLayoutEditorMigrationPostconditionVerifier(
                expectEditorPreferencesExtension: true,
                expectPrivateDraftPublicationExtension:
                    $expectPrivateDraftPublicationExtension
            );
    }

    public function contractVersion(): string
    {
        return 'blog-editor-preferences-schema-v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        if ($scope->moduleId() !== 'blog') {
            return false;
        }

        try {
            if (!$this->baseVerifier->verify($pdo, $scope)) {
                return false;
            }

            return match (MigrationDatabaseDriver::fromPdo($pdo)->value) {
                'sqlite' => $this->verifySqlite($pdo, $scope),
                'mysql' => $this->verifyMySql($pdo, $scope),
                default => false,
            };
        } catch (Throwable) {
            return false;
        }
    }

    private function verifySqlite(PDO $pdo, MigrationScope $scope): bool
    {
        $name = $scope->tableName('editor_preferences');
        $quoted = $scope->quotedTable('editor_preferences', 'sqlite');
        $definition = $pdo->prepare(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :name"
        );
        $definition->execute(['name' => $name]);
        $sql = $definition->fetchColumn();
        if (
            !is_string($sql)
            || stripos($sql, 'WITHOUT ROWID') === false
            || preg_match(
                '/"(?:scope_key|preferences_sha256|updated_by_user_public_id)"\s+TEXT\s+COLLATE\s+BINARY/i',
                $sql
            ) !== 1
        ) {
            return false;
        }

        $columns = $pdo->query('PRAGMA table_info(' . $quoted . ')')
            ->fetchAll(PDO::FETCH_ASSOC);
        $actual = array_map(
            static fn (array $row): array => [
                strtolower((string) ($row['name'] ?? '')),
                strtoupper((string) ($row['type'] ?? '')),
                (int) ($row['notnull'] ?? -1),
                (int) ($row['pk'] ?? -1),
                self::normalizeDefault($row['dflt_value'] ?? null),
            ],
            $columns
        );
        $expected = [
            ['scope_key', 'TEXT', 1, 1, null],
            ['schema_version', 'INTEGER', 1, 0, '1'],
            ['preferences_json', 'TEXT', 1, 0, null],
            ['preferences_bytes', 'INTEGER', 1, 0, null],
            ['preferences_sha256', 'TEXT', 1, 0, null],
            ['lock_version', 'INTEGER', 1, 0, '1'],
            ['updated_by_user_public_id', 'TEXT', 1, 0, null],
            ['created_at', 'TEXT', 1, 0, null],
            ['updated_at', 'TEXT', 1, 0, null],
        ];
        if ($actual !== $expected) {
            return false;
        }

        $actualChecks = $this->checkExpressions($sql);
        $expectedChecks = array_map(
            [SqlCheckExpressionCanonicalizer::class, 'canonicalize'],
            [
                "scope_key = 'global'",
                'schema_version = 1',
                'preferences_bytes BETWEEN 1 AND 4096',
                'length(preferences_sha256) = 64 AND '
                    . "preferences_sha256 NOT GLOB '*[^0-9a-f]*'",
                'lock_version > 0',
                'length(updated_by_user_public_id) = 36 AND '
                    . 'updated_by_user_public_id = '
                    . 'lower(updated_by_user_public_id)',
                'updated_at >= created_at',
            ]
        );
        sort($actualChecks, SORT_STRING);
        sort($expectedChecks, SORT_STRING);
        if ($actualChecks !== $expectedChecks) {
            return false;
        }

        $indexes = $pdo->query('PRAGMA index_list(' . $quoted . ')')
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexes as $index) {
            if ((string) ($index['origin'] ?? '') !== 'pk') {
                return false;
            }
        }
        $triggers = $pdo->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' "
                . 'AND tbl_name = :name'
        );
        $triggers->execute(['name' => $name]);

        return (int) $triggers->fetchColumn() === 0
            && $this->dataIsValid($pdo, $quoted);
    }

    private function verifyMySql(PDO $pdo, MigrationScope $scope): bool
    {
        $name = $scope->tableName('editor_preferences');
        $quoted = $scope->quotedTable('editor_preferences', 'mysql');
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        if (!is_string($version)) {
            return false;
        }
        $mariaDb = MySqlServerCapabilities::isMariaDb($version);
        $query = $pdo->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, '
                . 'COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH, '
                . 'DATETIME_PRECISION, CHARACTER_SET_NAME, COLLATION_NAME, '
                . 'EXTRA FROM information_schema.COLUMNS WHERE '
                . 'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                . 'ORDER BY ORDINAL_POSITION'
        );
        $query->execute(['name' => $name]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 9) {
            return false;
        }
        $expected = [
            ['scope_key', 'varchar', false, 16, 'ascii', 'ascii_bin', null],
            ['schema_version', 'smallint', false, null, null, null, '1'],
            ['preferences_json', 'longtext', false, 4294967295, 'utf8mb4', 'utf8mb4_unicode_ci', null],
            ['preferences_bytes', 'smallint', false, null, null, null, null],
            ['preferences_sha256', 'char', false, 64, 'ascii', 'ascii_bin', null],
            ['lock_version', 'bigint', false, null, null, null, '1'],
            ['updated_by_user_public_id', 'char', false, 36, 'ascii', 'ascii_bin', null],
            ['created_at', 'datetime', false, null, null, null, null],
            ['updated_at', 'datetime', false, null, null, null, null],
        ];
        foreach ($rows as $index => $row) {
            $default = $this->defaultNormalizer->normalizeMetadata(
                isset($row['COLUMN_DEFAULT'])
                    ? (string) $row['COLUMN_DEFAULT'] : null,
                strtolower((string) ($row['DATA_TYPE'] ?? '')),
                strtolower((string) ($row['EXTRA'] ?? '')),
                $mariaDb
            );
            $actual = [
                strtolower((string) ($row['COLUMN_NAME'] ?? '')),
                strtolower((string) ($row['DATA_TYPE'] ?? '')),
                strtoupper((string) ($row['IS_NULLABLE'] ?? '')) === 'YES',
                $row['CHARACTER_MAXIMUM_LENGTH'] === null
                    ? null : (int) $row['CHARACTER_MAXIMUM_LENGTH'],
                $row['CHARACTER_SET_NAME'] === null
                    ? null : strtolower((string) $row['CHARACTER_SET_NAME']),
                $row['COLLATION_NAME'] === null
                    ? null : strtolower((string) $row['COLLATION_NAME']),
                $default === null ? null : trim(strtolower($default), "'"),
            ];
            if ($actual !== $expected[$index]) {
                return false;
            }
            if (
                in_array($index, [1, 3, 5], true)
                && !str_contains(
                    strtolower((string) ($row['COLUMN_TYPE'] ?? '')),
                    'unsigned'
                )
            ) {
                return false;
            }
            if (
                in_array($index, [7, 8], true)
                && (int) ($row['DATETIME_PRECISION'] ?? -1) !== 6
            ) {
                return false;
            }
        }

        $indexes = $pdo->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME '
                . 'FROM information_schema.STATISTICS WHERE '
                . 'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                . 'ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $indexes->execute(['name' => $name]);
        $indexRows = $indexes->fetchAll(PDO::FETCH_ASSOC);
        if (
            count($indexRows) !== 1
            || strtoupper((string) ($indexRows[0]['INDEX_NAME'] ?? ''))
                !== 'PRIMARY'
            || (int) ($indexRows[0]['NON_UNIQUE'] ?? -1) !== 0
            || (string) ($indexRows[0]['COLUMN_NAME'] ?? '') !== 'scope_key'
        ) {
            return false;
        }
        if (!$this->mysqlChecksAreExact($pdo, $name, $mariaDb)) {
            return false;
        }
        $triggers = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE '
                . 'TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = :name'
        );
        $triggers->execute(['name' => $name]);

        return (int) $triggers->fetchColumn() === 0
            && $this->dataIsValid($pdo, $quoted);
    }

    private function mysqlChecksAreExact(
        PDO $pdo,
        string $table,
        bool $mariaDb
    ): bool {
        $query = $pdo->prepare(
            'SELECT cc.CHECK_CLAUSE, '
                . ($mariaDb ? "'YES'" : 'tc.ENFORCED')
                . ' AS ENFORCED FROM information_schema.TABLE_CONSTRAINTS tc '
                . 'JOIN information_schema.CHECK_CONSTRAINTS cc ON '
                . 'cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND '
                . 'cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
                . ($mariaDb ? 'AND cc.TABLE_NAME = tc.TABLE_NAME ' : '')
                . 'WHERE tc.TABLE_SCHEMA = DATABASE() AND '
                . 'tc.TABLE_NAME = :table AND tc.CONSTRAINT_TYPE = '
                . "'CHECK'"
        );
        $query->execute(['table' => $table]);
        $actual = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (strtoupper((string) ($row['ENFORCED'] ?? '')) !== 'YES') {
                return false;
            }
            $actual[] = SqlCheckExpressionCanonicalizer::canonicalize(
                (string) ($row['CHECK_CLAUSE'] ?? '')
            );
        }
        $expected = array_map(
            [SqlCheckExpressionCanonicalizer::class, 'canonicalize'],
            [
                "scope_key = 'global'",
                'schema_version = 1',
                'preferences_bytes BETWEEN 1 AND 4096',
                "preferences_sha256 REGEXP '^[0-9a-f]{64}$'",
                'lock_version > 0',
                'updated_by_user_public_id REGEXP '
                    . "'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-"
                    . "[89ab][0-9a-f]{3}-[0-9a-f]{12}$'",
                'updated_at >= created_at',
            ]
        );
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private function dataIsValid(PDO $pdo, string $table): bool
    {
        $rows = $pdo->query(
            'SELECT scope_key, schema_version, preferences_json, '
                . 'preferences_bytes, preferences_sha256, lock_version, '
                . 'updated_by_user_public_id, created_at, updated_at FROM '
                . $table
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return true;
        }
        if (count($rows) !== 1) {
            return false;
        }
        $row = $rows[0];
        $json = $row['preferences_json'] ?? null;
        $bytes = $this->positiveInteger($row['preferences_bytes'] ?? null);
        $lock = $this->positiveInteger($row['lock_version'] ?? null);
        $schema = $this->positiveInteger($row['schema_version'] ?? null);
        $hash = $row['preferences_sha256'] ?? null;
        $actor = $row['updated_by_user_public_id'] ?? null;
        if (
            ($row['scope_key'] ?? null) !== 'global'
            || $schema !== 1
            || !is_string($json)
            || strlen($json) !== $bytes
            || !is_string($hash)
            || !hash_equals(hash('sha256', $json), $hash)
            || $lock === null
            || !is_string($actor)
            || preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $actor
            ) !== 1
            || !is_string($row['created_at'] ?? null)
            || !is_string($row['updated_at'] ?? null)
            || strcmp($row['updated_at'], $row['created_at']) < 0
        ) {
            return false;
        }

        return hash_equals($this->codec->encode(
            $this->codec->decode($json)
        ), $json);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (
            is_string($value)
            && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1
            && (string) (int) $value === $value
        ) {
            return (int) $value;
        }

        return null;
    }

    /** @return list<string> */
    private function checkExpressions(string $sql): array
    {
        $expressions = [];
        $length = strlen($sql);
        for ($index = 0; $index < $length;) {
            if (in_array($sql[$index], ["'", '"', '`', '['], true)) {
                $this->skipQuotedToken($sql, $index);
                continue;
            }
            if (
                strncasecmp(substr($sql, $index, 5), 'check', 5) !== 0
                || ($index > 0
                    && preg_match('/[a-z0-9_]/i', $sql[$index - 1]) === 1)
                || preg_match('/[a-z0-9_]/i', $sql[$index + 5] ?? '') === 1
            ) {
                ++$index;
                continue;
            }
            $cursor = $index + 5;
            while ($cursor < $length && ctype_space($sql[$cursor])) {
                ++$cursor;
            }
            if (($sql[$cursor] ?? '') !== '(') {
                return ['invalid'];
            }
            $start = ++$cursor;
            $depth = 1;
            while ($cursor < $length && $depth > 0) {
                if (in_array($sql[$cursor], ["'", '"', '`', '['], true)) {
                    $this->skipQuotedToken($sql, $cursor);
                    continue;
                }
                if ($sql[$cursor] === '(') {
                    ++$depth;
                } elseif ($sql[$cursor] === ')') {
                    --$depth;
                }
                ++$cursor;
            }
            if ($depth !== 0) {
                return ['invalid'];
            }
            $expressions[] = SqlCheckExpressionCanonicalizer::canonicalize(
                substr($sql, $start, $cursor - $start - 1)
            );
            $index = $cursor;
        }

        return $expressions;
    }

    private function skipQuotedToken(string $sql, int &$index): void
    {
        $opening = $sql[$index];
        $closing = $opening === '[' ? ']' : $opening;
        ++$index;
        $length = strlen($sql);
        while ($index < $length) {
            if ($sql[$index] !== $closing) {
                ++$index;
                continue;
            }
            if (($sql[$index + 1] ?? '') === $closing) {
                $index += 2;
                continue;
            }
            ++$index;

            return;
        }
    }

    private static function normalizeDefault(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return strtolower(trim((string) $value, "'() \t\n\r\0\x0B"));
    }
}
