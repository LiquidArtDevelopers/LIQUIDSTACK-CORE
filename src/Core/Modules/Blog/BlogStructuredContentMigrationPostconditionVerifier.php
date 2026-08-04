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
use JsonException;
use PDO;
use Throwable;

/** Exact, read-only composite postcondition for Blog migration 0005. */
final class BlogStructuredContentMigrationPostconditionVerifier implements
    MigrationPostconditionVerifierInterface
{
    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'content_docs' => [
            'id', 'public_id', 'localization_id', 'schema_version',
            'template_key', 'document_json', 'document_bytes',
            'document_sha256', 'body_text_sha256', 'snapshot_sha256',
            'created_by_user_public_id', 'updated_by_user_public_id',
            'created_at', 'updated_at',
        ],
        'content_revisions' => [
            'id', 'public_id', 'localization_id', 'revision_number',
            'variant_lock_version', 'schema_version', 'template_key',
            'document_json', 'document_bytes', 'document_sha256',
            'body_text_sha256', 'snapshot_sha256', 'h1', 'slug',
            'seo_title', 'meta_description', 'excerpt', 'body_text',
            'created_by_user_public_id', 'created_at',
        ],
        'content_media' => [
            'document_id', 'block_public_id', 'media_asset_public_id',
            'role', 'created_at',
        ],
        'revision_media' => [
            'revision_id', 'block_public_id', 'media_asset_public_id',
            'role', 'created_at',
        ],
    ];

    private readonly BlogCategoryMigrationPostconditionVerifier
        $categoryVerifier;

    public function __construct(
        ?BlogCategoryMigrationPostconditionVerifier $categoryVerifier = null,
        private readonly MySqlColumnDefaultNormalizer $defaultNormalizer =
            new MySqlColumnDefaultNormalizer(),
        bool $expectSitemapStateExtension = false,
        bool $expectPostTombstoneExtension = false,
        bool $expectAnalyticsExtension = false
    ) {
        $this->categoryVerifier = $categoryVerifier
            ?? new BlogCategoryMigrationPostconditionVerifier(
                expectStructuredContentExtension: true,
                expectSitemapStateExtension: $expectSitemapStateExtension,
                expectPostTombstoneExtension: $expectPostTombstoneExtension,
                expectAnalyticsExtension: $expectAnalyticsExtension
            );
    }

    public function contractVersion(): string
    {
        return 'blog-structured-content-schema-v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        if ($scope->moduleId() !== 'blog') {
            return false;
        }

        try {
            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            if (!$this->categoryVerifier->verify($pdo, $scope)) {
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
                . 'EXTRA FROM information_schema.COLUMNS '
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
        $integer = ['INTEGER', 1, 0, null];
        $nullableText = ['TEXT', 0, 0, null];

        $expected = match ($suffix) {
            'content_docs' => [
                $id, $text, $integer, ['INTEGER', 1, 0, '1'], $text,
                $text, $integer, $text, $text, $text, $text, $text,
                ['TEXT', 1, 0, $timestamp],
                ['TEXT', 1, 0, $timestamp],
            ],
            'content_revisions' => [
                $id, $text, $integer, $integer, $integer,
                ['INTEGER', 1, 0, '1'], $text, $text, $integer,
                $text, $text, $text, $text, $nullableText, $nullableText,
                $nullableText, $nullableText, $text, $text,
                ['TEXT', 1, 0, $timestamp],
            ],
            'content_media' => [
                ['INTEGER', 1, 1, null], ['TEXT', 1, 2, null],
                $text, ['TEXT', 1, 3, null],
                ['TEXT', 1, 0, $timestamp],
            ],
            'revision_media' => [
                ['INTEGER', 1, 1, null], ['TEXT', 1, 2, null],
                $text, ['TEXT', 1, 3, null],
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
    ): bool {
        $expected = $this->mysqlColumnContract($suffix);
        if (count($rows) !== count($expected)) {
            return false;
        }
        foreach ($rows as $position => $row) {
            $contract = $expected[$position];
            $column = self::COLUMNS[$suffix][$position] ?? '';
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
            if (!$this->validMySqlExtra(
                $column,
                $extra,
                $contract['default'] ?? null,
                (string) ($contract['extra'] ?? '')
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
                'extra' => str_contains($extra, 'auto_increment')
                    ? 'auto_increment' : '',
            ];
            if ($actual !== $contract) {
                return false;
            }
        }

        return true;
    }

    private function validMySqlExtra(
        string $column,
        string $actual,
        ?string $expectedDefault,
        string $expectedExtra
    ): bool {
        $normalized = strtolower(trim($actual));
        if ($expectedExtra === 'auto_increment') {
            return $column === 'id' && $normalized === 'auto_increment';
        }
        if ($normalized === '') {
            return true;
        }

        return $expectedExtra === ''
            && $expectedDefault === 'current_timestamp(6)'
            && $normalized === 'default_generated';
    }

    /** @return list<array<string, mixed>> */
    private function mysqlColumnContract(string $suffix): array
    {
        $id = $this->mysqlContract(
            'bigint', true, false, null, null, null, null, null,
            'auto_increment'
        );
        $foreignId = $this->mysqlContract('bigint', true, false);
        $uuid = $this->mysqlContract(
            'char', false, false, 36, null, 'ascii', 'ascii_bin'
        );
        $hash = $this->mysqlContract(
            'char', false, false, 64, null, 'ascii', 'ascii_bin'
        );
        $timestamp = $this->mysqlContract(
            'datetime', false, false, null, 6, null, null,
            'current_timestamp(6)'
        );
        $schema = $this->mysqlContract(
            'smallint', true, false, null, null, null, null, "'1'"
        );
        $template = $this->mysqlContract(
            'varchar', false, false, 64, null, 'ascii', 'ascii_bin'
        );
        $document = $this->mysqlContract(
            'longtext', false, false, 4_294_967_295, null, 'utf8mb4',
            'utf8mb4_unicode_ci'
        );
        $bytes = $this->mysqlContract('int', true, false);
        $utf8Text = $this->mysqlContract(
            'text', false, true, 65_535, null, 'utf8mb4',
            'utf8mb4_unicode_ci'
        );

        return match ($suffix) {
            'content_docs' => [
                $id, $uuid, $foreignId, $schema, $template, $document,
                $bytes, $hash, $hash, $hash, $uuid, $uuid,
                $timestamp, $timestamp,
            ],
            'content_revisions' => [
                $id, $uuid, $foreignId, $foreignId, $foreignId, $schema,
                $template, $document, $bytes, $hash, $hash, $hash,
                $this->mysqlContract(
                    'varchar', false, false, 255, null, 'utf8mb4',
                    'utf8mb4_unicode_ci'
                ),
                $this->mysqlContract(
                    'varchar', false, true, 190, null, 'ascii', 'ascii_bin'
                ),
                $this->mysqlContract(
                    'varchar', false, true, 255, null, 'utf8mb4',
                    'utf8mb4_unicode_ci'
                ),
                $this->mysqlContract(
                    'varchar', false, true, 320, null, 'utf8mb4',
                    'utf8mb4_unicode_ci'
                ),
                $utf8Text, $document, $uuid, $timestamp,
            ],
            'content_media', 'revision_media' => [
                $foreignId, $uuid, $uuid,
                $this->mysqlContract(
                    'varchar', false, false, 16, null, 'ascii', 'ascii_bin'
                ),
                $timestamp,
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
        string $extra = ''
    ): array {
        return compact(
            'type', 'unsigned', 'nullable', 'length', 'precision', 'charset',
            'collation', 'default', 'extra'
        );
    }

    private function sqliteTableSqlIsExact(
        string $suffix,
        string $sql
    ): bool {
        $normalized = $this->canonicalSql($sql);
        if (!str_ends_with(trim($sql), ')')) {
            return false;
        }
        if (
            in_array($suffix, ['content_docs', 'content_revisions'], true)
            && !str_contains($normalized, 'idintegerprimarykeyautoincrement')
        ) {
            return false;
        }
        if (
            in_array($suffix, ['content_media', 'revision_media'], true)
            && !str_contains($normalized, 'primarykey(')
        ) {
            return false;
        }

        foreach ($this->sqliteBinaryColumns()[$suffix] as $column) {
            if (preg_match(
                '/"' . preg_quote($column, '/')
                . '"\s+TEXT\s+COLLATE\s+BINARY\b/i',
                $sql
            ) !== 1) {
                return false;
            }
        }

        $actualChecks = $this->checkExpressions($sql);
        $expectedChecks = $this->sqliteChecks()[$suffix];
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
            'content_docs' => [
                '0:updated_at',
                'p:id',
                '1:localization_id',
                '1:public_id',
            ],
            'content_revisions' => [
                '0:created_at',
                'p:id',
                '1:localization_id,revision_number',
                '1:localization_id,variant_lock_version',
                '1:public_id',
            ],
            'content_media' => [
                '0:media_asset_public_id',
                'p:document_id,block_public_id,role',
            ],
            'revision_media' => [
                '0:media_asset_public_id',
                'p:revision_id,block_public_id,role',
            ],
        ];
        $serverVersion = null;
        if ($driver === 'mysql') {
            $serverVersion = $pdo->query('SELECT VERSION()')->fetchColumn();
            if (!is_string($serverVersion)) {
                return false;
            }
        }
        foreach ($expected as $suffix => $contracts) {
            $actual = $this->indexSignatures(
                $pdo,
                $scope,
                $driver,
                $suffix,
                $serverVersion
            );
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
        string $suffix,
        ?string $serverVersion = null
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
                    ['c', 'pk']
                );
                if (!is_string($signature)) {
                    return ['invalid'];
                }
                $result[] = $signature;
            }
            if (
                in_array($suffix, ['content_docs', 'content_revisions'], true)
            ) {
                $result[] = 'p:id';
            }

            return $result;
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
        $statement = $pdo->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, '
            . 'INDEX_TYPE, SUB_PART, COLLATION, ' . $ignoredExpression . ' '
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

    private function foreignKeysAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $expected = [
            'content_docs' => [
                'localization_id>post_localizations.id>NO ACTION>CASCADE',
            ],
            'content_revisions' => [
                'localization_id>post_localizations.id>NO ACTION>CASCADE',
            ],
            'content_media' => [
                'document_id>content_docs.id>NO ACTION>CASCADE',
            ],
            'revision_media' => [
                'revision_id>content_revisions.id>NO ACTION>CASCADE',
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
                    fn (array $row): string =>
                        $this->foreignKeySignature($row, $scope, false),
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

    /** @param array<string, mixed> $row */
    private function foreignKeySignature(
        array $row,
        MigrationScope $scope,
        bool $sqlite
    ): string {
        if (
            !$sqlite
            && !in_array(
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
        $targets = [
            $scope->tableName('post_localizations') => 'post_localizations',
            $scope->tableName('content_docs') => 'content_docs',
            $scope->tableName('content_revisions') => 'content_revisions',
        ];
        $updateRule = strtoupper((string) ($sqlite
            ? ($row['on_update'] ?? '')
            : ($row['UPDATE_RULE'] ?? '')));
        if ($updateRule === 'RESTRICT') {
            $updateRule = 'NO ACTION';
        }

        return strtolower((string) ($sqlite
            ? ($row['from'] ?? '')
            : ($row['COLUMN_NAME'] ?? '')))
            . '>' . ($targets[$target] ?? 'invalid') . '.'
            . strtolower((string) ($sqlite
                ? ($row['to'] ?? '')
                : ($row['REFERENCED_COLUMN_NAME'] ?? '')))
            . '>' . $updateRule
            . '>' . strtoupper((string) ($sqlite
                ? ($row['on_delete'] ?? '')
                : ($row['DELETE_RULE'] ?? '')));
    }

    private function checksAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        if ($driver === 'sqlite') {
            return true;
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
        $parameters = [];
        $placeholders = [];
        foreach ($names as $position => $name) {
            $key = 'table_' . $position;
            $parameters[$key] = $name;
            $placeholders[] = ':' . $key;
        }
        $sql = $driver === 'sqlite'
            ? "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' "
                . 'AND tbl_name IN (' . implode(', ', $placeholders) . ')'
            : 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE '
                . 'TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE IN ('
                . implode(', ', $placeholders) . ')';
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);

        return in_array($statement->fetchColumn(), [0, '0'], true);
    }

    private function dataIsValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $documents = $scope->quotedTable('content_docs', $driver);
        $revisions = $scope->quotedTable('content_revisions', $driver);
        $localizations = $scope->quotedTable(
            'post_localizations',
            $driver
        );
        if (!$this->relationshipsAreValid($pdo, $scope, $driver)) {
            return false;
        }

        $documentRows = $pdo->query(
            'SELECT d.*, l.h1 AS localization_h1, '
            . 'l.slug AS localization_slug, '
            . 'l.seo_title AS localization_seo_title, '
            . 'l.meta_description AS localization_meta_description, '
            . 'l.excerpt AS localization_excerpt, '
            . 'l.body_text AS localization_body_text FROM '
            . $documents . ' d INNER JOIN ' . $localizations
            . ' l ON l.id = d.localization_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($documentRows as $row) {
            if (!$this->documentRowIsValid($row, false)) {
                return false;
            }
        }

        $revisionRows = $pdo->query(
            'SELECT * FROM ' . $revisions
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($revisionRows as $row) {
            if (!$this->documentRowIsValid($row, true)) {
                return false;
            }
        }

        foreach (['content_media', 'revision_media'] as $suffix) {
            $table = $scope->quotedTable($suffix, $driver);
            $rows = $pdo->query(
                'SELECT block_public_id, media_asset_public_id, role '
                . 'FROM ' . $table
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (
                    !$this->isUuid((string) ($row['block_public_id'] ?? ''))
                    || !$this->isUuid(
                        (string) ($row['media_asset_public_id'] ?? '')
                    )
                    || !in_array(
                        $row['role'] ?? null,
                        ['image', 'cover', 'poster'],
                        true
                    )
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    private function relationshipsAreValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        foreach ([
            ['content_docs', 'localization_id', 'post_localizations'],
            ['content_revisions', 'localization_id', 'post_localizations'],
            ['content_media', 'document_id', 'content_docs'],
            ['revision_media', 'revision_id', 'content_revisions'],
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

    /** @param array<string, mixed> $row */
    private function documentRowIsValid(array $row, bool $revision): bool
    {
        $publicId = (string) ($row['public_id'] ?? '');
        $createdBy = (string) ($row['created_by_user_public_id'] ?? '');
        $updatedBy = $revision
            ? null : (string) ($row['updated_by_user_public_id'] ?? '');
        $json = (string) ($row['document_json'] ?? '');
        $template = (string) ($row['template_key'] ?? '');
        if (
            !$this->isUuid($publicId)
            || !$this->isUuid($createdBy)
            || ($updatedBy !== null && !$this->isUuid($updatedBy))
            || (int) ($row['schema_version'] ?? 0) !== 1
            || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $template) !== 1
            || strlen($json) < 1
            || strlen($json) > 300_000
            || (int) ($row['document_bytes'] ?? 0) !== strlen($json)
            || !$this->isHash((string) ($row['document_sha256'] ?? ''))
            || !hash_equals(
                (string) $row['document_sha256'],
                hash('sha256', $json)
            )
        ) {
            return false;
        }

        try {
            $document = json_decode(
                $json,
                true,
                16,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING
            );
        } catch (JsonException) {
            return false;
        }
        if (
            !is_array($document)
            || array_keys($document) !== [
                'schema', 'version', 'template', 'blocks',
            ]
            || $document['schema'] !== 'liquidstack.blog.document'
            || $document['version'] !== 1
            || $document['template'] !== $template
            || !is_array($document['blocks'])
            || !array_is_list($document['blocks'])
            || count($document['blocks']) > 200
        ) {
            return false;
        }

        $h1 = (string) ($revision
            ? ($row['h1'] ?? '') : ($row['localization_h1'] ?? ''));
        $slug = $revision
            ? ($row['slug'] ?? null) : ($row['localization_slug'] ?? null);
        $seoTitle = $revision
            ? ($row['seo_title'] ?? null)
            : ($row['localization_seo_title'] ?? null);
        $metaDescription = $revision
            ? ($row['meta_description'] ?? null)
            : ($row['localization_meta_description'] ?? null);
        $excerpt = $revision
            ? ($row['excerpt'] ?? null)
            : ($row['localization_excerpt'] ?? null);
        $bodyText = (string) ($revision
            ? ($row['body_text'] ?? '')
            : ($row['localization_body_text'] ?? ''));
        if (
            trim($h1) === ''
            || ($slug !== null && !is_string($slug))
            || ($seoTitle !== null && !is_string($seoTitle))
            || ($metaDescription !== null && !is_string($metaDescription))
            || ($excerpt !== null && !is_string($excerpt))
            || !$this->isHash((string) ($row['body_text_sha256'] ?? ''))
            || !hash_equals(
                (string) $row['body_text_sha256'],
                hash('sha256', $bodyText)
            )
            || !$this->isHash((string) ($row['snapshot_sha256'] ?? ''))
            || !hash_equals(
                (string) $row['snapshot_sha256'],
                $this->snapshotHash(
                    (string) $row['document_sha256'],
                    $h1,
                    $slug,
                    $seoTitle,
                    $metaDescription,
                    $excerpt,
                    $bodyText
                )
            )
        ) {
            return false;
        }

        return !$revision || (
            (int) ($row['revision_number'] ?? 0) > 0
            && (int) ($row['variant_lock_version'] ?? 0) > 0
        );
    }

    private function snapshotHash(
        string $documentSha256,
        string $h1,
        ?string $slug,
        ?string $seoTitle,
        ?string $metaDescription,
        ?string $excerpt,
        string $bodyText
    ): string {
        $json = json_encode([
            'schema' => 'liquidstack.blog.snapshot',
            'version' => 1,
            'document_sha256' => $documentSha256,
            'h1' => $h1,
            'slug' => $slug,
            'seo_title' => $seoTitle,
            'meta_description' => $metaDescription,
            'excerpt' => $excerpt,
            'body_text' => $bodyText,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? hash('sha256', $json) : '';
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $value
        ) === 1;
    }

    private function isHash(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/', $value) === 1;
    }

    /** @return array<string, list<string>> */
    private function sqliteBinaryColumns(): array
    {
        return [
            'content_docs' => [
                'public_id', 'template_key', 'document_sha256',
                'body_text_sha256', 'snapshot_sha256',
                'created_by_user_public_id', 'updated_by_user_public_id',
            ],
            'content_revisions' => [
                'public_id', 'template_key', 'document_sha256',
                'body_text_sha256', 'snapshot_sha256', 'slug',
                'created_by_user_public_id',
            ],
            'content_media' => [
                'block_public_id', 'media_asset_public_id', 'role',
            ],
            'revision_media' => [
                'block_public_id', 'media_asset_public_id', 'role',
            ],
        ];
    }

    /** @return array<string, list<string>> */
    private function sqliteChecks(): array
    {
        $checks = [
            'content_docs' => [
                'length(public_id)=36',
                'schema_version=1',
                'length(template_key) BETWEEN 1 AND 64 '
                    . 'AND template_key = lower(template_key) '
                    . 'AND template_key = trim(template_key) '
                    . "AND substr(template_key, 1, 1) GLOB '[a-z]' "
                    . "AND template_key NOT GLOB '*[^a-z0-9_-]*'",
                'document_bytes BETWEEN 1 AND 300000',
                "length(document_sha256) = 64 "
                    . "AND document_sha256 NOT GLOB '*[^0-9a-f]*'",
                "length(body_text_sha256) = 64 "
                    . "AND body_text_sha256 NOT GLOB '*[^0-9a-f]*'",
                "length(snapshot_sha256) = 64 "
                    . "AND snapshot_sha256 NOT GLOB '*[^0-9a-f]*'",
                'length(created_by_user_public_id)=36',
                'length(updated_by_user_public_id)=36',
            ],
            'content_revisions' => [
                'length(public_id)=36',
                'revision_number>0',
                'variant_lock_version>0',
                'schema_version=1',
                'length(template_key) BETWEEN 1 AND 64 '
                    . 'AND template_key = lower(template_key) '
                    . 'AND template_key = trim(template_key) '
                    . "AND substr(template_key, 1, 1) GLOB '[a-z]' "
                    . "AND template_key NOT GLOB '*[^a-z0-9_-]*'",
                'document_bytes BETWEEN 1 AND 300000',
                "length(document_sha256) = 64 "
                    . "AND document_sha256 NOT GLOB '*[^0-9a-f]*'",
                "length(body_text_sha256) = 64 "
                    . "AND body_text_sha256 NOT GLOB '*[^0-9a-f]*'",
                "length(snapshot_sha256) = 64 "
                    . "AND snapshot_sha256 NOT GLOB '*[^0-9a-f]*'",
                'length(trim(h1))>0',
                'slug IS NULL OR (length(trim(slug)) > 0 '
                    . 'AND slug = lower(slug) AND slug = trim(slug))',
                'length(created_by_user_public_id)=36',
            ],
            'content_media' => [
                'length(block_public_id)=36',
                'length(media_asset_public_id)=36',
                "role IN ('image', 'cover', 'poster')",
            ],
            'revision_media' => [
                'length(block_public_id)=36',
                'length(media_asset_public_id)=36',
                "role IN ('image', 'cover', 'poster')",
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
            'content_docs' => [
                'char_length(public_id)=36',
                'schema_version=1',
                'char_length(template_key) BETWEEN 1 AND 64 '
                    . 'AND template_key = lower(template_key) '
                    . 'AND template_key = trim(template_key) '
                    . "AND template_key REGEXP '^[a-z][a-z0-9_-]{0,63}$'",
                'document_bytes BETWEEN 1 AND 300000',
                "document_sha256 REGEXP '^[0-9a-f]{64}$'",
                "body_text_sha256 REGEXP '^[0-9a-f]{64}$'",
                "snapshot_sha256 REGEXP '^[0-9a-f]{64}$'",
                'char_length(created_by_user_public_id)=36',
                'char_length(updated_by_user_public_id)=36',
            ],
            'content_revisions' => [
                'char_length(public_id)=36',
                'revision_number>0',
                'variant_lock_version>0',
                'schema_version=1',
                'char_length(template_key) BETWEEN 1 AND 64 '
                    . 'AND template_key = lower(template_key) '
                    . 'AND template_key = trim(template_key) '
                    . "AND template_key REGEXP '^[a-z][a-z0-9_-]{0,63}$'",
                'document_bytes BETWEEN 1 AND 300000',
                "document_sha256 REGEXP '^[0-9a-f]{64}$'",
                "body_text_sha256 REGEXP '^[0-9a-f]{64}$'",
                "snapshot_sha256 REGEXP '^[0-9a-f]{64}$'",
                'char_length(trim(h1))>0',
                'slug IS NULL OR (char_length(trim(slug)) > 0 '
                    . 'AND slug = lower(slug) AND slug = trim(slug))',
                'char_length(created_by_user_public_id)=36',
            ],
            'content_media' => [
                'char_length(block_public_id)=36',
                'char_length(media_asset_public_id)=36',
                "role IN ('image', 'cover', 'poster')",
            ],
            'revision_media' => [
                'char_length(block_public_id)=36',
                'char_length(media_asset_public_id)=36',
                "role IN ('image', 'cover', 'poster')",
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
