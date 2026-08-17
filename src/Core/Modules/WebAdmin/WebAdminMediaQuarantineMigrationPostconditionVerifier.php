<?php

declare(strict_types=1);

namespace App\Core\Modules\WebAdmin;

use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Exact, bounded verifier for the recoverable Media quarantine frontier. */
final class WebAdminMediaQuarantineMigrationPostconditionVerifier implements
    MigrationPostconditionVerifierInterface
{
    private const COLUMNS = [
        'id',
        'asset_id',
        'public_id',
        'state',
        'asset_version',
        'original_storage_prefix',
        'quarantine_storage_prefix',
        'manifest_storage_key',
        'manifest_json',
        'manifest_sha256',
        'request_id',
        'quarantined_by_user_id',
        'quarantined_at',
        'lock_version',
    ];

    public function __construct(
        private readonly WebAdminMediaMigrationPostconditionVerifier
            $mediaVerifier = new WebAdminMediaMigrationPostconditionVerifier(
                acceptAvifSource: true
            ),
        private readonly WebAdminProfileMigrationPostconditionVerifier
            $profileVerifier = new WebAdminProfileMigrationPostconditionVerifier()
    ) {
    }

    public function contractVersion(): string
    {
        return 'webadmin-media-quarantine-v1';
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
            $issues = array_merge(
                $this->mediaVerifier->issueCodes($pdo, $scope),
                $this->profileVerifier->issueCodes($pdo, $scope)
            );
            if (!$this->columnsAreValid($pdo, $scope, $driver)) {
                $issues[] = 'webadmin.media.quarantine_schema_invalid';
            }
            if (!$this->indexesAreValid($pdo, $scope, $driver)) {
                $issues[] = 'webadmin.media.quarantine_indexes_invalid';
            }
            if (!$this->foreignKeysAreValid($pdo, $scope, $driver)) {
                $issues[] = 'webadmin.media.quarantine_foreign_keys_invalid';
            }
            if (!$this->seedIsValid($pdo, $scope, $driver)) {
                $issues[] = 'webadmin.media.quarantine_seed_invalid';
            }
            if (!$this->rowsAreValid($pdo, $scope, $driver)) {
                $issues[] = 'webadmin.media.quarantine_data_invalid';
            }

            return array_values(array_unique($issues));
        } catch (Throwable) {
            return ['webadmin.media.quarantine_metadata_unavailable'];
        }
    }

    private function columnsAreValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $table = $scope->quotedTable('media_quarantines', $driver);
        $rows = $driver === 'sqlite'
            ? $pdo->query('PRAGMA table_info(' . $table . ')')
                ->fetchAll(PDO::FETCH_ASSOC)
            : $pdo->query('SHOW FULL COLUMNS FROM ' . $table)
                ->fetchAll(PDO::FETCH_ASSOC);
        $names = [];
        foreach ($rows as $row) {
            $name = $row[$driver === 'sqlite' ? 'name' : 'Field'] ?? null;
            if (!is_string($name)) {
                return false;
            }
            $names[] = $name;
        }

        if ($names !== self::COLUMNS) {
            return false;
        }

        return $driver === 'sqlite'
            ? $this->sqliteColumnsAreValid($rows)
                && $this->sqliteChecksAreValid($pdo, $scope)
            : $this->mysqlColumnsAreValid($rows)
                && $this->mysqlChecksAreValid($pdo, $scope);
    }

    /** @param list<array<string, mixed>> $rows */
    private function sqliteColumnsAreValid(array $rows): bool
    {
        $expected = [
            ['INTEGER', 0, null, 1],
            ['INTEGER', 1, null, 0],
            ['TEXT', 1, null, 0],
            ['TEXT', 1, null, 0],
            ['TEXT', 1, null, 0],
            ['TEXT', 1, null, 0],
            ['TEXT', 1, null, 0],
            ['TEXT', 1, null, 0],
            ['TEXT', 1, null, 0],
            ['TEXT', 1, null, 0],
            ['TEXT', 1, null, 0],
            ['INTEGER', 1, null, 0],
            ['TEXT', 1, "strftime('%Y-%m-%d %H:%M:%f000', 'now')", 0],
            ['INTEGER', 1, '1', 0],
        ];
        foreach ($rows as $index => $row) {
            $actual = [
                strtoupper((string) ($row['type'] ?? '')),
                (int) ($row['notnull'] ?? -1),
                $row['dflt_value'] ?? null,
                (int) ($row['pk'] ?? -1),
            ];
            if ($actual !== $expected[$index]) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, mixed>> $rows */
    private function mysqlColumnsAreValid(array $rows): bool
    {
        $types = [
            'bigint unsigned', 'bigint unsigned', 'char(36)', 'varchar(16)',
            'char(64)', 'varchar(255)', 'varchar(255)', 'varchar(255)',
            'text', 'char(64)', 'char(36)', 'bigint unsigned', 'datetime(6)',
            'bigint unsigned',
        ];
        $ascii = array_fill_keys([2, 3, 4, 5, 6, 7, 9, 10], true);
        foreach ($rows as $index => $row) {
            $type = strtolower((string) ($row['Type'] ?? ''));
            $typeIsValid = $types[$index] === 'bigint unsigned'
                ? preg_match('/\Abigint(?:\(20\))? unsigned\z/', $type) === 1
                : $type === $types[$index];
            if (
                !$typeIsValid
                || ($row['Null'] ?? null) !== 'NO'
            ) {
                return false;
            }
            $collation = $row['Collation'] ?? null;
            if (isset($ascii[$index])) {
                if (!is_string($collation)
                    || !str_ends_with(strtolower($collation), '_bin')) {
                    return false;
                }
            } elseif ($index === 8) {
                if (!is_string($collation)
                    || !str_starts_with(strtolower($collation), 'utf8mb4_')) {
                    return false;
                }
            } elseif ($collation !== null) {
                return false;
            }
            $default = $row['Default'] ?? null;
            if ($index === 12) {
                if (!is_string($default)
                    || strtolower($default) !== 'current_timestamp(6)') {
                    return false;
                }
            } elseif ($index === 13) {
                if (!in_array($default, [1, '1'], true)) {
                    return false;
                }
            } elseif ($default !== null) {
                return false;
            }
            $extra = strtolower(trim((string) ($row['Extra'] ?? '')));
            if ($index === 0 && $extra !== 'auto_increment') {
                return false;
            }
            if ($index !== 0 && $index !== 12 && $extra !== '') {
                return false;
            }
            if ($index === 12 && !in_array(
                $extra,
                ['', 'default_generated'],
                true
            )) {
                return false;
            }
        }

        return true;
    }

    private function sqliteChecksAreValid(
        PDO $pdo,
        MigrationScope $scope
    ): bool {
        $statement = $pdo->prepare(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :name"
        );
        $statement->execute(['name' => $scope->tableName('media_quarantines')]);
        $sql = $statement->fetchColumn();

        return is_string($sql) && $this->checksAreValid($sql, false);
    }

    private function mysqlChecksAreValid(
        PDO $pdo,
        MigrationScope $scope
    ): bool {
        $row = $pdo->query(
            'SHOW CREATE TABLE '
            . $scope->quotedTable('media_quarantines', 'mysql')
        )->fetch(PDO::FETCH_NUM);
        $sql = is_array($row) ? ($row[1] ?? null) : null;

        return is_string($sql) && $this->checksAreValid($sql, true);
    }

    private function checksAreValid(string $sql, bool $mysql): bool
    {
        $normalized = strtolower($sql);
        $normalized = str_replace(
            ["_utf8mb4'", "_utf8mb3'", '`', '"'],
            ["'", "'", '', ''],
            $normalized
        );
        $normalized = preg_replace('/\s+/', '', $normalized);
        if (!is_string($normalized)
            || preg_match_all('/check\(/', $normalized) !== 10) {
            return false;
        }
        $length = $mysql ? 'char_length' : 'length';
        foreach ([
            $length . '(public_id)=36',
            "state='quarantined'",
            $length . '(asset_version)=64',
            $length . '(original_storage_prefix)between39and255',
            $length . '(quarantine_storage_prefix)between1and255',
            $length . '(manifest_storage_key)between1and255',
            $length . '(manifest_json)between2and65535',
            $length . '(manifest_sha256)=64',
            $length . '(request_id)=36',
            'lock_version>0',
        ] as $expression) {
            if (!str_contains($normalized, 'check(' . $expression . ')')) {
                return false;
            }
        }

        return true;
    }

    private function indexesAreValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        if ($driver === 'sqlite') {
            $table = $scope->quotedTable('media_quarantines', 'sqlite');
            $indexes = $pdo->query('PRAGMA index_list(' . $table . ')')
                ->fetchAll(PDO::FETCH_ASSOC);
            $actual = [];
            foreach ($indexes as $index) {
                $name = $index['name'] ?? null;
                if (!is_string($name)) {
                    return false;
                }
                $columns = $pdo->query(
                    'PRAGMA index_info(' . $this->quoteSqlite($name) . ')'
                )->fetchAll(PDO::FETCH_ASSOC);
                $actual[] = [
                    'unique' => in_array($index['unique'] ?? null, [1, '1'], true),
                    'columns' => array_values(array_map(
                        static fn (array $row): string =>
                            (string) ($row['name'] ?? ''),
                        $columns
                    )),
                ];
            }
        } else {
            $statement = $pdo->prepare(
                'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME '
                . 'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA '
                . '= DATABASE() AND TABLE_NAME = :table ORDER BY '
                . 'INDEX_NAME, SEQ_IN_INDEX'
            );
            $statement->execute([
                'table' => $scope->tableName('media_quarantines'),
            ]);
            $grouped = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = $row['INDEX_NAME'] ?? null;
                $column = $row['COLUMN_NAME'] ?? null;
                if (!is_string($name) || !is_string($column)) {
                    return false;
                }
                $grouped[$name]['unique'] = in_array(
                    $row['NON_UNIQUE'] ?? null,
                    [0, '0'],
                    true
                );
                $grouped[$name]['columns'][] = $column;
            }
            $actual = array_values($grouped);
        }
        $normalize = static function (array $signatures): array {
            $values = array_map(
                static fn (array $signature): string =>
                    ($signature['unique'] ? 'u:' : 'n:')
                    . implode(',', $signature['columns']),
                $signatures
            );
            sort($values, SORT_STRING);
            return $values;
        };
        $expected = [
            ['unique' => true, 'columns' => ['asset_id']],
            ['unique' => true, 'columns' => ['public_id']],
            ['unique' => true, 'columns' => ['quarantine_storage_prefix']],
            ['unique' => true, 'columns' => ['manifest_storage_key']],
            ['unique' => true, 'columns' => ['request_id']],
            ['unique' => false, 'columns' => ['quarantined_by_user_id']],
            ['unique' => false, 'columns' => ['quarantined_at', 'id']],
        ];
        if ($driver !== 'sqlite') {
            $expected[] = ['unique' => true, 'columns' => ['id']];
        }

        return $normalize($actual) === $normalize($expected);
    }

    private function foreignKeysAreValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        if ($driver === 'sqlite') {
            $rows = $pdo->query(
                'PRAGMA foreign_key_list('
                . $scope->quotedTable('media_quarantines', 'sqlite') . ')'
            )->fetchAll(PDO::FETCH_ASSOC);
            $actual = array_map(
                static fn (array $row): array => [
                    (string) ($row['from'] ?? ''),
                    (string) ($row['table'] ?? ''),
                    (string) ($row['to'] ?? ''),
                    strtoupper((string) ($row['on_delete'] ?? '')),
                ],
                $rows
            );
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
                'table' => $scope->tableName('media_quarantines'),
            ]);
            $actual = array_map(
                static fn (array $row): array => [
                    (string) ($row['source_column'] ?? ''),
                    (string) ($row['target_table'] ?? ''),
                    (string) ($row['target_column'] ?? ''),
                    strtoupper((string) ($row['delete_rule'] ?? '')),
                ],
                $statement->fetchAll(PDO::FETCH_ASSOC)
            );
        }
        $expected = [
            [
                'asset_id',
                $scope->tableName('media_assets'),
                'id',
                'RESTRICT',
            ],
            [
                'quarantined_by_user_id',
                $scope->tableName('users'),
                'id',
                'RESTRICT',
            ],
        ];
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    private function seedIsValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $statement = $pdo->prepare(
            'SELECT c.module_id, c.label_key, c.is_delegable, '
            . 'GROUP_CONCAT(r.code) AS roles FROM '
            . $scope->quotedTable('capabilities', $driver) . ' c LEFT JOIN '
            . $scope->quotedTable('role_capabilities', $driver)
            . ' rc ON rc.capability_id = c.id LEFT JOIN '
            . $scope->quotedTable('roles', $driver)
            . ' r ON r.id = rc.role_id WHERE c.code = :code GROUP BY '
            . 'c.id, c.module_id, c.label_key, c.is_delegable'
        );
        $statement->execute(['code' => 'webadmin.media.delete']);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            return false;
        }
        $row = $rows[0];
        $roles = is_string($row['roles'] ?? null)
            ? explode(',', $row['roles']) : [];
        sort($roles, SORT_STRING);

        return ($row['module_id'] ?? null) === 'webadmin'
            && ($row['label_key'] ?? null)
                === 'webadmin.capabilities.media_delete'
            && in_array($row['is_delegable'] ?? null, [1, '1'], true)
            && $roles === ['site_admin', 'system_superadmin'];
    }

    private function rowsAreValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $statement = $pdo->query(
            'SELECT q.asset_id, q.public_id, q.state, q.asset_version, '
            . 'q.original_storage_prefix, q.quarantine_storage_prefix, '
            . 'q.manifest_storage_key, q.manifest_json, q.manifest_sha256, '
            . 'q.request_id, q.lock_version, a.public_id AS asset_public_id '
            . 'FROM ' . $scope->quotedTable('media_quarantines', $driver)
            . ' q JOIN ' . $scope->quotedTable('media_assets', $driver)
            . ' a ON a.id = q.asset_id ORDER BY q.id LIMIT 10001'
        );
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 10_000) {
            return false;
        }
        foreach ($rows as $row) {
            $json = $row['manifest_json'] ?? null;
            $hash = $row['manifest_sha256'] ?? null;
            $payload = is_string($json)
                ? json_decode($json, true, 32) : null;
            if (
                !is_array($payload)
                || !is_string($hash)
                || !hash_equals($hash, hash('sha256', $json))
                || ($row['state'] ?? null) !== 'quarantined'
                || ($row['public_id'] ?? null)
                    !== ($row['asset_public_id'] ?? null)
                || ($payload['schema'] ?? null)
                    !== 'liquidstack.webadmin.media-quarantine'
                || ($payload['version'] ?? null) !== 1
                || ($payload['state'] ?? null) !== 'quarantined'
                || ($payload['request_id'] ?? null)
                    !== ($row['request_id'] ?? null)
                || ($payload['asset_version'] ?? null)
                    !== ($row['asset_version'] ?? null)
                || ($payload['original_storage_prefix'] ?? null)
                    !== ($row['original_storage_prefix'] ?? null)
                || ($payload['quarantine_storage_prefix'] ?? null)
                    !== ($row['quarantine_storage_prefix'] ?? null)
                || ($payload['manifest_storage_key'] ?? null)
                    !== ($row['manifest_storage_key'] ?? null)
                || (($payload['asset']['id'] ?? null) !== (int) $row['asset_id'])
                || (($payload['asset']['public_id'] ?? null)
                    !== ($row['public_id'] ?? null))
                || !in_array($row['lock_version'] ?? null, [1, '1'], true)
            ) {
                return false;
            }
        }

        return true;
    }

    private function quoteSqlite(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
