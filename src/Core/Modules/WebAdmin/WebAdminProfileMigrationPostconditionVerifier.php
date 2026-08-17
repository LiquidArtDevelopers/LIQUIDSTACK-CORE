<?php

declare(strict_types=1);

namespace App\Core\Modules\WebAdmin;

use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Bounded, read-only contract for the optional WebAdmin profile frontier. */
final class WebAdminProfileMigrationPostconditionVerifier implements
    MigrationPostconditionVerifierInterface
{
    public function contractVersion(): string
    {
        return 'webadmin-profile-preferences-v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        return $this->issueCodes($pdo, $scope) === [];
    }

    /** @return list<string> */
    public function issueCodes(PDO $pdo, MigrationScope $scope): array
    {
        try {
            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            $table = $scope->quotedTable('user_profiles', $driver);
            $columns = $driver === 'sqlite'
                ? $pdo->query('PRAGMA table_info(' . $table . ')')
                    ->fetchAll(PDO::FETCH_ASSOC)
                : $pdo->query('SHOW COLUMNS FROM ' . $table)
                    ->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($columns)) {
                return ['webadmin.profile.schema_invalid'];
            }
            $names = [];
            foreach ($columns as $column) {
                $name = $column[$driver === 'sqlite' ? 'name' : 'Field'] ?? null;
                if (is_string($name)) {
                    $names[] = $name;
                }
            }
            if ($names !== [
                'user_id',
                'time_zone',
                'lock_version',
                'updated_by_user_id',
                'updated_at',
            ]) {
                return ['webadmin.profile.schema_invalid'];
            }
            if (!$this->foreignKeysAreValid($pdo, $scope, $driver)) {
                return ['webadmin.profile.foreign_keys_invalid'];
            }
            if (!$this->updaterIndexIsValid($pdo, $scope, $driver)) {
                return ['webadmin.profile.indexes_invalid'];
            }
            $invalid = $pdo->query(
                'SELECT COUNT(*) FROM ' . $table
                . ' WHERE lock_version < 1 OR (time_zone IS NOT NULL AND '
                . '(LENGTH(time_zone) < 1 OR LENGTH(time_zone) > 64))'
            )->fetchColumn();

            return in_array($invalid, [0, '0'], true)
                ? [] : ['webadmin.profile.data_integrity_invalid'];
        } catch (Throwable) {
            return ['webadmin.profile.schema_metadata_unavailable'];
        }
    }

    private function foreignKeysAreValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        if ($driver === 'sqlite') {
            $rows = $pdo->query(
                'PRAGMA foreign_key_list('
                . $scope->quotedTable('user_profiles', 'sqlite') . ')'
            )->fetchAll(PDO::FETCH_ASSOC);
            $actual = [];
            foreach ($rows as $row) {
                $actual[] = [
                    (string) ($row['from'] ?? ''),
                    (string) ($row['table'] ?? ''),
                    (string) ($row['to'] ?? ''),
                    strtoupper((string) ($row['on_delete'] ?? '')),
                ];
            }
        } else {
            $statement = $pdo->prepare(
                'SELECT k.COLUMN_NAME AS source_column, '
                . 'k.REFERENCED_TABLE_NAME AS target_table, '
                . 'k.REFERENCED_COLUMN_NAME AS target_column, '
                . 'r.DELETE_RULE AS delete_rule FROM '
                . 'information_schema.KEY_COLUMN_USAGE k JOIN '
                . 'information_schema.REFERENTIAL_CONSTRAINTS r ON '
                . 'r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND '
                . 'r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE '
                . 'k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = :table '
                . 'AND k.REFERENCED_TABLE_NAME IS NOT NULL'
            );
            $statement->execute([
                'table' => $scope->tableName('user_profiles'),
            ]);
            $actual = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $actual[] = [
                    (string) ($row['source_column'] ?? ''),
                    (string) ($row['target_table'] ?? ''),
                    (string) ($row['target_column'] ?? ''),
                    strtoupper((string) ($row['delete_rule'] ?? '')),
                ];
            }
        }
        sort($actual);
        $expected = [
            [
                'updated_by_user_id',
                $scope->tableName('users'),
                'id',
                'RESTRICT',
            ],
            ['user_id', $scope->tableName('users'), 'id', 'CASCADE'],
        ];
        sort($expected);
        return $actual === $expected;
    }

    private function updaterIndexIsValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        if ($driver === 'sqlite') {
            $rows = $pdo->query(
                'PRAGMA index_list('
                . $scope->quotedTable('user_profiles', 'sqlite') . ')'
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $name = $row['name'] ?? null;
                if (!is_string($name)) {
                    continue;
                }
                $columns = $pdo->query(
                    'PRAGMA index_info("' . str_replace('"', '""', $name)
                    . '")'
                )->fetchAll(PDO::FETCH_ASSOC);
                if (
                    count($columns) === 1
                    && ($columns[0]['name'] ?? null) === 'updated_by_user_id'
                ) {
                    return true;
                }
            }
            return false;
        }
        $rows = $pdo->query(
            'SHOW INDEX FROM '
            . $scope->quotedTable('user_profiles', 'mysql')
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if (
                ($row['Key_name'] ?? null) === 'idx_wa_profiles_updater'
                && ($row['Column_name'] ?? null) === 'updated_by_user_id'
                && (string) ($row['Non_unique'] ?? '') === '1'
            ) {
                return true;
            }
        }
        return false;
    }
}
