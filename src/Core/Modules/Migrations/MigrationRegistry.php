<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use DateTimeImmutable;
use PDO;
use Throwable;

final class MigrationRegistry
{
    public const TABLE = 'ls_module_migrations';

    public function __construct(
        private readonly MigrationRegistrySchemaValidator $schemaValidator =
            new MigrationRegistrySchemaValidator()
    ) {
    }

    public function exists(PDO $pdo): bool
    {
        $driver = MigrationDatabaseDriver::fromPdo($pdo);

        try {
            if ($driver === MigrationDatabaseDriver::MYSQL) {
                $statement = $pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.tables '
                    . 'WHERE table_schema = DATABASE() AND table_name = :table'
                );
                $statement->execute(['table' => self::TABLE]);

                return (int) $statement->fetchColumn() === 1;
            }

            $statement = $pdo->prepare(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table"
            );
            $statement->execute(['table' => self::TABLE]);

            return (int) $statement->fetchColumn() === 1;
        } catch (Throwable) {
            throw new MigrationException('migrations.registry_inspection_failed');
        }
    }

    public function ensureExists(PDO $pdo): void
    {
        $driver = MigrationDatabaseDriver::fromPdo($pdo);
        $sql = $driver === MigrationDatabaseDriver::MYSQL
            ? 'CREATE TABLE IF NOT EXISTS `ls_module_migrations` ('
                . '`module_id` VARCHAR(63) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
                . '`migration_id` VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
                . '`checksum` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
                . '`scope_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
                . '`batch` BIGINT UNSIGNED NOT NULL,'
                . '`applied_at` DATETIME(6) NOT NULL,'
                . 'PRIMARY KEY (`module_id`, `migration_id`),'
                . 'KEY `idx_ls_module_migrations_batch` (`batch`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : 'CREATE TABLE IF NOT EXISTS "ls_module_migrations" ('
                . '"module_id" TEXT NOT NULL,'
                . '"migration_id" TEXT NOT NULL,'
                . '"checksum" TEXT NOT NULL,'
                . '"scope_hash" TEXT NOT NULL,'
                . '"batch" INTEGER NOT NULL CHECK ("batch" > 0),'
                . '"applied_at" TEXT NOT NULL,'
                . 'PRIMARY KEY ("module_id", "migration_id")'
                . ') WITHOUT ROWID';

        try {
            if ($pdo->exec($sql) === false) {
                throw new \RuntimeException('Registry creation failed.');
            }
        } catch (Throwable) {
            throw new MigrationException('migrations.registry_create_failed');
        }

        $this->assertSchema($pdo);
    }

    public function assertSchema(PDO $pdo): void
    {
        $driver = MigrationDatabaseDriver::fromPdo($pdo);

        try {
            if ($driver === MigrationDatabaseDriver::MYSQL) {
                $statement = $pdo->prepare(
                    'SELECT column_name, data_type, column_type, is_nullable, '
                    . 'character_maximum_length, datetime_precision, '
                    . 'character_set_name, collation_name '
                    . 'FROM information_schema.columns '
                    . 'WHERE table_schema = DATABASE() AND table_name = :table '
                    . 'ORDER BY ordinal_position'
                );
                $statement->execute(['table' => self::TABLE]);
                $columns = $statement->fetchAll(PDO::FETCH_ASSOC);

                $primary = $pdo->prepare(
                    'SELECT column_name FROM information_schema.statistics '
                    . 'WHERE table_schema = DATABASE() AND table_name = :table '
                    . "AND index_name = 'PRIMARY' ORDER BY seq_in_index"
                );
                $primary->execute(['table' => self::TABLE]);
                $primaryColumns = $primary->fetchAll(PDO::FETCH_COLUMN);

                $table = $pdo->prepare(
                    'SELECT engine FROM information_schema.tables '
                    . 'WHERE table_schema = DATABASE() AND table_name = :table'
                );
                $table->execute(['table' => self::TABLE]);
                $engine = $table->fetchColumn();
            } else {
                $columns = $pdo->query(
                    'PRAGMA table_info("ls_module_migrations")'
                )->fetchAll(PDO::FETCH_ASSOC);
                $createSql = $pdo->query(
                    "SELECT sql FROM sqlite_master "
                    . "WHERE type = 'table' AND name = 'ls_module_migrations'"
                )->fetchColumn();
            }
        } catch (Throwable) {
            throw new MigrationException('migrations.registry_inspection_failed');
        }

        if ($driver === MigrationDatabaseDriver::MYSQL) {
            $this->schemaValidator->assertMySql(
                is_array($columns) ? $columns : [],
                is_array($primaryColumns) ? $primaryColumns : [],
                $engine
            );

            return;
        }

        $this->schemaValidator->assertSqlite(
            is_array($columns) ? $columns : [],
            $createSql
        );
    }

    /** @return list<AppliedMigration> */
    public function all(PDO $pdo): array
    {
        $this->assertSchema($pdo);

        try {
            $rows = $pdo->query(
                'SELECT module_id, migration_id, checksum, scope_hash, batch, applied_at '
                . 'FROM ls_module_migrations ORDER BY module_id, migration_id'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            throw new MigrationException('migrations.registry_read_failed');
        }

        return $this->hydrateRows($rows);
    }

    /**
     * Bounded registry read for an HTTP runtime gate.
     *
     * This deliberately validates every returned record but does not inspect
     * registry DDL through INFORMATION_SCHEMA/PRAGMA. Full structural auditing
     * remains the responsibility of migrate/doctor; a missing or unreadable
     * registry still fails closed because this query cannot succeed.
     *
     * @return list<AppliedMigration>
     */
    public function recordedForModule(PDO $pdo, string $moduleId): array
    {
        if (preg_match('/\A[a-z][a-z0-9-]{0,62}\z/', $moduleId) !== 1) {
            throw new MigrationException('migrations.registry_data_invalid');
        }

        try {
            $statement = $pdo->prepare(
                'SELECT module_id, migration_id, checksum, scope_hash, batch, applied_at '
                . 'FROM ls_module_migrations WHERE module_id = :module '
                . 'ORDER BY migration_id'
            );
            $statement->execute(['module' => $moduleId]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            throw new MigrationException('migrations.registry_read_failed');
        }

        return $this->hydrateRows($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<AppliedMigration>
     */
    private function hydrateRows(array $rows): array
    {
        try {
            return array_map(
                static fn (array $row): AppliedMigration => new AppliedMigration(
                    (string) ($row['module_id'] ?? ''),
                    (string) ($row['migration_id'] ?? ''),
                    (string) ($row['checksum'] ?? ''),
                    (string) ($row['scope_hash'] ?? ''),
                    (int) ($row['batch'] ?? 0),
                    (string) ($row['applied_at'] ?? '')
                ),
                $rows
            );
        } catch (Throwable) {
            throw new MigrationException('migrations.registry_data_invalid');
        }
    }

    public function nextBatch(PDO $pdo): int
    {
        try {
            $value = $pdo->query(
                'SELECT COALESCE(MAX(batch), 0) FROM ls_module_migrations'
            )->fetchColumn();
            $batch = (int) $value + 1;
        } catch (Throwable) {
            throw new MigrationException('migrations.registry_read_failed');
        }

        if ($batch < 1) {
            throw new MigrationException('migrations.registry_data_invalid');
        }

        return $batch;
    }

    public function record(
        PDO $pdo,
        string $moduleId,
        MigrationDefinition $migration,
        MigrationScope $targetScope,
        int $batch,
        DateTimeImmutable $appliedAt
    ): void {
        try {
            $statement = $pdo->prepare(
                'INSERT INTO ls_module_migrations '
                . '(module_id, migration_id, checksum, scope_hash, batch, applied_at) '
                . 'VALUES (:module, :migration, :checksum, :scope, :batch, :applied_at)'
            );
            if (!$statement->execute([
                'module' => $moduleId,
                'migration' => $migration->id(),
                'checksum' => $migration->checksum(),
                'scope' => $targetScope->hash(),
                'batch' => $batch,
                'applied_at' => $appliedAt->setTimezone(
                    new \DateTimeZone('UTC')
                )->format('Y-m-d H:i:s.u'),
            ])) {
                throw new \RuntimeException('Registry write failed.');
            }
        } catch (Throwable) {
            throw new MigrationException(
                'migrations.registry_write_failed',
                $moduleId,
                $migration->id()
            );
        }
    }
}
