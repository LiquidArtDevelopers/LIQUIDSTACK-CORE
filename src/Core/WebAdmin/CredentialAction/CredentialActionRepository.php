<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\CredentialAction;

use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/** PDO boundary for invitations and password resets. */
final class CredentialActionRepository
{
    private const UTC_FORMAT = 'Y-m-d H:i:s.u';

    private bool $transactionActive = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly WebAdminTableNames $tables
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
                throw new CredentialActionStorageException();
            }

            if ($tables->driver() === 'sqlite') {
                $statement = $pdo->query('PRAGMA foreign_keys');
                $foreignKeys = $statement instanceof PDOStatement
                    ? $statement->fetchColumn()
                    : false;
                if (!in_array($foreignKeys, [1, '1'], true)) {
                    throw new CredentialActionStorageException();
                }
            }
        } catch (CredentialActionStorageException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new CredentialActionStorageException();
        }
    }

    public function driver(): string
    {
        return $this->tables->driver();
    }

    /**
     * SQLite reserves the writer with BEGIN IMMEDIATE. MySQL locks rows with
     * FOR UPDATE and retries one deadlock/serialization conflict.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed
    {
        if ($this->transactionActive || $this->pdo->inTransaction()) {
            throw new CredentialActionStorageException();
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

                if ($exception instanceof CredentialActionStorageException) {
                    throw $exception;
                }

                throw new CredentialActionStorageException();
            }
        }

        throw new CredentialActionStorageException();
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transactionOnce(callable $operation): mixed
    {
        $sqlite = $this->driver() === 'sqlite';
        $started = false;

        try {
            $this->transactionActive = true;
            if ($sqlite) {
                if ($this->pdo->exec('BEGIN IMMEDIATE') === false) {
                    throw new CredentialActionStorageException();
                }
            } elseif (!$this->pdo->beginTransaction()) {
                throw new CredentialActionStorageException();
            }
            $started = true;

            $result = $operation();
            if ($sqlite) {
                if ($this->pdo->exec('COMMIT') === false) {
                    throw new CredentialActionStorageException();
                }
            } elseif (!$this->pdo->commit()) {
                throw new CredentialActionStorageException();
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
                // Keep the external storage failure generic.
            }
            if (!$started) {
                $this->transactionActive = false;
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    public function findActionByHashForUpdate(string $tokenHash): ?array
    {
        return $this->findAction(
            'a.token_hash = :token_hash',
            ['token_hash' => $tokenHash]
        );
    }

    /** @return array{action_token_id: mixed, user_id: mixed}|null */
    public function actionReferenceByHash(string $tokenHash): ?array
    {
        return $this->actionReference(
            'token_hash = :token_hash',
            ['token_hash' => $tokenHash]
        );
    }

    /** @return array{action_token_id: mixed, user_id: mixed}|null */
    public function actionReferenceById(int $actionTokenId): ?array
    {
        return $this->actionReference(
            'id = :action_token_id',
            ['action_token_id' => $actionTokenId]
        );
    }

    /**
     * Non-locking lookup used only to establish the canonical lock order.
     * Every value is re-read and revalidated under locks immediately after.
     *
     * @param array<string, int|string> $parameters
     * @return array{action_token_id: mixed, user_id: mixed}|null
     */
    private function actionReference(
        string $where,
        array $parameters
    ): ?array {
        $statement = $this->prepare(
            'SELECT id AS action_token_id, user_id FROM '
            . $this->tables->table('action_tokens')
            . ' WHERE ' . $where
        );
        $this->execute($statement, $parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findActionByIdForUpdate(int $actionTokenId): ?array
    {
        return $this->findAction(
            'a.id = :action_token_id',
            ['action_token_id' => $actionTokenId]
        );
    }

    /**
     * @param array<string, int|string> $parameters
     * @return array<string, mixed>|null
     */
    private function findAction(string $where, array $parameters): ?array
    {
        $statement = $this->prepare(
            'SELECT a.id AS action_token_id, a.user_id, a.purpose, '
            . 'a.token_hash, a.auth_version AS token_auth_version, '
            . 'a.created_at AS token_created_at, a.expires_at, '
            . 'a.delivered_at, a.used_at, a.revoked_at AS token_revoked_at, '
            . 'u.public_id AS user_public_id, '
            . 'u.email_canonical, u.status AS user_status, '
            . 'u.auth_version AS user_auth_version, u.activated_at, '
            . 'u.suspended_at, c.user_id AS credential_user_id, '
            . 'c.password_hash, c.password_set_at '
            . 'FROM ' . $this->tables->table('action_tokens') . ' a '
            . 'INNER JOIN ' . $this->tables->table('users') . ' u '
            . 'ON u.id = a.user_id '
            . 'LEFT JOIN ' . $this->tables->table('credentials') . ' c '
            . 'ON c.user_id = u.id WHERE ' . $where . $this->forUpdate()
        );
        $this->execute($statement, $parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function actionSessionReference(string $tokenHash): ?array
    {
        $statement = $this->prepare(
            'SELECT id, pending_action_token_id, session_type FROM '
            . $this->tables->table('sessions') . ' '
            . 'WHERE token_hash = :token_hash'
        );
        $this->execute($statement, ['token_hash' => $tokenHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findSessionForUpdate(string $tokenHash): ?array
    {
        $statement = $this->prepare(
            'SELECT id, public_id, user_id, session_type, token_hash, '
            . 'csrf_token_hash, auth_version, pending_action_token_id, '
            . 'created_at, last_seen_at, idle_expires_at, '
            . 'absolute_expires_at, revoked_at FROM '
            . $this->tables->table('sessions') . ' '
            . 'WHERE token_hash = :token_hash' . $this->forUpdate()
        );
        $this->execute($statement, ['token_hash' => $tokenHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function insertActionSession(
        string $publicId,
        string $tokenHash,
        string $csrfTokenHash,
        int $actionTokenId,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $idleExpiresAt,
        DateTimeImmutable $absoluteExpiresAt
    ): void {
        $statement = $this->prepare(
            'INSERT INTO ' . $this->tables->table('sessions') . ' '
            . '(public_id, user_id, session_type, token_hash, '
            . 'csrf_token_hash, auth_version, pending_action_token_id, '
            . 'created_at, last_seen_at, idle_expires_at, '
            . 'absolute_expires_at, revoked_at) VALUES '
            . "(:public_id, NULL, 'preauth', :token_hash, :csrf_token_hash, "
            . 'NULL, :pending_action_token_id, :created_at, :last_seen_at, '
            . ':idle_expires_at, :absolute_expires_at, NULL)'
        );
        $this->execute($statement, [
            'public_id' => $publicId,
            'token_hash' => $tokenHash,
            'csrf_token_hash' => $csrfTokenHash,
            'pending_action_token_id' => $actionTokenId,
            'created_at' => self::format($createdAt),
            'last_seen_at' => self::format($createdAt),
            'idle_expires_at' => self::format($idleExpiresAt),
            'absolute_expires_at' => self::format($absoluteExpiresAt),
        ]);
    }

    /**
     * Bounds scanner/retry sessions without invalidating the immediately
     * preceding browser. The action row is already locked by the caller, so
     * concurrent bindings for the same token serialize on both drivers.
     */
    public function pruneActionSessionsForToken(
        int $actionTokenId,
        DateTimeImmutable $now,
        int $keepNewest
    ): void {
        if ($keepNewest < 0 || $keepNewest > 3) {
            throw new CredentialActionStorageException();
        }
        $sessions = $this->tables->table('sessions');
        $statement = $this->prepare(
            'DELETE FROM ' . $sessions . ' '
            . "WHERE session_type = 'preauth' AND user_id IS NULL "
            . 'AND auth_version IS NULL '
            . 'AND pending_action_token_id = :action_token_id '
            . 'AND (revoked_at IS NOT NULL OR idle_expires_at <= :now_idle '
            . 'OR absolute_expires_at <= :now_absolute)'
        );
        $this->execute($statement, [
            'action_token_id' => $actionTokenId,
            'now_idle' => self::format($now),
            'now_absolute' => self::format($now),
        ]);

        $statement = $this->prepare(
            'SELECT id FROM ' . $sessions . ' '
            . "WHERE session_type = 'preauth' AND user_id IS NULL "
            . 'AND auth_version IS NULL AND revoked_at IS NULL '
            . 'AND pending_action_token_id = :action_token_id '
            . 'ORDER BY last_seen_at DESC, created_at DESC, id DESC'
        );
        $this->execute($statement, ['action_token_id' => $actionTokenId]);
        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($ids)) {
            throw new CredentialActionStorageException();
        }
        foreach (array_slice($ids, $keepNewest) as $id) {
            $sessionId = $this->positiveInteger($id);
            $delete = $this->prepare(
                'DELETE FROM ' . $sessions . ' WHERE id = :id '
                . "AND session_type = 'preauth' AND user_id IS NULL "
                . 'AND auth_version IS NULL '
                . 'AND pending_action_token_id = :action_token_id'
            );
            $this->execute($delete, [
                'id' => $sessionId,
                'action_token_id' => $actionTokenId,
            ]);
        }
    }

    public function touchSession(
        int $sessionId,
        DateTimeImmutable $lastSeenAt,
        DateTimeImmutable $idleExpiresAt
    ): void {
        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('sessions') . ' SET '
            . 'last_seen_at = :last_seen_at, '
            . 'idle_expires_at = :idle_expires_at '
            . 'WHERE id = :id AND revoked_at IS NULL'
        );
        $this->execute($statement, [
            'last_seen_at' => self::format($lastSeenAt),
            'idle_expires_at' => self::format($idleExpiresAt),
            'id' => $sessionId,
        ]);
    }

    public function revokeSession(
        int $sessionId,
        DateTimeImmutable $revokedAt
    ): void {
        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('sessions') . ' '
            . 'SET revoked_at = :revoked_at '
            . 'WHERE id = :id AND revoked_at IS NULL'
        );
        $this->execute($statement, [
            'revoked_at' => self::format($revokedAt),
            'id' => $sessionId,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findUserCredentialByEmailForUpdate(
        string $emailCanonical
    ): ?array {
        $statement = $this->prepare(
            'SELECT u.id AS user_id, u.public_id AS user_public_id, '
            . 'u.email_canonical, u.status AS user_status, '
            . 'u.auth_version AS user_auth_version, u.activated_at, '
            . 'u.suspended_at, c.user_id AS credential_user_id, '
            . 'c.password_hash, c.password_set_at FROM '
            . $this->tables->table('users') . ' u LEFT JOIN '
            . $this->tables->table('credentials') . ' c '
            . 'ON c.user_id = u.id '
            . 'WHERE u.email_canonical = :email_canonical'
            . $this->forUpdate()
        );
        $this->execute($statement, ['email_canonical' => $emailCanonical]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findUserCredentialByIdForUpdate(int $userId): ?array
    {
        $statement = $this->prepare(
            'SELECT u.id AS user_id, u.public_id AS user_public_id, '
            . 'u.email_canonical, u.status AS user_status, '
            . 'u.auth_version AS user_auth_version, u.activated_at, '
            . 'u.suspended_at, c.user_id AS credential_user_id, '
            . 'c.password_hash, c.password_set_at FROM '
            . $this->tables->table('users') . ' u LEFT JOIN '
            . $this->tables->table('credentials') . ' c '
            . 'ON c.user_id = u.id WHERE u.id = :user_id'
            . $this->forUpdate()
        );
        $statement->bindValue('user_id', $userId, PDO::PARAM_INT);
        $this->execute($statement);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function revokeLivePasswordResetTokens(
        int $userId,
        DateTimeImmutable $revokedAt
    ): void {
        $statement = $this->prepare(
            'SELECT id FROM ' . $this->tables->table('action_tokens') . ' '
            . 'WHERE user_id = :user_id '
            . "AND purpose = 'password_reset' AND used_at IS NULL "
            . 'AND revoked_at IS NULL ORDER BY id' . $this->forUpdate()
        );
        $this->execute($statement, ['user_id' => $userId]);
        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($ids)) {
            throw new CredentialActionStorageException();
        }
        foreach ($ids as $id) {
            $this->revokeActionSessionsForToken(
                $this->positiveInteger($id),
                $revokedAt
            );
        }

        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('action_tokens') . ' SET '
            . 'revoked_at = :revoked_at WHERE user_id = :user_id '
            . "AND purpose = 'password_reset' AND used_at IS NULL "
            . 'AND revoked_at IS NULL'
        );
        $this->execute($statement, [
            'revoked_at' => self::format($revokedAt),
            'user_id' => $userId,
        ]);
    }

    public function insertPasswordResetActionToken(
        int $userId,
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
            . "(:user_id, 'password_reset', :token_hash, :auth_version, "
            . 'NULL, :created_at, :expires_at, NULL, NULL, NULL)'
        );
        $this->execute($statement, [
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'auth_version' => $authVersion,
            'created_at' => self::format($createdAt),
            'expires_at' => self::format($expiresAt),
        ]);
        return $this->positiveInteger($this->pdo->lastInsertId());
    }

    public function markPasswordResetActionDelivered(
        int $actionTokenId,
        int $userId,
        int $authVersion,
        string $tokenHash,
        DateTimeImmutable $deliveredAt
    ): bool {
        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('action_tokens') . ' SET '
            . 'delivered_at = :delivered_at WHERE id = :id '
            . "AND user_id = :user_id AND purpose = 'password_reset' "
            . 'AND auth_version = :auth_version AND token_hash = :token_hash '
            . 'AND delivered_at IS NULL AND used_at IS NULL '
            . 'AND revoked_at IS NULL AND expires_at > :expires_after'
        );
        $formatted = self::format($deliveredAt);
        $this->execute($statement, [
            'delivered_at' => $formatted,
            'id' => $actionTokenId,
            'user_id' => $userId,
            'auth_version' => $authVersion,
            'token_hash' => $tokenHash,
            'expires_after' => $formatted,
        ]);

        return $statement->rowCount() === 1;
    }

    public function revokePasswordResetActionToken(
        int $actionTokenId,
        int $userId,
        DateTimeImmutable $revokedAt
    ): void {
        $this->revokeActionSessionsForToken($actionTokenId, $revokedAt);
        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('action_tokens') . ' SET '
            . 'revoked_at = :revoked_at WHERE id = :id '
            . "AND user_id = :user_id AND purpose = 'password_reset' "
            . 'AND used_at IS NULL AND revoked_at IS NULL'
        );
        $this->execute($statement, [
            'revoked_at' => self::format($revokedAt),
            'id' => $actionTokenId,
            'user_id' => $userId,
        ]);
    }

    private function revokeActionSessionsForToken(
        int $actionTokenId,
        DateTimeImmutable $revokedAt
    ): void {
        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('sessions') . ' SET '
            . 'revoked_at = :revoked_at '
            . 'WHERE pending_action_token_id = :action_token_id '
            . 'AND revoked_at IS NULL'
        );
        $this->execute($statement, [
            'revoked_at' => self::format($revokedAt),
            'action_token_id' => $actionTokenId,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findRateLimitForUpdate(
        string $action,
        string $subjectHash
    ): ?array {
        $statement = $this->prepare(
            'SELECT action, subject_hash, window_started_at, attempts, '
            . 'blocked_until, updated_at FROM '
            . $this->tables->table('rate_limits') . ' '
            . 'WHERE action = :action AND subject_hash = :subject_hash'
            . $this->forUpdate()
        );
        $this->execute($statement, [
            'action' => $action,
            'subject_hash' => $subjectHash,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function insertRateLimit(
        string $action,
        string $subjectHash,
        DateTimeImmutable $windowStartedAt,
        int $attempts,
        ?DateTimeImmutable $blockedUntil,
        DateTimeImmutable $updatedAt,
        int $requestLimit,
        DateTimeImmutable $newBlockedUntil
    ): void {
        $sql = 'INSERT INTO ' . $this->tables->table('rate_limits') . ' '
            . '(action, subject_hash, window_started_at, attempts, '
            . 'blocked_until, updated_at) VALUES '
            . '(:action, :subject_hash, :window_started_at, :attempts, '
            . ':blocked_until, :updated_at)';
        if ($this->driver() === 'mysql') {
            $sql .= ' ON DUPLICATE KEY UPDATE '
                . 'blocked_until = CASE '
                . 'WHEN blocked_until IS NOT NULL THEN blocked_until '
                . 'WHEN attempts >= :request_threshold '
                . 'THEN :new_blocked_until ELSE NULL END, '
                . 'attempts = CASE WHEN attempts >= :request_cap '
                . 'THEN :request_cap_value ELSE attempts + 1 END, '
                . 'updated_at = :duplicate_updated_at';
        }
        $statement = $this->prepare($sql);
        $statement->bindValue('action', $action);
        $statement->bindValue('subject_hash', $subjectHash);
        $statement->bindValue(
            'window_started_at',
            self::format($windowStartedAt)
        );
        $statement->bindValue('attempts', $attempts, PDO::PARAM_INT);
        $this->bindNullableDateTime(
            $statement,
            'blocked_until',
            $blockedUntil
        );
        $statement->bindValue('updated_at', self::format($updatedAt));
        if ($this->driver() === 'mysql') {
            $statement->bindValue(
                'request_threshold',
                $requestLimit - 1,
                PDO::PARAM_INT
            );
            $statement->bindValue(
                'request_cap',
                $requestLimit,
                PDO::PARAM_INT
            );
            $statement->bindValue(
                'request_cap_value',
                $requestLimit,
                PDO::PARAM_INT
            );
            $statement->bindValue(
                'new_blocked_until',
                self::format($newBlockedUntil)
            );
            $statement->bindValue(
                'duplicate_updated_at',
                self::format($updatedAt)
            );
        }
        $this->execute($statement);
    }

    public function updateRateLimit(
        string $action,
        string $subjectHash,
        DateTimeImmutable $windowStartedAt,
        int $attempts,
        ?DateTimeImmutable $blockedUntil,
        DateTimeImmutable $updatedAt
    ): void {
        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('rate_limits') . ' SET '
            . 'window_started_at = :window_started_at, attempts = :attempts, '
            . 'blocked_until = :blocked_until, updated_at = :updated_at '
            . 'WHERE action = :action AND subject_hash = :subject_hash'
        );
        $statement->bindValue(
            'window_started_at',
            self::format($windowStartedAt)
        );
        $statement->bindValue('attempts', $attempts, PDO::PARAM_INT);
        $this->bindNullableDateTime(
            $statement,
            'blocked_until',
            $blockedUntil
        );
        $statement->bindValue('updated_at', self::format($updatedAt));
        $statement->bindValue('action', $action);
        $statement->bindValue('subject_hash', $subjectHash);
        $this->execute($statement);
    }

    public function deleteRateLimit(
        string $action,
        string $subjectHash
    ): void {
        $statement = $this->prepare(
            'DELETE FROM ' . $this->tables->table('rate_limits') . ' '
            . 'WHERE action = :action AND subject_hash = :subject_hash'
        );
        $this->execute($statement, [
            'action' => $action,
            'subject_hash' => $subjectHash,
        ]);
    }

    public function replaceCredential(
        int $userId,
        string $passwordHash,
        DateTimeImmutable $passwordSetAt
    ): void {
        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('credentials') . ' SET '
            . 'password_hash = :password_hash, '
            . 'password_set_at = :password_set_at, updated_at = :updated_at '
            . 'WHERE user_id = :user_id'
        );
        $formatted = self::format($passwordSetAt);
        $statement->bindValue('password_hash', $passwordHash);
        $statement->bindValue('password_set_at', $formatted);
        $statement->bindValue('updated_at', $formatted);
        $statement->bindValue('user_id', $userId, PDO::PARAM_INT);
        $this->execute($statement);
        if ($statement->rowCount() !== 1) {
            throw new CredentialActionStorageException();
        }
    }

    public function advanceUserAuthenticationVersion(
        int $userId,
        int $expectedVersion,
        string $expectedStatus,
        bool $activate,
        DateTimeImmutable $updatedAt
    ): void {
        $formatted = self::format($updatedAt);
        $sql = 'UPDATE ' . $this->tables->table('users') . ' SET '
            . 'auth_version = :next_version, updated_at = :updated_at';
        if ($activate) {
            $sql .= ", status = 'active', activated_at = :activated_at, "
                . 'suspended_at = NULL';
        }
        $sql .= ' WHERE id = :user_id AND auth_version = :expected_version '
            . 'AND status = :expected_status';
        $statement = $this->prepare($sql);
        $statement->bindValue(
            'next_version',
            $expectedVersion + 1,
            PDO::PARAM_INT
        );
        $statement->bindValue('updated_at', $formatted);
        if ($activate) {
            $statement->bindValue('activated_at', $formatted);
        }
        $statement->bindValue('user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(
            'expected_version',
            $expectedVersion,
            PDO::PARAM_INT
        );
        $statement->bindValue('expected_status', $expectedStatus);
        $this->execute($statement);
        if ($statement->rowCount() !== 1) {
            throw new CredentialActionStorageException();
        }
    }

    public function markActionTokenUsed(
        int $actionTokenId,
        DateTimeImmutable $usedAt
    ): void {
        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('action_tokens') . ' SET '
            . 'used_at = :used_at WHERE id = :id '
            . 'AND used_at IS NULL AND revoked_at IS NULL'
        );
        $this->execute($statement, [
            'used_at' => self::format($usedAt),
            'id' => $actionTokenId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new CredentialActionStorageException();
        }
    }

    public function revokeOtherActionTokens(
        int $userId,
        int $usedActionTokenId,
        DateTimeImmutable $revokedAt
    ): void {
        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('action_tokens') . ' SET '
            . 'revoked_at = :revoked_at WHERE user_id = :user_id '
            . 'AND id <> :used_id AND used_at IS NULL AND revoked_at IS NULL'
        );
        $this->execute($statement, [
            'revoked_at' => self::format($revokedAt),
            'user_id' => $userId,
            'used_id' => $usedActionTokenId,
        ]);
    }

    public function revokeAllUserAndActionSessions(
        int $userId,
        DateTimeImmutable $revokedAt
    ): void {
        $sessions = $this->tables->table('sessions');
        $tokens = $this->tables->table('action_tokens');
        $statement = $this->prepare(
            'UPDATE ' . $sessions . ' SET revoked_at = :revoked_at '
            . 'WHERE revoked_at IS NULL AND (user_id = :user_id '
            . 'OR pending_action_token_id IN (SELECT id FROM ' . $tokens
            . ' WHERE user_id = :action_user_id))'
        );
        $this->execute($statement, [
            'revoked_at' => self::format($revokedAt),
            'user_id' => $userId,
            'action_user_id' => $userId,
        ]);
    }

    public function insertAuditEvent(
        string $requestId,
        string $eventCode,
        string $outcome,
        ?string $reasonCode,
        ?int $actorUserId,
        ?string $targetPublicId,
        ?string $ipHash,
        ?string $userAgentHash,
        DateTimeImmutable $occurredAt
    ): void {
        $statement = $this->prepare(
            'INSERT INTO ' . $this->tables->table('audit_log') . ' '
            . '(request_id, actor_user_id, actor_session_public_id, '
            . 'event_code, outcome, reason_code, target_type, '
            . 'target_public_id, metadata_json, ip_hash, user_agent_hash, '
            . 'occurred_at) VALUES (:request_id, :actor_user_id, NULL, '
            . ':event_code, :outcome, :reason_code, :target_type, '
            . ':target_public_id, NULL, :ip_hash, :user_agent_hash, '
            . ':occurred_at)'
        );
        $this->bindNullableInteger(
            $statement,
            'actor_user_id',
            $actorUserId
        );
        $statement->bindValue('request_id', $requestId);
        $statement->bindValue('event_code', $eventCode);
        $statement->bindValue('outcome', $outcome);
        $statement->bindValue(
            'reason_code',
            $reasonCode,
            $reasonCode === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(
            'target_type',
            $targetPublicId === null ? null : 'webadmin_user',
            $targetPublicId === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(
            'target_public_id',
            $targetPublicId,
            $targetPublicId === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(
            'ip_hash',
            $ipHash,
            $ipHash === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(
            'user_agent_hash',
            $userAgentHash,
            $userAgentHash === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue('occurred_at', self::format($occurredAt));
        $this->execute($statement);
    }

    public static function parseTimestamp(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new CredentialActionStorageException();
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
            throw new CredentialActionStorageException();
        }

        return $parsed;
    }

    public static function format(DateTimeImmutable $value): string
    {
        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(self::UTC_FORMAT);
    }

    private function forUpdate(): string
    {
        return $this->driver() === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new CredentialActionStorageException();
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
            throw new CredentialActionStorageException();
        }
    }

    private function isRetryableMySqlConflict(Throwable $exception): bool
    {
        if (
            $this->driver() !== 'mysql'
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

    private function bindNullableInteger(
        PDOStatement $statement,
        string $parameter,
        ?int $value
    ): void {
        $statement->bindValue(
            $parameter,
            $value,
            $value === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
    }

    private function positiveInteger(mixed $value): int
    {
        if (
            (is_int($value) && $value > 0)
            || (
                is_string($value)
                && preg_match('/^[1-9][0-9]*$/D', $value) === 1
                && (int) $value > 0
            )
        ) {
            return (int) $value;
        }

        throw new CredentialActionStorageException();
    }

    private function bindNullableDateTime(
        PDOStatement $statement,
        string $parameter,
        ?DateTimeImmutable $value
    ): void {
        $statement->bindValue(
            $parameter,
            $value === null ? null : self::format($value),
            $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
    }
}
