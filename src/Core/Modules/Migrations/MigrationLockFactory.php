<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use PDO;
use Throwable;

final class MigrationLockFactory
{
    public function create(PDO $pdo): MigrationLockInterface
    {
        $driver = MigrationDatabaseDriver::fromPdo($pdo);
        if ($driver === MigrationDatabaseDriver::SQLITE) {
            return new SqliteMigrationLock();
        }

        try {
            $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        } catch (Throwable) {
            throw new MigrationException('migrations.lock_scope_failed');
        }
        if (!is_string($database) || $database === '') {
            throw new MigrationException('migrations.lock_scope_failed');
        }

        return MySqlMigrationLock::forDatabaseName($database);
    }
}
