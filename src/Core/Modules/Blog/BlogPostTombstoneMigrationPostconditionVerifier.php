<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Database\MySqlColumnDefaultNormalizer;
use App\Core\Database\MySqlServerCapabilities;
use App\Core\Database\SqlCheckExpressionCanonicalizer;
use App\Core\Database\SqliteIndexSignature;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Exact, read-only composite postcondition for Blog migration 0007. */
final class BlogPostTombstoneMigrationPostconditionVerifier implements
    MigrationPostconditionVerifierInterface
{
    private readonly BlogSitemapStateMigrationPostconditionVerifier
        $baseVerifier;

    public function __construct(
        ?BlogSitemapStateMigrationPostconditionVerifier $baseVerifier = null,
        private readonly MySqlColumnDefaultNormalizer $defaultNormalizer =
            new MySqlColumnDefaultNormalizer(),
        bool $expectAnalyticsExtension = false
    ) {
        $this->baseVerifier = $baseVerifier
            ?? new BlogSitemapStateMigrationPostconditionVerifier(
                new BlogStructuredContentMigrationPostconditionVerifier(
                    expectSitemapStateExtension: true,
                    expectPostTombstoneExtension: true,
                    expectAnalyticsExtension: $expectAnalyticsExtension
                )
            );
    }

    public function contractVersion(): string
    {
        return 'blog-post-tombstone-schema-v1';
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
        $name = $scope->tableName('post_tombstones');
        $quoted = $scope->quotedTable('post_tombstones', 'sqlite');
        $definition = $pdo->prepare(
            "SELECT type, sql FROM sqlite_master WHERE name = :name"
        );
        $definition->execute(['name' => $name]);
        $object = $definition->fetch(PDO::FETCH_ASSOC);
        if (
            !is_array($object)
            || ($object['type'] ?? null) !== 'table'
            || !is_string($object['sql'] ?? null)
            || stripos((string) $object['sql'], 'WITHOUT ROWID') === false
            || preg_match(
                '/"trashed_by_user_public_id"\s+TEXT\s+COLLATE\s+BINARY\b/i',
                (string) $object['sql']
            ) !== 1
        ) {
            return false;
        }

        $columns = $pdo->query('PRAGMA table_info(' . $quoted . ')')
            ->fetchAll(PDO::FETCH_ASSOC);
        $actualColumns = array_map(
            static fn (array $row): array => [
                strtolower((string) ($row['name'] ?? '')),
                strtoupper((string) ($row['type'] ?? '')),
                (int) ($row['notnull'] ?? -1),
                (int) ($row['pk'] ?? -1),
                $row['dflt_value'] ?? null,
            ],
            $columns
        );
        if ($actualColumns !== [
            ['post_localization_id', 'INTEGER', 1, 1, null],
            ['trashed_by_user_public_id', 'TEXT', 1, 0, null],
            ['trashed_at', 'TEXT', 1, 0, null],
        ]) {
            return false;
        }

        $checks = $this->checkExpressions((string) $object['sql']);
        $expectedChecks = [SqlCheckExpressionCanonicalizer::canonicalize(
            'length(trashed_by_user_public_id) = 36'
        )];
        sort($checks, SORT_STRING);
        sort($expectedChecks, SORT_STRING);
        if ($checks !== $expectedChecks) {
            return false;
        }

        $signatures = [];
        foreach ($pdo->query('PRAGMA index_list(' . $quoted . ')')
            ->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $signature = SqliteIndexSignature::fromPragmaRow(
                $pdo,
                $row,
                ['c', 'pk']
            );
            if (!is_string($signature)) {
                return false;
            }
            $signatures[] = $signature;
        }
        sort($signatures, SORT_STRING);
        if ($signatures !== [
            '0:trashed_at,post_localization_id',
            'p:post_localization_id',
        ]) {
            return false;
        }

        $foreignKeys = $pdo->query('PRAGMA foreign_key_list(' . $quoted . ')')
            ->fetchAll(PDO::FETCH_ASSOC);
        if (count($foreignKeys) !== 1) {
            return false;
        }
        $foreign = $foreignKeys[0];
        if (
            (int) ($foreign['seq'] ?? -1) !== 0
            || strtolower((string) ($foreign['from'] ?? ''))
                !== 'post_localization_id'
            || strtolower((string) ($foreign['table'] ?? ''))
                !== strtolower($scope->tableName('post_localizations'))
            || strtolower((string) ($foreign['to'] ?? '')) !== 'id'
            || strtoupper((string) ($foreign['on_update'] ?? ''))
                !== 'NO ACTION'
            || strtoupper((string) ($foreign['on_delete'] ?? ''))
                !== 'CASCADE'
        ) {
            return false;
        }

        $triggers = $pdo->prepare(
            "SELECT COUNT(*) FROM sqlite_master "
                . "WHERE type = 'trigger' AND tbl_name = :name"
        );
        $triggers->execute(['name' => $name]);

        return (int) $triggers->fetchColumn() === 0
            && $this->dataIsValid($pdo, $scope, 'sqlite');
    }

    private function verifyMySql(PDO $pdo, MigrationScope $scope): bool
    {
        $name = $scope->tableName('post_tombstones');
        $quoted = $scope->quotedTable('post_tombstones', 'mysql');
        $serverVersion = $pdo->query('SELECT VERSION()')->fetchColumn();
        if (!is_string($serverVersion)) {
            return false;
        }
        $isMariaDb = MySqlServerCapabilities::isMariaDb($serverVersion);
        $table = $pdo->prepare(
            'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                . "AND TABLE_TYPE = 'BASE TABLE'"
        );
        $table->execute(['name' => $name]);
        $metadata = $table->fetch(PDO::FETCH_ASSOC);
        if (
            !is_array($metadata)
            || strtoupper((string) ($metadata['ENGINE'] ?? '')) !== 'INNODB'
            || strtolower((string) ($metadata['TABLE_COLLATION'] ?? ''))
                !== 'utf8mb4_unicode_ci'
        ) {
            return false;
        }

        $query = $pdo->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, '
                . 'COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH, '
                . 'DATETIME_PRECISION, CHARACTER_SET_NAME, COLLATION_NAME, '
                . 'EXTRA FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                . 'ORDER BY ORDINAL_POSITION'
        );
        $query->execute(['name' => $name]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 3) {
            return false;
        }
        $expected = [
            [
                'post_localization_id', 'bigint', true, false, null, null,
                null, null,
            ],
            [
                'trashed_by_user_public_id', 'char', false, false, 36,
                null, 'ascii', 'ascii_bin',
            ],
            [
                'trashed_at', 'datetime', false, false, null, 6, null, null,
            ],
        ];
        foreach ($rows as $position => $row) {
            $type = strtolower((string) ($row['DATA_TYPE'] ?? ''));
            $default = $this->defaultNormalizer->normalizeMetadata(
                isset($row['COLUMN_DEFAULT'])
                    ? (string) $row['COLUMN_DEFAULT'] : null,
                $type,
                strtolower((string) ($row['EXTRA'] ?? '')),
                $isMariaDb
            );
            $actual = [
                strtolower((string) ($row['COLUMN_NAME'] ?? '')),
                $type,
                str_contains(
                    strtolower((string) ($row['COLUMN_TYPE'] ?? '')),
                    'unsigned'
                ),
                strtoupper((string) ($row['IS_NULLABLE'] ?? '')) === 'YES',
                $row['CHARACTER_MAXIMUM_LENGTH'] === null
                    ? null : (int) $row['CHARACTER_MAXIMUM_LENGTH'],
                $row['DATETIME_PRECISION'] === null
                    ? null : (int) $row['DATETIME_PRECISION'],
                $row['CHARACTER_SET_NAME'] === null
                    ? null : strtolower((string) $row['CHARACTER_SET_NAME']),
                $row['COLLATION_NAME'] === null
                    ? null : strtolower((string) $row['COLLATION_NAME']),
            ];
            if (
                $actual !== $expected[$position]
                || $default !== null
                || trim(strtolower((string) ($row['EXTRA'] ?? ''))) !== ''
            ) {
                return false;
            }
        }

        $indexes = $pdo->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, '
                . 'SUB_PART, COLLATION FROM information_schema.STATISTICS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                . 'ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $indexes->execute(['name' => $name]);
        $signatures = [];
        foreach ($indexes->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (
                ($row['SUB_PART'] ?? null) !== null
                || strtoupper((string) ($row['COLLATION'] ?? '')) !== 'A'
            ) {
                return false;
            }
            $indexName = strtoupper((string) ($row['INDEX_NAME'] ?? ''));
            $kind = $indexName === 'PRIMARY'
                ? 'p'
                : ((int) ($row['NON_UNIQUE'] ?? -1) === 0 ? '1' : '0');
            $key = $kind . ':' . strtolower((string) $row['INDEX_NAME']);
            $signatures[$key][(int) ($row['SEQ_IN_INDEX'] ?? 0)] =
                strtolower((string) ($row['COLUMN_NAME'] ?? ''));
        }
        $normalized = [];
        foreach ($signatures as $key => $columns) {
            ksort($columns, SORT_NUMERIC);
            if (array_keys($columns) !== range(1, count($columns))) {
                return false;
            }
            $normalized[] = explode(':', $key, 2)[0] . ':'
                . implode(',', $columns);
        }
        sort($normalized, SORT_STRING);
        if ($normalized !== [
            '0:trashed_at,post_localization_id',
            'p:post_localization_id',
        ]) {
            return false;
        }

        $foreignKeys = $pdo->prepare(
            'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, '
                . 'k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE '
                . 'FROM information_schema.KEY_COLUMN_USAGE k JOIN '
                . 'information_schema.REFERENTIAL_CONSTRAINTS r ON '
                . 'r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND '
                . 'r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
                . 'WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = :name '
                . 'AND k.REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $foreignKeys->execute(['name' => $name]);
        $foreignRows = $foreignKeys->fetchAll(PDO::FETCH_ASSOC);
        if (count($foreignRows) !== 1) {
            return false;
        }
        $foreign = $foreignRows[0];
        if (
            strtolower((string) ($foreign['COLUMN_NAME'] ?? ''))
                !== 'post_localization_id'
            || strtolower((string) ($foreign['REFERENCED_TABLE_NAME'] ?? ''))
                !== strtolower($scope->tableName('post_localizations'))
            || strtolower((string) ($foreign['REFERENCED_COLUMN_NAME'] ?? ''))
                !== 'id'
            || strtoupper((string) ($foreign['UPDATE_RULE'] ?? ''))
                !== 'NO ACTION'
            || strtoupper((string) ($foreign['DELETE_RULE'] ?? ''))
                !== 'CASCADE'
        ) {
            return false;
        }

        if (!$this->mysqlChecksAreExact($pdo, $name, $isMariaDb)) {
            return false;
        }
        $triggers = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS '
                . 'WHERE TRIGGER_SCHEMA = DATABASE() '
                . 'AND EVENT_OBJECT_TABLE = :name'
        );
        $triggers->execute(['name' => $name]);

        return (int) $triggers->fetchColumn() === 0
            && $this->dataIsValid($pdo, $scope, 'mysql');
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
        $expected = [SqlCheckExpressionCanonicalizer::canonicalize(
            'char_length(trashed_by_user_public_id) = 36'
        )];
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private function dataIsValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $tombstones = $scope->quotedTable('post_tombstones', $driver);
        $localizations = $scope->quotedTable('post_localizations', $driver);
        $length = $driver === 'mysql' ? 'CHAR_LENGTH' : 'length';
        $count = $pdo->query(
            'SELECT COUNT(*) FROM ' . $tombstones . ' t LEFT JOIN '
                . $localizations . ' l ON l.id = t.post_localization_id '
                . 'WHERE l.id IS NULL OR l.status <> \'draft\' OR '
                . $length . '(t.trashed_by_user_public_id) <> 36'
        )->fetchColumn();

        return (int) $count === 0;
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
}
