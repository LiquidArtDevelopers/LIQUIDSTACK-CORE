<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\MigrationException;
use App\Core\Modules\Migrations\MigrationRegistrySchemaValidator;
use PHPUnit\Framework\TestCase;

final class MigrationRegistrySchemaValidatorTest extends TestCase
{
    public function testAcceptsExactMysqlRegistryMetadata(): void
    {
        (new MigrationRegistrySchemaValidator())->assertMySql(
            self::mysqlColumns(),
            ['module_id', 'migration_id'],
            'InnoDB'
        );

        self::addToAssertionCount(1);
    }

    /** @dataProvider unsafeMysqlMetadataProvider */
    public function testRejectsUnsafeMysqlRegistryMetadata(
        array $columns,
        array $primary,
        mixed $engine
    ): void {
        $this->expectException(MigrationException::class);

        (new MigrationRegistrySchemaValidator())->assertMySql(
            $columns,
            $primary,
            $engine
        );
    }

    public static function unsafeMysqlMetadataProvider(): iterable
    {
        $columns = self::mysqlColumns();
        yield 'non transactional engine' => [
            $columns,
            ['module_id', 'migration_id'],
            'MyISAM',
        ];

        $wrongCollation = $columns;
        $wrongCollation[0]['collation_name'] = 'ascii_general_ci';
        yield 'case insensitive identity' => [
            $wrongCollation,
            ['module_id', 'migration_id'],
            'InnoDB',
        ];

        $nullable = $columns;
        $nullable[4]['is_nullable'] = 'YES';
        yield 'nullable batch' => [
            $nullable,
            ['module_id', 'migration_id'],
            'InnoDB',
        ];

        $wrongPrecision = $columns;
        $wrongPrecision[5]['datetime_precision'] = 0;
        yield 'timestamp precision drift' => [
            $wrongPrecision,
            ['module_id', 'migration_id'],
            'InnoDB',
        ];

        yield 'wrong primary key' => [
            $columns,
            ['module_id'],
            'InnoDB',
        ];
    }

    public function testAcceptsExactSqliteRegistryMetadata(): void
    {
        (new MigrationRegistrySchemaValidator())->assertSqlite(
            self::sqliteColumns(),
            self::sqliteCreateSql()
        );

        self::addToAssertionCount(1);
    }

    /** @dataProvider unsafeSqliteMetadataProvider */
    public function testRejectsUnsafeSqliteRegistryMetadata(
        array $columns,
        string $sql
    ): void {
        $this->expectException(MigrationException::class);

        (new MigrationRegistrySchemaValidator())->assertSqlite($columns, $sql);
    }

    public static function unsafeSqliteMetadataProvider(): iterable
    {
        $columns = self::sqliteColumns();
        $wrongType = $columns;
        $wrongType[4]['type'] = 'TEXT';
        yield 'wrong batch affinity' => [$wrongType, self::sqliteCreateSql()];

        $nullable = $columns;
        $nullable[2]['notnull'] = 0;
        yield 'nullable checksum' => [$nullable, self::sqliteCreateSql()];

        yield 'rowid registry' => [
            $columns,
            str_replace(' WITHOUT ROWID', '', self::sqliteCreateSql()),
        ];

        yield 'missing positive batch check' => [
            $columns,
            str_replace(
                ' CHECK ("batch" > 0)',
                '',
                self::sqliteCreateSql()
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function mysqlColumns(): array
    {
        return [
            self::mysqlTextColumn('module_id', 'varchar', 63),
            self::mysqlTextColumn('migration_id', 'varchar', 190),
            self::mysqlTextColumn('checksum', 'char', 64),
            self::mysqlTextColumn('scope_hash', 'char', 64),
            [
                'column_name' => 'batch',
                'data_type' => 'bigint',
                'column_type' => 'bigint unsigned',
                'is_nullable' => 'NO',
                'character_maximum_length' => null,
                'datetime_precision' => null,
                'character_set_name' => null,
                'collation_name' => null,
            ],
            [
                'column_name' => 'applied_at',
                'data_type' => 'datetime',
                'column_type' => 'datetime(6)',
                'is_nullable' => 'NO',
                'character_maximum_length' => null,
                'datetime_precision' => 6,
                'character_set_name' => null,
                'collation_name' => null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function mysqlTextColumn(
        string $name,
        string $type,
        int $length
    ): array {
        return [
            'column_name' => $name,
            'data_type' => $type,
            'column_type' => $type . '(' . $length . ')',
            'is_nullable' => 'NO',
            'character_maximum_length' => $length,
            'datetime_precision' => null,
            'character_set_name' => 'ascii',
            'collation_name' => 'ascii_bin',
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function sqliteColumns(): array
    {
        $names = [
            'module_id',
            'migration_id',
            'checksum',
            'scope_hash',
            'batch',
            'applied_at',
        ];
        $types = ['TEXT', 'TEXT', 'TEXT', 'TEXT', 'INTEGER', 'TEXT'];
        $primary = [1, 2, 0, 0, 0, 0];

        return array_map(
            static fn (int $index): array => [
                'cid' => $index,
                'name' => $names[$index],
                'type' => $types[$index],
                'notnull' => 1,
                'dflt_value' => null,
                'pk' => $primary[$index],
            ],
            range(0, 5)
        );
    }

    private static function sqliteCreateSql(): string
    {
        return 'CREATE TABLE "ls_module_migrations" ('
            . '"module_id" TEXT NOT NULL,'
            . '"migration_id" TEXT NOT NULL,'
            . '"checksum" TEXT NOT NULL,'
            . '"scope_hash" TEXT NOT NULL,'
            . '"batch" INTEGER NOT NULL CHECK ("batch" > 0),'
            . '"applied_at" TEXT NOT NULL,'
            . 'PRIMARY KEY ("module_id", "migration_id")'
            . ') WITHOUT ROWID';
    }
}
