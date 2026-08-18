<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Database\MySqlServerCapabilities;
use App\Core\Database\SqlCheckExpressionCanonicalizer;
use App\Core\Modules\Migrations\MigrationConditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Exact growing schema contract for Blog tag migrations 0020-0024. */
final class BlogTagSchemaMigrationPostconditionVerifier implements
    MigrationPostconditionVerifierInterface
{
    private const TABLES = [
        'tags',
        'localization_tags',
        'tag_assignment_heads',
        'tag_assignment_workspaces',
        'tag_assignment_workspace_items',
    ];

    private readonly MigrationConditionVerifierInterface $baseVerifier;

    public function __construct(
        private readonly int $stage,
        ?MigrationConditionVerifierInterface $baseVerifier = null
    ) {
        if ($stage < 1 || $stage > 5) {
            throw new \InvalidArgumentException('Invalid Blog tag schema stage.');
        }
        $this->baseVerifier = $baseVerifier ?? self::priorFrontier($stage);
    }

    public function contractVersion(): string
    {
        return 'blog-tag-schema-v1-stage-' . $this->stage;
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

    private static function priorFrontier(int $stage):
        BlogCopyOperationMigrationPostconditionVerifier {
        $initial = new BlogMigrationPostconditionVerifier(
            expectCategoryExtension: true,
            expectStructuredContentExtension: true,
            expectSitemapStateExtension: true,
            expectPostTombstoneExtension: true,
            expectAnalyticsExtension: true,
            expectLayoutEditorExtension: true,
            expectEditorPreferencesExtension: true,
            expectPrivateDraftPublicationExtension: true,
            expectRobotsPreferencesExtension: true,
            expectUrlHistoryExtension: true,
            expectCopyOperationExtension: true,
            expectTagExtensionStage: $stage
        );
        $category = new BlogCategoryMigrationPostconditionVerifier($initial);
        $structured = new BlogStructuredContentMigrationPostconditionVerifier(
            $category
        );
        $sitemap = new BlogSitemapStateMigrationPostconditionVerifier(
            $structured
        );
        $tombstones = new BlogPostTombstoneMigrationPostconditionVerifier(
            $sitemap
        );
        $analytics = new BlogAnalyticsMigrationPostconditionVerifier(
            $tombstones
        );
        $layout = new BlogLayoutEditorMigrationPostconditionVerifier(
            $analytics
        );
        $preferences = new BlogEditorPreferencesMigrationPostconditionVerifier(
            $layout
        );
        $private = new BlogPrivateDraftPublicationMigrationPostconditionVerifier(
            $preferences
        );
        $robots = new BlogRobotsPreferencesMigrationPostconditionVerifier(
            $private
        );
        $url = new BlogUrlHistoryMigrationPostconditionVerifier($robots);
        $dummy = new BlogDummyCategoryNormalizationPostcondition($url);

        return new BlogCopyOperationMigrationPostconditionVerifier($dummy);
    }

    private function verifySqlite(PDO $pdo, MigrationScope $scope): bool
    {
        foreach (array_slice(self::TABLES, 0, $this->stage) as $suffix) {
            $quoted = $scope->quotedTable($suffix, 'sqlite');
            $columns = $pdo->query('PRAGMA table_info(' . $quoted . ')')
                ->fetchAll(PDO::FETCH_ASSOC);
            if (!$this->sqliteColumnsAreExact($columns, $suffix)) {
                return false;
            }
            $definition = $pdo->prepare(
                "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :name"
            );
            $definition->execute(['name' => $scope->tableName($suffix)]);
            $sql = $definition->fetchColumn();
            if (
                !is_string($sql)
                || ($suffix !== 'tags'
                    && stripos($sql, 'WITHOUT ROWID') === false)
                || ($suffix === 'tags'
                    && stripos($sql, 'AUTOINCREMENT') === false)
            ) {
                return false;
            }
            if (!$this->sqliteIndexesAreExact($pdo, $scope, $suffix)) {
                return false;
            }
            if (
                !$this->sqliteChecksAreExact($sql, $suffix)
                || !$this->sqliteForeignKeysAreExact(
                    $pdo,
                    $scope,
                    $suffix
                )
            ) {
                return false;
            }
        }

        return $this->dataIsValid($pdo, $scope, 'sqlite');
    }

    private function verifyMySql(PDO $pdo, MigrationScope $scope): bool
    {
        foreach (array_slice(self::TABLES, 0, $this->stage) as $suffix) {
            $statement = $pdo->prepare(
                'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, '
                    . 'CHARACTER_MAXIMUM_LENGTH, DATETIME_PRECISION, '
                    . 'CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_DEFAULT, '
                    . 'EXTRA FROM information_schema.COLUMNS '
                    . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                    . 'ORDER BY ORDINAL_POSITION'
            );
            $statement->execute(['name' => $scope->tableName($suffix)]);
            if (!$this->mysqlColumnsAreExact(
                $statement->fetchAll(PDO::FETCH_ASSOC),
                $suffix
            )) {
                return false;
            }
            if (
                !$this->mysqlIndexesAreExact($pdo, $scope, $suffix)
                || !$this->mysqlChecksAreExact($pdo, $scope, $suffix)
                || !$this->mysqlForeignKeysAreExact(
                    $pdo,
                    $scope,
                    $suffix
                )
            ) {
                return false;
            }
        }

        return $this->dataIsValid($pdo, $scope, 'mysql');
    }

    /** @return list<string> */
    private function expectedColumns(string $suffix): array
    {
        return match ($suffix) {
            'tags' => [
                'id', 'public_id', 'locale', 'slug', 'name',
                'normalized_sha256', 'lock_version',
                'created_by_user_public_id', 'updated_by_user_public_id',
                'created_at', 'updated_at',
            ],
            'localization_tags' => [
                'localization_id', 'tag_id', 'assigned_by_user_public_id',
                'created_at',
            ],
            'tag_assignment_heads' => [
                'localization_id', 'assignment_version',
                'updated_by_user_public_id', 'updated_at',
            ],
            'tag_assignment_workspaces' => [
                'localization_id', 'base_assignment_version',
                'workspace_version', 'created_by_user_public_id',
                'updated_by_user_public_id', 'created_at', 'updated_at',
            ],
            'tag_assignment_workspace_items' => [
                'localization_id', 'tag_id', 'assigned_by_user_public_id',
                'created_at',
            ],
            default => throw new \LogicException('Unknown Blog tag table.'),
        };
    }

    /** @param list<array<string, mixed>> $rows */
    private function sqliteColumnsAreExact(array $rows, string $suffix): bool
    {
        $actual = [];
        foreach ($rows as $row) {
            $default = $row['dflt_value'] ?? null;
            if (is_string($default)) {
                $default = trim($default, " ()'\"");
            }
            $actual[] = [
                'name' => strtolower((string) ($row['name'] ?? '')),
                'type' => strtoupper((string) ($row['type'] ?? '')),
                'not_null' => (int) ($row['notnull'] ?? -1),
                'primary' => (int) ($row['pk'] ?? -1),
                'default' => $default,
            ];
        }

        return $actual === $this->sqliteColumnContract($suffix);
    }

    /** @return list<array{name: string, type: string, not_null: int, primary: int, default: ?string}> */
    private function sqliteColumnContract(string $suffix): array
    {
        $column = static fn (
            string $name,
            string $type,
            int $notNull = 1,
            int $primary = 0,
            ?string $default = null
        ): array => compact('name', 'type', 'notNull', 'primary', 'default');
        $map = static fn (array $row): array => [
            'name' => $row['name'],
            'type' => $row['type'],
            'not_null' => $row['notNull'],
            'primary' => $row['primary'],
            'default' => $row['default'],
        ];
        $rows = match ($suffix) {
            'tags' => [
                $column('id', 'INTEGER', 0, 1),
                $column('public_id', 'TEXT'),
                $column('locale', 'TEXT'),
                $column('slug', 'TEXT'),
                $column('name', 'TEXT'),
                $column('normalized_sha256', 'TEXT'),
                $column('lock_version', 'INTEGER', 1, 0, '1'),
                $column('created_by_user_public_id', 'TEXT'),
                $column('updated_by_user_public_id', 'TEXT'),
                $column('created_at', 'TEXT'),
                $column('updated_at', 'TEXT'),
            ],
            'localization_tags', 'tag_assignment_workspace_items' => [
                $column('localization_id', 'INTEGER', 1, 1),
                $column('tag_id', 'INTEGER', 1, 2),
                $column('assigned_by_user_public_id', 'TEXT'),
                $column('created_at', 'TEXT'),
            ],
            'tag_assignment_heads' => [
                $column('localization_id', 'INTEGER', 1, 1),
                $column('assignment_version', 'INTEGER'),
                $column('updated_by_user_public_id', 'TEXT'),
                $column('updated_at', 'TEXT'),
            ],
            'tag_assignment_workspaces' => [
                $column('localization_id', 'INTEGER', 1, 1),
                $column('base_assignment_version', 'INTEGER', 1, 0, '0'),
                $column('workspace_version', 'INTEGER'),
                $column('created_by_user_public_id', 'TEXT'),
                $column('updated_by_user_public_id', 'TEXT'),
                $column('created_at', 'TEXT'),
                $column('updated_at', 'TEXT'),
            ],
            default => throw new \LogicException('Unknown Blog tag table.'),
        };

        return array_map($map, $rows);
    }

    /** @param list<array<string, mixed>> $rows */
    private function mysqlColumnsAreExact(array $rows, string $suffix): bool
    {
        $contracts = $this->mysqlColumnContract($suffix);
        if (count($rows) !== count($contracts)) {
            return false;
        }
        foreach ($rows as $index => $row) {
            $expected = $contracts[$index];
            $default = $row['COLUMN_DEFAULT'] ?? null;
            if ($default !== null) {
                $default = (string) $default;
            }
            if (
                strtolower((string) ($row['COLUMN_NAME'] ?? ''))
                    !== $expected['name']
                || strtolower((string) ($row['DATA_TYPE'] ?? ''))
                    !== $expected['type']
                || (strtoupper((string) ($row['IS_NULLABLE'] ?? '')) === 'YES')
                    !== $expected['nullable']
                || (($row['CHARACTER_MAXIMUM_LENGTH'] ?? null) === null
                    ? null : (int) $row['CHARACTER_MAXIMUM_LENGTH'])
                    !== $expected['length']
                || (($row['DATETIME_PRECISION'] ?? null) === null
                    ? null : (int) $row['DATETIME_PRECISION'])
                    !== $expected['precision']
                || (($row['CHARACTER_SET_NAME'] ?? null) === null
                    ? null : strtolower((string) $row['CHARACTER_SET_NAME']))
                    !== $expected['charset']
                || (($row['COLLATION_NAME'] ?? null) === null
                    ? null : strtolower((string) $row['COLLATION_NAME']))
                    !== $expected['collation']
                || $default !== $expected['default']
                || strtolower(trim((string) ($row['EXTRA'] ?? '')))
                    !== $expected['extra']
                || ($expected['unsigned']
                    && !str_contains(
                        strtolower((string) ($row['COLUMN_TYPE'] ?? '')),
                        'unsigned'
                    ))
            ) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{name: string,type: string,nullable: bool,length: ?int,precision: ?int,charset: ?string,collation: ?string,default: ?string,extra: string,unsigned: bool}> */
    private function mysqlColumnContract(string $suffix): array
    {
        $column = static fn (
            string $name,
            string $type,
            bool $unsigned = false,
            ?int $length = null,
            ?string $charset = null,
            ?string $collation = null,
            ?int $precision = null,
            ?string $default = null,
            string $extra = ''
        ): array => [
            'name' => $name,
            'type' => $type,
            'nullable' => false,
            'length' => $length,
            'precision' => $precision,
            'charset' => $charset,
            'collation' => $collation,
            'default' => $default,
            'extra' => $extra,
            'unsigned' => $unsigned,
        ];
        $id = static fn (string $name): array =>
            $column($name, 'bigint', true);
        $uuid = static fn (string $name): array =>
            $column($name, 'char', false, 36, 'ascii', 'ascii_bin');
        $time = static fn (string $name): array =>
            $column($name, 'datetime', false, null, null, null, 6);

        return match ($suffix) {
            'tags' => [
                $column('id', 'bigint', true, extra: 'auto_increment'),
                $uuid('public_id'),
                $column('locale', 'varchar', false, 16, 'ascii', 'ascii_bin'),
                $column('slug', 'varchar', false, 190, 'ascii', 'ascii_bin'),
                $column('name', 'varchar', false, 255, 'utf8mb4', 'utf8mb4_unicode_ci'),
                $column('normalized_sha256', 'char', false, 64, 'ascii', 'ascii_bin'),
                $column('lock_version', 'bigint', true, default: '1'),
                $uuid('created_by_user_public_id'),
                $uuid('updated_by_user_public_id'),
                $time('created_at'),
                $time('updated_at'),
            ],
            'localization_tags', 'tag_assignment_workspace_items' => [
                $id('localization_id'),
                $id('tag_id'),
                $uuid('assigned_by_user_public_id'),
                $time('created_at'),
            ],
            'tag_assignment_heads' => [
                $id('localization_id'),
                $id('assignment_version'),
                $uuid('updated_by_user_public_id'),
                $time('updated_at'),
            ],
            'tag_assignment_workspaces' => [
                $id('localization_id'),
                $column('base_assignment_version', 'bigint', true, default: '0'),
                $id('workspace_version'),
                $uuid('created_by_user_public_id'),
                $uuid('updated_by_user_public_id'),
                $time('created_at'),
                $time('updated_at'),
            ],
            default => throw new \LogicException('Unknown Blog tag table.'),
        };
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
            if (($row['origin'] ?? null) === 'pk') {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            $columns = $pdo->query(
                'PRAGMA index_info(' . $this->quoteSqlite($name) . ')'
            )->fetchAll(PDO::FETCH_ASSOC);
            $actual[] = [
                'unique' => (int) ($row['unique'] ?? 0) === 1,
                'columns' => array_map(
                    static fn (array $column): string =>
                        strtolower((string) ($column['name'] ?? '')),
                    $columns
                ),
            ];
        }
        $expected = $this->expectedIndexes($suffix);
        $this->sortIndexes($actual);
        $this->sortIndexes($expected);

        return $actual === $expected;
    }

    private function mysqlIndexesAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $suffix
    ): bool {
        $statement = $pdo->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM '
                . 'information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() '
                . 'AND TABLE_NAME = :name ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $statement->execute(['name' => $scope->tableName($suffix)]);
        $grouped = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = strtolower((string) ($row['INDEX_NAME'] ?? ''));
            $grouped[$name]['unique'] =
                (int) ($row['NON_UNIQUE'] ?? 1) === 0;
            $grouped[$name]['columns'][] = strtolower(
                (string) ($row['COLUMN_NAME'] ?? '')
            );
        }
        $actual = array_values($grouped);
        $expected = $this->expectedIndexes($suffix);
        if (in_array($suffix, [
            'tags', 'localization_tags', 'tag_assignment_heads',
            'tag_assignment_workspaces',
            'tag_assignment_workspace_items',
        ], true)) {
            array_unshift($expected, [
                'unique' => true,
                'columns' => $suffix === 'tags'
                    ? ['id']
                    : ($suffix === 'tag_assignment_heads'
                        || $suffix === 'tag_assignment_workspaces'
                            ? ['localization_id']
                            : ['localization_id', 'tag_id']),
            ]);
        }
        $this->sortIndexes($actual);
        $this->sortIndexes($expected);

        return $actual === $expected;
    }

    /** @return list<array{unique: bool, columns: list<string>}> */
    private function expectedIndexes(string $suffix): array
    {
        return match ($suffix) {
            'tags' => [
                ['unique' => true, 'columns' => ['public_id']],
                ['unique' => true, 'columns' => ['locale', 'slug']],
                ['unique' => true, 'columns' => ['locale', 'normalized_sha256']],
                ['unique' => false, 'columns' => ['locale', 'name']],
            ],
            'localization_tags', 'tag_assignment_workspace_items' => [[
                'unique' => false,
                'columns' => ['tag_id', 'localization_id'],
            ]],
            'tag_assignment_heads', 'tag_assignment_workspaces' => [],
            default => throw new \LogicException('Unknown Blog tag table.'),
        };
    }

    private function sqliteChecksAreExact(string $sql, string $suffix): bool
    {
        $actual = $this->checkExpressions($sql);
        $expected = $this->expectedCheckExpressions($suffix, 'sqlite');
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private function mysqlChecksAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $suffix
    ): bool {
        $expectedSuffixes = match ($suffix) {
            'tags' => [
                'c_bt_public', 'c_bt_locale', 'c_bt_slug', 'c_bt_name',
                'c_bt_hash', 'c_bt_lock', 'c_bt_created_actor',
                'c_bt_updated_actor', 'c_bt_time',
            ],
            'localization_tags' => ['c_blt_actor'],
            'tag_assignment_heads' => ['c_btah_version', 'c_btah_actor'],
            'tag_assignment_workspaces' => [
                'c_btaw_base', 'c_btaw_workspace', 'c_btaw_created_actor',
                'c_btaw_updated_actor', 'c_btaw_time',
            ],
            'tag_assignment_workspace_items' => ['c_btawi_actor'],
            default => throw new \LogicException('Unknown Blog tag table.'),
        };
        $serverVersion = $pdo->query('SELECT VERSION()')->fetchColumn();
        if (!is_string($serverVersion)) {
            return false;
        }
        $isMariaDb = MySqlServerCapabilities::isMariaDb($serverVersion);
        $statement = $pdo->prepare(
            'SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE, '
                . ($isMariaDb ? "'YES'" : 'tc.ENFORCED')
                . ' AS ENFORCED FROM information_schema.TABLE_CONSTRAINTS '
                . 'tc JOIN information_schema.CHECK_CONSTRAINTS cc ON '
                . 'cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND '
                . 'cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
                . ($isMariaDb ? 'AND cc.TABLE_NAME = tc.TABLE_NAME ' : '')
                . 'WHERE tc.CONSTRAINT_SCHEMA = DATABASE() '
                . 'AND tc.TABLE_NAME = :name AND tc.CONSTRAINT_TYPE = '
                . "'CHECK' ORDER BY tc.CONSTRAINT_NAME"
        );
        $statement->execute(['name' => $scope->tableName($suffix)]);
        $actual = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (strtoupper((string) ($row['ENFORCED'] ?? '')) !== 'YES') {
                return false;
            }
            $actual[strtolower((string) ($row['CONSTRAINT_NAME'] ?? ''))] =
                SqlCheckExpressionCanonicalizer::canonicalize(
                    (string) ($row['CHECK_CLAUSE'] ?? '')
                );
        }
        $expressions = $this->expectedCheckExpressions($suffix, 'mysql');
        $expected = [];
        foreach ($expectedSuffixes as $index => $name) {
            $expected[strtolower($scope->tableName($name))] =
                $expressions[$index];
        }
        ksort($actual, SORT_STRING);
        ksort($expected, SORT_STRING);

        return $actual === $expected;
    }

    /** @return list<string> */
    private function expectedCheckExpressions(
        string $suffix,
        string $driver
    ): array {
        $uuid = static fn (string $column, string $length): string =>
            $length . '(' . $column . ') = 36 AND ' . $column
                . ' = lower(' . $column . ')';
        $expressions = $driver === 'sqlite'
            ? match ($suffix) {
                'tags' => [
                    $uuid('public_id', 'length'),
                    'length(locale) BETWEEN 2 AND 16 AND locale = lower(locale) AND locale = trim(locale)',
                    "length(slug) BETWEEN 1 AND 190 AND slug = lower(slug) AND slug = trim(slug) AND slug NOT GLOB '*[^a-z0-9-]*' AND slug NOT LIKE '-%' AND slug NOT LIKE '%-' AND slug NOT LIKE '%--%'",
                    'length(trim(name)) BETWEEN 1 AND 64 AND length(CAST(name AS BLOB)) <= 255',
                    "length(normalized_sha256) = 64 AND normalized_sha256 = lower(normalized_sha256) AND normalized_sha256 NOT GLOB '*[^0-9a-f]*'",
                    'lock_version > 0',
                    $uuid('created_by_user_public_id', 'length'),
                    $uuid('updated_by_user_public_id', 'length'),
                    'updated_at >= created_at',
                ],
                'localization_tags' => [
                    $uuid('assigned_by_user_public_id', 'length'),
                ],
                'tag_assignment_heads' => [
                    'assignment_version > 0',
                    $uuid('updated_by_user_public_id', 'length'),
                ],
                'tag_assignment_workspaces' => [
                    'base_assignment_version >= 0',
                    'workspace_version > 0',
                    $uuid('created_by_user_public_id', 'length'),
                    $uuid('updated_by_user_public_id', 'length'),
                    'updated_at >= created_at',
                ],
                'tag_assignment_workspace_items' => [
                    $uuid('assigned_by_user_public_id', 'length'),
                ],
                default => throw new \LogicException(
                    'Unknown Blog tag table.'
                ),
            }
            : match ($suffix) {
                'tags' => [
                    "public_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'",
                    'char_length(locale) BETWEEN 2 AND 16 AND locale = lower(locale) AND locale = trim(locale)',
                    "char_length(slug) BETWEEN 1 AND 190 AND slug REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$'",
                    'char_length(trim(name)) BETWEEN 1 AND 64 AND octet_length(name) <= 255',
                    "normalized_sha256 REGEXP '^[0-9a-f]{64}$'",
                    'lock_version > 0',
                    "created_by_user_public_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'",
                    "updated_by_user_public_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'",
                    'updated_at >= created_at',
                ],
                'localization_tags' => [
                    "assigned_by_user_public_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'",
                ],
                'tag_assignment_heads' => [
                    'assignment_version > 0',
                    "updated_by_user_public_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'",
                ],
                'tag_assignment_workspaces' => [
                    'base_assignment_version >= 0',
                    'workspace_version > 0',
                    "created_by_user_public_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'",
                    "updated_by_user_public_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'",
                    'updated_at >= created_at',
                ],
                'tag_assignment_workspace_items' => [
                    "assigned_by_user_public_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'",
                ],
                default => throw new \LogicException(
                    'Unknown Blog tag table.'
                ),
            };

        return array_map(
            [SqlCheckExpressionCanonicalizer::class, 'canonicalize'],
            $expressions
        );
    }

    private function sqliteForeignKeysAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $suffix
    ): bool {
        $rows = $pdo->query(
            'PRAGMA foreign_key_list('
                . $scope->quotedTable($suffix, 'sqlite') . ')'
        )->fetchAll(PDO::FETCH_ASSOC);
        $actual = array_map(
            static fn (array $row): array => [
                'from' => strtolower((string) ($row['from'] ?? '')),
                'table' => strtolower((string) ($row['table'] ?? '')),
                'to' => strtolower((string) ($row['to'] ?? '')),
                'delete' => strtoupper((string) ($row['on_delete'] ?? '')),
            ],
            $rows
        );
        $expected = $this->expectedForeignKeys($scope, $suffix);
        $this->sortForeignKeys($actual);
        $this->sortForeignKeys($expected);

        return $actual === $expected;
    }

    private function mysqlForeignKeysAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $suffix
    ): bool {
        $statement = $pdo->prepare(
            'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, '
                . 'k.REFERENCED_COLUMN_NAME, r.DELETE_RULE FROM '
                . 'information_schema.KEY_COLUMN_USAGE k JOIN '
                . 'information_schema.REFERENTIAL_CONSTRAINTS r ON '
                . 'r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND '
                . 'r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND '
                . 'r.TABLE_NAME = k.TABLE_NAME WHERE k.TABLE_SCHEMA = '
                . 'DATABASE() AND k.TABLE_NAME = :name AND '
                . 'k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY '
                . 'k.CONSTRAINT_NAME, k.ORDINAL_POSITION'
        );
        $statement->execute(['name' => $scope->tableName($suffix)]);
        $actual = array_map(
            static fn (array $row): array => [
                'from' => strtolower((string) ($row['COLUMN_NAME'] ?? '')),
                'table' => strtolower(
                    (string) ($row['REFERENCED_TABLE_NAME'] ?? '')
                ),
                'to' => strtolower(
                    (string) ($row['REFERENCED_COLUMN_NAME'] ?? '')
                ),
                'delete' => strtoupper((string) ($row['DELETE_RULE'] ?? '')),
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
        $expected = $this->expectedForeignKeys($scope, $suffix);
        $this->sortForeignKeys($actual);
        $this->sortForeignKeys($expected);

        return $actual === $expected;
    }

    /** @return list<array{from:string,table:string,to:string,delete:string}> */
    private function expectedForeignKeys(
        MigrationScope $scope,
        string $suffix
    ): array {
        $localizations = strtolower($scope->tableName('post_localizations'));
        $tags = strtolower($scope->tableName('tags'));

        return match ($suffix) {
            'tags' => [],
            'localization_tags' => [
                [
                    'from' => 'localization_id',
                    'table' => $localizations,
                    'to' => 'id',
                    'delete' => 'CASCADE',
                ],
                [
                    'from' => 'tag_id',
                    'table' => $tags,
                    'to' => 'id',
                    'delete' => 'RESTRICT',
                ],
            ],
            'tag_assignment_heads', 'tag_assignment_workspaces' => [[
                'from' => 'localization_id',
                'table' => $localizations,
                'to' => 'id',
                'delete' => 'CASCADE',
            ]],
            'tag_assignment_workspace_items' => [
                [
                    'from' => 'localization_id',
                    'table' => strtolower($scope->tableName(
                        'tag_assignment_workspaces'
                    )),
                    'to' => 'localization_id',
                    'delete' => 'CASCADE',
                ],
                [
                    'from' => 'tag_id',
                    'table' => $tags,
                    'to' => 'id',
                    'delete' => 'RESTRICT',
                ],
            ],
            default => throw new \LogicException('Unknown Blog tag table.'),
        };
    }

    /** @param list<array{from:string,table:string,to:string,delete:string}> $keys */
    private function sortForeignKeys(array &$keys): void
    {
        usort(
            $keys,
            static fn (array $left, array $right): int => strcmp(
                implode(':', $left),
                implode(':', $right)
            )
        );
    }

    private function dataIsValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $tags = $scope->quotedTable('tags', $driver);
        $hashInvalid = $driver === 'mysql'
            ? "CHAR_LENGTH(normalized_sha256) <> 64 OR "
                . "normalized_sha256 <> LOWER(normalized_sha256) OR "
                . "normalized_sha256 REGEXP '[^0-9a-f]'"
            : "length(normalized_sha256) <> 64 OR "
                . "normalized_sha256 <> lower(normalized_sha256) OR "
                . "normalized_sha256 GLOB '*[^0-9a-f]*'";
        $invalidTags = $pdo->query(
            'SELECT COUNT(*) FROM ' . $tags . ' WHERE lock_version < 1 OR '
                . $hashInvalid
        );
        if (!$invalidTags || (int) $invalidTags->fetchColumn() !== 0) {
            return false;
        }
        if ($this->stage >= 2) {
            $live = $scope->quotedTable('localization_tags', $driver);
            $localizations = $scope->quotedTable('post_localizations', $driver);
            $invalid = $pdo->query(
                'SELECT COUNT(*) FROM ' . $live . ' r JOIN ' . $tags
                    . ' t ON t.id = r.tag_id JOIN ' . $localizations
                    . ' l ON l.id = r.localization_id WHERE t.locale <> l.locale'
            );
            if (!$invalid || (int) $invalid->fetchColumn() !== 0) {
                return false;
            }
            $overflow = $pdo->query(
                'SELECT COUNT(*) FROM (SELECT localization_id FROM ' . $live
                    . ' GROUP BY localization_id HAVING COUNT(*) > 30) x'
            );
            if (!$overflow || (int) $overflow->fetchColumn() !== 0) {
                return false;
            }
        }
        if ($this->stage >= 3) {
            $heads = $scope->quotedTable('tag_assignment_heads', $driver);
            $invalid = $pdo->query(
                'SELECT COUNT(*) FROM ' . $heads
                    . ' WHERE assignment_version < 1'
            );
            if (!$invalid || (int) $invalid->fetchColumn() !== 0) {
                return false;
            }
        }
        if ($this->stage >= 4) {
            $workspaces = $scope->quotedTable(
                'tag_assignment_workspaces',
                $driver
            );
            $invalid = $pdo->query(
                'SELECT COUNT(*) FROM ' . $workspaces
                    . ' WHERE base_assignment_version < 0 '
                    . 'OR workspace_version < 1 OR updated_at < created_at'
            );
            if (!$invalid || (int) $invalid->fetchColumn() !== 0) {
                return false;
            }
        }
        if ($this->stage >= 5) {
            $items = $scope->quotedTable(
                'tag_assignment_workspace_items',
                $driver
            );
            $localizations = $scope->quotedTable('post_localizations', $driver);
            $invalid = $pdo->query(
                'SELECT COUNT(*) FROM ' . $items . ' r JOIN ' . $tags
                    . ' t ON t.id = r.tag_id JOIN ' . $localizations
                    . ' l ON l.id = r.localization_id WHERE t.locale <> l.locale'
            );
            if (!$invalid || (int) $invalid->fetchColumn() !== 0) {
                return false;
            }
            $overflow = $pdo->query(
                'SELECT COUNT(*) FROM (SELECT localization_id FROM ' . $items
                    . ' GROUP BY localization_id HAVING COUNT(*) > 30) x'
            );
            if (!$overflow || (int) $overflow->fetchColumn() !== 0) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array{unique: bool, columns: list<string>}> $indexes */
    private function sortIndexes(array &$indexes): void
    {
        usort(
            $indexes,
            static fn (array $left, array $right): int => strcmp(
                ($left['unique'] ? '1' : '0') . ':' . implode(',', $left['columns']),
                ($right['unique'] ? '1' : '0') . ':' . implode(',', $right['columns'])
            )
        );
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
            $expressions[] = SqlCheckExpressionCanonicalizer::canonicalize(
                substr($sql, $start, $cursor - $start - 1)
            );
            $index = $cursor;
        }

        return $expressions;
    }

    private function stripSqlComments(string $sql): string
    {
        $result = '';
        $length = strlen($sql);
        for ($index = 0; $index < $length;) {
            if (in_array($sql[$index], ["'", '"', '`', '['], true)) {
                $start = $index;
                $this->skipQuotedToken($sql, $index);
                $result .= substr($sql, $start, $index - $start);
                continue;
            }
            if ($sql[$index] === '-' && ($sql[$index + 1] ?? '') === '-') {
                $result .= ' ';
                $index += 2;
                while (
                    $index < $length
                    && !in_array($sql[$index], ["\r", "\n"], true)
                ) {
                    $index++;
                }
                continue;
            }
            if ($sql[$index] === '/' && ($sql[$index + 1] ?? '') === '*') {
                $result .= ' ';
                $index += 2;
                while (
                    $index < $length
                    && !($sql[$index] === '*'
                        && ($sql[$index + 1] ?? '') === '/')
                ) {
                    $index++;
                }
                if ($index < $length) {
                    $index += 2;
                }
                continue;
            }
            $result .= $sql[$index++];
        }

        return $result;
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

    private function quoteSqlite(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
