<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Database\MySqlColumnDefaultNormalizer;
use App\Core\Database\MySqlServerCapabilities;
use App\Core\Database\SqlCheckExpressionCanonicalizer;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Exact read-only composite postcondition for Blog migration 0006. */
final class BlogSitemapStateMigrationPostconditionVerifier implements
    MigrationPostconditionVerifierInterface
{
    private readonly BlogStructuredContentMigrationPostconditionVerifier
        $structuredVerifier;

    public function __construct(
        ?BlogStructuredContentMigrationPostconditionVerifier
            $structuredVerifier = null,
        private readonly MySqlColumnDefaultNormalizer $defaultNormalizer =
            new MySqlColumnDefaultNormalizer(),
        bool $expectAnalyticsExtension = false
    ) {
        $this->structuredVerifier = $structuredVerifier
            ?? new BlogStructuredContentMigrationPostconditionVerifier(
                expectSitemapStateExtension: true,
                expectAnalyticsExtension: $expectAnalyticsExtension
            );
    }

    public function contractVersion(): string
    {
        return 'blog-sitemap-publication-state-v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        if ($scope->moduleId() !== 'blog') {
            return false;
        }

        try {
            if (!$this->structuredVerifier->verify($pdo, $scope)) {
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
        $table = $scope->tableName('sitemap_state');
        $quoted = $scope->quotedTable('sitemap_state', 'sqlite');
        $definition = $pdo->prepare(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :name"
        );
        $definition->execute(['name' => $table]);
        $sql = $definition->fetchColumn();
        if (!is_string($sql) || stripos($sql, 'WITHOUT ROWID') === false) {
            return false;
        }
        $actualChecks = $this->sqliteCheckExpressions($sql);
        $expectedChecks = array_map(
            [SqlCheckExpressionCanonicalizer::class, 'canonicalize'],
            [
                "state_key = 'sitemap'",
                'public_revision > 0',
                'cache_generation IS NULL OR ('
                    . 'length(cache_generation) = 36 AND '
                    . 'cache_generation = lower(cache_generation))',
            ]
        );
        sort($actualChecks, SORT_STRING);
        sort($expectedChecks, SORT_STRING);
        if ($actualChecks !== $expectedChecks) {
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
            ['state_key', 'TEXT', 1, 1, null],
            ['public_revision', 'INTEGER', 1, 0, '1'],
            ['cache_generation', 'TEXT', 0, 0, null],
            [
                'updated_at',
                'TEXT',
                1,
                0,
                self::normalizeDefault(
                    "strftime('%Y-%m-%d %H:%M:%f000', 'now')"
                ),
            ],
        ];
        if ($actual !== $expected) {
            return false;
        }

        $indexes = $pdo->query('PRAGMA index_list(' . $quoted . ')')
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexes as $index) {
            if ((string) ($index['origin'] ?? '') !== 'pk') {
                return false;
            }
        }

        $trigger = $pdo->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND tbl_name = :name"
        );
        $trigger->execute(['name' => $table]);
        if ((int) $trigger->fetchColumn() !== 0) {
            return false;
        }

        return $this->singletonIsValid($pdo, $quoted);
    }

    private function verifyMySql(PDO $pdo, MigrationScope $scope): bool
    {
        $table = $scope->tableName('sitemap_state');
        $quoted = $scope->quotedTable('sitemap_state', 'mysql');
        $serverVersion = $pdo->query('SELECT VERSION()')->fetchColumn();
        if (!is_string($serverVersion)) {
            return false;
        }
        $isMariaDb = MySqlServerCapabilities::isMariaDb($serverVersion);
        $query = $pdo->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, '
            . 'COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH, DATETIME_PRECISION, '
            . 'CHARACTER_SET_NAME, COLLATION_NAME, EXTRA '
            . 'FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
            . 'ORDER BY ORDINAL_POSITION'
        );
        $query->execute(['name' => $table]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 4) {
            return false;
        }
        $expected = [
            ['state_key', 'varchar', false, 16, 'ascii', 'ascii_bin', null],
            ['public_revision', 'bigint', false, null, null, null, '1'],
            ['cache_generation', 'char', true, 36, 'ascii', 'ascii_bin', null],
            [
                'updated_at', 'datetime', false, null, null, null,
                'current_timestamp(6)',
            ],
        ];
        foreach ($rows as $index => $row) {
            $default = $this->defaultNormalizer->normalizeMetadata(
                isset($row['COLUMN_DEFAULT'])
                    ? (string) $row['COLUMN_DEFAULT'] : null,
                strtolower((string) ($row['DATA_TYPE'] ?? '')),
                strtolower((string) ($row['EXTRA'] ?? '')),
                $isMariaDb
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
            if ($index === 1
                && !str_contains(
                    strtolower((string) ($row['COLUMN_TYPE'] ?? '')),
                    'unsigned'
                )) {
                return false;
            }
        }

        $indexes = $pdo->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME '
            . 'FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
            . 'ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $indexes->execute(['name' => $table]);
        $indexRows = $indexes->fetchAll(PDO::FETCH_ASSOC);
        if (count($indexRows) !== 1
            || strtoupper((string) ($indexRows[0]['INDEX_NAME'] ?? ''))
                !== 'PRIMARY'
            || (int) ($indexRows[0]['NON_UNIQUE'] ?? -1) !== 0
            || (string) ($indexRows[0]['COLUMN_NAME'] ?? '') !== 'state_key') {
            return false;
        }

        $triggers = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS '
            . 'WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = :name'
        );
        $triggers->execute(['name' => $table]);
        if ((int) $triggers->fetchColumn() !== 0) {
            return false;
        }

        if (!$this->mysqlChecksAreExact($pdo, $table, $isMariaDb)) {
            return false;
        }

        return $this->singletonIsValid($pdo, $quoted);
    }

    private function mysqlChecksAreExact(
        PDO $pdo,
        string $table,
        bool $isMariaDb
    ): bool {
        $statement = $pdo->prepare(
            'SELECT cc.CHECK_CLAUSE, '
            . ($isMariaDb ? "'YES'" : 'tc.ENFORCED')
            . ' AS ENFORCED FROM information_schema.TABLE_CONSTRAINTS tc '
            . 'JOIN information_schema.CHECK_CONSTRAINTS cc ON '
            . 'cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND '
            . 'cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
            . ($isMariaDb ? 'AND cc.TABLE_NAME = tc.TABLE_NAME ' : '')
            . 'WHERE tc.TABLE_SCHEMA = DATABASE() '
            . 'AND tc.TABLE_NAME = :table '
            . "AND tc.CONSTRAINT_TYPE = 'CHECK'"
        );
        $statement->execute(['table' => $table]);
        $actual = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
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
                "state_key = 'sitemap'",
                'public_revision > 0',
                'cache_generation IS NULL OR ('
                    . 'char_length(cache_generation) = 36 AND '
                    . 'cache_generation = lower(cache_generation))',
            ]
        );
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    /** @return list<string> */
    private function sqliteCheckExpressions(string $sql): array
    {
        $expressions = [];
        $length = strlen($sql);
        for ($index = 0; $index < $length;) {
            if (in_array($sql[$index], ["'", '"', '`', '['], true)) {
                $this->skipQuotedToken($sql, $index);
                continue;
            }
            if (
                substr($sql, $index, 2) === '--'
                || substr($sql, $index, 2) === '/*'
            ) {
                return ['invalid'];
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

    private function singletonIsValid(PDO $pdo, string $quoted): bool
    {
        $rows = $pdo->query(
            'SELECT state_key, public_revision, cache_generation FROM '
            . $quoted
        )->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1 || ($rows[0]['state_key'] ?? null) !== 'sitemap') {
            return false;
        }
        $revision = $rows[0]['public_revision'] ?? null;
        if (!is_int($revision)
            && !(is_string($revision)
                && preg_match('/\A[1-9][0-9]*\z/', $revision) === 1)) {
            return false;
        }
        $generation = $rows[0]['cache_generation'] ?? null;

        return $generation === null || (
            is_string($generation)
            && preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $generation
            ) === 1
        );
    }

    private static function normalizeDefault(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return strtolower((string) preg_replace('/\s+/', '', (string) $value));
    }
}
