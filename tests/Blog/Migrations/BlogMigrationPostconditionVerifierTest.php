<?php

declare(strict_types=1);

namespace Tests\Blog\Migrations;

use App\Core\Modules\Blog\BlogInitialSchemaContract;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class BlogMySqlMetadataStatement extends PDOStatement
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        private readonly array $rows = [],
        private readonly mixed $scalar = null
    ) {
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetchAll(
        int $mode = PDO::FETCH_DEFAULT,
        mixed ...$args
    ): array {
        return $this->rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->scalar;
    }
}

final class BlogMySqlMetadataPdo extends PDO
{
    public string $version = '10.4.32-MariaDB';
    /** @var list<array<string, mixed>> */
    public array $tables = [];
    /** @var list<array<string, mixed>> */
    public array $columns = [];
    /** @var list<array<string, mixed>> */
    public array $indexes = [];
    /** @var list<array<string, mixed>> */
    public array $foreignKeys = [];
    /** @var list<array<string, mixed>> */
    public array $checks = [];
    public int $triggerCount = 0;
    public int $integrityViolations = 0;
    public string $checkConstraintChecks = '1';
    public string $foreignKeyChecks = '1';
    public string $uniqueChecks = '1';
    public string $sqlMode = 'STRICT_TRANS_TABLES';

    public function __construct()
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : null;
    }

    public function query(
        string $query,
        ?int $fetchMode = null,
        mixed ...$fetchModeArgs
    ): PDOStatement|false {
        if (str_contains($query, 'SELECT VERSION()')) {
            return new BlogMySqlMetadataStatement(scalar: $this->version);
        }
        foreach ([
            'check_constraint_checks' => $this->checkConstraintChecks,
            'foreign_key_checks' => $this->foreignKeyChecks,
            'unique_checks' => $this->uniqueChecks,
            'sql_mode' => $this->sqlMode,
        ] as $setting => $value) {
            if (str_contains($query, '@@SESSION.' . $setting)) {
                return new BlogMySqlMetadataStatement(scalar: $value);
            }
        }

        return new BlogMySqlMetadataStatement(
            scalar: $this->integrityViolations
        );
    }

    public function prepare(
        string $query,
        array $options = []
    ): PDOStatement|false {
        return match (true) {
            str_contains($query, 'information_schema.TABLES') =>
                new BlogMySqlMetadataStatement($this->tables),
            str_contains($query, 'information_schema.COLUMNS') =>
                new BlogMySqlMetadataStatement($this->columns),
            str_contains($query, 'information_schema.STATISTICS') =>
                new BlogMySqlMetadataStatement($this->indexes),
            str_contains($query, 'REFERENTIAL_CONSTRAINTS') =>
                new BlogMySqlMetadataStatement($this->foreignKeys),
            str_contains($query, 'CHECK_CONSTRAINTS') =>
                new BlogMySqlMetadataStatement($this->checks),
            str_contains($query, 'information_schema.TRIGGERS') =>
                new BlogMySqlMetadataStatement(scalar: $this->triggerCount),
            default => false,
        };
    }
}

