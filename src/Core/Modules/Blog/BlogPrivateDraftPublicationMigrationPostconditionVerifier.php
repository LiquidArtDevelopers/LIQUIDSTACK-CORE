<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Exact, read-only postcondition for optional private publication migration. */
final class BlogPrivateDraftPublicationMigrationPostconditionVerifier implements
    MigrationPostconditionVerifierInterface
{
    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'editorial_workspaces' => [
            'localization_id', 'draft_revision_id',
            'base_publication_version',
            'created_by_user_public_id', 'updated_by_user_public_id',
            'created_at', 'updated_at',
        ],
        'category_assignment_heads' => [
            'post_id', 'assignment_version',
            'updated_by_user_public_id', 'updated_at',
        ],
        'category_assignment_workspaces' => [
            'post_id', 'base_assignment_version', 'workspace_version',
            'created_by_user_public_id', 'updated_by_user_public_id',
            'created_at', 'updated_at',
        ],
        'category_assignment_workspace_items' => [
            'post_id', 'category_id',
            'assigned_by_user_public_id', 'created_at',
        ],
        'publication_heads' => [
            'localization_id', 'revision_id', 'publication_version',
            'published_by_user_public_id', 'published_at',
        ],
    ];

    private readonly BlogEditorPreferencesMigrationPostconditionVerifier
        $baseVerifier;

    public function __construct(
        ?BlogEditorPreferencesMigrationPostconditionVerifier
            $baseVerifier = null
    ) {
        $this->baseVerifier = $baseVerifier
            ?? new BlogEditorPreferencesMigrationPostconditionVerifier(
                expectPrivateDraftPublicationExtension: true
            );
    }

    public function contractVersion(): string
    {
        return 'blog-private-draft-publication-schema-v1';
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
                && $this->checksAreExact($pdo, $scope, $driver)
                && $this->foreignKeysAreExact($pdo, $scope, $driver)
                && $this->indexesAreExact($pdo, $scope, $driver)
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
                $actual = array_map(
                    static fn (array $row): array => [
                        strtolower((string) ($row['name'] ?? '')),
                        strtoupper((string) ($row['type'] ?? '')),
                        (int) ($row['notnull'] ?? -1),
                        (int) ($row['pk'] ?? -1),
                        self::normalizedDefault($row['dflt_value'] ?? null),
                    ],
                    $rows
                );
                $definition = $pdo->prepare(
                    "SELECT sql FROM sqlite_master WHERE type = 'table' "
                        . 'AND name = :name'
                );
                $definition->execute(['name' => $scope->tableName($suffix)]);
                $sql = $definition->fetchColumn();
                if (
                    array_column($actual, 0) !== $expectedNames
                    || $actual !== $this->expectedSqliteColumns($suffix)
                    || !is_string($sql)
                    || stripos($sql, 'WITHOUT ROWID') === false
                ) {
                    return false;
                }
                continue;
            }

            $query = $pdo->prepare(
                'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, '
                    . 'COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH, '
                    . 'DATETIME_PRECISION, CHARACTER_SET_NAME, '
                    . 'COLLATION_NAME, EXTRA FROM information_schema.COLUMNS WHERE '
                    . 'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table '
                    . 'ORDER BY ORDINAL_POSITION'
            );
            $query->execute(['table' => $scope->tableName($suffix)]);
            $actual = array_map(
                static fn (array $row): array => [
                    strtolower((string) ($row['COLUMN_NAME'] ?? '')),
                    strtolower((string) ($row['DATA_TYPE'] ?? '')),
                    self::normalizedMySqlColumnType(
                        (string) ($row['COLUMN_TYPE'] ?? '')
                    ),
                    strtoupper((string) ($row['IS_NULLABLE'] ?? '')),
                    self::normalizedDefault($row['COLUMN_DEFAULT'] ?? null),
                    isset($row['CHARACTER_MAXIMUM_LENGTH'])
                        ? (int) $row['CHARACTER_MAXIMUM_LENGTH'] : null,
                    isset($row['DATETIME_PRECISION'])
                        ? (int) $row['DATETIME_PRECISION'] : null,
                    isset($row['CHARACTER_SET_NAME'])
                        ? strtolower((string) $row['CHARACTER_SET_NAME']) : null,
                    isset($row['COLLATION_NAME'])
                        ? strtolower((string) $row['COLLATION_NAME']) : null,
                    strtolower((string) ($row['EXTRA'] ?? '')),
                ],
                $query->fetchAll(PDO::FETCH_ASSOC)
            );
            if (
                array_column($actual, 0) !== $expectedNames
                || $actual !== $this->expectedMySqlColumns($suffix)
            ) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{string,string,int,int,?string}> */
    private function expectedSqliteColumns(string $suffix): array
    {
        $integer = static fn (
            string $name,
            int $nullable = 0,
            int $primary = 0,
            ?string $default = null
        ): array => [$name, 'INTEGER', $nullable === 0 ? 1 : 0, $primary, $default];
        $text = static fn (string $name): array => [$name, 'TEXT', 1, 0, null];

        return match ($suffix) {
            'editorial_workspaces' => [
                $integer('localization_id', 0, 1),
                $integer('draft_revision_id', 1),
                $integer('base_publication_version', 0, 0, '0'),
                $text('created_by_user_public_id'),
                $text('updated_by_user_public_id'),
                $text('created_at'), $text('updated_at'),
            ],
            'category_assignment_heads' => [
                $integer('post_id', 0, 1), $integer('assignment_version'),
                $text('updated_by_user_public_id'), $text('updated_at'),
            ],
            'category_assignment_workspaces' => [
                $integer('post_id', 0, 1),
                $integer('base_assignment_version', 0, 0, '0'),
                $integer('workspace_version'),
                $text('created_by_user_public_id'),
                $text('updated_by_user_public_id'),
                $text('created_at'), $text('updated_at'),
            ],
            'category_assignment_workspace_items' => [
                $integer('post_id', 0, 1),
                $integer('category_id', 0, 2),
                $text('assigned_by_user_public_id'), $text('created_at'),
            ],
            'publication_heads' => [
                $integer('localization_id', 0, 1),
                $integer('revision_id'), $integer('publication_version'),
                $text('published_by_user_public_id'), $text('published_at'),
            ],
            default => throw new \RuntimeException('Unknown table.'),
        };
    }

    /** @return list<array{string,string,string,string,?string,?int,?int,?string,?string,string}> */
    private function expectedMySqlColumns(string $suffix): array
    {
        $bigint = static fn (
            string $name,
            string $nullable = 'NO',
            ?string $default = null
        ): array => [$name, 'bigint', 'bigint unsigned', $nullable, $default,
            null, null, null, null, ''];
        $actor = static fn (string $name): array => [$name, 'char', 'char(36)',
            'NO', null, 36, null, 'ascii', 'ascii_bin', ''];
        $time = static fn (string $name): array => [$name, 'datetime',
            'datetime(6)', 'NO', null, null, 6, null, null, ''];

        return match ($suffix) {
            'editorial_workspaces' => [
                $bigint('localization_id'), $bigint('draft_revision_id', 'YES'),
                $bigint('base_publication_version', 'NO', '0'),
                $actor('created_by_user_public_id'),
                $actor('updated_by_user_public_id'),
                $time('created_at'), $time('updated_at'),
            ],
            'category_assignment_heads' => [
                $bigint('post_id'), $bigint('assignment_version'),
                $actor('updated_by_user_public_id'), $time('updated_at'),
            ],
            'category_assignment_workspaces' => [
                $bigint('post_id'),
                $bigint('base_assignment_version', 'NO', '0'),
                $bigint('workspace_version'),
                $actor('created_by_user_public_id'),
                $actor('updated_by_user_public_id'),
                $time('created_at'), $time('updated_at'),
            ],
            'category_assignment_workspace_items' => [
                $bigint('post_id'), $bigint('category_id'),
                $actor('assigned_by_user_public_id'), $time('created_at'),
            ],
            'publication_heads' => [
                $bigint('localization_id'), $bigint('revision_id'),
                $bigint('publication_version'),
                $actor('published_by_user_public_id'), $time('published_at'),
            ],
            default => throw new \RuntimeException('Unknown table.'),
        };
    }

    private function checksAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $required = [
            'editorial_workspaces' => [
                'updated_at>=created_at',
            ],
            'category_assignment_heads' => [
                'assignment_version>0',
            ],
            'category_assignment_workspaces' => [
                'workspace_version>0', 'updated_at>=created_at',
            ],
            'category_assignment_workspace_items' => [],
            'publication_heads' => [
                'publication_version>0',
            ],
        ];
        $actors = [
            'editorial_workspaces' => [
                'created_by_user_public_id', 'updated_by_user_public_id',
            ],
            'category_assignment_heads' => ['updated_by_user_public_id'],
            'category_assignment_workspaces' => [
                'created_by_user_public_id', 'updated_by_user_public_id',
            ],
            'category_assignment_workspace_items' => [
                'assigned_by_user_public_id',
            ],
            'publication_heads' => ['published_by_user_public_id'],
        ];
        $expectedCheckCounts = $driver === 'sqlite'
            ? [
                'editorial_workspaces' => 4,
                'category_assignment_heads' => 2,
                'category_assignment_workspaces' => 5,
                'category_assignment_workspace_items' => 1,
                'publication_heads' => 2,
            ]
            : [
                'editorial_workspaces' => 3,
                'category_assignment_heads' => 2,
                'category_assignment_workspaces' => 4,
                'category_assignment_workspace_items' => 1,
                'publication_heads' => 2,
            ];
        foreach ($required as $suffix => $needles) {
            if ($driver === 'sqlite') {
                $statement = $pdo->prepare(
                    "SELECT sql FROM sqlite_master WHERE type = 'table' "
                        . 'AND name = :name'
                );
                $statement->execute(['name' => $scope->tableName($suffix)]);
                $sql = $statement->fetchColumn();
            } else {
                $row = $pdo->query(
                    'SHOW CREATE TABLE '
                        . $scope->quotedTable($suffix, 'mysql')
                )->fetch(PDO::FETCH_NUM);
                $sql = is_array($row) ? ($row[1] ?? null) : null;
            }
            if (!is_string($sql)) {
                return false;
            }
            $canonical = self::canonicalSql($sql);
            $checkCount = preg_match_all('/\bcheck\s*\(/i', $sql);
            if ($checkCount !== $expectedCheckCounts[$suffix]) {
                return false;
            }
            foreach ($needles as $needle) {
                if (!str_contains($canonical, self::canonicalSql($needle))) {
                    return false;
                }
            }
            foreach ($actors[$suffix] as $actor) {
                $needle = $driver === 'sqlite'
                    ? 'length(' . $actor . ')=36and' . $actor
                        . '=lower(' . $actor . ')'
                    : $actor . "regexp'^[0-9a-f]{8}-";
                if (!str_contains(
                    $canonical,
                    self::canonicalSql($needle)
                )) {
                    return false;
                }
            }
            if ($driver === 'sqlite') {
                $extra = $suffix === 'editorial_workspaces'
                    ? 'base_publication_version>=0'
                    : ($suffix === 'category_assignment_workspaces'
                        ? 'base_assignment_version>=0' : null);
                if ($extra !== null && !str_contains(
                    $canonical,
                    self::canonicalSql($extra)
                )) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function normalizedDefault(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value, "'\"");

        if (strcasecmp($value, 'null') === 0) {
            return null;
        }

        return strtolower($value);
    }

    private static function normalizedMySqlColumnType(string $value): string
    {
        $normalized = strtolower(trim($value));

        return (string) preg_replace(
            '/\b(bigint|int|smallint|tinyint|mediumint)\(\d+\)/',
            '$1',
            $normalized
        );
    }

    private static function canonicalSql(string $value): string
    {
        return (string) preg_replace('/[\s`"()]+/u', '', strtolower($value));
    }

    private function foreignKeysAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $expected = [
            'editorial_workspaces' => [
                'draft_revision_id>content_revisions.id:RESTRICT',
                'localization_id>post_localizations.id:CASCADE',
            ],
            'category_assignment_heads' => [
                'post_id>posts.id:CASCADE',
            ],
            'category_assignment_workspaces' => [
                'post_id>posts.id:CASCADE',
            ],
            'category_assignment_workspace_items' => [
                'category_id>categories.id:RESTRICT',
                'post_id>category_assignment_workspaces.post_id:CASCADE',
            ],
            'publication_heads' => [
                'localization_id>post_localizations.id:CASCADE',
                'revision_id>content_revisions.id:RESTRICT',
            ],
        ];
        foreach ($expected as $suffix => $wanted) {
            if ($driver === 'sqlite') {
                $rows = $pdo->query(
                    'PRAGMA foreign_key_list('
                        . $scope->quotedTable($suffix, 'sqlite') . ')'
                )->fetchAll(PDO::FETCH_ASSOC);
                $actual = array_map(
                    function (array $row) use ($scope): string {
                        $target = strtolower((string) ($row['table'] ?? ''));
                        $prefix = strtolower($scope->tablePrefix());
                        if (!str_starts_with($target, $prefix)) {
                            return 'invalid';
                        }

                        return strtolower((string) ($row['from'] ?? ''))
                            . '>' . substr($target, strlen($prefix)) . '.'
                            . strtolower((string) ($row['to'] ?? '')) . ':'
                            . strtoupper((string) ($row['on_delete'] ?? ''));
                    },
                    $rows
                );
            } else {
                $query = $pdo->prepare(
                    'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, '
                        . 'k.REFERENCED_COLUMN_NAME, r.DELETE_RULE FROM '
                        . 'information_schema.KEY_COLUMN_USAGE k JOIN '
                        . 'information_schema.REFERENTIAL_CONSTRAINTS r ON '
                        . 'r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND '
                        . 'r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE '
                        . 'k.TABLE_SCHEMA = DATABASE() AND '
                        . 'k.TABLE_NAME = :table AND '
                        . 'k.REFERENCED_TABLE_NAME IS NOT NULL'
                );
                $query->execute(['table' => $scope->tableName($suffix)]);
                $actual = array_map(
                    function (array $row) use ($scope): string {
                        $target = strtolower(
                            (string) ($row['REFERENCED_TABLE_NAME'] ?? '')
                        );
                        $prefix = strtolower($scope->tablePrefix());
                        if (!str_starts_with($target, $prefix)) {
                            return 'invalid';
                        }

                        return strtolower((string) ($row['COLUMN_NAME'] ?? ''))
                            . '>' . substr($target, strlen($prefix)) . '.'
                            . strtolower((string) (
                                $row['REFERENCED_COLUMN_NAME'] ?? ''
                            )) . ':'
                            . strtoupper((string) ($row['DELETE_RULE'] ?? ''));
                    },
                    $query->fetchAll(PDO::FETCH_ASSOC)
                );
            }
            sort($actual, SORT_STRING);
            sort($wanted, SORT_STRING);
            if ($actual !== $wanted) {
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
        $workspace = $this->indexColumns(
            $pdo,
            $scope,
            $driver,
            'editorial_workspaces'
        );
        $categories = $this->indexColumns(
            $pdo,
            $scope,
            $driver,
            'category_assignment_workspace_items'
        );
        $categoryWorkspaces = $this->indexColumns(
            $pdo,
            $scope,
            $driver,
            'category_assignment_workspaces'
        );
        $categoryHeads = $this->indexColumns(
            $pdo,
            $scope,
            $driver,
            'category_assignment_heads'
        );
        $heads = $this->indexColumns(
            $pdo,
            $scope,
            $driver,
            'publication_heads'
        );

        return in_array('u:draft_revision_id', $workspace, true)
            && in_array('u:localization_id', $workspace, true)
            && in_array('u:post_id,category_id', $categories, true)
            && in_array('n:category_id', $categories, true)
            && in_array('u:post_id', $categoryWorkspaces, true)
            && in_array('u:post_id', $categoryHeads, true)
            && in_array('u:localization_id', $heads, true)
            && in_array('u:revision_id', $heads, true);
    }

    /** @return list<string> */
    private function indexColumns(
        PDO $pdo,
        MigrationScope $scope,
        string $driver,
        string $suffix
    ): array {
        $indexes = [];
        if ($driver === 'sqlite') {
            $rows = $pdo->query(
                'PRAGMA index_list('
                    . $scope->quotedTable($suffix, 'sqlite') . ')'
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $name = (string) ($row['name'] ?? '');
                $columns = $pdo->query(
                    'PRAGMA index_info(' . $this->quoteSqlite($name) . ')'
                )->fetchAll(PDO::FETCH_ASSOC);
                $indexes[] = ((int) ($row['unique'] ?? 0) === 1 ? 'u:' : 'n:')
                    . implode(',', array_map(
                        static fn (array $column): string => strtolower(
                            (string) ($column['name'] ?? '')
                        ),
                        $columns
                    ));
            }
        } else {
            $query = $pdo->prepare(
                'SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM '
                    . 'information_schema.STATISTICS WHERE TABLE_SCHEMA = '
                    . 'DATABASE() AND TABLE_NAME = :table ORDER BY '
                    . 'INDEX_NAME, SEQ_IN_INDEX'
            );
            $query->execute(['table' => $scope->tableName($suffix)]);
            $grouped = [];
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = (string) ($row['INDEX_NAME'] ?? '');
                $grouped[$name]['unique'] =
                    (int) ($row['NON_UNIQUE'] ?? 1) === 0;
                $grouped[$name]['columns'][] = strtolower(
                    (string) ($row['COLUMN_NAME'] ?? '')
                );
            }
            foreach ($grouped as $index) {
                $indexes[] = (($index['unique'] ?? false) ? 'u:' : 'n:')
                    . implode(',', $index['columns'] ?? []);
            }
        }
        sort($indexes, SORT_STRING);

        return $indexes;
    }

    private function hasNoTriggers(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        foreach (array_keys(self::COLUMNS) as $suffix) {
            $query = $driver === 'sqlite'
                ? $pdo->prepare(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' "
                        . 'AND tbl_name = :table'
                )
                : $pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE '
                        . 'TRIGGER_SCHEMA = DATABASE() AND '
                        . 'EVENT_OBJECT_TABLE = :table'
                );
            $query->execute(['table' => $scope->tableName($suffix)]);
            if ((int) $query->fetchColumn() !== 0) {
                return false;
            }
        }

        return true;
    }

    private function dataIsValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $workspaces = $scope->quotedTable('editorial_workspaces', $driver);
        $categoryWorkspaces = $scope->quotedTable(
            'category_assignment_workspaces',
            $driver
        );
        $heads = $scope->quotedTable('publication_heads', $driver);
        $categoryHeads = $scope->quotedTable(
            'category_assignment_heads',
            $driver
        );
        $localizations = $scope->quotedTable('post_localizations', $driver);
        $revisions = $scope->quotedTable('content_revisions', $driver);

        $invalidWorkspace = $pdo->query(
            'SELECT COUNT(*) FROM ' . $workspaces . ' w JOIN '
                . $localizations . ' l ON l.id = w.localization_id LEFT JOIN '
                . $revisions . ' r ON r.id = w.draft_revision_id LEFT JOIN '
                . $heads . ' h ON h.localization_id = w.localization_id WHERE '
                . "l.status <> 'published' OR (w.draft_revision_id IS NOT NULL "
                . 'AND (r.id IS NULL OR r.localization_id <> w.localization_id)) '
                . 'OR (h.localization_id IS NULL AND '
                . 'w.base_publication_version <> 0) OR '
                . '(h.localization_id IS NOT NULL AND '
                . 'h.publication_version <> w.base_publication_version)'
        )->fetchColumn();
        $invalidHead = $pdo->query(
            'SELECT COUNT(*) FROM ' . $heads . ' h JOIN ' . $localizations
                . ' l ON l.id = h.localization_id LEFT JOIN ' . $revisions
                . ' r ON r.id = h.revision_id WHERE '
                . "l.status <> 'published' OR r.id IS NULL OR "
                . 'r.localization_id <> h.localization_id'
        )->fetchColumn();
        $invalidCategoryWorkspaces = $pdo->query(
            'SELECT COUNT(*) FROM ' . $categoryWorkspaces . ' w LEFT JOIN '
                . $categoryHeads . ' h ON h.post_id = w.post_id WHERE '
                . 'w.base_assignment_version < 0 OR w.workspace_version < 1 '
                . 'OR (h.post_id IS NULL AND '
                . 'w.base_assignment_version <> 0) OR (h.post_id IS NOT NULL '
                . 'AND h.assignment_version <> w.base_assignment_version)'
        )->fetchColumn();
        $invalidCategoryHeads = $pdo->query(
            'SELECT COUNT(*) FROM ' . $categoryHeads
                . ' WHERE assignment_version < 1'
        )->fetchColumn();

        return (int) $invalidWorkspace === 0
            && (int) $invalidHead === 0
            && (int) $invalidCategoryWorkspaces === 0
            && (int) $invalidCategoryHeads === 0;
    }

    private function quoteSqlite(string $identifier): string
    {
        if ($identifier === '' || str_contains($identifier, "\0")) {
            throw new \RuntimeException('Invalid identifier.');
        }

        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
