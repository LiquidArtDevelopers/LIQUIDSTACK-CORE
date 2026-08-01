<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

final class MigrationRegistrySchemaValidator
{
    private const COLUMN_NAMES = [
        'module_id',
        'migration_id',
        'checksum',
        'scope_hash',
        'batch',
        'applied_at',
    ];

    /**
     * @param list<array<string, mixed>> $columns
     * @param list<mixed> $primaryColumns
     */
    public function assertMySql(
        array $columns,
        array $primaryColumns,
        mixed $engine
    ): void {
        if (
            !is_string($engine)
            || strcasecmp($engine, 'InnoDB') !== 0
            || $this->columnNames($columns) !== self::COLUMN_NAMES
            || $this->stringList($primaryColumns)
                !== ['module_id', 'migration_id']
        ) {
            $this->invalid();
        }

        $expected = [
            'module_id' => ['varchar', 63, 'ascii', 'ascii_bin'],
            'migration_id' => ['varchar', 190, 'ascii', 'ascii_bin'],
            'checksum' => ['char', 64, 'ascii', 'ascii_bin'],
            'scope_hash' => ['char', 64, 'ascii', 'ascii_bin'],
        ];

        foreach ($columns as $column) {
            $name = $column['column_name'] ?? null;
            if (
                !is_string($name)
                || ($column['is_nullable'] ?? null) !== 'NO'
            ) {
                $this->invalid();
            }

            if (isset($expected[$name])) {
                [$type, $length, $charset, $collation] = $expected[$name];
                if (
                    strtolower((string) ($column['data_type'] ?? '')) !== $type
                    || (int) ($column['character_maximum_length'] ?? -1)
                        !== $length
                    || strtolower((string) ($column['character_set_name'] ?? ''))
                        !== $charset
                    || strtolower((string) ($column['collation_name'] ?? ''))
                        !== $collation
                ) {
                    $this->invalid();
                }
                continue;
            }

            if ($name === 'batch') {
                if (
                    strtolower((string) ($column['data_type'] ?? '')) !== 'bigint'
                    || preg_match(
                        '/\bunsigned\b/i',
                        (string) ($column['column_type'] ?? '')
                    ) !== 1
                ) {
                    $this->invalid();
                }
                continue;
            }

            if (
                $name !== 'applied_at'
                || strtolower((string) ($column['data_type'] ?? ''))
                    !== 'datetime'
                || (int) ($column['datetime_precision'] ?? -1) !== 6
            ) {
                $this->invalid();
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $columns
     */
    public function assertSqlite(array $columns, mixed $createSql): void
    {
        usort(
            $columns,
            static fn (array $left, array $right): int =>
                ((int) ($left['cid'] ?? -1)) <=> ((int) ($right['cid'] ?? -1))
        );

        if (
            $this->sqliteColumnNames($columns) !== self::COLUMN_NAMES
            || !is_string($createSql)
            || preg_match('/\)\s*WITHOUT\s+ROWID\s*\z/i', $createSql) !== 1
            || preg_match(
                '/CHECK\s*\(\s*"batch"\s*>\s*0\s*\)/i',
                $createSql
            ) !== 1
        ) {
            $this->invalid();
        }

        $types = ['TEXT', 'TEXT', 'TEXT', 'TEXT', 'INTEGER', 'TEXT'];
        $primaryPositions = [1, 2, 0, 0, 0, 0];
        foreach ($columns as $index => $column) {
            if (
                strtoupper(trim((string) ($column['type'] ?? '')))
                    !== $types[$index]
                || (int) ($column['notnull'] ?? 0) !== 1
                || (int) ($column['pk'] ?? 0) !== $primaryPositions[$index]
            ) {
                $this->invalid();
            }
        }
    }

    /** @param list<array<string, mixed>> $columns */
    private function columnNames(array $columns): array
    {
        return array_values(array_map(
            static fn (array $column): string =>
                is_string($column['column_name'] ?? null)
                    ? $column['column_name']
                    : '',
            $columns
        ));
    }

    /** @param list<array<string, mixed>> $columns */
    private function sqliteColumnNames(array $columns): array
    {
        return array_values(array_map(
            static fn (array $column): string =>
                is_string($column['name'] ?? null) ? $column['name'] : '',
            $columns
        ));
    }

    /** @param list<mixed> $values */
    private function stringList(array $values): array
    {
        return array_values(array_map(
            static fn (mixed $value): string =>
                is_string($value) ? $value : '',
            $values
        ));
    }

    private function invalid(): never
    {
        throw new MigrationException('migrations.registry_schema_invalid');
    }
}
