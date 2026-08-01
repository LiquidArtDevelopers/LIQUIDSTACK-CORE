<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use PDO;
use Throwable;

final class SqliteMigrationLock implements MigrationLockInterface
{
    private bool $acquired = false;

    private ?int $previousBusyTimeoutMilliseconds = null;

    public function acquire(PDO $pdo, int $timeoutSeconds): void
    {
        if ($timeoutSeconds < 0 || $timeoutSeconds > 300) {
            throw new MigrationException('migrations.lock_timeout_invalid');
        }
        if ($pdo->inTransaction()) {
            throw new MigrationException('migrations.transaction_already_open');
        }
        if ($this->acquired) {
            throw new MigrationException('migrations.lock_already_acquired');
        }

        try {
            $this->previousBusyTimeoutMilliseconds = $this->busyTimeout($pdo);
            $this->execute(
                $pdo,
                'PRAGMA busy_timeout = ' . ($timeoutSeconds * 1000)
            );
            $this->execute($pdo, 'BEGIN IMMEDIATE');
            $this->acquired = true;
        } catch (Throwable) {
            try {
                $this->restoreBusyTimeout($pdo);
            } catch (Throwable) {
                throw new MigrationException(
                    'migrations.lock_state_restore_failed'
                );
            }
            throw new MigrationException('migrations.lock_timeout');
        }
    }

    public function release(PDO $pdo, bool $successful): void
    {
        if (!$this->acquired) {
            if ($this->previousBusyTimeoutMilliseconds !== null) {
                try {
                    $this->restoreBusyTimeout($pdo);
                } catch (Throwable) {
                    throw new MigrationException(
                        'migrations.lock_state_restore_failed'
                    );
                }
            }

            return;
        }

        $issueCode = null;
        $transactionClosed = false;

        if ($successful) {
            try {
                $this->execute($pdo, 'COMMIT');
                $transactionClosed = true;
            } catch (Throwable) {
                try {
                    $this->execute($pdo, 'ROLLBACK');
                    $transactionClosed = true;
                    $issueCode = 'migrations.commit_failed';
                } catch (Throwable) {
                    $issueCode = 'migrations.commit_rollback_failed';
                }
            }
        } else {
            try {
                $this->execute($pdo, 'ROLLBACK');
                $transactionClosed = true;
            } catch (Throwable) {
                $issueCode = 'migrations.rollback_failed';
            }
        }

        if ($transactionClosed) {
            $this->acquired = false;
        }

        try {
            $this->restoreBusyTimeout($pdo);
        } catch (Throwable) {
            $issueCode ??= 'migrations.lock_state_restore_failed';
        }

        if ($issueCode !== null) {
            throw new MigrationException($issueCode);
        }
    }

    public function ownsBatchTransaction(): bool
    {
        return true;
    }

    private function busyTimeout(PDO $pdo): int
    {
        $statement = $pdo->query('PRAGMA busy_timeout');
        if ($statement === false) {
            throw new \RuntimeException('SQLite busy_timeout is unavailable.');
        }

        $value = $statement->fetchColumn();
        if (
            !is_int($value)
            && !(is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1)
        ) {
            throw new \RuntimeException('SQLite busy_timeout is invalid.');
        }

        $timeout = (int) $value;
        if ($timeout < 0) {
            throw new \RuntimeException('SQLite busy_timeout is invalid.');
        }

        return $timeout;
    }

    private function restoreBusyTimeout(PDO $pdo): void
    {
        if ($this->previousBusyTimeoutMilliseconds === null) {
            return;
        }

        $timeout = $this->previousBusyTimeoutMilliseconds;
        $this->execute($pdo, 'PRAGMA busy_timeout = ' . $timeout);
        $this->previousBusyTimeoutMilliseconds = null;
    }

    private function execute(PDO $pdo, string $sql): void
    {
        if ($pdo->exec($sql) === false) {
            throw new \RuntimeException('SQLite statement failed.');
        }
    }
}