final class BlogMigrationPostconditionVerifierTest extends TestCase
{
    public function testVerificationIsReadOnlyAndSupportsCustomPrefix(): void
    {
        $pdo = $this->sqlite();
        $scope = MigrationScope::forTablePrefix('blog', 'customer_articles_');
        $migration = $this->blogMigrations()[0];
        $this->apply($pdo, $migration, $scope);
        $before = $this->sqliteSnapshot($pdo);

        self::assertTrue($migration->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
        self::assertTrue($migration->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
        self::assertSame($before, $this->sqliteSnapshot($pdo));
    }

    public function testGlobalMigrationRegistryIsOutsideTheBlogNamespace(): void
    {
        $pdo = $this->sqlite();
        $scope = MigrationScope::forTablePrefix('blog', 'ls_');
        $migration = $this->blogMigrations()[0];
        $pdo->exec(
            'CREATE TABLE "ls_module_migrations" '
            . '("module_id" TEXT NOT NULL)'
        );
        $this->apply($pdo, $migration, $scope);

        self::assertTrue($migration->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
    }

    public function testWrongScopeAndDisabledIntegrityGatesFailClosed(): void
    {
        $pdo = $this->sqlite();
        $scope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $migration = $this->blogMigrations()[0];
        $this->apply($pdo, $migration, $scope);
        $verifier = $migration->postconditionVerifier();

        self::assertFalse($verifier?->verify(
            $pdo,
            MigrationScope::forTablePrefix('webadmin', 'ls_blog_')
        ));
        $pdo->exec('PRAGMA foreign_keys = OFF');
        self::assertFalse($verifier?->verify($pdo, $scope));
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA ignore_check_constraints = ON');
        self::assertFalse($verifier?->verify($pdo, $scope));
        $pdo->exec('PRAGMA ignore_check_constraints = OFF');
        self::assertTrue($verifier?->verify($pdo, $scope));
    }

    public function testMissingOrExtraSchemaObjectsAreRejected(): void
    {
        $scope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $migration = $this->blogMigrations()[0];

        $missing = $this->sqlite();
        $this->apply($missing, $migration, $scope);
        $missing->exec('DROP INDEX "ls_blog_ix_pl_state"');
        self::assertFalse($migration->postconditionVerifier()?->verify(
            $missing,
            $scope
        ));

        $extra = $this->sqlite();
        $this->apply($extra, $migration, $scope);
        $extra->exec(
            'CREATE INDEX "local_extra" ON "ls_blog_posts" ("updated_at")'
        );
        self::assertFalse($migration->postconditionVerifier()?->verify(
            $extra,
            $scope
        ));

        $trigger = $this->sqlite();
        $this->apply($trigger, $migration, $scope);
        $trigger->exec(
            'CREATE TRIGGER "ls_blog_forbidden" AFTER UPDATE '
            . 'ON "ls_blog_posts" BEGIN SELECT 1; END'
        );
        self::assertFalse($migration->postconditionVerifier()?->verify(
            $trigger,
            $scope
        ));
    }

    public function testStoredRowDriftIsRejectedEvenAfterChecksAreRestored(): void
    {
        $pdo = $this->sqlite();
        $scope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $migration = $this->blogMigrations()[0];
        $this->apply($pdo, $migration, $scope);

        $pdo->exec('PRAGMA ignore_check_constraints = ON');
        $pdo->exec(
            'INSERT INTO "ls_blog_posts" '
            . '("public_id", "created_by_user_public_id") '
            . "VALUES ('short', 'also-short')"
        );
        $pdo->exec('PRAGMA ignore_check_constraints = OFF');
        self::assertFalse($migration->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
    }

    public function testSqliteCheckExtractorIgnoresCommentDecoysAndPreservesLiterals(): void
    {
        $verifier = $this->blogMigrations()[0]->postconditionVerifier();
        self::assertNotNull($verifier);
        $method = new \ReflectionMethod(
            $verifier,
            'extractCheckExpressions'
        );
        $expressions = $method->invoke($verifier, <<<'SQL'
CREATE TABLE sample (
    "note" TEXT CHECK(note IN ('--keep-literal', '/*keep-literal*/')),
    "CHECK(quoted_decoy = 1)" TEXT,
    /* CHECK(block_decoy = 1) */
    -- CHECK(line_decoy = 1)
    "value" INTEGER CHECK(value > 0)
)
SQL);

        self::assertCount(2, $expressions);
        $actual = implode("\n", $expressions);
        self::assertStringContainsString("'--keep-literal'", $actual);
        self::assertStringContainsString("'/*keep-literal*/'", $actual);
        self::assertStringNotContainsString('quoted_decoy', $actual);
        self::assertStringNotContainsString('block_decoy', $actual);
        self::assertStringNotContainsString('line_decoy', $actual);
    }

    public function testSqliteDefaultStringLiteralCaseIsSignificant(): void
    {
        $postDefaults = [];
        foreach (BlogInitialSchemaContract::sqliteColumns()['posts'] as $column) {
            $postDefaults[$column['name']] = $column['default'];
        }
        self::assertSame(
            "strftime('%Y-%m-%d %H:%M:%f000','now')",
            $postDefaults['created_at']
        );

        $pdo = $this->sqlite();
        $scope = MigrationScope::forTablePrefix('blog', 'case_blog_');
        $migration = $this->blogMigrations()[0];
        $replacements = 0;
        foreach ($migration->statementsFor('sqlite', $scope) as $statement) {
            $statement = str_replace(
                "DEFAULT 'draft'",
                "DEFAULT 'DRAFT'",
                $statement,
                $count
            );
            $replacements += $count;
            $pdo->exec($statement);
        }

        self::assertSame(1, $replacements);
        self::assertFalse($migration->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
    }

    public function testPartialNamespaceFailsBothPreAndPostconditions(): void
    {
        $pdo = $this->sqlite();
        $scope = MigrationScope::forTablePrefix('blog', 'partial_blog_');
        $migration = $this->blogMigrations()[0];
        $pdo->exec(<<<'SQL'
CREATE TABLE partial_blog_posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL
)
SQL);

        self::assertFalse($migration->preconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
        self::assertFalse($migration->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
    }

    public function testMysqlMetadataContractAcceptsExactStateAndRejectsDrift(): void
    {
        $scope = MigrationScope::forTablePrefix('blog', 'tenant_blog_');
        $migration = $this->blogMigrations()[0];
        $verifier = $migration->postconditionVerifier();
        $pdo = $this->mysqlMetadataPdo($scope);

        self::assertTrue($verifier?->verify($pdo, $scope));

        $mySql = $this->mysqlMetadataPdo($scope);
        $mySql->version = '8.0.36';
        foreach ($mySql->columns as &$column) {
            if (
                strtolower((string) ($column['COLUMN_DEFAULT'] ?? ''))
                    === 'current_timestamp(6)'
            ) {
                $column['EXTRA'] = 'DEFAULT_GENERATED';
            }
        }
        unset($column);
        foreach ($mySql->checks as &$check) {
            $constraint = (string) $check['CONSTRAINT_NAME'];
            if (str_ends_with($constraint, 'c_pl_locale')) {
                $check['CHECK_CLAUSE'] = '((char_length(`locale`) '
                    . 'between 2 and 16) and (`locale` = lcase(`locale`)) '
                    . 'and (`locale` = trim(`locale`)))';
            } elseif (str_ends_with($constraint, 'c_pl_slug')) {
                $check['CHECK_CLAUSE'] = '(isnull(`slug`) or '
                    . '((char_length(trim(`slug`)) > 0) '
                    . 'and (`slug` = lcase(`slug`)) '
                    . 'and (`slug` = trim(`slug`))))';
            }
        }
        unset($check);
        self::assertTrue($verifier?->verify($mySql, $scope));

        $pdo->columns[0]['DATA_TYPE'] = 'int';
        self::assertFalse($verifier?->verify($pdo, $scope));
        $pdo = $this->mysqlMetadataPdo($scope);
        $pdo->columns[array_key_last($pdo->columns)]['EXTRA'] =
            'on update CURRENT_TIMESTAMP(6)';
        self::assertFalse($verifier?->verify($pdo, $scope));
        $pdo = $this->mysqlMetadataPdo($scope);
        $pdo->indexes[] = [
            'TABLE_NAME' => $scope->tableName('posts'),
            'INDEX_NAME' => 'unexpected_index',
            'NON_UNIQUE' => 1,
            'SEQ_IN_INDEX' => 1,
            'COLUMN_NAME' => 'updated_at',
            'INDEX_TYPE' => 'BTREE',
            'SUB_PART' => null,
            'COLLATION' => 'A',
            'IGNORED' => 'NO',
        ];
        self::assertFalse($verifier?->verify($pdo, $scope));
        $pdo = $this->mysqlMetadataPdo($scope);
        $pdo->checks[0]['CHECK_CLAUSE'] = 'char_length(public_id) = 35';
        self::assertFalse($verifier?->verify($pdo, $scope));
        $pdo = $this->mysqlMetadataPdo($scope);
        $pdo->version = '8.0.36';
        $pdo->checks[0]['ENFORCED'] = 'NO';
        self::assertFalse($verifier?->verify($pdo, $scope));
        $pdo = $this->mysqlMetadataPdo($scope);
        $pdo->integrityViolations = 1;
        self::assertFalse($verifier?->verify($pdo, $scope));
        $pdo = $this->mysqlMetadataPdo($scope);
        $pdo->foreignKeyChecks = '0';
        self::assertFalse($verifier?->verify($pdo, $scope));
        $pdo = $this->mysqlMetadataPdo($scope);
        $pdo->foreignKeys[0]['SAME_SCHEMA'] = 0;
        self::assertFalse($verifier?->verify($pdo, $scope));
        $pdo = $this->mysqlMetadataPdo($scope);
        $pdo->sqlMode = '';
        self::assertFalse($verifier?->verify($pdo, $scope));
        $pdo = $this->mysqlMetadataPdo($scope);
        $pdo->version = '5.7.44';
        self::assertFalse($verifier?->verify($pdo, $scope));
    }

    public function testCapabilityPostconditionPreservesMetadataDriftButRepairsMissingEdges(): void
    {
        $pdo = $this->sqlite();
        $scope = MigrationScope::forTablePrefix('webadmin', 'ls_webadmin_');
        $webAdmin = iterator_to_array(
            WebAdminMigrationProvider::migrations(),
            false
        )[0];
        $this->apply($pdo, $webAdmin, $scope);
        $capabilities = $this->blogMigrations()[1];
        $this->apply($pdo, $capabilities, $scope);
        self::assertTrue($capabilities->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));

        $pdo->exec(
            'UPDATE "ls_webadmin_capabilities" SET "is_delegable" = 0 '
            . "WHERE \"code\" = 'blog.articles.publish'"
        );
        self::assertFalse($capabilities->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
        $this->apply($pdo, $capabilities, $scope);
        self::assertFalse($capabilities->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
        self::assertSame(0, (int) $pdo->query(
            "SELECT is_delegable FROM ls_webadmin_capabilities "
            . "WHERE code = 'blog.articles.publish'"
        )->fetchColumn());
        $pdo->exec(
            'UPDATE "ls_webadmin_capabilities" SET "is_delegable" = 1 '
            . "WHERE \"code\" = 'blog.articles.publish'"
        );
        self::assertTrue($capabilities->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));

        $pdo->exec(
            'DELETE FROM "ls_webadmin_role_capabilities" WHERE role_id = '
            . '(SELECT id FROM "ls_webadmin_roles" '
            . "WHERE code = 'site_admin') AND capability_id = "
            . '(SELECT id FROM "ls_webadmin_capabilities" '
            . "WHERE code = 'blog.articles.view')"
        );
        self::assertFalse($capabilities->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
        $this->apply($pdo, $capabilities, $scope);
        self::assertTrue($capabilities->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
    }

    /** @return list<MigrationDefinition> */
    private function blogMigrations(): array
    {
        return iterator_to_array(BlogMigrationProvider::migrations(), false);
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function apply(
        PDO $pdo,
        MigrationDefinition $migration,
        MigrationScope $scope
    ): void {
        foreach ($migration->statementsFor('sqlite', $scope) as $statement) {
            $pdo->exec($statement);
        }
    }

    /** @return array<string, mixed> */
    private function sqliteSnapshot(PDO $pdo): array
    {
        return [
            'changes' => (int) $pdo->query('SELECT total_changes()')->fetchColumn(),
            'schema' => $pdo->query(
                'SELECT type, name, tbl_name, sql FROM sqlite_master '
                . 'ORDER BY type, name'
            )->fetchAll(PDO::FETCH_ASSOC),
            'posts' => $pdo->query(
                'SELECT * FROM "customer_articles_posts" ORDER BY id'
            )->fetchAll(PDO::FETCH_ASSOC),
            'localizations' => $pdo->query(
                'SELECT * FROM "customer_articles_post_localizations" '
                . 'ORDER BY id'
            )->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    private function mysqlMetadataPdo(
        MigrationScope $scope
    ): BlogMySqlMetadataPdo {
        $pdo = new BlogMySqlMetadataPdo();
        foreach (BlogInitialSchemaContract::tableSuffixes() as $suffix) {
            $table = $scope->tableName($suffix);
            $pdo->tables[] = [
                'TABLE_NAME' => $table,
                'TABLE_TYPE' => 'BASE TABLE',
                'ENGINE' => 'InnoDB',
                'TABLE_COLLATION' => 'utf8mb4_unicode_ci',
            ];
            foreach (
                BlogInitialSchemaContract::mysqlColumns()[$suffix]
                as $position => $column
            ) {
                $pdo->columns[] = [
                    'TABLE_NAME' => $table,
                    'COLUMN_NAME' => $column['name'],
                    'ORDINAL_POSITION' => $position + 1,
                    'DATA_TYPE' => $column['type'],
                    'COLUMN_TYPE' => $column['type']
                        . ($column['unsigned'] ? ' unsigned' : ''),
                    'IS_NULLABLE' => $column['nullable'] ? 'YES' : 'NO',
                    'CHARACTER_MAXIMUM_LENGTH' => $column['length'],
                    'DATETIME_PRECISION' => $column['datetime_precision'],
                    'CHARACTER_SET_NAME' => $column['charset'],
                    'COLLATION_NAME' => $column['collation'],
                    'COLUMN_DEFAULT' => $column['default'],
                    'EXTRA' => $column['extra'],
                ];
            }
            $pdo->indexes[] = [
                'TABLE_NAME' => $table,
                'INDEX_NAME' => 'PRIMARY',
                'NON_UNIQUE' => 0,
                    'SEQ_IN_INDEX' => 1,
                    'COLUMN_NAME' => 'id',
                    'INDEX_TYPE' => 'BTREE',
                    'SUB_PART' => null,
                    'COLLATION' => 'A',
                    'IGNORED' => 'NO',
            ];
            foreach (
                BlogInitialSchemaContract::indexes()[$suffix]
                as $indexPosition => $index
            ) {
                foreach ($index['columns'] as $columnPosition => $column) {
                    $pdo->indexes[] = [
                        'TABLE_NAME' => $table,
                        'INDEX_NAME' => 'idx_fixture_'
                            . $suffix . '_' . $indexPosition,
                        'NON_UNIQUE' => $index['unique'] ? 0 : 1,
                        'SEQ_IN_INDEX' => $columnPosition + 1,
                        'COLUMN_NAME' => $column,
                        'INDEX_TYPE' => 'BTREE',
                        'SUB_PART' => null,
                        'COLLATION' => 'A',
                        'IGNORED' => 'NO',
                    ];
                }
            }
            foreach (
                BlogInitialSchemaContract::mysqlChecks()[$suffix]
                as $constraintSuffix => $expression
            ) {
                $pdo->checks[] = [
                    'TABLE_NAME' => $table,
                    'CONSTRAINT_NAME' => $scope->tableName($constraintSuffix),
                    'CHECK_CLAUSE' => $expression,
                    'ENFORCED' => 'YES',
                ];
            }
        }
        $pdo->foreignKeys[] = [
            'TABLE_NAME' => $scope->tableName('post_localizations'),
            'COLUMN_NAME' => 'post_id',
            'REFERENCED_TABLE_NAME' => $scope->tableName('posts'),
            'REFERENCED_COLUMN_NAME' => 'id',
            'ORDINAL_POSITION' => 1,
            'SAME_SCHEMA' => 1,
            'UPDATE_RULE' => 'RESTRICT',
            'DELETE_RULE' => 'CASCADE',
        ];

        return $pdo;
    }
}
