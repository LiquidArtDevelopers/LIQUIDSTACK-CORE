<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use PDO;
use Throwable;

final class MySqlMigrationLock implements MigrationLockInterface
{
    private bool $acquired = false;

    public function __construct(private readonly string $lockName)
    {
        if (
            strlen($lockName) > 64
            || preg_match('/\A[a-z0-9:_-]+\z/', $lockName) !== 1
        ) {
            throw new MigrationException('migrations.lock_name_invalid');
        }
    }

    public static function forDatabaseName(string $databaseName): self
    {
        return new self(
            'liquidstack:migrate:'
                . substr(hash('sha256', $databaseName), 0, 40)
        );
    }

    public function acquire(PDO $pdo, int $timeoutSeconds): void
    {
        if ($timeoutSeconds < 0 || $timeoutSeconds > 300) {
            throw new MigrationException('migrations.lock_timeout_invalid');
        }

        try {
            $statement = $pdo->prepare(
                'SELECT GET_LOCK(:lock_name, :lock_timeout)'
            );
            $statement->bindValue('lock_name', $this->lockName, PDO::PARAM_STR);
            $statement->bindValue(
                'lock_timeout',
                $timeoutSeconds,
                PDO::PARAM_INT
            );
            $statement->execute();
            $result = $statement->fetchColumn();
        } catch (Throwable) {
            throw new MigrationException('migrations.lock_failed');
        }

        if ((string) $result !== '1') {
            throw new MigrationException('migrations.lock_timeout');
        }
        $this->acquired = true;
    }

    public function release(PDO $pdo, bool $successful): void
    {
        if (!$this->acquired) {
            return;
        }

        try {
            $statement = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $statement->execute(['lock_name' => $this->lockName]);
            $result = $statement->fetchColumn();
        } catch (Throwable) {
            throw new MigrationException('migrations.lock_release_failed');
        } finally {
            $this->acquired = false;
        }

        if ((string) $result !== '1') {
            throw new MigrationException('migrations.lock_release_failed');
        }
    }

    public function ownsBatchTransaction(): bool
    {
        return false;
    }
}
