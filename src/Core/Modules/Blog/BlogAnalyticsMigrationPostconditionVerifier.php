<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Database\MySqlServerCapabilities;
use App\Core\Database\SqlCheckExpressionCanonicalizer;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Read-only composite postcondition for the optional analytics schema. */
final class BlogAnalyticsMigrationPostconditionVerifier implements
    MigrationPostconditionVerifierInterface
{
    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'analytics_sessions' => [
            'id', 'session_hash', 'visitor_hash',
            'landing_localization_id', 'is_returning', 'pageview_count',
            'engagement_msec', 'started_at', 'last_activity_at',
        ],
        'analytics_views' => [
            'id', 'public_id', 'session_id', 'localization_id',
            'engagement_msec', 'last_sequence', 'started_at',
            'last_activity_at',
        ],
    ];

    public function __construct(
        private readonly BlogPostTombstoneMigrationPostconditionVerifier
            $baseVerifier = new BlogPostTombstoneMigrationPostconditionVerifier(
                expectAnalyticsExtension: true
            )
    ) {
    }

    public function contractVersion(): string
    {
        return 'blog-analytics-schema-v1';
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
            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            if (!in_array($driver, ['mysql', 'sqlite'], true)) {
                return false;
            }

            return $this->columnsAreExact($pdo, $scope, $driver)
                && $this->indexesAreExact($pdo, $scope, $driver)
                && $this->foreignKeysAreExact($pdo, $scope, $driver)
                && $this->checksAreExact($pdo, $scope, $driver)
                && $this->hasNoTriggers($pdo, $scope, $driver)
                && $this->dataIsValid($pdo, $scope, $driver);
        } catch (Throwable) {
            return false;
        }
    }

    private function columnsAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        foreach (self::COLUMNS as $suffix => $expectedNames) {
            if ($driver === 'sqlite') {
                $rows = $pdo->query(
                    'PRAGMA table_info('
                        . $scope->quotedTable($suffix, 'sqlite') . ')'
                )->fetchAll(PDO::FETCH_ASSOC);
                $names = array_map(
                    static fn (array $row): string =>
                        strtolower((string) ($row['name'] ?? '')),
                    $rows
                );
                if (
                    $names !== $expectedNames
                    || !$this->sqliteColumnContract($suffix, $rows)
                ) {
                    return false;
                }
                continue;
            }
            $table = $pdo->prepare(
                'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES '
                    . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                    . "AND TABLE_TYPE = 'BASE TABLE'"
            );
            $table->execute(['name' => $scope->tableName($suffix)]);
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
                    . 'EXTRA FROM information_schema.COLUMNS WHERE '
                    . 'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                    . 'ORDER BY ORDINAL_POSITION'
            );
            $query->execute(['name' => $scope->tableName($suffix)]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            $names = array_map(
                static fn (array $row): string =>
                    strtolower((string) ($row['COLUMN_NAME'] ?? '')),
                $rows
            );
            if (
                $names !== $expectedNames
                || !$this->mysqlColumnContract($suffix, $rows)
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, mixed>> $rows */
    private function sqliteColumnContract(string $suffix, array $rows): bool
    {
        $expected = $suffix === 'analytics_sessions'
            ? [
                ['INTEGER', 0, 1, null], ['TEXT', 1, 0, null],
                ['TEXT', 1, 0, null], ['INTEGER', 1, 0, null],
                ['INTEGER', 1, 0, '0'], ['INTEGER', 1, 0, '0'],
                ['INTEGER', 1, 0, '0'], ['TEXT', 1, 0, null],
                ['TEXT', 1, 0, null],
            ]
            : [
                ['INTEGER', 0, 1, null], ['TEXT', 1, 0, null],
                ['INTEGER', 1, 0, null], ['INTEGER', 1, 0, null],
                ['INTEGER', 1, 0, '0'], ['INTEGER', 1, 0, '0'],
                ['TEXT', 1, 0, null], ['TEXT', 1, 0, null],
            ];
        $actual = array_map(
            static fn (array $row): array => [
                strtoupper((string) ($row['type'] ?? '')),
                (int) ($row['notnull'] ?? -1),
                (int) ($row['pk'] ?? -1),
                $row['dflt_value'] === null
                    ? null
                    : trim((string) $row['dflt_value'], "()'\" "),
            ],
            $rows
        );

        return $actual === $expected;
    }

    /** @param list<array<string, mixed>> $rows */
    private function mysqlColumnContract(string $suffix, array $rows): bool
    {
        $types = $suffix === 'analytics_sessions'
            ? ['bigint', 'char', 'char', 'bigint', 'tinyint', 'bigint',
                'bigint', 'datetime', 'datetime']
            : ['bigint', 'char', 'bigint', 'bigint', 'bigint', 'bigint',
                'datetime', 'datetime'];
        foreach ($rows as $index => $row) {
            $type = strtolower((string) ($row['DATA_TYPE'] ?? ''));
            if (
                $type !== $types[$index]
                || strtoupper((string) ($row['IS_NULLABLE'] ?? '')) !== 'NO'
            ) {
                return false;
            }
            $name = strtolower((string) ($row['COLUMN_NAME'] ?? ''));
            $isId = $name === 'id';
            $numeric = in_array($type, ['bigint', 'tinyint'], true);
            if (
                $numeric
                && !str_contains(
                    strtolower((string) ($row['COLUMN_TYPE'] ?? '')),
                    'unsigned'
                )
            ) {
                return false;
            }
            $extra = strtolower(trim((string) ($row['EXTRA'] ?? '')));
            if ($extra !== ($isId ? 'auto_increment' : '')) {
                return false;
            }
            if ($type === 'char') {
                $expectedLength = $name === 'public_id' ? 36 : 64;
                if (
                    (int) ($row['CHARACTER_MAXIMUM_LENGTH'] ?? 0)
                        !== $expectedLength
                    || strtolower((string) ($row['CHARACTER_SET_NAME'] ?? ''))
                        !== 'ascii'
                    || strtolower((string) ($row['COLLATION_NAME'] ?? ''))
                        !== 'ascii_bin'
                ) {
                    return false;
                }
            }
            if ($type === 'datetime'
                && (int) ($row['DATETIME_PRECISION'] ?? -1) !== 6) {
                return false;
            }
            $default = $row['COLUMN_DEFAULT'] ?? null;
            $expectsZero = in_array(
                $name,
                [
                    'is_returning', 'pageview_count', 'engagement_msec',
                    'last_sequence',
                ],
                true
            );
            if ($expectsZero ? (string) $default !== '0' : $default !== null) {
                return false;
            }
        }

        return true;
    }

    private function indexesAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $expected = [
            'analytics_sessions' => [
                '0:landing_localization_id,started_at',
                '0:last_activity_at',
                '0:visitor_hash,started_at',
                '1:session_hash',
                'p:id',
            ],
            'analytics_views' => [
                '0:localization_id,started_at',
                '0:session_id,started_at',
                '1:public_id',
                'p:id',
            ],
        ];
        foreach (array_keys(self::COLUMNS) as $suffix) {
            $actual = $driver === 'sqlite'
                ? $this->sqliteIndexSignatures($pdo, $scope, $suffix)
                : $this->mysqlIndexSignatures($pdo, $scope, $suffix);
            sort($actual, SORT_STRING);
            if ($actual !== $expected[$suffix]) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function sqliteIndexSignatures(
        PDO $pdo,
        MigrationScope $scope,
        string $suffix
    ): array {
        $signatures = ['p:id'];
        $rows = $pdo->query(
            'PRAGMA index_list(' . $scope->quotedTable($suffix, 'sqlite') . ')'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            if (!is_string($name) || $name === '') {
                return ['invalid'];
            }
            $columns = $pdo->query(
                'PRAGMA index_info("' . str_replace('"', '""', $name) . '")'
            )->fetchAll(PDO::FETCH_ASSOC);
            $columnNames = array_map(
                static fn (array $column): string =>
                    strtolower((string) ($column['name'] ?? '')),
                $columns
            );
            $signatures[] = ((int) ($row['unique'] ?? 0) === 1 ? '1:' : '0:')
                . implode(',', $columnNames);
        }

        return $signatures;
    }

    /** @return list<string> */
    private function mysqlIndexSignatures(
        PDO $pdo,
        MigrationScope $scope,
        string $suffix
    ): array {
        $query = $pdo->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, '
                . 'SUB_PART FROM information_schema.STATISTICS WHERE '
                . 'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                . 'ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $query->execute(['name' => $scope->tableName($suffix)]);
        $grouped = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['SUB_PART'] ?? null) !== null) {
                return ['invalid'];
            }
            $name = (string) ($row['INDEX_NAME'] ?? '');
            $kind = strtoupper($name) === 'PRIMARY'
                ? 'p'
                : ((int) ($row['NON_UNIQUE'] ?? 1) === 0 ? '1' : '0');
            $grouped[$kind . ':' . strtolower($name)][] =
                strtolower((string) ($row['COLUMN_NAME'] ?? ''));
        }

        return array_map(
            static fn (string $key, array $columns): string =>
                explode(':', $key, 2)[0] . ':' . implode(',', $columns),
            array_keys($grouped),
            array_values($grouped)
        );
    }

    private function foreignKeysAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $expected = [
            'analytics_sessions' => [
                ['landing_localization_id', 'post_localizations', 'id'],
            ],
            'analytics_views' => [
                ['localization_id', 'post_localizations', 'id'],
                ['session_id', 'analytics_sessions', 'id'],
            ],
        ];
        foreach (array_keys(self::COLUMNS) as $suffix) {
            if ($driver === 'sqlite') {
                $rows = $pdo->query(
                    'PRAGMA foreign_key_list('
                        . $scope->quotedTable($suffix, 'sqlite') . ')'
                )->fetchAll(PDO::FETCH_ASSOC);
                $actual = array_map(
                    static fn (array $row): array => [
                        strtolower((string) ($row['from'] ?? '')),
                        strtolower((string) ($row['table'] ?? '')),
                        strtolower((string) ($row['to'] ?? '')),
                        strtoupper((string) ($row['on_delete'] ?? '')),
                    ],
                    $rows
                );
            } else {
                $query = $pdo->prepare(
                    'SELECT COLUMN_NAME AS `from`, REFERENCED_TABLE_NAME AS '
                        . '`table`, REFERENCED_COLUMN_NAME AS `to`, '
                        . 'DELETE_RULE AS on_delete FROM '
                        . 'information_schema.KEY_COLUMN_USAGE k JOIN '
                        . 'information_schema.REFERENTIAL_CONSTRAINTS r ON '
                        . 'r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND '
                        . 'r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE '
                        . 'k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = '
                        . ':name AND k.REFERENCED_TABLE_NAME IS NOT NULL'
                );
                $query->execute(['name' => $scope->tableName($suffix)]);
                $actual = array_map(
                    static fn (array $row): array => [
                        strtolower((string) ($row['from'] ?? '')),
                        strtolower((string) ($row['table'] ?? '')),
                        strtolower((string) ($row['to'] ?? '')),
                        strtoupper((string) ($row['on_delete'] ?? '')),
                    ],
                    $query->fetchAll(PDO::FETCH_ASSOC)
                );
            }
            $wanted = array_map(
                static fn (array $row): array => [
                    $row[0], strtolower($scope->tableName($row[1])),
                    $row[2], 'CASCADE',
                ],
                $expected[$suffix]
            );
            usort($actual, static fn (array $a, array $b): int => $a <=> $b);
            usort($wanted, static fn (array $a, array $b): int => $a <=> $b);
            if ($actual !== $wanted) {
                return false;
            }
        }

        return true;
    }

    private function hasNoTriggers(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        foreach (array_keys(self::COLUMNS) as $suffix) {
            if ($driver === 'sqlite') {
                $query = $pdo->prepare(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' "
                        . 'AND tbl_name = :name'
                );
            } else {
                $query = $pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE '
                        . 'TRIGGER_SCHEMA = DATABASE() AND '
                        . 'EVENT_OBJECT_TABLE = :name'
                );
            }
            $query->execute(['name' => $scope->tableName($suffix)]);
            if ((int) $query->fetchColumn() !== 0) {
                return false;
            }
        }

        return true;
    }

    private function checksAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $expected = [
            'analytics_sessions' => [
                'char_length(session_hash) = 64 and session_hash = lower(session_hash)',
                'char_length(visitor_hash) = 64 and visitor_hash = lower(visitor_hash)',
                'is_returning in (0, 1)',
                'last_activity_at >= started_at',
            ],
            'analytics_views' => [
                'char_length(public_id) = 36 and public_id = lower(public_id)',
                'last_activity_at >= started_at',
            ],
        ];
        if ($driver === 'mysql') {
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();
            if (!is_string($version)) {
                return false;
            }
            $mariaDb = MySqlServerCapabilities::isMariaDb($version);
            foreach ($expected as $suffix => $expressions) {
                $query = $pdo->prepare(
                    'SELECT cc.CHECK_CLAUSE, '
                        . ($mariaDb ? "'YES'" : 'tc.ENFORCED')
                        . ' AS ENFORCED FROM '
                        . 'information_schema.TABLE_CONSTRAINTS tc JOIN '
                        . 'information_schema.CHECK_CONSTRAINTS cc ON '
                        . 'cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND '
                        . 'cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
                        . ($mariaDb
                            ? 'AND cc.TABLE_NAME = tc.TABLE_NAME ' : '')
                        . 'WHERE tc.TABLE_SCHEMA = DATABASE() AND '
                        . 'tc.TABLE_NAME = :table AND '
                        . "tc.CONSTRAINT_TYPE = 'CHECK'"
                );
                $query->execute(['table' => $scope->tableName($suffix)]);
                $actual = [];
                foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (strtoupper((string) ($row['ENFORCED'] ?? '')) !== 'YES') {
                        return false;
                    }
                    $actual[] = SqlCheckExpressionCanonicalizer::canonicalize(
                        (string) ($row['CHECK_CLAUSE'] ?? '')
                    );
                }
                $wanted = array_map(
                    [SqlCheckExpressionCanonicalizer::class, 'canonicalize'],
                    $expressions
                );
                sort($actual, SORT_STRING);
                sort($wanted, SORT_STRING);
                if ($actual !== $wanted) {
                    return false;
                }
            }

            return true;
        }

        $sqliteExpected = [
            'analytics_sessions' => [
                'length(session_hash)=64',
                "session_hashnotglob'*[^0-9a-f]*'",
                'length(visitor_hash)=64',
                "visitor_hashnotglob'*[^0-9a-f]*'",
                'is_returningin(0,1)',
                'pageview_count>=0',
                'engagement_msec>=0',
                'last_activity_at>=started_at',
            ],
            'analytics_views' => [
                'length(public_id)=36',
                'public_id=lower(public_id)',
                'engagement_msec>=0',
                'last_sequence>=0',
                'last_activity_at>=started_at',
            ],
        ];
        foreach ($sqliteExpected as $suffix => $snippets) {
            $query = $pdo->prepare(
                "SELECT sql FROM sqlite_master WHERE type = 'table' "
                    . 'AND name = :name'
            );
            $query->execute(['name' => $scope->tableName($suffix)]);
            $sql = $query->fetchColumn();
            if (!is_string($sql)) {
                return false;
            }
            $canonical = strtolower(str_replace(
                ['"', '`', "\r", "\n", "\t", ' '],
                '',
                $sql
            ));
            foreach ($snippets as $snippet) {
                if (!str_contains($canonical, $snippet)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function dataIsValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $sessions = $scope->quotedTable('analytics_sessions', $driver);
        $views = $scope->quotedTable('analytics_views', $driver);
        $localizations = $scope->quotedTable('post_localizations', $driver);
        $length = $driver === 'mysql' ? 'CHAR_LENGTH' : 'length';
        $sessionsInvalid = $pdo->query(
            'SELECT COUNT(*) FROM ' . $sessions . ' s LEFT JOIN '
                . $localizations . ' l ON l.id = s.landing_localization_id '
                . 'WHERE l.id IS NULL OR ' . $length
                . '(s.session_hash) <> 64 OR ' . $length
                . '(s.visitor_hash) <> 64 OR s.is_returning NOT IN (0, 1) '
                . 'OR s.pageview_count < 0 OR s.engagement_msec < 0 OR '
                . 's.last_activity_at < s.started_at'
        )->fetchColumn();
        $viewsInvalid = $pdo->query(
            'SELECT COUNT(*) FROM ' . $views . ' v LEFT JOIN '
                . $sessions . ' s ON s.id = v.session_id LEFT JOIN '
                . $localizations . ' l ON l.id = v.localization_id WHERE '
                . 's.id IS NULL OR l.id IS NULL OR ' . $length
                . '(v.public_id) <> 36 OR v.engagement_msec < 0 OR '
                . 'v.last_sequence < 0 OR v.last_activity_at < v.started_at'
        )->fetchColumn();

        return (int) $sessionsInvalid === 0 && (int) $viewsInvalid === 0;
    }
}
