<?php

declare(strict_types=1);

namespace App\Core\Modules\WebAdmin;

use App\Core\Database\MySqlColumnDefaultNormalizer;
use App\Core\Database\MySqlServerCapabilities;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/**
 * Read-only verifier for WebAdmin migration 0001.
 *
 * Compatibility contract: MySQL >= 8.0.16 and MariaDB patch lines with
 * reliable CHECK_CLAUSE metadata must prove every invariant. If a server
 * cannot prove schema, session and stored-row integrity, verification fails
 * closed instead of guessing.
 */
final class WebAdminMigrationPostconditionVerifier implements
    MigrationPostconditionVerifierInterface
{
    public function __construct(
        private readonly WebAdminCanonicalSeedVerifier $seedVerifier =
            new WebAdminCanonicalSeedVerifier(),
        private readonly MySqlColumnDefaultNormalizer $defaultNormalizer =
            new MySqlColumnDefaultNormalizer()
    ) {
    }

    public function contractVersion(): string
    {
        return 'webadmin-initial-schema-v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        return $this->issueCodes($pdo, $scope) === [];
    }

    /** @return list<string> Category-only diagnostics; never returns DB data. */
    public function issueCodes(PDO $pdo, MigrationScope $scope): array
    {
        try {
            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            $metadata = $driver === 'sqlite'
                ? $this->collectSqlite($pdo, $scope)
                : $this->collectMySql($pdo, $scope);

            return $this->metadataIssueCodes($driver, $metadata);
        } catch (Throwable) {
            return ['webadmin.schema_metadata_unavailable'];
        }
    }

    /**
     * Pure validation entry point used by metadata fixtures. It performs no
     * database access and deliberately returns only a generic boolean.
     *
     * @param array<string, mixed> $metadata
     */
    public function validateMetadata(string $driver, array $metadata): bool
    {
        return $this->metadataIssueCodes($driver, $metadata) === [];
    }

    /** @param array<string, mixed> $metadata @return list<string> */
    private function metadataIssueCodes(string $driver, array $metadata): array
    {
        try {
            if (!in_array($driver, ['mysql', 'sqlite'], true)) {
                return ['webadmin.schema_driver_unsupported'];
            }
            $checks = [
                'webadmin.schema_tables_invalid' =>
                    $this->validateTables($driver, $metadata),
                'webadmin.schema_columns_invalid' =>
                    $this->validateColumns($driver, $metadata),
                'webadmin.schema_primary_keys_invalid' =>
                    $this->validatePrimaryKeys($metadata),
                'webadmin.schema_indexes_invalid' =>
                    $this->validateIndexes($driver, $metadata),
                'webadmin.schema_foreign_keys_invalid' =>
                    $this->validateForeignKeys($driver, $metadata),
                'webadmin.schema_checks_invalid' =>
                    $this->validateChecks($driver, $metadata),
                'webadmin.schema_triggers_invalid' =>
                    $this->validateNoTriggers($metadata),
                'webadmin.schema_data_integrity_invalid' =>
                    ($metadata['data_integrity'] ?? null) === true,
                'webadmin.schema_seeds_invalid' =>
                    is_array($metadata['seeds'] ?? null)
                    && $this->seedVerifier->validateMetadata(
                        $metadata['seeds']
                    ),
            ];

            return array_keys(array_filter(
                $checks,
                static fn (bool $valid): bool => !$valid
            ));
        } catch (Throwable) {
            return ['webadmin.schema_metadata_invalid'];
        }
    }

    /** @return array<string, mixed> */
    private function collectSqlite(PDO $pdo, MigrationScope $scope): array
    {
        $metadata = $this->emptyMetadata();
        $metadata['checks_enforced'] = (string) $pdo->query(
            'PRAGMA ignore_check_constraints'
        )->fetchColumn() === '0';
        $metadata['foreign_keys_enforced'] = (string) $pdo->query(
            'PRAGMA foreign_keys'
        )->fetchColumn() === '1';
        $tableNames = $this->tableNames($scope);

        $object = $pdo->prepare(
            'SELECT type, name, sql FROM sqlite_master WHERE name = :name'
        );
        foreach ($tableNames as $suffix => $tableName) {
            $object->execute(['name' => $tableName]);
            $row = $object->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                continue;
            }
            $row = array_change_key_case($row, CASE_LOWER);
            $metadata['tables'][$suffix] = [
                'kind' => strtolower((string) ($row['type'] ?? '')),
                'engine' => null,
                'collation' => null,
                'sql' => (string) ($row['sql'] ?? ''),
            ];
            if (($row['type'] ?? null) !== 'table') {
                continue;
            }

            $quoted = $scope->quotedTable($suffix, 'sqlite');
            $columns = $pdo->query('PRAGMA table_info(' . $quoted . ')')
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columns as $column) {
                $column = array_change_key_case($column, CASE_LOWER);
                $metadata['columns'][$suffix][] = [
                    'name' => (string) ($column['name'] ?? ''),
                    'type' => strtoupper(trim((string) ($column['type'] ?? ''))),
                    'nullable' => (int) ($column['notnull'] ?? 0) === 0,
                    'primary_position' => (int) ($column['pk'] ?? 0),
                    'default' => $this->normalizeDefault($column['dflt_value'] ?? null),
                ];
            }

            $indexRows = $pdo->query('PRAGMA index_list(' . $quoted . ')')
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($indexRows as $indexRow) {
                $indexRow = array_change_key_case($indexRow, CASE_LOWER);
                $indexName = (string) ($indexRow['name'] ?? '');
                if ($indexName === '') {
                    continue;
                }
                $indexColumns = $pdo->query(
                    'PRAGMA index_xinfo(' . $this->quoteSqliteIdentifier($indexName) . ')'
                )->fetchAll(PDO::FETCH_ASSOC);
                $indexColumns = array_values(array_filter(
                    $indexColumns,
                    static fn (array $entry): bool =>
                        (int) ($entry['key'] ?? 1) === 1
                ));
                usort(
                    $indexColumns,
                    static fn (array $a, array $b): int =>
                        (int) ($a['seqno'] ?? 0) <=> (int) ($b['seqno'] ?? 0)
                );
                $metadata['indexes'][$suffix][$indexName] = [
                    'unique' => (int) ($indexRow['unique'] ?? 0) === 1,
                    'origin' => strtolower((string) ($indexRow['origin'] ?? '')),
                    'columns' => array_values(array_map(
                        static fn (array $entry): string =>
                            (string) ($entry['name'] ?? ''),
                        $indexColumns
                    )),
                    'directions' => array_values(array_map(
                        static fn (array $entry): string =>
                            (int) ($entry['desc'] ?? 0) === 1 ? 'D' : 'A',
                        $indexColumns
                    )),
                    'collations' => array_values(array_map(
                        static fn (array $entry): string => strtoupper(
                            (string) ($entry['coll'] ?? '')
                        ),
                        $indexColumns
                    )),
                    'partial' => (int) ($indexRow['partial'] ?? 0) === 1,
                ];
            }

            $foreignKeys = $pdo->query(
                'PRAGMA foreign_key_list(' . $quoted . ')'
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($foreignKeys as $foreignKey) {
                $foreignKey = array_change_key_case($foreignKey, CASE_LOWER);
                $targetSuffix = array_search(
                    (string) ($foreignKey['table'] ?? ''),
                    $tableNames,
                    true
                );
                $metadata['foreign_keys'][$suffix][] = [
                    'name' => null,
                    'from' => (string) ($foreignKey['from'] ?? ''),
                    'target_suffix' => is_string($targetSuffix) ? $targetSuffix : '',
                    'target_schema_local' => true,
                    'target_column' => (string) ($foreignKey['to'] ?? ''),
                    'on_update' => strtoupper((string) ($foreignKey['on_update'] ?? '')),
                    'on_delete' => strtoupper((string) ($foreignKey['on_delete'] ?? '')),
                    'match' => strtoupper((string) ($foreignKey['match'] ?? '')),
                ];
            }
        }

        [$tableIn, $tableBindings] = $this->inClause(
            array_values($tableNames),
            'trigger_table'
        );
        $triggers = $pdo->prepare(
            'SELECT tbl_name, name FROM sqlite_master '
            . "WHERE type = 'trigger' AND tbl_name IN (" . $tableIn . ')'
        );
        $triggers->execute($tableBindings);
        foreach ($triggers->fetchAll(PDO::FETCH_ASSOC) as $trigger) {
            $trigger = array_change_key_case($trigger, CASE_LOWER);
            $suffix = array_search(
                (string) ($trigger['tbl_name'] ?? ''),
                $tableNames,
                true
            );
            if (is_string($suffix)) {
                $metadata['triggers'][$suffix][] =
                    (string) ($trigger['name'] ?? '');
            }
        }

        $metadata['seeds'] = $this->seedVerifier->collectMetadata(
            $pdo,
            $scope,
            'sqlite'
        );
        $metadata['data_integrity'] = $this->databaseIntegritySatisfied(
            $pdo,
            $scope,
            'sqlite'
        );

        return $metadata;
    }

    /** @return array<string, mixed> */
    private function collectMySql(PDO $pdo, MigrationScope $scope): array
    {
        $metadata = $this->emptyMetadata();
        $metadata['server_version'] = (string) $pdo->query(
            'SELECT VERSION()'
        )->fetchColumn();
        $isMariaDb = MySqlServerCapabilities::isMariaDb(
            (string) $metadata['server_version']
        );
        $metadata['check_runtime_enabled'] = $isMariaDb
            ? in_array(
                strtoupper((string) $pdo->query(
                    'SELECT @@SESSION.check_constraint_checks'
                )->fetchColumn()),
                ['1', 'ON'],
                true
            )
            : true;
        $metadata['foreign_keys_enforced'] = in_array(
            (string) $pdo->query(
                'SELECT @@SESSION.foreign_key_checks'
            )->fetchColumn(),
            ['1', 'ON'],
            true
        );
        $metadata['unique_checks_enforced'] = in_array(
            (string) $pdo->query(
                'SELECT @@SESSION.unique_checks'
            )->fetchColumn(),
            ['1', 'ON'],
            true
        );
        $sqlModes = array_map(
            'strtoupper',
            array_filter(array_map(
                'trim',
                explode(',', (string) $pdo->query(
                    'SELECT @@SESSION.sql_mode'
                )->fetchColumn())
            ))
        );
        $metadata['strict_sql_mode'] = in_array(
            'STRICT_TRANS_TABLES',
            $sqlModes,
            true
        ) || in_array('STRICT_ALL_TABLES', $sqlModes, true);
        $tableNames = $this->tableNames($scope);
        [$inSql, $bindings] = $this->inClause(
            array_values($tableNames),
            'table'
        );
        $mariaDbSupportsIgnoredIndexes =
            MySqlServerCapabilities::supportsIgnoredIndexes(
                (string) $metadata['server_version']
            );
        $ignoredIndexExpression = $isMariaDb
            ? ($mariaDbSupportsIgnoredIndexes
                ? 'IGNORED'
                : "'NO' AS IGNORED")
            : "CASE WHEN IS_VISIBLE = 'YES' THEN 'NO' ELSE 'YES' END AS IGNORED";

        $statement = $pdo->prepare(
            'SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION '
            . 'FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $inSql . ')'
        );
        $statement->execute($bindings);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $suffix = array_search(
                (string) ($row['table_name'] ?? ''),
                $tableNames,
                true
            );
            if (!is_string($suffix)) {
                continue;
            }
            $metadata['tables'][$suffix] = [
                'kind' => strtoupper((string) ($row['table_type'] ?? '')),
                'engine' => strtoupper((string) ($row['engine'] ?? '')),
                'collation' => strtolower((string) ($row['table_collation'] ?? '')),
                'sql' => null,
            ];
        }

        $statement = $pdo->prepare(
            'SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, DATA_TYPE, '
            . 'COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, '
            . 'CHARACTER_MAXIMUM_LENGTH, DATETIME_PRECISION, '
            . 'CHARACTER_SET_NAME, COLLATION_NAME, EXTRA '
            . 'FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $inSql . ') '
            . 'ORDER BY TABLE_NAME, ORDINAL_POSITION'
        );
        $statement->execute($bindings);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $suffix = array_search(
                (string) ($row['table_name'] ?? ''),
                $tableNames,
                true
            );
            if (!is_string($suffix)) {
                continue;
            }
            $columnType = strtolower((string) ($row['column_type'] ?? ''));
            $extra = strtolower((string) ($row['extra'] ?? ''));
            $metadata['columns'][$suffix][] = [
                'name' => (string) ($row['column_name'] ?? ''),
                'type' => strtolower((string) ($row['data_type'] ?? '')),
                'nullable' => strtoupper((string) ($row['is_nullable'] ?? '')) === 'YES',
                'unsigned' => str_contains($columnType, 'unsigned'),
                'length' => $row['character_maximum_length'] === null
                    ? null
                    : (int) $row['character_maximum_length'],
                'datetime_precision' => $row['datetime_precision'] === null
                    ? null
                    : (int) $row['datetime_precision'],
                'charset' => $row['character_set_name'] === null
                    ? null
                    : strtolower((string) $row['character_set_name']),
                'collation' => $row['collation_name'] === null
                    ? null
                    : strtolower((string) $row['collation_name']),
                'default' => $this->normalizeMySqlMetadataDefault(
                    $row['column_default'] ?? null,
                    strtolower((string) ($row['data_type'] ?? '')),
                    $extra,
                    $isMariaDb
                ),
                'extra' => $extra,
            ];
        }

        $statement = $pdo->prepare(
            'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, '
            . 'COLUMN_NAME, SUB_PART, INDEX_TYPE, COLLATION, '
            . $ignoredIndexExpression . ' '
            . 'FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $inSql . ') '
            . 'ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX'
        );
        $statement->execute($bindings);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $suffix = array_search(
                (string) ($row['table_name'] ?? ''),
                $tableNames,
                true
            );
            $indexName = (string) ($row['index_name'] ?? '');
            if (!is_string($suffix) || $indexName === '') {
                continue;
            }
            $metadata['indexes'][$suffix][$indexName] ??= [
                'unique' => (int) ($row['non_unique'] ?? 1) === 0,
                'origin' => null,
                'columns' => [],
                'directions' => [],
                'collations' => [],
                'partial' => false,
                'type' => strtoupper((string) ($row['index_type'] ?? '')),
                'visible' => strtoupper((string) ($row['ignored'] ?? 'YES'))
                    === 'NO',
            ];
            $metadata['indexes'][$suffix][$indexName]['columns'][] =
                (string) ($row['column_name'] ?? '');
            $metadata['indexes'][$suffix][$indexName]['directions'][] =
                strtoupper((string) ($row['collation'] ?? ''));
            $metadata['indexes'][$suffix][$indexName]['collations'][] =
                null;
            if (($row['sub_part'] ?? null) !== null) {
                $metadata['indexes'][$suffix][$indexName]['partial'] = true;
            }
        }

        $statement = $pdo->prepare(
            'SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, '
            . 'k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, '
            . 'k.REFERENCED_COLUMN_NAME, DATABASE() AS CURRENT_SCHEMA, '
            . 'k.ORDINAL_POSITION, r.UPDATE_RULE, r.DELETE_RULE, '
            . 'r.MATCH_OPTION '
            . 'FROM information_schema.KEY_COLUMN_USAGE AS k '
            . 'JOIN information_schema.REFERENTIAL_CONSTRAINTS AS r '
            . 'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA '
            . 'AND r.TABLE_NAME = k.TABLE_NAME '
            . 'AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
            . 'WHERE k.CONSTRAINT_SCHEMA = DATABASE() '
            . 'AND k.TABLE_NAME IN (' . $inSql . ') '
            . 'AND k.REFERENCED_TABLE_NAME IS NOT NULL '
            . 'ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION'
        );
        $statement->execute($bindings);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $suffix = array_search(
                (string) ($row['table_name'] ?? ''),
                $tableNames,
                true
            );
            $targetSuffix = array_search(
                (string) ($row['referenced_table_name'] ?? ''),
                $tableNames,
                true
            );
            if (!is_string($suffix) || !is_string($targetSuffix)) {
                continue;
            }
            $metadata['foreign_keys'][$suffix][] = [
                'name' => (string) ($row['constraint_name'] ?? ''),
                'from' => (string) ($row['column_name'] ?? ''),
                'target_suffix' => $targetSuffix,
                'target_schema_local' => hash_equals(
                    (string) ($row['current_schema'] ?? ''),
                    (string) ($row['referenced_table_schema'] ?? '')
                ),
                'target_column' => (string) ($row['referenced_column_name'] ?? ''),
                'on_update' => strtoupper((string) ($row['update_rule'] ?? '')),
                'on_delete' => strtoupper((string) ($row['delete_rule'] ?? '')),
                'match' => strtoupper((string) ($row['match_option'] ?? '')),
            ];
        }

        // MySQL 8 and current MariaDB expose CHECK_CLAUSE here. Failing this
        // query means the server cannot prove the contract and must fail shut.
        $statement = $pdo->prepare(
            'SELECT tc.TABLE_NAME, tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE, '
            . ($isMariaDb ? "'YES'" : 'tc.ENFORCED') . ' AS ENFORCED '
            . 'FROM information_schema.TABLE_CONSTRAINTS AS tc '
            . 'JOIN information_schema.CHECK_CONSTRAINTS AS cc '
            . 'ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA '
            . 'AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
            . ($isMariaDb
                ? 'AND cc.TABLE_NAME = tc.TABLE_NAME '
                : '')
            . 'WHERE tc.CONSTRAINT_SCHEMA = DATABASE() '
            . "AND tc.CONSTRAINT_TYPE = 'CHECK' "
            . 'AND tc.TABLE_NAME IN (' . $inSql . ')'
        );
        $statement->execute($bindings);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $suffix = array_search(
                (string) ($row['table_name'] ?? ''),
                $tableNames,
                true
            );
            if (!is_string($suffix)) {
                continue;
            }
            $metadata['checks'][$suffix][(string) ($row['constraint_name'] ?? '')] =
                (string) ($row['check_clause'] ?? '');
            $metadata['check_enforcement'][$suffix][
                (string) ($row['constraint_name'] ?? '')
            ] = strtoupper((string) ($row['enforced'] ?? '')) === 'YES';
        }

        $statement = $pdo->prepare(
            'SELECT EVENT_OBJECT_TABLE, TRIGGER_NAME '
            . 'FROM information_schema.TRIGGERS '
            . 'WHERE TRIGGER_SCHEMA = DATABASE() '
            . 'AND EVENT_OBJECT_TABLE IN (' . $inSql . ')'
        );
        $statement->execute($bindings);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $suffix = array_search(
                (string) ($row['event_object_table'] ?? ''),
                $tableNames,
                true
            );
            if (is_string($suffix)) {
                $metadata['triggers'][$suffix][] =
                    (string) ($row['trigger_name'] ?? '');
            }
        }

        $metadata['seeds'] = $this->seedVerifier->collectMetadata(
            $pdo,
            $scope,
            'mysql'
        );
        $metadata['data_integrity'] = $this->databaseIntegritySatisfied(
            $pdo,
            $scope,
            'mysql'
        );

        return $metadata;
    }

    /** @param array<string, mixed> $metadata */
    private function validateTables(string $driver, array $metadata): bool
    {
        $tables = $metadata['tables'] ?? null;
        if (!is_array($tables)) {
            return false;
        }
        if (
            $driver === 'sqlite'
            && ($metadata['checks_enforced'] ?? null) !== true
        ) {
            return false;
        }
        if (
            $driver === 'sqlite'
            && ($metadata['foreign_keys_enforced'] ?? null) !== true
        ) {
            return false;
        }
        if (
            $driver === 'mysql'
            && !MySqlServerCapabilities::supportsReliableCheckMetadata(
                (string) ($metadata['server_version'] ?? '')
            )
        ) {
            return false;
        }
        if (
            $driver === 'mysql'
            && ($metadata['check_runtime_enabled'] ?? null) !== true
        ) {
            return false;
        }
        if (
            $driver === 'mysql'
            && ($metadata['foreign_keys_enforced'] ?? null) !== true
        ) {
            return false;
        }
        if (
            $driver === 'mysql'
            && ($metadata['unique_checks_enforced'] ?? null) !== true
        ) {
            return false;
        }
        if (
            $driver === 'mysql'
            && ($metadata['strict_sql_mode'] ?? null) !== true
        ) {
            return false;
        }
        foreach (WebAdminInitialSchemaContract::tableSuffixes() as $suffix) {
            $table = $tables[$suffix] ?? null;
            if (!is_array($table)) {
                return false;
            }
            if ($driver === 'sqlite') {
                if (
                    ($table['kind'] ?? null) !== 'table'
                    || !is_string($table['sql'] ?? null)
                    || trim((string) $table['sql']) === ''
                ) {
                    return false;
                }
                $options = $this->sqliteTableOptions(
                    (string) $table['sql']
                );
                if ($options !== ($suffix === 'state' ? 'WITHOUT ROWID' : '')) {
                    return false;
                }
            } elseif (
                ($table['kind'] ?? null) !== 'BASE TABLE'
                || strtoupper((string) ($table['engine'] ?? '')) !== 'INNODB'
                || strtolower((string) ($table['collation'] ?? ''))
                    !== 'utf8mb4_unicode_ci'
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $metadata */
    private function validateColumns(string $driver, array $metadata): bool
    {
        $actualTables = $metadata['columns'] ?? null;
        $expectedTables = $driver === 'sqlite'
            ? WebAdminInitialSchemaContract::sqliteColumns()
            : WebAdminInitialSchemaContract::mysqlColumns();
        if (!is_array($actualTables)) {
            return false;
        }
        foreach ($expectedTables as $suffix => $expectedColumns) {
            $actualColumns = $actualTables[$suffix] ?? null;
            if (!is_array($actualColumns)) {
                return false;
            }
            $actualByName = [];
            foreach ($actualColumns as $actualColumn) {
                if (!is_array($actualColumn)) {
                    return false;
                }
                $name = (string) ($actualColumn['name'] ?? '');
                if ($name === '' || isset($actualByName[$name])) {
                    return false;
                }
                $actualByName[$name] = $actualColumn;
            }
            foreach ($expectedColumns as $expected) {
                $actual = $actualByName[$expected['name']] ?? null;
                if (!is_array($actual)) {
                    return false;
                }
                if ($driver === 'sqlite') {
                    if (!$this->validateSqliteColumn($expected, $actual, $metadata, $suffix)) {
                        return false;
                    }
                } elseif (!$this->validateMySqlColumn($expected, $actual)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $expected @param array<string, mixed> $actual @param array<string, mixed> $metadata */
    private function validateSqliteColumn(
        array $expected,
        array $actual,
        array $metadata,
        string $suffix
    ): bool {
        if (
            ($actual['name'] ?? null) !== $expected['name']
            || strtoupper((string) ($actual['type'] ?? '')) !== $expected['type']
            || ($actual['nullable'] ?? null) !== $expected['nullable']
            || (int) ($actual['primary_position'] ?? -1)
                !== $expected['primary_position']
            || ($actual['default'] ?? null)
                !== $this->normalizeDefault($expected['default'] ?? null)
        ) {
            return false;
        }

        $definitions = $this->sqliteColumnDefinitions(
            (string) ($metadata['tables'][$suffix]['sql'] ?? '')
        );
        $name = strtolower((string) $expected['name']);
        $definitionSql = $definitions[$name] ?? null;
        if (!is_string($definitionSql)) {
            return false;
        }
        $definition = $this->normalizeSqlStructure($definitionSql);
        $typePrefix = $name . strtolower((string) $expected['type']);
        if (
            ($expected['autoincrement'] ?? false) === true
            && !str_starts_with(
                $definition,
                $name . 'integerprimarykeyautoincrement'
            )
        ) {
            return false;
        }
        if ($expected['type'] === 'TEXT') {
            $collations = $this->sqliteColumnCollations($definitionSql);
            $expectedCollations = ($expected['binary_text'] ?? false) === true
                ? ['BINARY']
                : [];
            if ($collations !== $expectedCollations) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $expected @param array<string, mixed> $actual */
    private function validateMySqlColumn(array $expected, array $actual): bool
    {
        if (
            ($actual['name'] ?? null) !== $expected['name']
            || strtolower((string) ($actual['type'] ?? '')) !== $expected['type']
            || ($actual['nullable'] ?? null) !== $expected['nullable']
            || (bool) ($actual['unsigned'] ?? false)
                !== (bool) ($expected['unsigned'] ?? false)
            || ($actual['default'] ?? null)
                !== $this->normalizeMySqlDefault(
                    $expected['default'] ?? null
                )
        ) {
            return false;
        }
        foreach (['length', 'datetime_precision', 'charset', 'collation'] as $key) {
            if (
                array_key_exists($key, $expected)
                && ($actual[$key] ?? null) !== $expected[$key]
            ) {
                return false;
            }
        }
        $extra = strtolower(trim((string) ($actual['extra'] ?? '')));
        $tokens = $extra === ''
            ? []
            : (preg_split('/\s+/', $extra) ?: []);
        $expectsAutoIncrement = ($expected['extra'] ?? null)
            === 'auto_increment';
        if (
            in_array('auto_increment', $tokens, true)
                !== $expectsAutoIncrement
        ) {
            return false;
        }
        $allowed = $expectsAutoIncrement ? ['auto_increment'] : [];
        if (
            ($expected['default'] ?? null) === 'current_timestamp(6)'
        ) {
            // MySQL may report this informational token for expression-like
            // defaults. It does not alter writes as ON UPDATE/generated do.
            $allowed[] = 'default_generated';
        }
        foreach ($tokens as $token) {
            if (!in_array($token, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $metadata */
    private function validatePrimaryKeys(array $metadata): bool
    {
        $columnsByTable = $metadata['columns'] ?? null;
        $indexesByTable = $metadata['indexes'] ?? null;
        if (!is_array($columnsByTable) || !is_array($indexesByTable)) {
            return false;
        }
        foreach (WebAdminInitialSchemaContract::primaryKeys() as $suffix => $expected) {
            $actual = [];
            foreach (($columnsByTable[$suffix] ?? []) as $column) {
                if ((int) ($column['primary_position'] ?? 0) > 0) {
                    $actual[(int) $column['primary_position']] = (string) $column['name'];
                }
            }
            if ($actual === [] && isset($indexesByTable[$suffix]['PRIMARY'])) {
                $actual = array_values(
                    $indexesByTable[$suffix]['PRIMARY']['columns'] ?? []
                );
            } else {
                ksort($actual, SORT_NUMERIC);
                $actual = array_values($actual);
            }
            if ($actual !== $expected) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $metadata */
    private function validateIndexes(
        string $driver,
        array $metadata
    ): bool {
        $actualTables = $metadata['indexes'] ?? null;
        if (!is_array($actualTables)) {
            return false;
        }
        foreach (WebAdminInitialSchemaContract::indexes() as $suffix => $expectedIndexes) {
            $actualIndexes = $actualTables[$suffix] ?? null;
            if (!is_array($actualIndexes)) {
                return false;
            }
            $matched = [];
            foreach ($expectedIndexes as $expected) {
                $actualName = $this->findSemanticIndexName(
                    $driver,
                    $actualIndexes,
                    $expected,
                    $matched
                );
                if ($actualName === null) {
                    return false;
                }
                $matched[$actualName] = true;
            }

            $canonicalColumns = array_column(
                ($driver === 'sqlite'
                    ? WebAdminInitialSchemaContract::sqliteColumns()
                    : WebAdminInitialSchemaContract::mysqlColumns())[$suffix],
                'name'
            );
            $primary = WebAdminInitialSchemaContract::primaryKeys()[$suffix];
            foreach ($actualIndexes as $name => $actual) {
                if (!is_array($actual)) {
                    return false;
                }
                $columns = array_values($actual['columns'] ?? []);
                if (
                    isset($matched[(string) $name])
                    || strtoupper((string) $name) === 'PRIMARY'
                    || (($actual['unique'] ?? null) === true
                        && $columns === $primary)
                ) {
                    continue;
                }
                if (
                    ($actual['unique'] ?? null) === true
                    && array_intersect($columns, $canonicalColumns) !== []
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<string, array<string, mixed>> $indexes
     * @param array{unique: bool, columns: list<string>} $expected
     * @param array<string, true> $alreadyMatched
     */
    private function findSemanticIndexName(
        string $driver,
        array $indexes,
        array $expected,
        array $alreadyMatched
    ): ?string {
        foreach ($indexes as $name => $index) {
            if (isset($alreadyMatched[(string) $name]) || !is_array($index)) {
                continue;
            }
            $columns = $expected['columns'];
            if (
                ($index['unique'] ?? null) !== $expected['unique']
                || array_values($index['columns'] ?? []) !== $columns
                || array_values($index['directions'] ?? [])
                    !== array_fill(0, count($columns), 'A')
                || ($index['partial'] ?? false) === true
            ) {
                continue;
            }
            if (
                $driver === 'sqlite'
                && array_values($index['collations'] ?? [])
                    !== array_fill(0, count($columns), 'BINARY')
            ) {
                continue;
            }
            if (
                $driver === 'mysql'
                && (
                    strtoupper((string) ($index['type'] ?? '')) !== 'BTREE'
                    || ($index['visible'] ?? null) !== true
                )
            ) {
                continue;
            }

            return (string) $name;
        }

        return null;
    }

    /** @param array<string, mixed> $metadata */
    private function validateForeignKeys(
        string $driver,
        array $metadata
    ): bool {
        $actualTables = $metadata['foreign_keys'] ?? null;
        if (!is_array($actualTables)) {
            return false;
        }
        foreach (WebAdminInitialSchemaContract::foreignKeys() as $suffix => $expectedKeys) {
            $actualKeys = $actualTables[$suffix] ?? null;
            if (
                !is_array($actualKeys)
                || count($actualKeys) !== count($expectedKeys)
            ) {
                return false;
            }
            $matched = [];
            foreach ($expectedKeys as $expected) {
                $found = false;
                foreach ($actualKeys as $position => $actual) {
                    if (isset($matched[$position]) || !is_array($actual)) {
                        continue;
                    }
                    $updateMatches = ($actual['on_update'] ?? null)
                        === $expected['on_update'];
                    if (
                        $driver === 'mysql'
                        && $expected['on_update'] === 'NO ACTION'
                        && ($actual['on_update'] ?? null) === 'RESTRICT'
                    ) {
                        $updateMatches = true;
                    }
                    if (
                        ($actual['target_schema_local'] ?? null) === true
                        && ($actual['from'] ?? null) === $expected['from']
                        && ($actual['target_suffix'] ?? null) === $expected['target_suffix']
                        && ($actual['target_column'] ?? null) === $expected['target_column']
                        && $updateMatches
                        && ($actual['on_delete'] ?? null) === $expected['on_delete']
                        && ($actual['match'] ?? null) === $expected['match']
                    ) {
                        $found = true;
                        $matched[$position] = true;
                        break;
                    }
                }
                if (!$found) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $metadata */
    private function validateChecks(
        string $driver,
        array $metadata
    ): bool {
        $expectedTables = $driver === 'sqlite'
            ? WebAdminInitialSchemaContract::sqliteChecks()
            : WebAdminInitialSchemaContract::mysqlChecks();
        foreach ($expectedTables as $suffix => $expectedChecks) {
            if ($driver === 'sqlite') {
                $actualChecks = $this->extractSqliteCheckExpressions(
                    (string) ($metadata['tables'][$suffix]['sql'] ?? '')
                );
                if (count($actualChecks) !== count($expectedChecks)) {
                    return false;
                }
                $matched = [];
                foreach ($expectedChecks as $expression) {
                    $found = false;
                    foreach ($actualChecks as $position => $actual) {
                        if (
                            !isset($matched[$position])
                            && $this->expressionsEquivalent($actual, $expression)
                        ) {
                            $found = true;
                            $matched[$position] = true;
                            break;
                        }
                    }
                    if (!$found) {
                        return false;
                    }
                }
                continue;
            }

            $actualChecks = $metadata['checks'][$suffix] ?? null;
            if (
                !is_array($actualChecks)
                || count($actualChecks) !== count($expectedChecks)
            ) {
                return false;
            }
            $matched = [];
            foreach ($expectedChecks as $expression) {
                $found = false;
                foreach ($actualChecks as $name => $actual) {
                    if (
                        isset($matched[(string) $name])
                        || !is_string($actual)
                        || !$this->expressionsEquivalent($actual, $expression)
                        || ($metadata['check_enforcement'][$suffix][$name] ?? null)
                            !== true
                    ) {
                        continue;
                    }
                    $matched[(string) $name] = true;
                    $found = true;
                    break;
                }
                if (!$found) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $metadata */
    private function validateNoTriggers(array $metadata): bool
    {
        $triggers = $metadata['triggers'] ?? null;
        if (!is_array($triggers)) {
            return false;
        }
        foreach (WebAdminInitialSchemaContract::tableSuffixes() as $suffix) {
            if (($triggers[$suffix] ?? null) !== []) {
                return false;
            }
        }

        return true;
    }

    private function databaseIntegritySatisfied(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $checks = $driver === 'sqlite'
            ? WebAdminInitialSchemaContract::sqliteChecks()
            : WebAdminInitialSchemaContract::mysqlChecks();
        if ($driver === 'mysql') {
            $checks['sessions']['stored_session_identity'] =
                "(session_type='preauth' and user_id is null "
                . "and auth_version is null)or(session_type='authenticated' "
                . 'and user_id is not null and auth_version is not null '
                . 'and pending_action_token_id is null)';
        }
        foreach ($checks as $suffix => $expressions) {
            foreach ($expressions as $expression) {
                $invalid = $pdo->query(
                    'SELECT 1 FROM ' . $scope->quotedTable($suffix, $driver)
                    . ' WHERE (' . $expression . ') = 0 LIMIT 1'
                )->fetchColumn();
                if ($invalid !== false) {
                    return false;
                }
            }
        }

        if ($driver === 'sqlite') {
            foreach (WebAdminInitialSchemaContract::tableSuffixes() as $suffix) {
                $violation = $pdo->query(
                    'PRAGMA foreign_key_check('
                    . $scope->quotedTable($suffix, 'sqlite') . ')'
                )->fetch(PDO::FETCH_ASSOC);
                if (is_array($violation)) {
                    return false;
                }
            }
        } else {
            foreach (
                WebAdminInitialSchemaContract::foreignKeys()
                as $suffix => $foreignKeys
            ) {
                foreach ($foreignKeys as $foreignKey) {
                    $sourceColumn = $this->quoteMySqlIdentifier(
                        $foreignKey['from']
                    );
                    $targetColumn = $this->quoteMySqlIdentifier(
                        $foreignKey['target_column']
                    );
                    $violation = $pdo->query(
                        'SELECT 1 FROM '
                        . $scope->quotedTable($suffix, 'mysql') . ' AS s '
                        . 'LEFT JOIN ' . $scope->quotedTable(
                            $foreignKey['target_suffix'],
                            'mysql'
                        ) . ' AS t ON t.' . $targetColumn
                        . ' = s.' . $sourceColumn . ' WHERE s.' . $sourceColumn
                        . ' IS NOT NULL AND t.' . $targetColumn
                        . ' IS NULL LIMIT 1'
                    )->fetchColumn();
                    if ($violation !== false) {
                        return false;
                    }
                }
            }
        }

        foreach (WebAdminInitialSchemaContract::indexes() as $suffix => $indexes) {
            foreach ($indexes as $index) {
                if (!$index['unique']) {
                    continue;
                }
                $columns = array_map(
                    fn (string $column): string => $driver === 'mysql'
                        ? $this->quoteMySqlIdentifier($column)
                        : $this->quoteSqliteIdentifier($column),
                    $index['columns']
                );
                $notNull = array_map(
                    static fn (string $column): string =>
                        $column . ' IS NOT NULL',
                    $columns
                );
                $violation = $pdo->query(
                    'SELECT 1 FROM ' . $scope->quotedTable($suffix, $driver)
                    . ' WHERE ' . implode(' AND ', $notNull)
                    . ' GROUP BY ' . implode(', ', $columns)
                    . ' HAVING COUNT(*) > 1 LIMIT 1'
                )->fetchColumn();
                if ($violation !== false) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function emptyMetadata(): array
    {
        $metadata = [
            'tables' => [],
            'columns' => [],
            'indexes' => [],
            'foreign_keys' => [],
            'checks' => [],
            'check_enforcement' => [],
            'triggers' => [],
            'seeds' => [],
        ];
        foreach (WebAdminInitialSchemaContract::tableSuffixes() as $suffix) {
            $metadata['columns'][$suffix] = [];
            $metadata['indexes'][$suffix] = [];
            $metadata['foreign_keys'][$suffix] = [];
            $metadata['checks'][$suffix] = [];
            $metadata['check_enforcement'][$suffix] = [];
            $metadata['triggers'][$suffix] = [];
        }

        return $metadata;
    }

    /** @return array<string, string> */
    private function tableNames(MigrationScope $scope): array
    {
        $tables = [];
        foreach (WebAdminInitialSchemaContract::tableSuffixes() as $suffix) {
            $tables[$suffix] = $scope->tableName($suffix);
        }

        return $tables;
    }

    /** @param list<string> $values @return array{string, array<string, string>} */
    private function inClause(array $values, string $prefix): array
    {
        $placeholders = [];
        $bindings = [];
        foreach (array_values($values) as $index => $value) {
            $key = $prefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $value;
        }

        return [implode(', ', $placeholders), $bindings];
    }

    private function quoteSqliteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function quoteMySqlIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function normalizeDefault(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->normalizeSql((string) $value);
    }

    private function normalizeMySqlDefault(
        mixed $value,
        bool $unquotedValueIsLiteral = false
    ): ?string
    {
        return $this->defaultNormalizer->normalize(
            $this->normalizeDefault($value),
            $unquotedValueIsLiteral
        );
    }

    private function normalizeMySqlMetadataDefault(
        mixed $value,
        string $dataType,
        string $extra,
        bool $isMariaDb
    ): ?string {
        return $this->defaultNormalizer->normalizeMetadata(
            $this->normalizeDefault($value),
            $dataType,
            $extra,
            $isMariaDb
        );
    }

    private function normalizeSql(string $sql): string
    {
        $result = '';
        $length = strlen($sql);
        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];
            if ($character === "'") {
                $result .= $character;
                for ($index++; $index < $length; $index++) {
                    $result .= $sql[$index];
                    if ($sql[$index] !== "'") {
                        continue;
                    }
                    if ($index + 1 < $length && $sql[$index + 1] === "'") {
                        $result .= $sql[++$index];
                        continue;
                    }
                    break;
                }
                continue;
            }
            if ($character === '`' || $character === '"') {
                $quote = $character;
                for ($index++; $index < $length; $index++) {
                    if ($sql[$index] === $quote) {
                        if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                            $result .= strtolower($quote);
                            $index++;
                            continue;
                        }
                        break;
                    }
                    $result .= strtolower($sql[$index]);
                }
                continue;
            }
            if ($character === '[') {
                for ($index++; $index < $length; $index++) {
                    if ($sql[$index] === ']') {
                        if ($index + 1 < $length && $sql[$index + 1] === ']') {
                            $result .= ']';
                            $index++;
                            continue;
                        }
                        break;
                    }
                    $result .= strtolower($sql[$index]);
                }
                continue;
            }
            if (!ctype_space($character)) {
                $result .= strtolower($character);
            }
        }

        return $result;
    }

    /**
     * Normalizes structural SQL tokens while removing comments and literal
     * contents. This prevents a CHECK/default string from impersonating a
     * COLLATE, AUTOINCREMENT or WITHOUT ROWID clause.
     */
    private function normalizeSqlStructure(string $sql): string
    {
        $sql = $this->stripSqlComments($sql);
        $result = '';
        $length = strlen($sql);
        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];
            if ($character === "'") {
                $result .= "''";
                for ($index++; $index < $length; $index++) {
                    if ($sql[$index] !== "'") {
                        continue;
                    }
                    if ($index + 1 < $length && $sql[$index + 1] === "'") {
                        $index++;
                        continue;
                    }
                    break;
                }
                continue;
            }
            if ($character === '"' || $character === '`') {
                $quote = $character;
                for ($index++; $index < $length; $index++) {
                    if ($sql[$index] === $quote) {
                        if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                            $result .= $quote;
                            $index++;
                            continue;
                        }
                        break;
                    }
                    $result .= $sql[$index];
                }
                continue;
            }
            if ($character === '[') {
                for ($index++; $index < $length; $index++) {
                    if ($sql[$index] === ']') {
                        if ($index + 1 < $length && $sql[$index + 1] === ']') {
                            $result .= ']';
                            $index++;
                            continue;
                        }
                        break;
                    }
                    $result .= $sql[$index];
                }
                continue;
            }
            if (!ctype_space($character)) {
                $result .= strtolower($character);
            }
        }

        return strtolower($result);
    }

    /** @return list<string> */
    private function extractSqliteCheckExpressions(string $sql): array
    {
        $sql = $this->stripSqlComments($sql);
        $checks = [];
        $length = strlen($sql);
        $quote = null;
        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];
            if ($quote !== null) {
                if ($character === $quote) {
                    if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                        $index++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if (
                $character === "'"
                || $character === '"'
                || $character === '`'
                || $character === '['
            ) {
                $quote = $character === '[' ? ']' : $character;
                continue;
            }
            if (
                strncasecmp(substr($sql, $index, 5), 'check', 5) !== 0
                || ($index > 0 && $this->isSqlIdentifierCharacter($sql[$index - 1]))
                || ($index + 5 < $length
                    && $this->isSqlIdentifierCharacter($sql[$index + 5]))
            ) {
                continue;
            }
            $cursor = $index + 5;
            while ($cursor < $length && ctype_space($sql[$cursor])) {
                $cursor++;
            }
            if ($cursor >= $length || $sql[$cursor] !== '(') {
                continue;
            }
            $start = $cursor + 1;
            $depth = 1;
            $innerQuote = null;
            for ($cursor++; $cursor < $length; $cursor++) {
                $current = $sql[$cursor];
                if ($innerQuote !== null) {
                    if ($current === $innerQuote) {
                        if (
                            $cursor + 1 < $length
                            && $sql[$cursor + 1] === $innerQuote
                        ) {
                            $cursor++;
                        } else {
                            $innerQuote = null;
                        }
                    }
                    continue;
                }
                if (
                    $current === "'"
                    || $current === '"'
                    || $current === '`'
                    || $current === '['
                ) {
                    $innerQuote = $current === '[' ? ']' : $current;
                } elseif ($current === '(') {
                    $depth++;
                } elseif ($current === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $checks[] = substr($sql, $start, $cursor - $start);
                        $index = $cursor;
                        break;
                    }
                }
            }
        }

        return $checks;
    }

    private function stripSqlComments(string $sql): string
    {
        $result = '';
        $length = strlen($sql);
        $quote = null;
        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];
            if ($quote !== null) {
                $result .= $character;
                if ($character === $quote) {
                    if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                        $result .= $sql[++$index];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if (
                $character === "'"
                || $character === '"'
                || $character === '`'
                || $character === '['
            ) {
                $quote = $character === '[' ? ']' : $character;
                $result .= $character;
                continue;
            }
            if ($character === '-' && ($sql[$index + 1] ?? '') === '-') {
                $index += 2;
                while ($index < $length && !in_array($sql[$index], ["\r", "\n"], true)) {
                    $index++;
                }
                $result .= ' ';
                continue;
            }
            if ($character === '/' && ($sql[$index + 1] ?? '') === '*') {
                $index += 2;
                while (
                    $index + 1 < $length
                    && !($sql[$index] === '*' && $sql[$index + 1] === '/')
                ) {
                    $index++;
                }
                $index++;
                $result .= ' ';
                continue;
            }
            $result .= $character;
        }

        return $result;
    }

    private function isSqlIdentifierCharacter(string $character): bool
    {
        return ctype_alnum($character) || $character === '_';
    }

    /** @return array<string, string> */
    private function sqliteColumnDefinitions(string $sql): array
    {
        $sql = $this->stripSqlComments($sql);
        $open = $this->firstUnquotedCharacter($sql, '(');
        if ($open === null) {
            return [];
        }

        $segments = [];
        $segmentStart = $open + 1;
        $depth = 1;
        $quote = null;
        $length = strlen($sql);
        for ($index = $segmentStart; $index < $length; $index++) {
            $character = $sql[$index];
            if ($quote !== null) {
                if ($character === $quote) {
                    if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                        $index++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if (
                $character === "'"
                || $character === '"'
                || $character === '`'
                || $character === '['
            ) {
                $quote = $character === '[' ? ']' : $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
                if ($depth === 0) {
                    $segments[] = substr(
                        $sql,
                        $segmentStart,
                        $index - $segmentStart
                    );
                    break;
                }
            } elseif ($character === ',' && $depth === 1) {
                $segments[] = substr(
                    $sql,
                    $segmentStart,
                    $index - $segmentStart
                );
                $segmentStart = $index + 1;
            }
        }

        $definitions = [];
        foreach ($segments as $segment) {
            $identifier = $this->leadingSqliteIdentifier($segment);
            if ($identifier === null) {
                continue;
            }
            $definitions[$identifier] = trim($segment);
        }

        return $definitions;
    }

    /** @return list<string> */
    private function sqliteColumnCollations(string $definition): array
    {
        $tokens = [];
        $sql = $this->stripSqlComments($definition);
        $length = strlen($sql);
        for ($index = 0; $index < $length;) {
            $character = $sql[$index];
            if ($character === "'") {
                $this->skipQuotedSqlToken($sql, $index, "'");
                continue;
            }
            if ($character === '"' || $character === '`' || $character === '[') {
                $close = $character === '[' ? ']' : $character;
                $value = $this->readQuotedSqlToken($sql, $index, $close);
                $tokens[] = ['quoted' => true, 'value' => strtoupper($value)];
                continue;
            }
            if (preg_match('/[A-Za-z_]/', $character) === 1) {
                $start = $index++;
                while (
                    $index < $length
                    && preg_match('/[A-Za-z0-9_]/', $sql[$index]) === 1
                ) {
                    $index++;
                }
                $tokens[] = [
                    'quoted' => false,
                    'value' => strtoupper(substr($sql, $start, $index - $start)),
                ];
                continue;
            }
            $index++;
        }

        $collations = [];
        foreach ($tokens as $position => $token) {
            if ($token['quoted'] || $token['value'] !== 'COLLATE') {
                continue;
            }
            $next = $tokens[$position + 1] ?? null;
            if (!is_array($next) || ($next['value'] ?? '') === '') {
                return [];
            }
            $collations[] = (string) $next['value'];
        }

        return $collations;
    }

    private function skipQuotedSqlToken(
        string $sql,
        int &$index,
        string $close
    ): void {
        $length = strlen($sql);
        $index++;
        while ($index < $length) {
            if ($sql[$index] !== $close) {
                $index++;
                continue;
            }
            if ($index + 1 < $length && $sql[$index + 1] === $close) {
                $index += 2;
                continue;
            }
            $index++;
            return;
        }
    }

    private function readQuotedSqlToken(
        string $sql,
        int &$index,
        string $close
    ): string {
        $length = strlen($sql);
        $value = '';
        $index++;
        while ($index < $length) {
            if ($sql[$index] !== $close) {
                $value .= $sql[$index++];
                continue;
            }
            if ($index + 1 < $length && $sql[$index + 1] === $close) {
                $value .= $close;
                $index += 2;
                continue;
            }
            $index++;
            break;
        }

        return $value;
    }

    private function firstUnquotedCharacter(
        string $sql,
        string $needle
    ): ?int {
        $quote = null;
        $length = strlen($sql);
        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];
            if ($quote !== null) {
                if ($character === $quote) {
                    if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                        $index++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if (
                $character === "'"
                || $character === '"'
                || $character === '`'
                || $character === '['
            ) {
                $quote = $character === '[' ? ']' : $character;
            } elseif ($character === $needle) {
                return $index;
            }
        }

        return null;
    }

    private function sqliteTableOptions(string $sql): string
    {
        $sql = $this->stripSqlComments($sql);
        $open = $this->firstUnquotedCharacter($sql, '(');
        if ($open === null) {
            return '__INVALID__';
        }
        $depth = 1;
        $quote = null;
        $length = strlen($sql);
        for ($index = $open + 1; $index < $length; $index++) {
            $character = $sql[$index];
            if ($quote !== null) {
                if ($character === $quote) {
                    if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                        $index++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if (
                $character === "'"
                || $character === '"'
                || $character === '`'
                || $character === '['
            ) {
                $quote = $character === '[' ? ']' : $character;
                continue;
            }
            if ($character === '(') {
                $depth++;
                continue;
            }
            if ($character !== ')') {
                continue;
            }
            $depth--;
            if ($depth !== 0) {
                continue;
            }
            $tail = trim(substr($sql, $index + 1));
            $tail = rtrim($tail, "; \t\r\n");

            return strtoupper((string) preg_replace('/\s+/', ' ', $tail));
        }

        return '__INVALID__';
    }

    private function leadingSqliteIdentifier(string $definition): ?string
    {
        $definition = ltrim($definition);
        if ($definition === '') {
            return null;
        }
        $first = $definition[0];
        if ($first === '"' || $first === '`' || $first === '[') {
            $close = $first === '[' ? ']' : $first;
            $identifier = '';
            $length = strlen($definition);
            for ($index = 1; $index < $length; $index++) {
                if ($definition[$index] === $close) {
                    if (
                        $index + 1 < $length
                        && $definition[$index + 1] === $close
                    ) {
                        $identifier .= $close;
                        $index++;
                        continue;
                    }
                    break;
                }
                $identifier .= $definition[$index];
            }
        } elseif (
            preg_match('/\A([a-z_][a-z0-9_]*)/i', $definition, $match) === 1
        ) {
            $identifier = $match[1];
        } else {
            return null;
        }

        $identifier = strtolower($identifier);
        if (in_array(
            $identifier,
            ['check', 'constraint', 'foreign', 'primary', 'unique'],
            true
        )) {
            return null;
        }

        return $identifier;
    }

    private function expressionsEquivalent(string $actual, string $expected): bool
    {
        return $this->canonicalCheckExpression($actual)
            === $this->canonicalCheckExpression($expected);
    }

    private function canonicalCheckExpression(string $expression): string
    {
        $tokens = $this->checkExpressionTokens($expression);

        return $tokens === []
            ? ''
            : $this->canonicalBooleanTokens($tokens);
    }

    /** @return list<string> */
    private function checkExpressionTokens(string $expression): array
    {
        $tokens = [];
        $length = strlen($expression);
        for ($index = 0; $index < $length;) {
            $character = $expression[$index];
            if (ctype_space($character)) {
                $index++;
                continue;
            }
            if ($character === "'") {
                $start = $index++;
                while ($index < $length) {
                    if ($expression[$index] !== "'") {
                        $index++;
                        continue;
                    }
                    if (
                        $index + 1 < $length
                        && $expression[$index + 1] === "'"
                    ) {
                        $index += 2;
                        continue;
                    }
                    $index++;
                    break;
                }
                $tokens[] = substr($expression, $start, $index - $start);
                continue;
            }
            if ($character === '`' || $character === '"' || $character === '[') {
                $close = $character === '[' ? ']' : $character;
                $index++;
                $identifier = '';
                while ($index < $length) {
                    if ($expression[$index] !== $close) {
                        $identifier .= strtolower($expression[$index++]);
                        continue;
                    }
                    if (
                        $index + 1 < $length
                        && $expression[$index + 1] === $close
                    ) {
                        $identifier .= strtolower($close);
                        $index += 2;
                        continue;
                    }
                    $index++;
                    break;
                }
                $tokens[] = $identifier;
                continue;
            }
            if (
                preg_match('/[A-Za-z_]/', $character) === 1
            ) {
                $start = $index++;
                while (
                    $index < $length
                    && preg_match('/[A-Za-z0-9_]/', $expression[$index]) === 1
                ) {
                    $index++;
                }
                $identifier = strtolower(substr(
                    $expression,
                    $start,
                    $index - $start
                ));
                $lookahead = $index;
                while (
                    $lookahead < $length
                    && ctype_space($expression[$lookahead])
                ) {
                    $lookahead++;
                }
                if (
                    str_starts_with($identifier, '_')
                    && ($expression[$lookahead] ?? '') === "'"
                ) {
                    continue;
                }
                $tokens[] = $identifier === 'lcase' ? 'lower' : $identifier;
                continue;
            }
            $pair = substr($expression, $index, 2);
            if (in_array($pair, ['!=', '<=', '>=', '<>'], true)) {
                $tokens[] = $pair === '!=' ? '<>' : $pair;
                $index += 2;
                continue;
            }
            $tokens[] = strtolower($character);
            $index++;
        }

        return $tokens;
    }

    /** @param list<string> $tokens */
    private function canonicalBooleanTokens(array $tokens): string
    {
        while (
            count($tokens) >= 2
            && $tokens[0] === '('
            && $this->matchingClosingParenthesis($tokens, 0)
                === count($tokens) - 1
        ) {
            $tokens = array_slice($tokens, 1, -1);
        }

        foreach (['or', 'and'] as $operator) {
            $parts = $this->splitTopLevelBoolean($tokens, $operator);
            if (count($parts) > 1) {
                return $operator . '(' . implode(',', array_map(
                    fn (array $part): string =>
                        $this->canonicalBooleanTokens($part),
                    $parts
                )) . ')';
            }
        }

        return implode('', $tokens);
    }

    /**
     * @param list<string> $tokens
     * @return list<list<string>>
     */
    private function splitTopLevelBoolean(
        array $tokens,
        string $operator
    ): array {
        $parts = [];
        $start = 0;
        $depth = 0;
        foreach ($tokens as $position => $token) {
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;
            } elseif ($depth === 0 && $token === $operator) {
                $parts[] = array_slice($tokens, $start, $position - $start);
                $start = $position + 1;
            }
        }
        if ($parts === []) {
            return [$tokens];
        }
        $parts[] = array_slice($tokens, $start);

        return $parts;
    }

    /** @param list<string> $tokens */
    private function matchingClosingParenthesis(
        array $tokens,
        int $openingPosition
    ): ?int {
        $depth = 0;
        for (
            $position = $openingPosition;
            $position < count($tokens);
            $position++
        ) {
            if ($tokens[$position] === '(') {
                $depth++;
            } elseif ($tokens[$position] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $position;
                }
            }
        }

        return null;
    }

    private function stripOuterParentheses(string $expression): string
    {
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

    private function outerParenthesesWrapWholeExpression(string $expression): bool
    {
        $depth = 0;
        $quote = null;
        $length = strlen($expression);
        for ($index = 0; $index < $length; $index++) {
            $character = $expression[$index];
            if ($quote !== null) {
                if ($character === $quote) {
                    if ($index + 1 < $length && $expression[$index + 1] === $quote) {
                        $index++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($character === "'" || $character === '"') {
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
