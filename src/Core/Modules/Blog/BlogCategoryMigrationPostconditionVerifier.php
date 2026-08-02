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

/** Exact composite postcondition for Blog categories migration 0003. */
final class BlogCategoryMigrationPostconditionVerifier implements
    MigrationPostconditionVerifierInterface
{
    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'categories' => [
            'id', 'public_id', 'created_by_user_public_id', 'created_at',
            'updated_at',
        ],
        'category_locales' => [
            'id', 'public_id', 'category_id', 'locale', 'slug', 'name',
            'lock_version', 'created_by_user_public_id',
            'updated_by_user_public_id', 'created_at', 'updated_at',
        ],
        'post_categories' => [
            'id', 'public_id', 'post_id', 'category_id',
            'assigned_by_user_public_id', 'created_at', 'updated_at',
        ],
    ];

    private readonly BlogMigrationPostconditionVerifier $baseVerifier;

    public function __construct(
        ?BlogMigrationPostconditionVerifier $baseVerifier = null,
        private readonly MySqlColumnDefaultNormalizer $defaultNormalizer =
            new MySqlColumnDefaultNormalizer(),
        bool $expectStructuredContentExtension = false
    ) {
        $this->baseVerifier = $baseVerifier
            ?? new BlogMigrationPostconditionVerifier(
                expectCategoryExtension: true,
                expectStructuredContentExtension:
                    $expectStructuredContentExtension
            );
    }

    public function contractVersion(): string
    {
        return 'blog-category-schema-v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        if ($scope->moduleId() !== 'blog') {
            return false;
        }

        try {
            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            if (!$this->baseVerifier->verify($pdo, $scope)) {
                return false;
            }

            return $this->tablesAreExact($pdo, $scope, $driver)
                && $this->indexesAreExact($pdo, $scope, $driver)
                && $this->foreignKeysAreExact($pdo, $scope, $driver)
                && $this->checksAreExact($pdo, $scope, $driver)
                && $this->hasNoTriggers($pdo, $scope, $driver)
                && $this->dataIsValid($pdo, $scope, $driver);
        } catch (Throwable) {
            return false;
        }
    }

    private function tablesAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $isMariaDb = false;
        if ($driver === 'mysql') {
            $serverVersion = $pdo->query('SELECT VERSION()')->fetchColumn();
            if (!is_string($serverVersion)) {
                return false;
            }
            $isMariaDb = MySqlServerCapabilities::isMariaDb($serverVersion);
        }
        foreach (self::COLUMNS as $suffix => $names) {
            if ($driver === 'sqlite') {
                $table = $pdo->prepare(
                    "SELECT type, sql FROM sqlite_master WHERE name = :name"
                );
                $table->execute(['name' => $scope->tableName($suffix)]);
                $object = $table->fetch(PDO::FETCH_ASSOC);
                if (
                    !is_array($object)
                    || ($object['type'] ?? null) !== 'table'
                    || !is_string($object['sql'] ?? null)
                ) {
                    return false;
                }
                $columns = $pdo->query(
                    'PRAGMA table_info('
                    . $scope->quotedTable($suffix, 'sqlite') . ')'
                )->fetchAll(PDO::FETCH_ASSOC);
                if (
                    array_map(
                        static fn (array $row): string =>
                            strtolower((string) ($row['name'] ?? '')),
                        $columns
                    ) !== $names
                    || !$this->sqliteColumnsAreExact($suffix, $columns)
                    || !$this->sqliteTableSqlIsExact(
                        $suffix,
                        (string) $object['sql']
                    )
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
            $object = $table->fetch(PDO::FETCH_ASSOC);
            if (
                !is_array($object)
                || strtoupper((string) ($object['ENGINE'] ?? '')) !== 'INNODB'
                || strtolower((string) ($object['TABLE_COLLATION'] ?? ''))
                    !== 'utf8mb4_unicode_ci'
            ) {
                return false;
            }
            $query = $pdo->prepare(
                'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, '
                . 'COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH, '
                . 'DATETIME_PRECISION, CHARACTER_SET_NAME, COLLATION_NAME, '
                . 'COLUMN_KEY, EXTRA FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                . 'ORDER BY ORDINAL_POSITION'
            );
            $query->execute(['name' => $scope->tableName($suffix)]);
            $columns = $query->fetchAll(PDO::FETCH_ASSOC);
            if (
                array_map(
                    static fn (array $row): string =>
                        strtolower((string) ($row['COLUMN_NAME'] ?? '')),
                    $columns
                ) !== $names
                || !$this->mysqlColumnsAreExact(
                    $suffix,
                    $columns,
                    $isMariaDb
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, mixed>> $rows */
    private function sqliteColumnsAreExact(string $suffix, array $rows): bool
    {
        $actual = array_map(
            fn (array $row): array => [
                strtoupper((string) ($row['type'] ?? '')),
                (int) ($row['notnull'] ?? -1),
                (int) ($row['pk'] ?? -1),
                $this->canonicalDefault($row['dflt_value'] ?? null),
            ],
            $rows
        );
        $timestamp = $this->canonicalDefault(
            "(strftime('%Y-%m-%d %H:%M:%f000', 'now'))"
        );
        $id = ['INTEGER', 0, 1, null];
        $text = ['TEXT', 1, 0, null];
        $expected = match ($suffix) {
            'categories' => [
                $id, $text, $text,
                ['TEXT', 1, 0, $timestamp],
                ['TEXT', 1, 0, $timestamp],
            ],
            'category_locales' => [
                $id, $text, ['INTEGER', 1, 0, null], $text, $text, $text,
                ['INTEGER', 1, 0, '1'], $text, $text,
                ['TEXT', 1, 0, $timestamp],
                ['TEXT', 1, 0, $timestamp],
            ],
            'post_categories' => [
                $id, $text, ['INTEGER', 1, 0, null],
                ['INTEGER', 1, 0, null], $text,
                ['TEXT', 1, 0, $timestamp],
                ['TEXT', 1, 0, $timestamp],
            ],
            default => [],
        };

        return $actual === $expected;
    }

    /** @param list<array<string, mixed>> $rows */
    private function mysqlColumnsAreExact(
        string $suffix,
        array $rows,
        bool $isMariaDb
    ): bool
    {
        $expected = $this->mysqlColumnContract($suffix);
        if (count($rows) !== count($expected)) {
            return false;
        }
        foreach ($rows as $position => $row) {
            $contract = $expected[$position];
            $type = strtolower((string) ($row['DATA_TYPE'] ?? ''));
            $extra = strtolower((string) ($row['EXTRA'] ?? ''));
            $default = $this->defaultNormalizer->normalizeMetadata(
                isset($row['COLUMN_DEFAULT'])
                    ? (string) $row['COLUMN_DEFAULT']
                    : null,
                $type,
                $extra,
                $isMariaDb
            );
            if (!$this->mysqlExtraIsExact(
                $extra,
                (string) ($contract['extra'] ?? ''),
                isset($contract['default'])
                    ? (string) $contract['default'] : null
            )) {
                return false;
            }
            $actual = [
                'type' => $type,
                'unsigned' => str_contains(
                    strtolower((string) ($row['COLUMN_TYPE'] ?? '')),
                    'unsigned'
                ),
                'nullable' => strtoupper(
                    (string) ($row['IS_NULLABLE'] ?? '')
                ) === 'YES',
                'length' => $row['CHARACTER_MAXIMUM_LENGTH'] === null
                    ? null : (int) $row['CHARACTER_MAXIMUM_LENGTH'],
                'precision' => $row['DATETIME_PRECISION'] === null
                    ? null : (int) $row['DATETIME_PRECISION'],
                'charset' => $row['CHARACTER_SET_NAME'] === null
                    ? null : strtolower((string) $row['CHARACTER_SET_NAME']),
                'collation' => $row['COLLATION_NAME'] === null
                    ? null : strtolower((string) $row['COLLATION_NAME']),
                'default' => $default === null ? null : strtolower($default),
                // COLUMN_KEY also reports UNI/MUL for secondary indexes and
                // is not a stable column-level contract. Their exact shape is
                // verified separately through information_schema.STATISTICS.
                'key' => strtoupper((string) ($row['COLUMN_KEY'] ?? ''))
                    === 'PRI' ? 'PRI' : '',
                'extra' => str_contains($extra, 'auto_increment')
                    ? 'auto_increment' : '',
            ];
            if ($actual !== $contract) {
                return false;
            }
        }

        return true;
    }

    private function mysqlExtraIsExact(
        string $actual,
        string $expected,
        ?string $default
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
        if ($default === 'current_timestamp(6)') {
            $allowed[] = 'default_generated';
        }
        foreach ($tokens as $token) {
            if (!in_array($token, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array<string, mixed>> */
    private function mysqlColumnContract(string $suffix): array
    {
        $id = $this->mysqlContract(
            'bigint', true, false, null, null, null, null, null,
            'PRI', 'auto_increment'
        );
        $uuid = $this->mysqlContract(
            'char', false, false, 36, null, 'ascii', 'ascii_bin'
        );
        $timestamp = $this->mysqlContract(
            'datetime', false, false, null, 6, null, null,
            'current_timestamp(6)'
        );
        $foreignId = $this->mysqlContract('bigint', true, false);

        return match ($suffix) {
            'categories' => [
                $id, $uuid, $uuid, $timestamp, $timestamp,
            ],
            'category_locales' => [
                $id, $uuid, $foreignId,
                $this->mysqlContract(
                    'varchar', false, false, 16, null, 'ascii', 'ascii_bin'
                ),
                $this->mysqlContract(
                    'varchar', false, false, 190, null, 'ascii', 'ascii_bin'
                ),
                $this->mysqlContract(
                    'varchar', false, false, 255, null, 'utf8mb4',
                    'utf8mb4_unicode_ci'
                ),
                $this->mysqlContract(
                    'bigint', true, false, null, null, null, null, "'1'"
                ),
                $uuid, $uuid, $timestamp, $timestamp,
            ],
            'post_categories' => [
                $id, $uuid, $foreignId, $foreignId, $uuid,
                $timestamp, $timestamp,
            ],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function mysqlContract(
        string $type,
        bool $unsigned,
        bool $nullable,
        ?int $length = null,
        ?int $precision = null,
        ?string $charset = null,
        ?string $collation = null,
        ?string $default = null,
        string $key = '',
        string $extra = ''
    ): array {
        return compact(
            'type', 'unsigned', 'nullable', 'length', 'precision', 'charset',
            'collation', 'default', 'key', 'extra'
        );
    }

    private function sqliteTableSqlIsExact(
        string $suffix,
        string $sql
    ): bool {
        $normalized = $this->canonicalSql($sql);
        if (
            !str_contains($normalized, 'idintegerprimarykeyautoincrement')
            || !str_ends_with(trim($sql), ')')
        ) {
            return false;
        }
        $binary = match ($suffix) {
            'categories' => ['public_id', 'created_by_user_public_id'],
            'category_locales' => [
                'public_id', 'locale', 'slug',
                'created_by_user_public_id', 'updated_by_user_public_id',
            ],
            'post_categories' => [
                'public_id', 'assigned_by_user_public_id',
            ],
            default => [],
        };
        foreach ($binary as $column) {
            if (preg_match(
                '/"' . preg_quote($column, '/')
                . '"\s+TEXT\s+COLLATE\s+BINARY\b/i',
                $sql
            ) !== 1) {
                return false;
            }
        }

        $actualChecks = $this->checkExpressions($sql);
        $expectedChecks = $this->sqliteChecks()[$suffix] ?? [];
        sort($actualChecks, SORT_STRING);
        sort($expectedChecks, SORT_STRING);

        return $actualChecks === $expectedChecks;
    }

    private function indexesAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $expected = [
            'categories' => [
                '0:created_by_user_public_id',
                '1:public_id',
                'p:id',
            ],
            'category_locales' => [
                '0:locale,name',
                '1:category_id,locale',
                '1:locale,slug',
                '1:public_id',
                'p:id',
            ],
            'post_categories' => [
                '0:category_id,post_id',
                '1:post_id,category_id',
                '1:public_id',
                'p:id',
            ],
        ];
        foreach ($expected as $suffix => $contracts) {
            $actual = $this->indexSignatures($pdo, $scope, $driver, $suffix);
            sort($actual, SORT_STRING);
            sort($contracts, SORT_STRING);
            if ($actual !== $contracts) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function indexSignatures(
        PDO $pdo,
        MigrationScope $scope,
        string $driver,
        string $suffix
    ): array {
        if ($driver === 'sqlite') {
            $rows = $pdo->query(
                'PRAGMA index_list(' . $scope->quotedTable($suffix, 'sqlite')
                . ')'
            )->fetchAll(PDO::FETCH_ASSOC);
            $result = [];
            foreach ($rows as $row) {
                $signature = SqliteIndexSignature::fromPragmaRow(
                    $pdo,
                    $row,
                    ['c']
                );
                if (!is_string($signature)) {
                    return ['invalid'];
                }
                $result[] = $signature;
            }
            $result[] = 'p:id';

            return $result;
        }

        $visible = $this->statisticsColumnExists($pdo, 'IS_VISIBLE')
            ? 'IS_VISIBLE' : "'YES'";
        $ignored = $this->statisticsColumnExists($pdo, 'IGNORED')
            ? 'IGNORED' : "'NO'";
        $statement = $pdo->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, '
            . 'INDEX_TYPE, SUB_PART, COLLATION, '
            . $visible . ' AS INDEX_VISIBLE, '
            . $ignored . ' AS INDEX_IGNORED '
            . 'FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table '
            . 'ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $statement->execute(['table' => $scope->tableName($suffix)]);
        return $this->mysqlIndexSignaturesFromRows(
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function mysqlIndexSignaturesFromRows(array $rows): array
    {
        $grouped = [];
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
                || strtoupper((string) ($row['INDEX_VISIBLE'] ?? '')) !== 'YES'
                || strtoupper((string) ($row['INDEX_IGNORED'] ?? '')) !== 'NO'
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
        $result = [];
        foreach ($grouped as $index) {
            ksort($index['columns'], SORT_NUMERIC);
            if (
                array_keys($index['columns'])
                    !== range(1, count($index['columns']))
            ) {
                return ['invalid'];
            }
            $result[] = ($index['primary']
                ? 'p:'
                : ($index['unique'] ? '1:' : '0:'))
                . implode(',', $index['columns']);
        }

        return $result;
    }

    private function statisticsColumnExists(PDO $pdo, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . "WHERE TABLE_SCHEMA = 'information_schema' "
            . "AND TABLE_NAME = 'STATISTICS' AND COLUMN_NAME = :column"
        );
        $statement->execute(['column' => $column]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function foreignKeysAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $expected = [
            'categories' => [],
            'category_locales' => [
                'category_id>categories.id>NO ACTION>CASCADE',
            ],
            'post_categories' => [
                'category_id>categories.id>NO ACTION>CASCADE',
                'post_id>posts.id>NO ACTION>CASCADE',
            ],
        ];
        foreach ($expected as $suffix => $contracts) {
            if ($driver === 'sqlite') {
                $rows = $pdo->query(
                    'PRAGMA foreign_key_list('
                    . $scope->quotedTable($suffix, 'sqlite') . ')'
                )->fetchAll(PDO::FETCH_ASSOC);
                $actual = array_map(
                    function (array $row) use ($scope): string {
                        $target = (string) ($row['table'] ?? '');
                        $targetSuffix = $target === $scope->tableName('posts')
                            ? 'posts' : ($target === $scope->tableName('categories')
                                ? 'categories' : 'invalid');
                        $update = strtoupper(
                            (string) ($row['on_update'] ?? '')
                        );
                        if ($update === 'RESTRICT') {
                            $update = 'NO ACTION';
                        }
                        return strtolower((string) ($row['from'] ?? ''))
                            . '>' . $targetSuffix . '.'
                            . strtolower((string) ($row['to'] ?? '')) . '>'
                            . $update . '>'
                            . strtoupper((string) ($row['on_delete'] ?? ''));
                    },
                    $rows
                );
            } else {
                $statement = $pdo->prepare(
                    'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, '
                    . 'k.REFERENCED_COLUMN_NAME, '
                    . 'k.REFERENCED_TABLE_SCHEMA = DATABASE() AS SAME_SCHEMA, '
                    . 'r.UPDATE_RULE, '
                    . 'r.DELETE_RULE FROM '
                    . 'information_schema.KEY_COLUMN_USAGE k JOIN '
                    . 'information_schema.REFERENTIAL_CONSTRAINTS r ON '
                    . 'r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND '
                    . 'r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND '
                    . 'r.TABLE_NAME = k.TABLE_NAME WHERE k.TABLE_SCHEMA = '
                    . 'DATABASE() AND k.TABLE_NAME = :table AND '
                    . 'k.REFERENCED_TABLE_NAME IS NOT NULL'
                );
                $statement->execute(['table' => $scope->tableName($suffix)]);
                $actual = array_map(
                    function (array $row) use ($scope): string {
                        if (!in_array(
                            $row['SAME_SCHEMA'] ?? null,
                            [1, '1', true],
                            true
                        )) {
                            return 'invalid';
                        }
                        $target = (string) $row['REFERENCED_TABLE_NAME'];
                        $targetSuffix = $target === $scope->tableName('posts')
                            ? 'posts' : ($target === $scope->tableName('categories')
                                ? 'categories' : 'invalid');
                        $update = strtoupper(
                            (string) ($row['UPDATE_RULE'] ?? '')
                        );
                        if ($update === 'RESTRICT') {
                            $update = 'NO ACTION';
                        }
                        return strtolower((string) $row['COLUMN_NAME']) . '>'
                            . $targetSuffix . '.'
                            . strtolower((string) $row['REFERENCED_COLUMN_NAME'])
                            . '>' . $update . '>'
                            . strtoupper((string) $row['DELETE_RULE']);
                    },
                    $statement->fetchAll(PDO::FETCH_ASSOC)
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

    private function checksAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        if ($driver === 'sqlite') {
            return true; // Exact expressions are verified from sqlite_master.
        }
        $serverVersion = $pdo->query('SELECT VERSION()')->fetchColumn();
        if (!is_string($serverVersion)) {
            return false;
        }
        $isMariaDb = MySqlServerCapabilities::isMariaDb($serverVersion);
        $expected = $this->mysqlChecks();
        foreach (array_keys(self::COLUMNS) as $suffix) {
            $statement = $pdo->prepare(
                'SELECT cc.CHECK_CLAUSE, '
                . ($isMariaDb ? "'YES'" : 'tc.ENFORCED')
                . ' AS ENFORCED FROM information_schema.TABLE_CONSTRAINTS '
                . 'tc JOIN information_schema.CHECK_CONSTRAINTS cc ON '
                . 'cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND '
                . 'cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
                . ($isMariaDb ? 'AND cc.TABLE_NAME = tc.TABLE_NAME ' : '')
                . 'WHERE '
                . 'tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = :table '
                . "AND tc.CONSTRAINT_TYPE = 'CHECK'"
            );
            $statement->execute(['table' => $scope->tableName($suffix)]);
            $actual = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (strtoupper((string) ($row['ENFORCED'] ?? '')) !== 'YES') {
                    return false;
                }
                $actual[] = $this->canonicalCheckExpression(
                    (string) ($row['CHECK_CLAUSE'] ?? '')
                );
            }
            sort($actual, SORT_STRING);
            $contracts = $expected[$suffix];
            sort($contracts, SORT_STRING);
            if ($actual !== $contracts) {
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
        $names = array_map(
            fn (string $suffix): string => $scope->tableName($suffix),
            array_keys(self::COLUMNS)
        );
        if ($driver === 'sqlite') {
            $statement = $pdo->prepare(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' "
                . 'AND tbl_name IN (:a, :b, :c)'
            );
        } else {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE '
                . 'TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE IN '
                . '(:a, :b, :c)'
            );
        }
        $statement->execute(['a' => $names[0], 'b' => $names[1], 'c' => $names[2]]);

        return in_array($statement->fetchColumn(), [0, '0'], true);
    }

    private function dataIsValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $categories = $scope->quotedTable('categories', $driver);
        $locales = $scope->quotedTable('category_locales', $driver);
        $relations = $scope->quotedTable('post_categories', $driver);
        if (!$this->relationshipsAreValid($pdo, $scope, $driver)) {
            return false;
        }
        $categoryViolations = (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $categories . ' WHERE '
            . 'LENGTH(public_id) <> 36 '
            . 'OR LENGTH(created_by_user_public_id) <> 36'
        )->fetchColumn();
        $localeViolations = (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $locales . ' WHERE '
            . 'LENGTH(public_id) <> 36 '
            . 'OR LENGTH(locale) NOT BETWEEN 2 AND 16 '
            . 'OR locale <> LOWER(locale) OR locale <> TRIM(locale) '
            . 'OR LENGTH(TRIM(slug)) = 0 OR slug <> LOWER(slug) '
            . 'OR slug <> TRIM(slug) OR LENGTH(TRIM(name)) = 0 '
            . 'OR lock_version < 1 '
            . 'OR LENGTH(created_by_user_public_id) <> 36 '
            . 'OR LENGTH(updated_by_user_public_id) <> 36'
        )->fetchColumn();
        $relationViolations = (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $relations . ' WHERE '
            . 'LENGTH(public_id) <> 36 '
            . 'OR LENGTH(assigned_by_user_public_id) <> 36'
        )->fetchColumn();

        return $categoryViolations === 0
            && $localeViolations === 0
            && $relationViolations === 0;
    }

    private function relationshipsAreValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        foreach ([
            ['category_locales', 'category_id', 'categories'],
            ['post_categories', 'category_id', 'categories'],
            ['post_categories', 'post_id', 'posts'],
        ] as [$childSuffix, $foreignColumn, $parentSuffix]) {
            $child = $scope->quotedTable($childSuffix, $driver);
            $parent = $scope->quotedTable($parentSuffix, $driver);
            $orphans = (int) $pdo->query(
                'SELECT COUNT(*) FROM ' . $child . ' AS child '
                . 'LEFT JOIN ' . $parent . ' AS parent ON parent.id = child.'
                . $foreignColumn . ' WHERE parent.id IS NULL'
            )->fetchColumn();
            if ($orphans !== 0) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, list<string>> */
    private function sqliteChecks(): array
    {
        $checks = [
            'categories' => [
                'length(public_id)=36',
                'length(created_by_user_public_id)=36',
            ],
            'category_locales' => [
                'length(public_id)=36',
                'length(locale) BETWEEN 2 AND 16 '
                    . 'AND locale = lower(locale) AND locale = trim(locale)',
                'length(trim(slug)) > 0 AND slug = lower(slug) '
                    . 'AND slug = trim(slug)',
                'length(trim(name))>0',
                'lock_version>0',
                'length(created_by_user_public_id)=36',
                'length(updated_by_user_public_id)=36',
            ],
            'post_categories' => [
                'length(public_id)=36',
                'length(assigned_by_user_public_id)=36',
            ],
        ];
        foreach ($checks as &$expressions) {
            $expressions = array_map(
                [$this, 'canonicalCheckExpression'],
                $expressions
            );
        }
        unset($expressions);

        return $checks;
    }

    /** @return array<string, list<string>> */
    private function mysqlChecks(): array
    {
        $checks = [
            'categories' => [
                'char_length(public_id)=36',
                'char_length(created_by_user_public_id)=36',
            ],
            'category_locales' => [
                'char_length(public_id)=36',
                'char_length(locale) BETWEEN 2 AND 16 '
                    . 'AND locale = lower(locale) AND locale = trim(locale)',
                'char_length(trim(slug)) > 0 AND slug = lower(slug) '
                    . 'AND slug = trim(slug)',
                'char_length(trim(name))>0',
                'lock_version>0',
                'char_length(created_by_user_public_id)=36',
                'char_length(updated_by_user_public_id)=36',
            ],
            'post_categories' => [
                'char_length(public_id)=36',
                'char_length(assigned_by_user_public_id)=36',
            ],
        ];
        foreach ($checks as &$expressions) {
            $expressions = array_map(
                [$this, 'canonicalCheckExpression'],
                $expressions
            );
        }
        unset($expressions);

        return $checks;
    }

    /** @return list<string> */
    private function checkExpressions(string $sql): array
    {
        $sql = $this->stripSqlComments($sql);
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
                $index++;
                continue;
            }
            $cursor = $index + 5;
            while ($cursor < $length && ctype_space($sql[$cursor])) {
                $cursor++;
            }
            if (($sql[$cursor] ?? '') !== '(') {
                $index += 5;
                continue;
            }
            $start = ++$cursor;
            $depth = 1;
            while ($cursor < $length && $depth > 0) {
                if (in_array($sql[$cursor], ["'", '"', '`', '['], true)) {
                    $this->skipQuotedToken($sql, $cursor);
                    continue;
                }
                if ($sql[$cursor] === '(') {
                    $depth++;
                } elseif ($sql[$cursor] === ')') {
                    $depth--;
                }
                $cursor++;
            }
            if ($depth !== 0) {
                return ['invalid'];
            }
            $expressions[] = $this->canonicalCheckExpression(
                substr($sql, $start, $cursor - $start - 1)
            );
            $index = $cursor;
        }

        return $expressions;
    }

    private function stripSqlComments(string $sql): string
    {
        $withoutComments = '';
        $length = strlen($sql);
        for ($index = 0; $index < $length;) {
            if (in_array($sql[$index], ["'", '"', '`', '['], true)) {
                $start = $index;
                $this->skipQuotedToken($sql, $index);
                $withoutComments .= substr($sql, $start, $index - $start);
                continue;
            }
            if (
                $sql[$index] === '-'
                && ($sql[$index + 1] ?? '') === '-'
            ) {
                $withoutComments .= ' ';
                $index += 2;
                while (
                    $index < $length
                    && !in_array($sql[$index], ["\r", "\n"], true)
                ) {
                    $index++;
                }
                continue;
            }
            if (
                $sql[$index] === '/'
                && ($sql[$index + 1] ?? '') === '*'
            ) {
                $withoutComments .= ' ';
                $index += 2;
                while (
                    $index < $length
                    && !(
                        $sql[$index] === '*'
                        && ($sql[$index + 1] ?? '') === '/'
                    )
                ) {
                    $index++;
                }
                if ($index < $length) {
                    $index += 2;
                }
                continue;
            }
            $withoutComments .= $sql[$index++];
        }

        return $withoutComments;
    }

    private function skipQuotedToken(string $sql, int &$index): void
    {
        $opening = $sql[$index];
        $closing = $opening === '[' ? ']' : $opening;
        $length = strlen($sql);
        $index++;
        while ($index < $length) {
            if ($sql[$index] !== $closing) {
                $index++;
                continue;
            }
            if (($sql[$index + 1] ?? '') === $closing) {
                $index += 2;
                continue;
            }
            $index++;
            return;
        }
    }

    private function canonicalDefault(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->canonicalSql((string) $value);
    }

    private function canonicalSql(string $sql): string
    {
        return SqlCheckExpressionCanonicalizer::compact($sql);
    }

    private function canonicalCheckExpression(string $sql): string
    {
        return SqlCheckExpressionCanonicalizer::canonicalize($sql);
    }
}
