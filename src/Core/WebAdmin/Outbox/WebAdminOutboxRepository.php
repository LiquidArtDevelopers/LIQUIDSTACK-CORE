<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Outbox;

use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\EmailAddress;
use App\Core\WebAdmin\Security\OpaqueSecret;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Support\WebAdminLocale;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Transactional PDO boundary for claiming and fencing WebAdmin email work.
 *
 * SMTP is deliberately outside this class. A claim persists only hashes and
 * returns its raw lease/action secrets in process-local redacted containers.
 */
final class WebAdminOutboxRepository
{
    public const FAILURE_RETRY_SCHEDULED = 'retry_scheduled';
    public const FAILURE_PERMANENT = 'permanent_failure';
    public const FAILURE_FENCED = 'fenced';

    public const MAX_ATTEMPTS = 5;
    public const LEASE_SECONDS = 300;
    public const INVITE_TTL_SECONDS = 259200;
    public const RESET_TTL_SECONDS = 1800;

    private const UTC_FORMAT = 'Y-m-d H:i:s.u';
    private const BACKOFF_SECONDS = [
        1 => 60,
        2 => 300,
        3 => 900,
        4 => 3600,
    ];
    private const ERROR_DELIVERY_FAILED = 'outbox.delivery_failed';
    private const ERROR_MESSAGE_INVALID = 'outbox.message_invalid';
    private const ERROR_LEASE_EXPIRED = 'outbox.lease_expired';
    private const ERROR_MAX_ATTEMPTS = 'outbox.max_attempts';
    private const ERROR_RECIPIENT_UNAVAILABLE =
        'outbox.recipient_unavailable';
    private const ERROR_LOCALE_UNSUPPORTED = 'outbox.locale_unsupported';

