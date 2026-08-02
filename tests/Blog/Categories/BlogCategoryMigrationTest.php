<?php

declare(strict_types=1);

namespace Tests\Blog\Categories;

use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Modules\Blog\BlogCategoryCapabilitySeedPostcondition;
use App\Core\Modules\Blog\BlogCategoryMigrationPostconditionVerifier;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use PDO;
use PHPUnit\Framework\TestCase;

final class BlogCategoryMigrationTest extends TestCase
{
    public function testSchemaAndCapabilitiesAreAdditiveAndIdempotent(): void
    {
        $pdo = $this->pdo();
        $blog = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $admin = MigrationScope::forTablePrefix('webadmin', 'ls_webadmin_');
        $blogMigrations = iterator_to_array(
            BlogMigrationProvider::migrations(),
            false
        );
        $webAdminMigrations = iterator_to_array(
            WebAdminMigrationProvider::migrations(),
            false
        );
        $this->apply($pdo, $webAdminMigrations[0], $admin);
        $this->apply($pdo, $blogMigrations[0], $blog);
        $this->apply($pdo, $blogMigrations[1], $admin);
        $this->apply($pdo, $blogMigrations[2], $blog);
        $this->apply($pdo, $blogMigrations[2], $blog);
        $this->apply($pdo, $blogMigrations[3], $admin);
        $this->apply($pdo, $blogMigrations[3], $admin);

        self::assertTrue((new BlogCategoryMigrationPostconditionVerifier())
            ->verify($pdo, $blog));
        self::assertTrue((new BlogCategoryCapabilitySeedPostcondition())
            ->verify($pdo, $admin));
        self::assertSame(
            ['0001_blog_posts'],
            $blogMigrations[2]->supersededPostconditionIds()
        );
        self::assertSame(
            ['0002_blog_capabilities'],
            $blogMigrations[3]->supersededPostconditionIds()
        );
    }

    public function testExactVerifierRejectsIndexCheckForeignKeyAndTriggerDrift(): void
    {
        $scope = MigrationScope::forTablePrefix('blog', 'drift_blog_');
        foreach (['index', 'check', 'foreign_key', 'trigger'] as $drift) {
            $pdo = $this->pdo();
            $migrations = iterator_to_array(
                BlogMigrationProvider::migrations(),
                false
            );
            $this->apply($pdo, $migrations[0], $scope);
            $this->apply($pdo, $migrations[2], $scope);
            self::assertTrue((new BlogCategoryMigrationPostconditionVerifier())
                ->verify($pdo, $scope), $drift);

            if ($drift === 'index') {
                $pdo->exec('DROP INDEX "drift_blog_ux_cl_locale_slug"');
            } elseif ($drift === 'trigger') {
                $pdo->exec(
                    'CREATE TRIGGER drift_blog_forbidden AFTER INSERT ON '
                    . '"drift_blog_categories" BEGIN SELECT 1; END'
                );
            } elseif ($drift === 'foreign_key') {
                $pdo->exec('PRAGMA foreign_keys = OFF');
            } else {
                $pdo->exec('PRAGMA ignore_check_constraints = ON');
            }
            self::assertFalse((new BlogCategoryMigrationPostconditionVerifier())
                ->verify($pdo, $scope), $drift);
        }
    }

