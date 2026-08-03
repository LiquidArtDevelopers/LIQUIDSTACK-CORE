<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Database\MySqlColumnDefaultNormalizer;
use App\Core\Database\MySqlServerCapabilities;
use App\Core\Database\SqlCheckExpressionCanonicalizer;
use App\Core\Database\SqliteIndexSignature;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Strict, read-only verifier for Blog migration 0001. */
final class BlogMigrationPostconditionVerifier implements
    MigrationPostconditionVerifierInterface
{
    public function __construct(
        private readonly MySqlColumnDefaultNormalizer $defaultNormalizer =
            new MySqlColumnDefaultNormalizer(),
        private readonly bool $expectCategoryExtension = false,
        private readonly bool $expectStructuredContentExtension = false,
        private readonly bool $expectSitemapStateExtension = false
    ) {
    }

    public function contractVersion(): string
    {
        return 'blog-initial-schema-v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        if ($scope->moduleId() !== 'blog') {
            return false;
        }

        try {
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
        if (
            (string) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== '1'
            || (string) $pdo->query(
                'PRAGMA ignore_check_constraints'
            )->fetchColumn() !== '0'
            || !$this->sqliteObjectsAreExact($pdo, $scope)
        ) {
            return false;
        }

        foreach (BlogInitialSchemaContract::tableSuffixes() as $suffix) {
            if (
                !$this->sqliteColumnsAreExact($pdo, $scope, $suffix)
                || !$this->sqliteDefinitionIsExact($pdo, $scope, $suffix)
                || !$this->sqliteIndexesAreExact($pdo, $scope, $suffix)
                || !$this->sqliteForeignKeysAreExact($pdo, $scope, $suffix)
            ) {
                return false;
            }
        }

        return $this->sqliteIntegrityIsValid($pdo, $scope);
    }

    private function sqliteObjectsAreExact(
        PDO $pdo,
        MigrationScope $scope
    ): bool {
        $prefix = strtolower($scope->tablePrefix());
        $rows = $pdo->query(
            "SELECT type, name FROM sqlite_master "
            . "WHERE type IN ('table', 'view', 'index', 'trigger')"
        )->fetchAll(PDO::FETCH_ASSOC);
        $actual = [];
        foreach ($rows as $row) {
            $name = strtolower((string) ($row['name'] ?? ''));
            if ($name === strtolower(MigrationRegistry::TABLE)) {
                continue;
            }
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            $actual[] = strtolower((string) ($row['type'] ?? '')) . ':' . $name;
        }
        sort($actual, SORT_STRING);

        $expected = [
            'table:' . strtolower($scope->tableName('posts')),
            'table:' . strtolower($scope->tableName('post_localizations')),
        ];
        foreach ($this->sqliteIndexSuffixes() as $suffix) {
            $expected[] = 'index:' . strtolower($scope->tableName($suffix));
        }
        if ($this->expectCategoryExtension) {
            foreach ($this->categoryTableSuffixes() as $suffix) {
                $expected[] = 'table:' . strtolower(
                    $scope->tableName($suffix)
                );
            }
            foreach ($this->categoryIndexSuffixes() as $suffix) {
                $expected[] = 'index:' . strtolower(
                    $scope->tableName($suffix)
                );
            }
        }
        if ($this->expectStructuredContentExtension) {
            foreach ($this->structuredContentTableSuffixes() as $suffix) {
                $expected[] = 'table:' . strtolower(
                    $scope->tableName($suffix)
                );
            }
            foreach ($this->structuredContentIndexSuffixes() as $suffix) {
                $expected[] = 'index:' . strtolower(
                    $scope->tableName($suffix)
                );
            }
        }
        if ($this->expectSitemapStateExtension) {
            $expected[] = 'table:' . strtolower(
                $scope->tableName('sitemap_state')
            );
        }
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private function sqliteColumnsAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $suffix
    ): bool {
        $rows = $pdo->query(
            'PRAGMA table_info(' . $scope->quotedTable($suffix, 'sqlite') . ')'
        )->fetchAll(PDO::FETCH_ASSOC);
        $actual = [];
        foreach ($rows as $row) {
            $actual[] = [
                'name' => strtolower((string) ($row['name'] ?? '')),
                'type' => strtoupper((string) ($row['type'] ?? '')),
                'not_null' => (int) ($row['notnull'] ?? 0) === 1,
                'primary_position' => (int) ($row['pk'] ?? 0),
                'default' => $this->normalizeSqliteDefault(
                    $row['dflt_value'] ?? null
                ),
            ];
        }

        return $actual === BlogInitialSchemaContract::sqliteColumns()[$suffix];
    }

    private function sqliteDefinitionIsExact(
        PDO $pdo,
        MigrationScope $scope,
        string $suffix
    ): bool {
        $statement = $pdo->prepare(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :name"
        );
        $statement->execute(['name' => $scope->tableName($suffix)]);
        $sql = $statement->fetchColumn();
        if (!is_string($sql) || trim($sql) === '') {
            return false;
        }

        $checks = array_map(
            fn (string $value): string =>
                $this->canonicalCheckExpression($value),
            $this->extractCheckExpressions($sql)
        );
        sort($checks, SORT_STRING);
        $expectedChecks = array_map(
            fn (string $value): string =>
                $this->canonicalCheckExpression($value),
            BlogInitialSchemaContract::sqliteChecks()[$suffix]
        );
        sort($expectedChecks, SORT_STRING);
        if ($checks !== $expectedChecks) {
            return false;
        }

        foreach (
            BlogInitialSchemaContract::sqliteBinaryTextColumns()[$suffix]
            as $column
        ) {
            if (preg_match(
                '/"' . preg_quote($column, '/')
                    . '"\s+TEXT\s+COLLATE\s+BINARY\b/i',
                $sql
            ) !== 1) {
                return false;
            }
        }

        $normalized = $this->canonicalExpression($sql);

        return str_ends_with(rtrim($sql), ')')
            && str_contains(
                $normalized,
                'idintegerprimarykeyautoincrement'
            );
    }

    private function sqliteIndexesAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $suffix
    ): bool {
        $rows = $pdo->query(
            'PRAGMA index_list(' . $scope->quotedTable($suffix, 'sqlite') . ')'
        )->fetchAll(PDO::FETCH_ASSOC);
        $actual = [];
        foreach ($rows as $row) {
            $signature = SqliteIndexSignature::fromPragmaRow(
                $pdo,
                $row,
                ['c']
            );
            if (!is_string($signature)) {
                return false;
            }
            [$unique, $columns] = explode(':', $signature, 2);
            $actual[] = [
                'unique' => $unique === '1',
                'columns' => explode(',', $columns),
            ];
        }
        $this->sortIndexContracts($actual);
        $expected = BlogInitialSchemaContract::indexes()[$suffix];
        $this->sortIndexContracts($expected);

        return $actual === $expected;
    }

    private function sqliteForeignKeysAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $suffix
    ): bool {
        $rows = $pdo->query(
            'PRAGMA foreign_key_list('
            . $scope->quotedTable($suffix, 'sqlite')
            . ')'
        )->fetchAll(PDO::FETCH_ASSOC);
        $actual = [];
        foreach ($rows as $row) {
            if ((int) ($row['seq'] ?? -1) !== 0) {
                return false;
            }
            $target = strtolower((string) ($row['table'] ?? ''));
            $targetSuffix = null;
            foreach (BlogInitialSchemaContract::tableSuffixes() as $candidate) {
                if ($target === strtolower($scope->tableName($candidate))) {
                    $targetSuffix = $candidate;
                    break;
                }
            }
            if ($targetSuffix === null) {
                return false;
            }
            $actual[] = [
                'from' => strtolower((string) ($row['from'] ?? '')),
                'target_suffix' => $targetSuffix,
                'target_column' => strtolower((string) ($row['to'] ?? '')),
                'on_update' => strtoupper((string) ($row['on_update'] ?? '')),
                'on_delete' => strtoupper((string) ($row['on_delete'] ?? '')),
            ];
        }

        return $actual === BlogInitialSchemaContract::foreignKeys()[$suffix];
    }

    private function sqliteIntegrityIsValid(
        PDO $pdo,
        MigrationScope $scope
    ): bool {
        if (
            strtolower((string) $pdo->query(
                'PRAGMA integrity_check(1)'
            )->fetchColumn()) !== 'ok'
            || $pdo->query('PRAGMA foreign_key_check')->fetch() !== false
        ) {
            return false;
        }

        $posts = $scope->quotedTable('posts', 'sqlite');
        $localizations = $scope->quotedTable(
            'post_localizations',
            'sqlite'
        );
        $postViolations = (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $posts . ' WHERE '
            . 'length("public_id") <> 36 '
            . 'OR length("created_by_user_public_id") <> 36'
        )->fetchColumn();
        $localizationViolations = (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $localizations . ' WHERE '
            . 'length("public_id") <> 36 '
            . 'OR length("locale") NOT BETWEEN 2 AND 16 '
            . 'OR "locale" <> lower("locale") '
            . 'OR "locale" <> trim("locale") '
            . 'OR ("slug" IS NOT NULL AND ('
            . 'length(trim("slug")) = 0 OR "slug" <> lower("slug") '
            . 'OR "slug" <> trim("slug"))) '
            . 'OR length(trim("h1")) = 0 '
            . "OR \"status\" NOT IN ('draft', 'published') "
            . "OR (\"status\" = 'draft' AND \"published_at\" IS NOT NULL) "
            . "OR (\"status\" = 'published' AND \"published_at\" IS NULL) "
            . "OR (\"status\" = 'published' AND (\"slug\" IS NULL "
            . 'OR "seo_title" IS NULL OR length(trim("seo_title")) = 0 '
            . 'OR "meta_description" IS NULL '
            . 'OR length(trim("meta_description")) = 0 '
            . 'OR "excerpt" IS NULL OR length(trim("excerpt")) = 0 '
            . 'OR length(trim("body_text")) = 0)) '
            . 'OR "lock_version" <= 0 '
            . 'OR length("created_by_user_public_id") <> 36 '
            . 'OR length("updated_by_user_public_id") <> 36'
        )->fetchColumn();

        return $postViolations === 0 && $localizationViolations === 0;
    }

    private function verifyMySql(PDO $pdo, MigrationScope $scope): bool
    {
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        if (
            !is_string($version)
            || !MySqlServerCapabilities::supportsReliableCheckMetadata($version)
            || !$this->mysqlRuntimeIntegrityIsEnabled($pdo, $version)
            || !$this->mysqlTablesAreExact($pdo, $scope)
            || !$this->mysqlColumnsAreExact($pdo, $scope, $version)
            || !$this->mysqlIndexesAreExact($pdo, $scope, $version)
            || !$this->mysqlForeignKeysAreExact($pdo, $scope)
            || !$this->mysqlChecksAreExact($pdo, $scope, $version)
            || !$this->mysqlHasNoTriggers($pdo, $scope)
        ) {
            return false;
        }

        return $this->mysqlIntegrityIsValid($pdo, $scope);
    }

    private function mysqlRuntimeIntegrityIsEnabled(
        PDO $pdo,
        string $serverVersion
    ): bool {
        if (MySqlServerCapabilities::isMariaDb($serverVersion)) {
            $checks = strtoupper((string) $pdo->query(
                'SELECT @@SESSION.check_constraint_checks'
            )->fetchColumn());
            if (!in_array($checks, ['1', 'ON'], true)) {
                return false;
            }
        }
        foreach (['foreign_key_checks', 'unique_checks'] as $setting) {
            $value = strtoupper((string) $pdo->query(
                'SELECT @@SESSION.' . $setting
            )->fetchColumn());
            if (!in_array($value, ['1', 'ON'], true)) {
                return false;
            }
        }
        $modes = array_map(
            'strtoupper',
            array_filter(array_map(
                'trim',
                explode(',', (string) $pdo->query(
                    'SELECT @@SESSION.sql_mode'
                )->fetchColumn())
            ))
        );

        return in_array('STRICT_TRANS_TABLES', $modes, true)
            || in_array('STRICT_ALL_TABLES', $modes, true);
    }

    private function mysqlTablesAreExact(
        PDO $pdo,
        MigrationScope $scope
    ): bool {
        $statement = $pdo->prepare(
            'SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION '
            . 'FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() '
            . 'AND LOWER(LEFT(TABLE_NAME, :length)) = :prefix '
            . 'AND LOWER(TABLE_NAME) <> :registry_table'
        );
        $statement->execute([
            'length' => strlen($scope->tablePrefix()),
            'prefix' => strtolower($scope->tablePrefix()),
            'registry_table' => strtolower(MigrationRegistry::TABLE),
        ]);
        $actual = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $actual[strtolower((string) $row['TABLE_NAME'])] = [
                strtoupper((string) $row['TABLE_TYPE']),
                strtoupper((string) $row['ENGINE']),
                strtolower((string) $row['TABLE_COLLATION']),
            ];
        }
        ksort($actual, SORT_STRING);
        $expected = [];
        foreach (BlogInitialSchemaContract::tableSuffixes() as $suffix) {
            $expected[strtolower($scope->tableName($suffix))] = [
                'BASE TABLE',
                'INNODB',
                'utf8mb4_unicode_ci',
            ];
        }
        if ($this->expectCategoryExtension) {
            foreach ($this->categoryTableSuffixes() as $suffix) {
                $expected[strtolower($scope->tableName($suffix))] = [
                    'BASE TABLE',
                    'INNODB',
                    'utf8mb4_unicode_ci',
                ];
            }
        }
        if ($this->expectStructuredContentExtension) {
            foreach ($this->structuredContentTableSuffixes() as $suffix) {
                $expected[strtolower($scope->tableName($suffix))] = [
                    'BASE TABLE',
                    'INNODB',
                    'utf8mb4_unicode_ci',
                ];
            }
        }
        if ($this->expectSitemapStateExtension) {
            $expected[strtolower($scope->tableName('sitemap_state'))] = [
                'BASE TABLE',
                'INNODB',
                'utf8mb4_unicode_ci',
            ];
        }
        ksort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private function mysqlColumnsAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $serverVersion
    ): bool {
        $tables = $this->tableNames($scope);
        [$in, $params] = $this->inClause($tables, 'column_table');
        $statement = $pdo->prepare(
            'SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, DATA_TYPE, '
            . 'COLUMN_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH, '
            . 'DATETIME_PRECISION, CHARACTER_SET_NAME, COLLATION_NAME, '
            . 'COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $in . ') '
            . 'ORDER BY TABLE_NAME, ORDINAL_POSITION'
        );
        $statement->execute($params);
        $actual = array_fill_keys(
            BlogInitialSchemaContract::tableSuffixes(),
            []
        );
        $nameToSuffix = array_flip($tables);
        $isMariaDb = stripos($serverVersion, 'mariadb') !== false;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string) ($row['TABLE_NAME'] ?? '');
            $suffix = $nameToSuffix[$table] ?? null;
            if (!is_string($suffix)) {
                return false;
            }
            $dataType = strtolower((string) ($row['DATA_TYPE'] ?? ''));
            $extra = strtolower((string) ($row['EXTRA'] ?? ''));
            $default = $this->defaultNormalizer->normalizeMetadata(
                isset($row['COLUMN_DEFAULT'])
                    ? (string) $row['COLUMN_DEFAULT']
                    : null,
                $dataType,
                $extra,
                $isMariaDb
            );
            $position = count($actual[$suffix]);
            $contract = BlogInitialSchemaContract::mysqlColumns()[$suffix][
                $position
            ] ?? null;
            if (
                !is_array($contract)
                || !$this->mysqlExtraIsExact(
                    $extra,
                    (string) ($contract['extra'] ?? ''),
                    isset($contract['default'])
                        ? (string) $contract['default'] : null
                )
            ) {
                return false;
            }
            if ($default !== null && str_starts_with($default, "'")) {
                // Preserve literal case; only SQL expressions are canonicalized.
            } elseif ($default !== null) {
                $default = strtolower($default);
            }
            $actual[$suffix][] = [
                'name' => strtolower((string) ($row['COLUMN_NAME'] ?? '')),
                'type' => $dataType,
                'nullable' => strtoupper((string) ($row['IS_NULLABLE'] ?? ''))
                    === 'YES',
                'unsigned' => str_contains(
                    strtolower((string) ($row['COLUMN_TYPE'] ?? '')),
                    'unsigned'
                ),
                'length' => $row['CHARACTER_MAXIMUM_LENGTH'] === null
                    ? null
                    : (int) $row['CHARACTER_MAXIMUM_LENGTH'],
                'datetime_precision' => $row['DATETIME_PRECISION'] === null
                    ? null
                    : (int) $row['DATETIME_PRECISION'],
                'charset' => $row['CHARACTER_SET_NAME'] === null
                    ? null
                    : strtolower((string) $row['CHARACTER_SET_NAME']),
                'collation' => $row['COLLATION_NAME'] === null
                    ? null
                    : strtolower((string) $row['COLLATION_NAME']),
                'default' => $default,
                'extra' => str_contains($extra, 'auto_increment')
                    ? 'auto_increment'
                    : '',
            ];
        }

        return $actual === BlogInitialSchemaContract::mysqlColumns();
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
        if (strtolower((string) $default) === 'current_timestamp(6)') {
            $allowed[] = 'default_generated';
        }
        foreach ($tokens as $token) {
            if (!in_array($token, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    private function mysqlIndexesAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $serverVersion
    ): bool {
        $tables = $this->tableNames($scope);
        [$in, $params] = $this->inClause($tables, 'index_table');
        $ignoredExpression = MySqlServerCapabilities::isMariaDb($serverVersion)
            ? (MySqlServerCapabilities::supportsIgnoredIndexes($serverVersion)
                ? 'IGNORED'
                : "'NO' AS IGNORED")
            : "CASE WHEN IS_VISIBLE = 'YES' THEN 'NO' "
                . "ELSE 'YES' END AS IGNORED";
        $statement = $pdo->prepare(
            'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, '
            . 'COLUMN_NAME, INDEX_TYPE, SUB_PART, COLLATION, '
            . $ignoredExpression . ' '
            . 'FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $in . ') '
            . 'ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX'
        );
        $statement->execute($params);
        $nameToSuffix = array_flip($tables);
        $primary = array_fill_keys(
            BlogInitialSchemaContract::tableSuffixes(),
            []
        );
        $grouped = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $suffix = $nameToSuffix[(string) $row['TABLE_NAME']] ?? null;
            if (!is_string($suffix)) {
                return false;
            }
            $indexName = (string) ($row['INDEX_NAME'] ?? '');
            $column = (string) ($row['COLUMN_NAME'] ?? '');
            $sequence = (int) ($row['SEQ_IN_INDEX'] ?? 0);
            $nonUnique = $row['NON_UNIQUE'] ?? null;
            if (
                $indexName === ''
                || $column === ''
                || $sequence < 1
                || !in_array($nonUnique, [0, '0', 1, '1'], true)
                || strtoupper((string) ($row['INDEX_TYPE'] ?? '')) !== 'BTREE'
                || ($row['SUB_PART'] ?? null) !== null
                || strtoupper((string) ($row['COLLATION'] ?? '')) !== 'A'
                || strtoupper((string) ($row['IGNORED'] ?? 'YES')) !== 'NO'
            ) {
                return false;
            }
            if (strtoupper($indexName) === 'PRIMARY') {
                if (
                    (int) $nonUnique !== 0
                    || isset($primary[$suffix][$sequence])
                ) {
                    return false;
                }
                $primary[$suffix][$sequence] = strtolower($column);
                continue;
            }
            $key = $suffix . "\0" . $indexName;
            if (
                isset($grouped[$key]['columns'][$sequence])
                || (isset($grouped[$key]['unique'])
                    && $grouped[$key]['unique'] !== ((int) $nonUnique === 0))
            ) {
                return false;
            }
            $grouped[$key]['suffix'] = $suffix;
            $grouped[$key]['unique'] = (int) $nonUnique === 0;
            $grouped[$key]['columns'][$sequence] = strtolower($column);
        }
        foreach ($primary as &$columns) {
            ksort($columns, SORT_NUMERIC);
            if (array_keys($columns) !== range(1, count($columns))) {
                return false;
            }
            $columns = array_values($columns);
        }
        unset($columns);
        if ($primary !== [
            'posts' => ['id'],
            'post_localizations' => ['id'],
        ]) {
            return false;
        }

        $actual = array_fill_keys(
            BlogInitialSchemaContract::tableSuffixes(),
            []
        );
        foreach ($grouped as $index) {
            ksort($index['columns'], SORT_NUMERIC);
            if (
                array_keys($index['columns'])
                    !== range(1, count($index['columns']))
            ) {
                return false;
            }
            $actual[$index['suffix']][] = [
                'unique' => $index['unique'],
                'columns' => array_values($index['columns']),
            ];
        }
        foreach ($actual as &$contracts) {
            $this->sortIndexContracts($contracts);
        }
        unset($contracts);
        $expected = BlogInitialSchemaContract::indexes();
        foreach ($expected as &$contracts) {
            $this->sortIndexContracts($contracts);
        }
        unset($contracts);

        return $actual === $expected;
    }

    private function mysqlForeignKeysAreExact(
        PDO $pdo,
        MigrationScope $scope
    ): bool {
        $tables = $this->tableNames($scope);
        [$in, $params] = $this->inClause($tables, 'fk_table');
        $statement = $pdo->prepare(
            'SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, '
            . 'k.REFERENCED_COLUMN_NAME, k.ORDINAL_POSITION, '
            . 'k.REFERENCED_TABLE_SCHEMA = DATABASE() AS SAME_SCHEMA, '
            . 'r.UPDATE_RULE, r.DELETE_RULE '
            . 'FROM information_schema.KEY_COLUMN_USAGE AS k '
            . 'JOIN information_schema.REFERENTIAL_CONSTRAINTS AS r '
            . 'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA '
            . 'AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
            . 'AND r.TABLE_NAME = k.TABLE_NAME '
            . 'WHERE k.CONSTRAINT_SCHEMA = DATABASE() '
            . 'AND k.TABLE_NAME IN (' . $in . ') '
            . 'AND k.REFERENCED_TABLE_NAME IS NOT NULL '
            . 'ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION'
        );
        $statement->execute($params);
        $nameToSuffix = array_flip($tables);
        $actual = array_fill_keys(
            BlogInitialSchemaContract::tableSuffixes(),
            []
        );
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (
                (int) ($row['ORDINAL_POSITION'] ?? 0) !== 1
                || !in_array(
                    $row['SAME_SCHEMA'] ?? null,
                    [1, '1', true],
                    true
                )
            ) {
                return false;
            }
            $suffix = $nameToSuffix[(string) $row['TABLE_NAME']] ?? null;
            $target = $nameToSuffix[
                (string) $row['REFERENCED_TABLE_NAME']
            ] ?? null;
            if (!is_string($suffix) || !is_string($target)) {
                return false;
            }
            $updateRule = strtoupper((string) $row['UPDATE_RULE']);
            if ($updateRule === 'RESTRICT') {
                // MySQL reports omitted ON UPDATE as RESTRICT; SQLite uses
                // the SQL-standard equivalent NO ACTION.
                $updateRule = 'NO ACTION';
            }
            $actual[$suffix][] = [
                'from' => strtolower((string) $row['COLUMN_NAME']),
                'target_suffix' => $target,
                'target_column' => strtolower(
                    (string) $row['REFERENCED_COLUMN_NAME']
                ),
                'on_update' => $updateRule,
                'on_delete' => strtoupper((string) $row['DELETE_RULE']),
            ];
        }

        return $actual === BlogInitialSchemaContract::foreignKeys();
    }

    private function mysqlChecksAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $serverVersion
    ): bool {
        $tables = $this->tableNames($scope);
        [$in, $params] = $this->inClause($tables, 'check_table');
        $isMariaDb = MySqlServerCapabilities::isMariaDb($serverVersion);
        $statement = $pdo->prepare(
            'SELECT tc.TABLE_NAME, tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE, '
            . ($isMariaDb ? "'YES'" : 'tc.ENFORCED')
            . ' AS ENFORCED '
            . 'FROM information_schema.TABLE_CONSTRAINTS AS tc '
            . 'JOIN information_schema.CHECK_CONSTRAINTS AS cc '
            . 'ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA '
            . 'AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
            . ($isMariaDb ? 'AND cc.TABLE_NAME = tc.TABLE_NAME ' : '')
            . "WHERE tc.CONSTRAINT_SCHEMA = DATABASE() "
            . "AND tc.CONSTRAINT_TYPE = 'CHECK' "
            . 'AND tc.TABLE_NAME IN (' . $in . ')'
        );
        $statement->execute($params);
        $nameToSuffix = array_flip($tables);
        $actual = array_fill_keys(
            BlogInitialSchemaContract::tableSuffixes(),
            []
        );
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (strtoupper((string) ($row['ENFORCED'] ?? '')) !== 'YES') {
                return false;
            }
            $suffix = $nameToSuffix[(string) $row['TABLE_NAME']] ?? null;
            if (!is_string($suffix)) {
                return false;
            }
            $actual[$suffix][strtolower((string) $row['CONSTRAINT_NAME'])] =
                $this->canonicalCheckExpression(
                    (string) $row['CHECK_CLAUSE']
                );
        }
        foreach ($actual as &$checks) {
            ksort($checks, SORT_STRING);
        }
        unset($checks);

        $expected = [];
        foreach (BlogInitialSchemaContract::mysqlChecks() as $suffix => $checks) {
            foreach ($checks as $constraintSuffix => $expression) {
                $expected[$suffix][strtolower(
                    $scope->tableName($constraintSuffix)
                )] = $this->canonicalCheckExpression($expression);
            }
            ksort($expected[$suffix], SORT_STRING);
        }

        return $actual === $expected;
    }

    private function mysqlHasNoTriggers(PDO $pdo, MigrationScope $scope): bool
    {
        $tables = $this->tableNames($scope);
        [$in, $params] = $this->inClause($tables, 'trigger_table');
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS '
            . 'WHERE TRIGGER_SCHEMA = DATABASE() '
            . 'AND EVENT_OBJECT_TABLE IN (' . $in . ')'
        );
        $statement->execute($params);
        $count = $statement->fetchColumn();

        return $count === 0 || $count === '0';
    }

    private function mysqlIntegrityIsValid(
        PDO $pdo,
        MigrationScope $scope
    ): bool {
        $posts = $scope->quotedTable('posts', 'mysql');
        $localizations = $scope->quotedTable(
            'post_localizations',
            'mysql'
        );
        $postViolations = (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $posts . ' WHERE '
            . 'CHAR_LENGTH(`public_id`) <> 36 '
            . 'OR CHAR_LENGTH(`created_by_user_public_id`) <> 36'
        )->fetchColumn();
        $localizationViolations = (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $localizations . ' WHERE '
            . 'CHAR_LENGTH(`public_id`) <> 36 '
            . 'OR CHAR_LENGTH(`locale`) NOT BETWEEN 2 AND 16 '
            . 'OR `locale` <> LOWER(`locale`) '
            . 'OR `locale` <> TRIM(`locale`) '
            . 'OR (`slug` IS NOT NULL AND ('
            . 'CHAR_LENGTH(TRIM(`slug`)) = 0 OR `slug` <> LOWER(`slug`) '
            . 'OR `slug` <> TRIM(`slug`))) '
            . 'OR CHAR_LENGTH(TRIM(`h1`)) = 0 '
            . "OR `status` NOT IN ('draft', 'published') "
            . "OR (`status` = 'draft' AND `published_at` IS NOT NULL) "
            . "OR (`status` = 'published' AND `published_at` IS NULL) "
            . "OR (`status` = 'published' AND (`slug` IS NULL "
            . 'OR `seo_title` IS NULL '
            . 'OR CHAR_LENGTH(TRIM(`seo_title`)) = 0 '
            . 'OR `meta_description` IS NULL '
            . 'OR CHAR_LENGTH(TRIM(`meta_description`)) = 0 '
            . 'OR `excerpt` IS NULL '
            . 'OR CHAR_LENGTH(TRIM(`excerpt`)) = 0 '
            . 'OR CHAR_LENGTH(TRIM(`body_text`)) = 0)) '
            . 'OR `lock_version` <= 0 '
            . 'OR CHAR_LENGTH(`created_by_user_public_id`) <> 36 '
            . 'OR CHAR_LENGTH(`updated_by_user_public_id`) <> 36'
        )->fetchColumn();
        $orphans = (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $localizations . ' AS l '
            . 'LEFT JOIN ' . $posts . ' AS p ON p.`id` = l.`post_id` '
            . 'WHERE p.`id` IS NULL'
        )->fetchColumn();

        return $postViolations === 0
            && $localizationViolations === 0
            && $orphans === 0;
    }

    /** @return array<string, string> suffix => physical table */
    private function tableNames(MigrationScope $scope): array
    {
        $tables = [];
        foreach (BlogInitialSchemaContract::tableSuffixes() as $suffix) {
            $tables[$suffix] = $scope->tableName($suffix);
        }

        return $tables;
    }

    /** @param array<string, string> $values @return array{string, array<string, string>} */
    private function inClause(array $values, string $prefix): array
    {
        $parameters = [];
        $placeholders = [];
        foreach (array_values($values) as $position => $value) {
            $key = $prefix . '_' . $position;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $value;
        }

        return [implode(', ', $placeholders), $parameters];
    }

    /** @return list<string> */
    private function sqliteIndexSuffixes(): array
    {
        return [
            'ux_po_public',
            'ix_po_author',
            'ux_pl_public',
            'ux_pl_post_locale',
            'ux_pl_locale_slug',
            'ix_pl_state',
        ];
    }

    /** @return list<string> */
    private function categoryTableSuffixes(): array
    {
        return ['categories', 'category_locales', 'post_categories'];
    }

    /** @return list<string> */
    private function categoryIndexSuffixes(): array
    {
        return [
            'ux_ca_public',
            'ix_ca_author',
            'ux_cl_public',
            'ux_cl_cat_locale',
            'ux_cl_locale_slug',
            'ix_cl_name',
            'ux_pc_public',
            'ux_pc_pair',
            'ix_pc_category',
        ];
    }

    /** @return list<string> */
    private function structuredContentTableSuffixes(): array
    {
        return [
            'content_docs',
            'content_revisions',
            'content_media',
            'revision_media',
        ];
    }

    /** @return list<string> */
    private function structuredContentIndexSuffixes(): array
    {
        return [
            'ux_cd_public',
            'ux_cd_local',
            'ix_cd_updated',
            'ux_cr_public',
            'ux_cr_loc_rev',
            'ux_cr_loc_variant',
            'ix_cr_time',
            'ix_cm_asset',
            'ix_rm_asset',
        ];
    }

    /** @param list<array{unique: bool, columns: list<string>}> $contracts */
    private function sortIndexContracts(array &$contracts): void
    {
        usort(
            $contracts,
            static fn (array $left, array $right): int => strcmp(
                ($left['unique'] ? '1' : '0') . ':' . implode(',', $left['columns']),
                ($right['unique'] ? '1' : '0') . ':' . implode(',', $right['columns'])
            )
        );
    }

    private function normalizeSqliteDefault(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return SqlCheckExpressionCanonicalizer::compact((string) $value);
    }

    private function canonicalExpression(string $expression): string
    {
        $expression = strtolower(trim($expression));
        $expression = (string) preg_replace('/_[a-z0-9]+(?=\')/i', '', $expression);
        $expression = str_replace(['`', '"', '[', ']'], '', $expression);
        $expression = str_replace('lcase(', 'lower(', $expression);
        $expression = (string) preg_replace('/\s+/', '', $expression);
        while (
            strlen($expression) >= 2
            && $expression[0] === '('
            && str_ends_with($expression, ')')
            && $this->outerParenthesesWrapWholeExpression($expression)
        ) {
            $expression = substr($expression, 1, -1);
        }

        return $expression;
    }

    private function canonicalCheckExpression(string $expression): string
    {
        return SqlCheckExpressionCanonicalizer::canonicalize($expression);
    }

    /** @return list<string> */
    private function extractCheckExpressions(string $sql): array
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
                || ($index > 0 && preg_match('/[a-z0-9_]/i', $sql[$index - 1]) === 1)
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
                return [];
            }
            $expressions[] = substr($sql, $start, $cursor - $start - 1);
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

    private function outerParenthesesWrapWholeExpression(string $value): bool
    {
        $depth = 0;
        $quote = null;
        $length = strlen($value);
        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];
            if ($quote !== null) {
                if ($character === $quote) {
                    if (($value[$index + 1] ?? '') === $quote) {
                        $index++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($character === "'") {
                $quote = $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
                if ($depth === 0 && $index !== $length - 1) {
                    return false;
                }
            }
        }

        return $depth === 0 && $quote === null;
    }
}