    private bool $transactionActive = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly WebAdminTableNames $tables,
        private readonly SecureTokenGenerator $tokens = new SecureTokenGenerator()
    ) {
        try {
            if (
                $pdo->getAttribute(PDO::ATTR_ERRMODE)
                    !== PDO::ERRMODE_EXCEPTION
                || (
                    $tables->driver() === 'mysql'
                    && !in_array(
                        $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
                        [false, 0, '0'],
                        true
                    )
                )
            ) {
                throw new WebAdminOutboxStorageException();
            }

            if ($tables->driver() === 'sqlite') {
                $statement = $pdo->query('PRAGMA foreign_keys');
                $foreignKeys = $statement instanceof PDOStatement
                    ? $statement->fetchColumn()
                    : false;
                if (!in_array($foreignKeys, [1, '1'], true)) {
                    throw new WebAdminOutboxStorageException();
                }
            }
        } catch (WebAdminOutboxStorageException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new WebAdminOutboxStorageException();
        }
    }

    public function claimNext(
        DateTimeImmutable $now
    ): WebAdminOutboxClaimResult {
        return $this->transaction(function () use ($now): WebAdminOutboxClaimResult {
            $candidate = $this->findCandidateForUpdate($now);
            if ($candidate === null) {
                return WebAdminOutboxClaimResult::none();
            }

            $outboxId = $this->positiveInteger($candidate['id'] ?? null);
            $attempts = $this->nonNegativeInteger(
                $candidate['attempts'] ?? null
            );
            $kind = $candidate['kind'] ?? null;
            $userId = $this->positiveInteger($candidate['user_id'] ?? null);
            if (
                $outboxId === null
                || $attempts === null
                || !in_array($kind, ['invite', 'password_reset'], true)
                || $userId === null
            ) {
                throw new WebAdminOutboxStorageException();
            }

            $locale = $candidate['locale'] ?? null;
            if (
                !is_string($locale)
                || !WebAdminLocale::isCanonical($locale)
            ) {
                $this->findUserForUpdate($userId);
                $this->terminalCandidate(
                    $outboxId,
                    $this->nullablePositiveInteger(
                        $candidate['action_token_id'] ?? null
                    ),
                    $now,
                    self::ERROR_LOCALE_UNSUPPORTED
                );

                return WebAdminOutboxClaimResult::terminalFailure();
            }

            if ($attempts >= self::MAX_ATTEMPTS) {
                // Keep the same outbox -> user -> token lock order used by
                // normal claims, ACKs and failures.
                $this->findUserForUpdate($userId);
                $this->terminalCandidate(
                    $outboxId,
                    $this->nullablePositiveInteger(
                        $candidate['action_token_id'] ?? null
                    ),
                    $now,
                    ($candidate['status'] ?? null) === 'processing'
                        ? self::ERROR_LEASE_EXPIRED
                        : self::ERROR_MAX_ATTEMPTS
                );

                return WebAdminOutboxClaimResult::terminalFailure();
            }

            $user = $this->findUserForUpdate($userId);
            if (!$this->isEligibleRecipient($user, $kind)) {
                $this->terminalCandidate(
                    $outboxId,
                    $this->nullablePositiveInteger(
                        $candidate['action_token_id'] ?? null
                    ),
                    $now,
                    self::ERROR_RECIPIENT_UNAVAILABLE
                );

                return WebAdminOutboxClaimResult::terminalFailure();
            }

            $email = (string) $user['email_canonical'];
            $authVersion = $this->positiveInteger(
                $user['auth_version'] ?? null
            );
            if ($authVersion === null) {
                throw new WebAdminOutboxStorageException();
            }

            $this->revokeLiveActionTokens($userId, $kind, $now);

            $rawActionToken = $this->tokens->generate();
            $rawLeaseToken = $this->tokens->generate();
            $actionTokenId = $this->insertActionToken(
                $userId,
                $kind,
                $this->tokens->hashForStorage($rawActionToken),
                $authVersion,
                $now,
                $now->modify('+' . $this->ttlFor($kind) . ' seconds')
            );
            $attempt = $attempts + 1;
            $this->markProcessing(
                $outboxId,
                $attempt,
                $now,
                $this->tokens->hashForStorage($rawLeaseToken),
                $actionTokenId
            );

            return WebAdminOutboxClaimResult::claimed(
                new WebAdminOutboxLease(
                    $outboxId,
                    $actionTokenId,
                    $attempt,
                    $kind,
                    $email,
                    $locale,
                    OpaqueSecret::fromString($rawLeaseToken),
                    OpaqueSecret::fromString($rawActionToken)
                )
            );
        });
    }

    public function acknowledge(
        WebAdminOutboxLease $lease,
        DateTimeImmutable $now
    ): bool {
        return $this->transaction(function () use ($lease, $now): bool {
            $outbox = $this->findOutboxForUpdate($lease->outboxId());
            if (!$this->matchesLease($outbox, $lease, $now)) {
                return false;
            }

            $userId = $this->positiveInteger($outbox['user_id'] ?? null);
            if ($userId === null) {
                throw new WebAdminOutboxStorageException();
            }
            $user = $this->findUserForUpdate($userId);
            $token = $this->findActionTokenForUpdate(
                $lease->actionTokenId()
            );
            if (
                !$this->isEligibleRecipient($user, $lease->kind())
                || !$this->isAcknowledgableToken(
                    $token,
                    $user,
                    $lease,
                    $now
                )
            ) {
                return false;
            }

            $statement = $this->prepare(
                'UPDATE ' . $this->tables->table('action_tokens') . ' SET '
                . 'delivered_at = :delivered_at WHERE id = :id '
                . 'AND delivered_at IS NULL AND used_at IS NULL '
                . 'AND revoked_at IS NULL'
            );
            $this->execute($statement, [
                'delivered_at' => self::format($now),
                'id' => $lease->actionTokenId(),
            ]);
            if ($statement->rowCount() !== 1) {
                throw new WebAdminOutboxStorageException();
            }

            $statement = $this->prepare(
                'UPDATE ' . $this->tables->table('outbox') . ' SET '
                . "status = 'sent', locked_at = NULL, "
                . 'lock_token_hash = NULL, last_error_code = NULL, '
                . 'sent_at = :sent_at WHERE id = :id '
                . "AND status = 'processing'"
            );
            $this->execute($statement, [
                'sent_at' => self::format($now),
                'id' => $lease->outboxId(),
            ]);
            if ($statement->rowCount() !== 1) {
                throw new WebAdminOutboxStorageException();
            }

            return true;
        });
    }

    public function recordFailure(
        WebAdminOutboxLease $lease,
        DateTimeImmutable $now,
        string $errorCode
    ): string {
        if (!in_array(
            $errorCode,
            [self::ERROR_DELIVERY_FAILED, self::ERROR_MESSAGE_INVALID],
            true
        )) {
            throw new WebAdminOutboxStorageException();
        }

        return $this->transaction(function () use (
            $lease,
            $now,
            $errorCode
        ): string {
            $outbox = $this->findOutboxForUpdate($lease->outboxId());
            if (!$this->matchesLease($outbox, $lease, $now)) {
                return self::FAILURE_FENCED;
            }

            $userId = $this->positiveInteger($outbox['user_id'] ?? null);
            if ($userId === null) {
                throw new WebAdminOutboxStorageException();
            }
            // Uses the same outbox -> user lock order as claim/ACK.
            $this->findUserForUpdate($userId);
            $this->revokeActionToken(
                $lease->actionTokenId(),
                $now
            );

            $permanent = $lease->attempt() >= self::MAX_ATTEMPTS;
            $availableAt = $permanent
                ? $now
                : $now->modify(
                    '+' . (self::BACKOFF_SECONDS[$lease->attempt()] ?? 3600)
                    . ' seconds'
                );
            $statement = $this->prepare(
                'UPDATE ' . $this->tables->table('outbox') . ' SET '
                . 'status = :status, available_at = :available_at, '
                . 'locked_at = NULL, lock_token_hash = NULL, '
                . 'action_token_id = NULL, last_error_code = :error_code, '
                . 'sent_at = NULL WHERE id = :id '
                . "AND status = 'processing'"
            );
            $this->execute($statement, [
                'status' => $permanent ? 'failed' : 'pending',
                'available_at' => self::format($availableAt),
                'error_code' => $errorCode,
                'id' => $lease->outboxId(),
            ]);
            if ($statement->rowCount() !== 1) {
                throw new WebAdminOutboxStorageException();
            }

            return $permanent
                ? self::FAILURE_PERMANENT
                : self::FAILURE_RETRY_SCHEDULED;
        });
    }

    /** @return array<string, mixed>|null */
    private function findCandidateForUpdate(
        DateTimeImmutable $now
    ): ?array {
        $statement = $this->prepare(
            'SELECT id, kind, user_id, locale, status, attempts, '
            . 'action_token_id FROM ' . $this->tables->table('outbox')
            . ' WHERE '
            . "(status = 'pending' AND available_at <= :available_now) "
            . "OR (status = 'processing' AND locked_at <= :stale_before) "
            . 'ORDER BY available_at, id LIMIT 1'
            . $this->forUpdate()
        );
        $this->execute($statement, [
            'available_now' => self::format($now),
            'stale_before' => self::format(
                $now->modify('-' . self::LEASE_SECONDS . ' seconds')
            ),
        ]);

        return $this->oneOrNull($statement->fetch(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    private function findOutboxForUpdate(int $id): ?array
    {
        $statement = $this->prepare(
            'SELECT id, kind, user_id, status, attempts, locked_at, '
            . 'lock_token_hash, '
            . 'action_token_id FROM ' . $this->tables->table('outbox')
            . ' WHERE id = :id' . $this->forUpdate()
        );
        $this->execute($statement, ['id' => $id]);

        return $this->oneOrNull($statement->fetch(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    private function findUserForUpdate(int $id): ?array
    {
        $statement = $this->prepare(
            'SELECT u.id, u.email_canonical, u.status, u.auth_version, '
            . 'u.activated_at, u.suspended_at, '
            . 'c.user_id AS credential_user_id, c.password_hash, '
            . 'c.password_set_at FROM '
            . $this->tables->table('users') . ' u LEFT JOIN '
            . $this->tables->table('credentials') . ' c '
            . 'ON c.user_id = u.id WHERE u.id = :id'
            . $this->forUpdate()
        );
        $this->execute($statement, ['id' => $id]);

        return $this->oneOrNull($statement->fetch(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    private function findActionTokenForUpdate(int $id): ?array
    {
        $statement = $this->prepare(
            'SELECT id, user_id, purpose, auth_version, expires_at, '
            . 'delivered_at, used_at, revoked_at FROM '
            . $this->tables->table('action_tokens') . ' WHERE id = :id'
            . $this->forUpdate()
        );
        $this->execute($statement, ['id' => $id]);

        return $this->oneOrNull($statement->fetch(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed>|null $user */
    private function isEligibleRecipient(?array $user, string $kind): bool
    {
        if ($user === null) {
            return false;
        }
        $email = $user['email_canonical'] ?? null;
        if (!is_string($email)) {
            return false;
        }
        try {
            if (EmailAddress::fromString($email)->value() !== $email) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $userId = $this->positiveInteger($user['id'] ?? null);
        $authVersion = $this->positiveInteger(
            $user['auth_version'] ?? null
        );
        if (
            $userId === null
            || $authVersion === null
            || $authVersion >= PHP_INT_MAX
            || $this->positiveInteger($user['credential_user_id'] ?? null)
                !== $userId
        ) {
            return false;
        }

        if ($kind === 'invite') {
            return ($user['status'] ?? null) === 'invited'
                && ($user['password_hash'] ?? null) === null
                && ($user['password_set_at'] ?? null) === null
                && ($user['activated_at'] ?? null) === null
                && ($user['suspended_at'] ?? null) === null;
        }

        if (
            $kind !== 'password_reset'
            || ($user['status'] ?? null) !== 'active'
            || ($user['suspended_at'] ?? null) !== null
            || !is_string($user['password_hash'] ?? null)
            || $user['password_hash'] === ''
        ) {
            return false;
        }

        try {
            $this->parseTimestamp($user['password_set_at'] ?? null);
            $this->parseTimestamp($user['activated_at'] ?? null);
        } catch (WebAdminOutboxStorageException) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed>|null $token
     * @param array<string, mixed>|null $user
     */
    private function isAcknowledgableToken(
        ?array $token,
        ?array $user,
        WebAdminOutboxLease $lease,
        DateTimeImmutable $now
    ): bool {
        if ($token === null || $user === null) {
            return false;
        }
        $expiresAt = $this->parseTimestamp($token['expires_at'] ?? null);

        return $this->positiveInteger($token['id'] ?? null)
                === $lease->actionTokenId()
            && $this->positiveInteger($token['user_id'] ?? null)
                === $this->positiveInteger($user['id'] ?? null)
            && ($token['purpose'] ?? null) === $lease->kind()
            && $this->positiveInteger($token['auth_version'] ?? null)
                === $this->positiveInteger($user['auth_version'] ?? null)
            && ($token['delivered_at'] ?? null) === null
            && ($token['used_at'] ?? null) === null
            && ($token['revoked_at'] ?? null) === null
            && $expiresAt > $now;
    }

    /** @param array<string, mixed>|null $outbox */
    private function matchesLease(
        ?array $outbox,
        WebAdminOutboxLease $lease,
        DateTimeImmutable $now
    ): bool {
        $storedHash = $outbox['lock_token_hash'] ?? null;
        $lockedAt = $outbox['locked_at'] ?? null;
        if (!is_string($lockedAt)) {
            return false;
        }

        return $outbox !== null
            && ($outbox['status'] ?? null) === 'processing'
            && $this->positiveInteger($outbox['id'] ?? null)
                === $lease->outboxId()
            && $this->positiveInteger($outbox['action_token_id'] ?? null)
                === $lease->actionTokenId()
            && $this->nonNegativeInteger($outbox['attempts'] ?? null)
                === $lease->attempt()
            && ($outbox['kind'] ?? null) === $lease->kind()
            && is_string($storedHash)
            && $this->parseTimestamp($lockedAt)
                > $now->modify('-' . self::LEASE_SECONDS . ' seconds')
            && $this->tokens->verify(
                $lease->revealLeaseToken(),
                $storedHash
            );
    }

    private function revokeLiveActionTokens(
        int $userId,
        string $purpose,
        DateTimeImmutable $now
    ): void {
        $statement = $this->prepare(
            'SELECT id FROM ' . $this->tables->table('action_tokens') . ' '
            . 'WHERE user_id = :user_id '
            . 'AND purpose = :purpose AND used_at IS NULL '
            . 'AND revoked_at IS NULL ORDER BY id' . $this->forUpdate()
        );
        $this->execute($statement, [
            'user_id' => $userId,
            'purpose' => $purpose,
        ]);
        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($ids)) {
            throw new WebAdminOutboxStorageException();
        }
        foreach ($ids as $id) {
            $actionTokenId = $this->positiveInteger($id);
            if ($actionTokenId === null) {
                throw new WebAdminOutboxStorageException();
            }
            $this->revokeActionSessions($actionTokenId, $now);
        }

        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('action_tokens') . ' SET '
            . 'revoked_at = :revoked_at WHERE user_id = :user_id '
            . 'AND purpose = :purpose AND used_at IS NULL '
            . 'AND revoked_at IS NULL'
        );
        $this->execute($statement, [
            'revoked_at' => self::format($now),
            'user_id' => $userId,
            'purpose' => $purpose,
        ]);
    }

    private function revokeActionToken(
        int $actionTokenId,
        DateTimeImmutable $now
    ): void {
        // Preserve user -> action -> session across delivery and consumption.
        $this->findActionTokenForUpdate($actionTokenId);
        $this->revokeActionSessions($actionTokenId, $now);
        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('action_tokens') . ' SET '
            . 'revoked_at = :revoked_at WHERE id = :id '
            . 'AND used_at IS NULL AND revoked_at IS NULL'
        );
        $this->execute($statement, [
            'revoked_at' => self::format($now),
            'id' => $actionTokenId,
        ]);
    }

    private function revokeActionSessions(
        int $actionTokenId,
        DateTimeImmutable $now
    ): void {
        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('sessions') . ' SET '
            . 'revoked_at = :revoked_at '
            . 'WHERE pending_action_token_id = :action_token_id '
            . 'AND revoked_at IS NULL'
        );
        $this->execute($statement, [
            'revoked_at' => self::format($now),
            'action_token_id' => $actionTokenId,
        ]);
    }

    private function insertActionToken(
        int $userId,
        string $purpose,
        string $tokenHash,
        int $authVersion,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt
    ): int {
        $statement = $this->prepare(
            'INSERT INTO ' . $this->tables->table('action_tokens') . ' '
            . '(user_id, purpose, token_hash, auth_version, '
            . 'created_by_user_id, created_at, expires_at, delivered_at, '
            . 'used_at, revoked_at) VALUES '
            . '(:user_id, :purpose, :token_hash, :auth_version, NULL, '
            . ':created_at, :expires_at, NULL, NULL, NULL)'
        );
        $this->execute($statement, [
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => $tokenHash,
            'auth_version' => $authVersion,
            'created_at' => self::format($createdAt),
            'expires_at' => self::format($expiresAt),
        ]);
        $id = $this->positiveInteger($this->pdo->lastInsertId());
        if ($id === null) {
            throw new WebAdminOutboxStorageException();
        }

        return $id;
    }

    private function markProcessing(
        int $outboxId,
        int $attempt,
        DateTimeImmutable $now,
        string $leaseHash,
        int $actionTokenId
    ): void {
        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('outbox') . ' SET '
            . "status = 'processing', attempts = :attempts, "
            . 'locked_at = :locked_at, lock_token_hash = :lock_hash, '
            . 'action_token_id = :action_token_id, last_error_code = NULL, '
            . 'sent_at = NULL WHERE id = :id'
        );
        $this->execute($statement, [
            'attempts' => $attempt,
            'locked_at' => self::format($now),
            'lock_hash' => $leaseHash,
            'action_token_id' => $actionTokenId,
            'id' => $outboxId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new WebAdminOutboxStorageException();
        }
    }

    private function terminalCandidate(
        int $outboxId,
        ?int $actionTokenId,
        DateTimeImmutable $now,
        string $errorCode
    ): void {
        if ($actionTokenId !== null) {
            $this->revokeActionToken($actionTokenId, $now);
        }
        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('outbox') . ' SET '
            . "status = 'failed', locked_at = NULL, "
            . 'lock_token_hash = NULL, action_token_id = NULL, '
            . 'last_error_code = :error_code, sent_at = NULL WHERE id = :id'
        );
        $this->execute($statement, [
            'error_code' => $errorCode,
            'id' => $outboxId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new WebAdminOutboxStorageException();
        }
    }

    private function ttlFor(string $kind): int
    {
        return $kind === 'invite'
            ? self::INVITE_TTL_SECONDS
            : self::RESET_TTL_SECONDS;
    }

    /** @template T @param callable(): T $operation @return T */
    private function transaction(callable $operation): mixed
    {
        if ($this->transactionActive || $this->pdo->inTransaction()) {
            throw new WebAdminOutboxStorageException();
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return $this->transactionOnce($operation);
            } catch (Throwable $exception) {
                if (
                    $attempt === 0
                    && !$this->transactionActive
                    && $this->isRetryableMySqlConflict($exception)
                ) {
                    continue;
                }
                if ($exception instanceof WebAdminOutboxStorageException) {
                    throw $exception;
                }

                throw new WebAdminOutboxStorageException();
            }
        }

        throw new WebAdminOutboxStorageException();
    }

    /** @template T @param callable(): T $operation @return T */
    private function transactionOnce(callable $operation): mixed
    {
        $sqlite = $this->tables->driver() === 'sqlite';
        $started = false;
        try {
            $this->transactionActive = true;
            if ($sqlite) {
                if ($this->pdo->exec('BEGIN IMMEDIATE') === false) {
                    throw new WebAdminOutboxStorageException();
                }
            } elseif (!$this->pdo->beginTransaction()) {
                throw new WebAdminOutboxStorageException();
            }
            $started = true;

            $result = $operation();
            if ($sqlite) {
                if ($this->pdo->exec('COMMIT') === false) {
                    throw new WebAdminOutboxStorageException();
                }
            } elseif (!$this->pdo->commit()) {
                throw new WebAdminOutboxStorageException();
            }
            $started = false;
            $this->transactionActive = false;

            return $result;
        } catch (Throwable $exception) {
            try {
                if ($started) {
                    if ($sqlite) {
                        if ($this->pdo->exec('ROLLBACK') !== false) {
                            $started = false;
                        }
                    } elseif (
                        $this->pdo->inTransaction()
                        && $this->pdo->rollBack()
                    ) {
                        $started = false;
                    } elseif (!$this->pdo->inTransaction()) {
                        $started = false;
                    }
                }
            } catch (Throwable) {
                // The public failure remains intentionally generic.
            }
            if (!$started) {
                $this->transactionActive = false;
            }

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
            throw new WebAdminOutboxStorageException();
        }

        return $statement;
    }

    /** @param array<string, mixed>|null $parameters */
    private function execute(
        PDOStatement $statement,
        ?array $parameters = null
    ): void {
        $success = $parameters === null
            ? $statement->execute()
            : $statement->execute($parameters);
        if (!$success) {
            throw new WebAdminOutboxStorageException();
        }
    }

    /** @return array<string, mixed>|null */
    private function oneOrNull(mixed $row): ?array
    {
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new WebAdminOutboxStorageException();
        }

        return $row;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (
            is_string($value)
            && preg_match('/\A[1-9][0-9]*\z/', $value) === 1
            && (string) (int) $value === $value
        ) {
            return (int) $value;
        }

        return null;
    }

    private function nullablePositiveInteger(mixed $value): ?int
    {
        return $value === null ? null : $this->positiveInteger($value);
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (
            is_string($value)
            && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) === 1
            && (string) (int) $value === $value
        ) {
            return (int) $value;
        }

        return null;
    }

    private function parseTimestamp(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new WebAdminOutboxStorageException();
        }
        $parsed = DateTimeImmutable::createFromFormat(
            '!' . self::UTC_FORMAT,
            $value,
            new DateTimeZone('UTC')
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (
            !$parsed instanceof DateTimeImmutable
            || ($errors !== false && (
                $errors['warning_count'] !== 0
                || $errors['error_count'] !== 0
            ))
            || self::format($parsed) !== $value
        ) {
            throw new WebAdminOutboxStorageException();
        }

        return $parsed;
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(self::UTC_FORMAT);
    }

    private function isRetryableMySqlConflict(Throwable $exception): bool
    {
        if (
            $this->tables->driver() !== 'mysql'
            || !$exception instanceof PDOException
        ) {
            return false;
        }
        $sqlState = (string) $exception->getCode();
        $driverCode = is_array($exception->errorInfo ?? null)
            ? (int) ($exception->errorInfo[1] ?? 0)
            : 0;

        return $sqlState === '40001'
            || $driverCode === 1205
            || $driverCode === 1213;
    }
}
