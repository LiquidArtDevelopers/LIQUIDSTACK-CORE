<?php

declare(strict_types=1);

namespace App\Core\Commerce\Persistence;

use PDO;
use PDOStatement;
use Throwable;

abstract class AbstractPdoCommerceRepository
{
    protected readonly string $driver;
    private bool $transactionActive = false;

    public function __construct(
        protected readonly PDO $pdo,
        protected readonly CommerceTableNames $tables
    ) {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (
                !is_string($driver)
                || $driver !== $tables->driver()
                || !in_array($driver, ['mysql', 'sqlite'], true)
                || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
                || ($driver === 'mysql' && !in_array(
                    $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
                    [false, 0, '0'],
                    true
                ))
            ) {
                throw new CommercePersistenceException();
            }
            if ($driver === 'sqlite') {
                $statement = $pdo->query('PRAGMA foreign_keys');
                if (
                    !$statement instanceof PDOStatement
                    || !in_array($statement->fetchColumn(), [1, '1'], true)
                ) {
                    throw new CommercePersistenceException();
                }
            }
            $this->driver = $driver;
        } catch (CommercePersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new CommercePersistenceException();
        }
    }

    /** @template T @param callable(): T $operation @return T */
    protected function transactional(callable $operation): mixed
    {
        if ($this->transactionActive || $this->pdo->inTransaction()) {
            throw new CommercePersistenceException();
        }
        $started = false;
        $this->transactionActive = true;
        try {
            if (!$this->pdo->beginTransaction()) {
                throw new CommercePersistenceException();
            }
            $started = true;
            $result = $operation();
            if (!$this->pdo->commit()) {
                throw new CommercePersistenceException();
            }
            $started = false;
            $this->transactionActive = false;

            return $result;
        } catch (Throwable $exception) {
            try {
                if ($started && $this->pdo->inTransaction() && $this->pdo->rollBack()) {
                    $started = false;
                } elseif ($started && $this->driver === 'mysql' && !$this->pdo->inTransaction()) {
                    $started = false;
                }
            } catch (Throwable) {
                // The connection cannot safely continue if rollback is unproven.
            }
            if (!$started) {
                $this->transactionActive = false;
            }
            if ($started) {
                throw new CommercePersistenceException();
            }
            throw $exception;
        }
    }

    protected function inTransaction(): bool
    {
        return $this->transactionActive && $this->pdo->inTransaction();
    }

    protected function forUpdate(): string
    {
        return $this->driver === 'mysql' ? ' FOR UPDATE' : '';
    }

    protected function prepare(string $sql): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
        } catch (Throwable) {
            throw new CommercePersistenceException();
        }
        if (!$statement instanceof PDOStatement) {
            throw new CommercePersistenceException();
        }

        return $statement;
    }

    /** @param array<string, mixed> $parameters */
    protected function execute(PDOStatement $statement, array $parameters = []): void
    {
        try {
            $success = $parameters === []
                ? $statement->execute()
                : $statement->execute($parameters);
        } catch (Throwable) {
            throw new CommercePersistenceException();
        }
        if (!$success) {
            throw new CommercePersistenceException();
        }
    }

    /** @param array<string, mixed> $parameters @return array<string, mixed>|null */
    protected function one(string $sql, array $parameters = []): ?array
    {
        $statement = $this->prepare($sql);
        $this->execute($statement, $parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new CommercePersistenceException();
        }

        return $row;
    }

    /** @param array<string, mixed> $parameters @return list<array<string, mixed>> */
    protected function all(string $sql, array $parameters = []): array
    {
        $statement = $this->prepare($sql);
        $this->execute($statement, $parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            throw new CommercePersistenceException();
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new CommercePersistenceException();
            }
        }

        /** @var list<array<string, mixed>> $rows */
        return array_values($rows);
    }

    /** @param array<string, mixed> $parameters */
    protected function write(string $sql, array $parameters = []): int
    {
        $statement = $this->prepare($sql);
        $this->execute($statement, $parameters);

        return $statement->rowCount();
    }

    protected function lastInsertId(): int
    {
        $id = $this->pdo->lastInsertId();
        if (!is_string($id) || preg_match('/\A[1-9][0-9]*\z/', $id) !== 1) {
            throw new CommercePersistenceException();
        }

        return (int) $id;
    }

    protected function positiveInt(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            return (int) $value;
        }

        throw new CommercePersistenceException();
    }

    protected function nonNegativeInt(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) === 1) {
            return (int) $value;
        }

        throw new CommercePersistenceException();
    }
}
