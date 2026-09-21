<?php

declare(strict_types=1);

namespace App\Core\Commerce\Outbox;

use App\Core\Commerce\Persistence\CommerceTableNames;
use App\Core\WebAdmin\Security\EmailAddress;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

final class CommerceInquiryOutboxRepository
{
    public const FAILURE_RETRY_SCHEDULED = 'retry_scheduled';
    public const FAILURE_PERMANENT = 'permanent_failure';
    public const FAILURE_FENCED = 'fenced';
    public const MAX_ATTEMPTS = 5;
    public const LEASE_SECONDS = 300;

    private const UTC_FORMAT = 'Y-m-d H:i:s.u';
    private const BACKOFF_SECONDS = [1 => 60, 2 => 300, 3 => 900, 4 => 3600];

    private readonly string $table;
    private bool $transactionActive = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly CommerceTableNames $tables
    ) {
        $this->table = $tables->table('inquiry_outbox');
        try {
            if (
                $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
                || ($tables->driver() === 'mysql' && !in_array(
                    $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
                    [false, 0, '0'],
                    true
                ))
            ) {
                throw new CommerceOutboxStorageException();
            }
            if ($tables->driver() === 'sqlite') {
                $foreignKeys = $pdo->query('PRAGMA foreign_keys')?->fetchColumn();
                if (!in_array($foreignKeys, [1, '1'], true)) {
                    throw new CommerceOutboxStorageException();
                }
            }
        } catch (CommerceOutboxStorageException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new CommerceOutboxStorageException();
        }
    }

    public function claimNext(DateTimeImmutable $now): CommerceOutboxClaimResult
    {
        return $this->transaction(function () use ($now): CommerceOutboxClaimResult {
            $statement = $this->prepare(
                'SELECT id, audience, recipient_email, template_key, payload_json, '
                . 'status, attempts FROM ' . $this->table . ' WHERE '
                . "(status = 'pending' AND available_at <= :available_now) OR "
                . "(status = 'processing' AND locked_at <= :stale_before) "
                . 'ORDER BY available_at, id LIMIT 1' . $this->forUpdate()
            );
            $this->execute($statement, [
                'available_now' => self::format($now),
                'stale_before' => self::format(
                    $now->modify('-' . self::LEASE_SECONDS . ' seconds')
                ),
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return CommerceOutboxClaimResult::none();
            }
            if (!is_array($row)) {
                throw new CommerceOutboxStorageException();
            }

            $id = $this->positiveInteger($row['id'] ?? null);
            $attempts = $this->nonNegativeInteger($row['attempts'] ?? null);
            $audience = $row['audience'] ?? null;
            $template = $row['template_key'] ?? null;
            $recipient = $row['recipient_email'] ?? null;
            $payload = $row['payload_json'] ?? null;
            if (
                $id === null || $attempts === null
                || !is_string($audience)
                || !is_string($template)
                || !is_string($recipient)
                || !is_string($payload)
                || !in_array($audience, ['requester', 'admin'], true)
                || $template !== 'commerce.inquiry.' . $audience
            ) {
                throw new CommerceOutboxStorageException();
            }
            try {
                if (EmailAddress::fromString($recipient)->value() !== $recipient) {
                    throw new CommerceOutboxStorageException();
                }
            } catch (Throwable) {
                $this->markFailed($id, $now);

                return CommerceOutboxClaimResult::terminalFailure();
            }
            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->markFailed($id, $now);

                return CommerceOutboxClaimResult::terminalFailure();
            }

            $attempt = $attempts + 1;
            $lockToken = $this->newUuid();
            $update = $this->prepare(
                'UPDATE ' . $this->table . " SET status = 'processing', "
                . 'attempts = :attempts, locked_at = :locked_at, '
                . 'lock_token = :lock_token, sent_at = NULL, updated_at = :updated_at '
                . 'WHERE id = :id'
            );
            $timestamp = self::format($now);
            $this->execute($update, [
                'attempts' => $attempt,
                'locked_at' => $timestamp,
                'lock_token' => $lockToken,
                'updated_at' => $timestamp,
                'id' => $id,
            ]);
            if ($update->rowCount() !== 1) {
                throw new CommerceOutboxStorageException();
            }

            return CommerceOutboxClaimResult::claimed(new CommerceOutboxLease(
                $id,
                $attempt,
                $audience,
                $template,
                $recipient,
                $payload,
                $lockToken
            ));
        });
    }

    public function acknowledge(CommerceOutboxLease $lease, DateTimeImmutable $now): bool
    {
        return $this->transaction(function () use ($lease, $now): bool {
            if (!$this->leaseMatches($lease, $now)) {
                return false;
            }
            $statement = $this->prepare(
                'UPDATE ' . $this->table . " SET status = 'sent', locked_at = NULL, "
                . 'lock_token = NULL, sent_at = :sent_at, updated_at = :updated_at '
                . "WHERE id = :id AND status = 'processing' AND lock_token = :lock_token"
            );
            $timestamp = self::format($now);
            $this->execute($statement, [
                'sent_at' => $timestamp,
                'updated_at' => $timestamp,
                'id' => $lease->id(),
                'lock_token' => $lease->lockToken(),
            ]);

            return $statement->rowCount() === 1;
        });
    }

    public function recordFailure(CommerceOutboxLease $lease, DateTimeImmutable $now): string
    {
        return $this->transaction(function () use ($lease, $now): string {
            if (!$this->leaseMatches($lease, $now)) {
                return self::FAILURE_FENCED;
            }
            $permanent = $lease->attempt() >= self::MAX_ATTEMPTS;
            $available = $permanent ? $now : $now->modify(
                '+' . (self::BACKOFF_SECONDS[$lease->attempt()] ?? 3600) . ' seconds'
            );
            $statement = $this->prepare(
                'UPDATE ' . $this->table . ' SET status = :status, '
                . 'available_at = :available_at, locked_at = NULL, lock_token = NULL, '
                . 'sent_at = NULL, updated_at = :updated_at WHERE id = :id '
                . "AND status = 'processing' AND lock_token = :lock_token"
            );
            $this->execute($statement, [
                'status' => $permanent ? 'failed' : 'pending',
                'available_at' => self::format($available),
                'updated_at' => self::format($now),
                'id' => $lease->id(),
                'lock_token' => $lease->lockToken(),
            ]);
            if ($statement->rowCount() !== 1) {
                throw new CommerceOutboxStorageException();
            }

            return $permanent
                ? self::FAILURE_PERMANENT
                : self::FAILURE_RETRY_SCHEDULED;
        });
    }

    private function leaseMatches(CommerceOutboxLease $lease, DateTimeImmutable $now): bool
    {
        $statement = $this->prepare(
            'SELECT status, attempts, locked_at, lock_token FROM ' . $this->table
            . ' WHERE id = :id' . $this->forUpdate()
        );
        $this->execute($statement, ['id' => $lease->id()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }
        $attempts = $this->nonNegativeInteger($row['attempts'] ?? null);
        $lockedAt = $this->parseTimestamp($row['locked_at'] ?? null);

        return ($row['status'] ?? null) === 'processing'
            && $attempts === $lease->attempt()
            && is_string($row['lock_token'] ?? null)
            && hash_equals($row['lock_token'], $lease->lockToken())
            && $lockedAt > $now->modify('-' . self::LEASE_SECONDS . ' seconds');
    }

    private function markFailed(int $id, DateTimeImmutable $now): void
    {
        $statement = $this->prepare(
            'UPDATE ' . $this->table . " SET status = 'failed', locked_at = NULL, "
            . 'lock_token = NULL, sent_at = NULL, updated_at = :updated_at WHERE id = :id'
        );
        $this->execute($statement, ['updated_at' => self::format($now), 'id' => $id]);
        if ($statement->rowCount() !== 1) {
            throw new CommerceOutboxStorageException();
        }
    }

    /** @template T @param callable(): T $operation @return T */
    private function transaction(callable $operation): mixed
    {
        if ($this->transactionActive || $this->pdo->inTransaction()) {
            throw new CommerceOutboxStorageException();
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return $this->transactionOnce($operation);
            } catch (Throwable $exception) {
                if ($attempt === 0 && $this->isRetryableMySqlConflict($exception)) {
                    continue;
                }
                if ($exception instanceof CommerceOutboxStorageException) {
                    throw $exception;
                }
                throw new CommerceOutboxStorageException();
            }
        }
        throw new CommerceOutboxStorageException();
    }

    /** @template T @param callable(): T $operation @return T */
    private function transactionOnce(callable $operation): mixed
    {
        $sqlite = $this->tables->driver() === 'sqlite';
        $started = false;
        try {
            $this->transactionActive = true;
            $started = $sqlite
                ? $this->pdo->exec('BEGIN IMMEDIATE') !== false
                : $this->pdo->beginTransaction();
            if (!$started) {
                throw new CommerceOutboxStorageException();
            }
            $result = $operation();
            $committed = $sqlite
                ? $this->pdo->exec('COMMIT') !== false
                : $this->pdo->commit();
            if (!$committed) {
                throw new CommerceOutboxStorageException();
            }
            $started = false;
            $this->transactionActive = false;

            return $result;
        } catch (Throwable $exception) {
            try {
                if ($started) {
                    if ($sqlite) {
                        $this->pdo->exec('ROLLBACK');
                    } elseif ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                }
            } catch (Throwable) {
            }
            $this->transactionActive = false;
            throw $exception;
        }
    }

    private function forUpdate(): string
    {
        return $this->tables->driver() === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new CommerceOutboxStorageException();
        }

        return $statement;
    }

    /** @param array<string, mixed> $parameters */
    private function execute(PDOStatement $statement, array $parameters): void
    {
        if (!$statement->execute($parameters)) {
            throw new CommerceOutboxStorageException();
        }
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        return is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1
            ? (int) $value
            : null;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        return is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) === 1
            ? (int) $value
            : null;
    }

    private function parseTimestamp(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new CommerceOutboxStorageException();
        }
        $parsed = DateTimeImmutable::createFromFormat(
            '!' . self::UTC_FORMAT,
            $value,
            new DateTimeZone('UTC')
        );
        if (!$parsed instanceof DateTimeImmutable || self::format($parsed) !== $value) {
            throw new CommerceOutboxStorageException();
        }

        return $parsed;
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format(self::UTC_FORMAT);
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
            . substr($hex, 20);
    }

    private function isRetryableMySqlConflict(Throwable $exception): bool
    {
        if ($this->tables->driver() !== 'mysql' || !$exception instanceof PDOException) {
            return false;
        }
        $driverCode = is_array($exception->errorInfo ?? null)
            ? (int) ($exception->errorInfo[1] ?? 0)
            : 0;

        return in_array((string) $exception->getCode(), ['40001', '41000'], true)
            || in_array($driverCode, [1205, 1213], true);
    }
}