    public function testPendingOrPartialCategorySchemaNeverSupersedesBase(): void
    {
        $pdo = $this->pdo();
        $scope = MigrationScope::forTablePrefix('blog', 'pending_blog_');
        $migrations = iterator_to_array(
            BlogMigrationProvider::migrations(),
            false
        );
        $this->apply($pdo, $migrations[0], $scope);

        self::assertTrue($migrations[0]->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
        self::assertFalse($migrations[2]->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));

        $pdo->exec(
            'CREATE TABLE "pending_blog_categories" '
            . '(id INTEGER PRIMARY KEY AUTOINCREMENT)'
        );
        self::assertFalse($migrations[0]->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
        self::assertFalse($migrations[2]->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
    }

    public function testDataVerifierRejectsRowsOrphanedWhileChecksWereOff(): void
    {
        $pdo = $this->pdo();
        $scope = MigrationScope::forTablePrefix('blog', 'orphan_blog_');
        $migrations = iterator_to_array(
            BlogMigrationProvider::migrations(),
            false
        );
        $this->apply($pdo, $migrations[0], $scope);
        $this->apply($pdo, $migrations[2], $scope);
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec(
            'INSERT INTO "orphan_blog_category_locales" '
            . '(public_id, category_id, locale, slug, name, '
            . 'created_by_user_public_id, updated_by_user_public_id) VALUES '
            . "('10000000-0000-4000-8000-000000000001', 999, 'es', "
            . "'orphan', 'Orphan', "
            . "'20000000-0000-4000-8000-000000000001', "
            . "'30000000-0000-4000-8000-000000000001')"
        );
        $pdo->exec('PRAGMA foreign_keys = ON');

        $method = new \ReflectionMethod(
            new BlogCategoryMigrationPostconditionVerifier(),
            'dataIsValid'
        );
        self::assertFalse($method->invoke(
            new BlogCategoryMigrationPostconditionVerifier(),
            $pdo,
            $scope,
            'sqlite'
        ));
    }

    public function testSqliteCheckExtractorIgnoresCommentDecoysAndPreservesLiterals(): void
    {
        $verifier = new BlogCategoryMigrationPostconditionVerifier();
        $method = new \ReflectionMethod($verifier, 'checkExpressions');
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

    public function testMariaDbAndMySqlColumnMetadataAreValidatedExactly(): void
    {
        $verifier = new BlogCategoryMigrationPostconditionVerifier();
        $method = new \ReflectionMethod($verifier, 'mysqlColumnsAreExact');
        $rows = $this->mysqlCategoryColumns();

        self::assertTrue($method->invoke(
            $verifier,
            'categories',
            $rows,
            true
        ));

        $mySqlRows = $rows;
        $mySqlRows[3]['EXTRA'] = 'DEFAULT_GENERATED';
        $mySqlRows[4]['EXTRA'] = 'DEFAULT_GENERATED';
        self::assertTrue($method->invoke(
            $verifier,
            'categories',
            $mySqlRows,
            false
        ));

        $missingPrimary = $rows;
        $missingPrimary[0]['COLUMN_KEY'] = '';
        self::assertFalse($method->invoke(
            $verifier,
            'categories',
            $missingPrimary,
            true
        ));

        $unsafeExtra = $rows;
        $unsafeExtra[4]['EXTRA'] = 'on update CURRENT_TIMESTAMP(6)';
        self::assertFalse($method->invoke(
            $verifier,
            'categories',
            $unsafeExtra,
            true
        ));
    }

    public function testMySqlIndexMetadataRequiresTheExactPrimaryShape(): void
    {
        $verifier = new BlogCategoryMigrationPostconditionVerifier();
        $method = new \ReflectionMethod(
            $verifier,
            'mysqlIndexSignaturesFromRows'
        );
        $rows = [
            $this->mysqlIndexRow('PRIMARY', 0, 1, 'id'),
            $this->mysqlIndexRow('uq_public', 0, 1, 'public_id'),
            $this->mysqlIndexRow(
                'ix_author',
                1,
                1,
                'created_by_user_public_id'
            ),
        ];
        $expected = [
            'p:id',
            '1:public_id',
            '0:created_by_user_public_id',
        ];
        sort($expected, SORT_STRING);
        $actual = $method->invoke($verifier, $rows);
        sort($actual, SORT_STRING);
        self::assertSame($expected, $actual);

        foreach ([
            ['INDEX_TYPE', 'HASH'],
            ['SUB_PART', 8],
            ['COLLATION', 'D'],
            ['INDEX_VISIBLE', 'NO'],
            ['INDEX_IGNORED', 'YES'],
            ['SEQ_IN_INDEX', 2],
        ] as [$field, $value]) {
            $drifted = $rows;
            $drifted[1][$field] = $value;
            self::assertSame(
                ['invalid'],
                $method->invoke($verifier, $drifted),
                (string) $field
            );
        }

        $swappedPrimary = $rows;
        $swappedPrimary[0]['COLUMN_NAME'] = 'public_id';
        $swappedPrimary[1]['COLUMN_NAME'] = 'id';
        self::assertNotSame(
            $expected,
            $method->invoke($verifier, $swappedPrimary)
        );
    }

    public function testMaximumSupportedPrefixKeepsEveryIdentifierPortable(): void
    {
        $prefix = 'b' . str_repeat(
            'x',
            BlogConfig::MAX_TABLE_PREFIX_LENGTH - 2
        ) . '_';
        $scope = MigrationScope::forTablePrefix('blog', $prefix);
        $migration = iterator_to_array(
            BlogMigrationProvider::migrations(),
            false
        )[2];

        foreach (['mysql', 'sqlite'] as $driver) {
            $sql = implode("\n", $migration->statementsFor($driver, $scope));
            preg_match_all('/[`"]([A-Za-z][A-Za-z0-9_]*)[`"]/', $sql, $matches);
            foreach ($matches[1] as $identifier) {
                self::assertLessThanOrEqual(64, strlen($identifier), $identifier);
            }
        }
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    /** @return list<array<string, mixed>> */
    private function mysqlCategoryColumns(): array
    {
        return [
            [
                'COLUMN_NAME' => 'id',
                'DATA_TYPE' => 'bigint',
                'COLUMN_TYPE' => 'bigint unsigned',
                'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => null,
                'CHARACTER_MAXIMUM_LENGTH' => null,
                'DATETIME_PRECISION' => null,
                'CHARACTER_SET_NAME' => null,
                'COLLATION_NAME' => null,
                'COLUMN_KEY' => 'PRI',
                'EXTRA' => 'auto_increment',
            ],
            [
                'COLUMN_NAME' => 'public_id',
                'DATA_TYPE' => 'char',
                'COLUMN_TYPE' => 'char(36)',
                'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => null,
                'CHARACTER_MAXIMUM_LENGTH' => 36,
                'DATETIME_PRECISION' => null,
                'CHARACTER_SET_NAME' => 'ascii',
                'COLLATION_NAME' => 'ascii_bin',
                'COLUMN_KEY' => 'UNI',
                'EXTRA' => '',
            ],
            [
                'COLUMN_NAME' => 'created_by_user_public_id',
                'DATA_TYPE' => 'char',
                'COLUMN_TYPE' => 'char(36)',
                'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => null,
                'CHARACTER_MAXIMUM_LENGTH' => 36,
                'DATETIME_PRECISION' => null,
                'CHARACTER_SET_NAME' => 'ascii',
                'COLLATION_NAME' => 'ascii_bin',
                'COLUMN_KEY' => 'MUL',
                'EXTRA' => '',
            ],
            [
                'COLUMN_NAME' => 'created_at',
                'DATA_TYPE' => 'datetime',
                'COLUMN_TYPE' => 'datetime(6)',
                'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => 'current_timestamp(6)',
                'CHARACTER_MAXIMUM_LENGTH' => null,
                'DATETIME_PRECISION' => 6,
                'CHARACTER_SET_NAME' => null,
                'COLLATION_NAME' => null,
                'COLUMN_KEY' => '',
                'EXTRA' => '',
            ],
            [
                'COLUMN_NAME' => 'updated_at',
                'DATA_TYPE' => 'datetime',
                'COLUMN_TYPE' => 'datetime(6)',
                'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => 'current_timestamp(6)',
                'CHARACTER_MAXIMUM_LENGTH' => null,
                'DATETIME_PRECISION' => 6,
                'CHARACTER_SET_NAME' => null,
                'COLLATION_NAME' => null,
                'COLUMN_KEY' => '',
                'EXTRA' => '',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function mysqlIndexRow(
        string $name,
        int $nonUnique,
        int $sequence,
        string $column
    ): array {
        return [
            'INDEX_NAME' => $name,
            'NON_UNIQUE' => $nonUnique,
            'SEQ_IN_INDEX' => $sequence,
            'COLUMN_NAME' => $column,
            'INDEX_TYPE' => 'BTREE',
            'SUB_PART' => null,
            'COLLATION' => 'A',
            'INDEX_VISIBLE' => 'YES',
            'INDEX_IGNORED' => 'NO',
        ];
    }

    private function apply(PDO $pdo, $migration, MigrationScope $scope): void
    {
        foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
            self::assertNotFalse($pdo->exec($sql));
        }
    }
}
