<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\UserManagement;

use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/** Transactional PDO boundary for editor administration. */
final class UserManagementRepository
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
                throw new UserManagementStorageException();
            }
            if ($tables->driver() === 'sqlite') {
                $statement = $pdo->query('PRAGMA foreign_keys');
                if (
                    !$statement instanceof PDOStatement
                    || !in_array($statement->fetchColumn(), [1, '1'], true)
                ) {
                    throw new UserManagementStorageException();
                }
            }
        } catch (UserManagementStorageException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new UserManagementStorageException();
        }
    }

    public function driver(): string
    {
        return $this->tables->driver();
    }

    /** @template T @param callable(): T $operation @return T */
    public function transaction(callable $operation): mixed
    {
        if ($this->transactionActive || $this->pdo->inTransaction()) {
            throw new UserManagementStorageException();
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
                if ($exception instanceof UserManagementStorageException) {
                    throw $exception;
                }

                throw new UserManagementStorageException();
            }
        }

        throw new UserManagementStorageException();
    }

    /** @template T @param callable(): T $operation @return T */
    private function transactionOnce(callable $operation): mixed
    {
        $sqlite = $this->driver() === 'sqlite';
        $started = false;
        try {
            $this->transactionActive = true;
            if ($sqlite) {
                if ($this->pdo->exec('BEGIN IMMEDIATE') === false) {
                    throw new UserManagementStorageException();
                }
            } elseif (!$this->pdo->beginTransaction()) {
                throw new UserManagementStorageException();
            }
            $started = true;
            $result = $operation();
            if ($sqlite) {
                if ($this->pdo->exec('COMMIT') === false) {
                    throw new UserManagementStorageException();
                }
            } elseif (!$this->pdo->commit()) {
                throw new UserManagementStorageException();
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
                // The public storage failure deliberately stays generic.
            }
            if (!$started) {
                $this->transactionActive = false;
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    public function sessionReference(string $tokenHash): ?array
    {
        return $this->one(
            'SELECT id, user_id, session_type FROM '
            . $this->tables->table('sessions')
            . ' WHERE token_hash = :token_hash',
            ['token_hash' => $tokenHash]
        );
    }

    /** @return array<string, mixed>|null */
    public function userReferenceByPublicId(string $publicId): ?array
    {
        return $this->one(
            'SELECT id, public_id FROM ' . $this->tables->table('users')
            . ' WHERE public_id = :public_id',
            ['public_id' => $publicId]
        );
    }

    /** @return array<string, mixed>|null */
    public function userReferenceByEmail(string $email): ?array
    {
        return $this->one(
            'SELECT id, public_id FROM ' . $this->tables->table('users')
            . ' WHERE email_canonical = :email',
            ['email' => $email]
        );
    }

    /**
     * Locks the unique email record or its index gap on InnoDB. Repeating this
     * lookup inside the retryable transaction turns a concurrent invite into
     * a deterministic conflict instead of leaking a uniqueness exception.
     *
     * @return array<string, mixed>|null
     */
    public function lockUserByEmail(string $email): ?array
    {
        return $this->one(
            'SELECT id, public_id FROM ' . $this->tables->table('users')
            . ' WHERE email_canonical = :email' . $this->forUpdate(),
            ['email' => $email]
        );
    }

    /**
     * Locks users one by one in ascending numeric order.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function lockUsers(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        $locked = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new UserManagementStorageException();
            }
            $row = $this->one(
                'SELECT u.id, u.public_id, u.email_canonical, '
                . 'u.display_name, u.status, u.auth_version, '
                . 'u.created_by_user_id, u.invited_at, u.activated_at, '
                . 'u.suspended_at, u.last_login_at, u.created_at, '
                . 'u.updated_at, c.user_id AS credential_user_id, '
                . 'c.password_hash, c.password_set_at '
                . 'FROM ' . $this->tables->table('users') . ' u '
                . 'LEFT JOIN ' . $this->tables->table('credentials') . ' c '
                . 'ON c.user_id = u.id WHERE u.id = :id'
                . $this->forUpdate(),
                ['id' => $id]
            );
            if ($row !== null) {
                $locked[$id] = $row;
            }
        }

        return $locked;
    }

    /** @return array<string, mixed>|null */
    public function lockSession(string $tokenHash): ?array
    {
        return $this->one(
            'SELECT id, public_id, user_id, session_type, token_hash, '
            . 'csrf_token_hash, auth_version, pending_action_token_id, '
            . 'created_at, last_seen_at, idle_expires_at, '
            . 'absolute_expires_at, revoked_at FROM '
            . $this->tables->table('sessions')
            . ' WHERE token_hash = :token_hash' . $this->forUpdate(),
            ['token_hash' => $tokenHash]
        );
    }

    public function touchSession(
        int $sessionId,
        DateTimeImmutable $now,
        DateTimeImmutable $idleExpiresAt
    ): void {
        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('sessions') . ' SET '
            . 'last_seen_at = :last_seen, idle_expires_at = :idle_expires '
            . 'WHERE id = :id AND revoked_at IS NULL'
        );
        $this->execute($statement, [
            'last_seen' => self::format($now),
            'idle_expires' => self::format($idleExpiresAt),
            'id' => $sessionId,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function editorPageRows(int $afterId, int $limit): array
    {
        if ($afterId < 0 || $limit < 1 || $limit > 101) {
            throw new UserManagementStorageException();
        }
        $statement = $this->prepare(
            'SELECT u.id, u.public_id, u.email_canonical, u.display_name, '
            . 'u.status, u.created_at, u.updated_at FROM '
            . $this->tables->table('users') . ' u WHERE u.id > :after_id '
            . 'AND EXISTS (SELECT 1 FROM '
            . $this->tables->table('user_roles') . ' ur JOIN '
            . $this->tables->table('roles') . ' r ON r.id = ur.role_id '
            . "WHERE ur.user_id = u.id AND r.code = 'editor' "
            . 'AND r.is_protected = 0 AND r.is_delegable = 1) '
            . 'AND NOT EXISTS (SELECT 1 FROM '
            . $this->tables->table('user_roles') . ' pur JOIN '
            . $this->tables->table('roles') . ' pr ON pr.id = pur.role_id '
            . 'WHERE pur.user_id = u.id AND '
            . '(pr.is_protected = 1 OR pr.is_delegable = 0)) '
            . 'ORDER BY u.id ASC LIMIT :row_limit'
        );
        $statement->bindValue('after_id', $afterId, PDO::PARAM_INT);
        $statement->bindValue('row_limit', $limit, PDO::PARAM_INT);
        $this->execute($statement);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function editorRowByPublicId(string $publicId): ?array
    {
        return $this->one(
            'SELECT u.id, u.public_id, u.email_canonical, u.display_name, '
            . 'u.status, u.invited_at, u.activated_at, u.suspended_at, '
            . 'u.last_login_at, u.created_at, u.updated_at FROM '
            . $this->tables->table('users') . ' u '
            . 'WHERE u.public_id = :public_id AND EXISTS (SELECT 1 FROM '
            . $this->tables->table('user_roles') . ' ur JOIN '
            . $this->tables->table('roles') . ' r ON r.id = ur.role_id '
            . "WHERE ur.user_id = u.id AND r.code = 'editor' "
            . 'AND r.is_protected = 0 AND r.is_delegable = 1) '
            . 'AND NOT EXISTS (SELECT 1 FROM '
            . $this->tables->table('user_roles') . ' pur JOIN '
            . $this->tables->table('roles') . ' pr ON pr.id = pur.role_id '
            . 'WHERE pur.user_id = u.id AND '
            . '(pr.is_protected = 1 OR pr.is_delegable = 0))',
            ['public_id' => $publicId]
        );
    }

    /**
     * Resolves an opaque public cursor inside the authorized list transaction.
     * The internal ordering key never leaves this persistence boundary.
     *
     * @return array{id: mixed}|null
     */
    public function editorCursorReference(string $publicId): ?array
    {
        return $this->one(
            'SELECT u.id FROM ' . $this->tables->table('users') . ' u '
            . 'WHERE u.public_id = :public_id AND EXISTS (SELECT 1 FROM '
            . $this->tables->table('user_roles') . ' ur JOIN '
            . $this->tables->table('roles') . ' r ON r.id = ur.role_id '
            . "WHERE ur.user_id = u.id AND r.code = 'editor' "
            . 'AND r.is_protected = 0 AND r.is_delegable = 1) '
            . 'AND NOT EXISTS (SELECT 1 FROM '
            . $this->tables->table('user_roles') . ' pur JOIN '
            . $this->tables->table('roles') . ' pr ON pr.id = pur.role_id '
            . 'WHERE pur.user_id = u.id AND '
            . '(pr.is_protected = 1 OR pr.is_delegable = 0))',
            ['public_id' => $publicId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function lockRoleAssignments(int $userId): array
    {
        $statement = $this->prepare(
            'SELECT ur.role_id, r.code, r.is_protected, r.is_delegable '
            . 'FROM ' . $this->tables->table('user_roles') . ' ur JOIN '
            . $this->tables->table('roles') . ' r ON r.id = ur.role_id '
            . 'WHERE ur.user_id = :user_id ORDER BY ur.role_id'
            . $this->forUpdate()
        );
        $this->execute($statement, ['user_id' => $userId]);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function roleCapabilityRows(int $userId): array
    {
        $statement = $this->prepare(
            'SELECT c.id, c.module_id, c.code, c.label_key, c.is_delegable '
            . 'FROM ' . $this->tables->table('user_roles') . ' ur JOIN '
            . $this->tables->table('role_capabilities') . ' rc '
            . 'ON rc.role_id = ur.role_id JOIN '
            . $this->tables->table('capabilities') . ' c '
            . 'ON c.id = rc.capability_id WHERE ur.user_id = :user_id '
            . 'ORDER BY c.id'
        );
        $this->execute($statement, ['user_id' => $userId]);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function lockDirectCapabilityRows(int $userId): array
    {
        $statement = $this->prepare(
            'SELECT c.id, c.module_id, c.code, c.label_key, c.is_delegable '
            . 'FROM ' . $this->tables->table('user_capabilities') . ' uc '
            . 'JOIN ' . $this->tables->table('capabilities') . ' c '
            . 'ON c.id = uc.capability_id WHERE uc.user_id = :user_id '
            . 'ORDER BY c.id' . $this->forUpdate()
        );
        $this->execute($statement, ['user_id' => $userId]);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function directCapabilityRows(int $userId): array
    {
        $statement = $this->prepare(
            'SELECT c.id, c.module_id, c.code, c.label_key, c.is_delegable '
            . 'FROM ' . $this->tables->table('user_capabilities') . ' uc '
            . 'JOIN ' . $this->tables->table('capabilities') . ' c '
            . 'ON c.id = uc.capability_id WHERE uc.user_id = :user_id '
            . 'ORDER BY c.id'
        );
        $this->execute($statement, ['user_id' => $userId]);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function delegableCapabilityRows(): array
    {
        $statement = $this->prepare(
            'SELECT id, module_id, code, label_key, is_delegable FROM '
            . $this->tables->table('capabilities')
            . ' WHERE is_delegable = 1 ORDER BY module_id, code'
        );
        $this->execute($statement);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function editorRole(): ?array
    {
        return $this->one(
            'SELECT id, code, is_protected, is_delegable FROM '
            . $this->tables->table('roles')
            . " WHERE code = 'editor'" . $this->forUpdate()
        );
    }

    public function insertInvitedEditor(
        string $publicId,
        string $email,
        ?string $displayName,
        int $actorUserId,
        DateTimeImmutable $now
    ): int {
        $statement = $this->prepare(
            'INSERT INTO ' . $this->tables->table('users') . ' '
            . '(public_id, email_canonical, display_name, status, '
            . 'auth_version, created_by_user_id, invited_at, activated_at, '
            . 'suspended_at, last_login_at, created_at, updated_at) VALUES '
            . "(:public_id, :email, :display_name, 'invited', 1, "
            . ':actor_id, :invited_at, NULL, NULL, NULL, :created_at, '
            . ':updated_at)'
        );
        $formatted = self::format($now);
        $statement->bindValue('public_id', $publicId);
        $statement->bindValue('email', $email);
        $statement->bindValue(
            'display_name',
            $displayName,
            $displayName === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue('actor_id', $actorUserId, PDO::PARAM_INT);
        $statement->bindValue('invited_at', $formatted);
        $statement->bindValue('created_at', $formatted);
        $statement->bindValue('updated_at', $formatted);
        $this->execute($statement);
        $id = $this->positiveInteger($this->pdo->lastInsertId());
        if ($id === null) {
            throw new UserManagementStorageException();
        }

        return $id;
    }

    public function insertNullCredential(int $userId, DateTimeImmutable $now): void
    {
        $statement = $this->prepare(
            'INSERT INTO ' . $this->tables->table('credentials') . ' '
            . '(user_id, password_hash, password_set_at, created_at, '
            . 'updated_at) VALUES (:user_id, NULL, NULL, :created_at, '
            . ':updated_at)'
        );
        $formatted = self::format($now);
        $this->execute($statement, [
            'user_id' => $userId,
            'created_at' => $formatted,
            'updated_at' => $formatted,
        ]);
    }

    public function assignEditorRole(
        int $userId,
        int $roleId,
        int $actorUserId,
        DateTimeImmutable $now
    ): void {
        $statement = $this->prepare(
            'INSERT INTO ' . $this->tables->table('user_roles') . ' '
            . '(user_id, role_id, assigned_by_user_id, source, created_at) '
            . "VALUES (:user_id, :role_id, :actor_id, 'manual', :created_at)"
        );
        $this->execute($statement, [
            'user_id' => $userId,
            'role_id' => $roleId,
            'actor_id' => $actorUserId,
            'created_at' => self::format($now),
        ]);
    }

    public function assignDirectCapability(
        int $userId,
        int $capabilityId,
        int $actorUserId,
        DateTimeImmutable $now
    ): void {
        $statement = $this->prepare(
            'INSERT INTO ' . $this->tables->table('user_capabilities') . ' '
            . '(user_id, capability_id, assigned_by_user_id, created_at) '
            . 'VALUES (:user_id, :capability_id, :actor_id, :created_at)'
        );
        $this->execute($statement, [
            'user_id' => $userId,
            'capability_id' => $capabilityId,
            'actor_id' => $actorUserId,
            'created_at' => self::format($now),
        ]);
    }

    public function removeDirectCapability(int $userId, int $capabilityId): void
    {
        $statement = $this->prepare(
            'DELETE FROM ' . $this->tables->table('user_capabilities')
            . ' WHERE user_id = :user_id AND capability_id = :capability_id'
        );
        $this->execute($statement, [
            'user_id' => $userId,
            'capability_id' => $capabilityId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new UserManagementStorageException();
        }
    }

    public function insertPendingInvitation(
        int $userId,
        string $locale,
        DateTimeImmutable $now
    ): void {
        $statement = $this->prepare(
            'INSERT INTO ' . $this->tables->table('outbox') . ' '
            . '(kind, user_id, locale, status, attempts, available_at, '
            . 'locked_at, lock_token_hash, action_token_id, last_error_code, '
            . "created_at, sent_at) VALUES ('invite', :user_id, :locale, "
            . "'pending', 0, :available_at, NULL, NULL, NULL, NULL, "
            . ':created_at, NULL)'
        );
        $formatted = self::format($now);
        $this->execute($statement, [
            'user_id' => $userId,
            'locale' => $locale,
            'available_at' => $formatted,
            'created_at' => $formatted,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function lockOpenOutboxForUser(int $userId): array
    {
        $statement = $this->prepare(
            'SELECT id, kind, status, action_token_id FROM '
            . $this->tables->table('outbox')
            . " WHERE user_id = :user_id AND status IN ('pending', "
            . "'processing') ORDER BY id" . $this->forUpdate()
        );
        $this->execute($statement, ['user_id' => $userId]);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function lockActionTokensForUser(int $userId): array
    {
        $statement = $this->prepare(
            'SELECT id, purpose, used_at, revoked_at FROM '
            . $this->tables->table('action_tokens')
            . ' WHERE user_id = :user_id AND used_at IS NULL '
            . 'AND revoked_at IS NULL ORDER BY id' . $this->forUpdate()
        );
        $this->execute($statement, ['user_id' => $userId]);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function lockTargetSessions(int $userId): array
    {
        $statement = $this->prepare(
            'SELECT s.id FROM ' . $this->tables->table('sessions') . ' s '
            . 'WHERE (s.user_id = :session_user_id OR EXISTS (SELECT 1 FROM '
            . $this->tables->table('action_tokens') . ' at '
            . 'WHERE at.id = s.pending_action_token_id '
            . 'AND at.user_id = :action_user_id)) '
            . 'AND s.revoked_at IS NULL ORDER BY s.id' . $this->forUpdate()
        );
        $this->execute($statement, [
            'session_user_id' => $userId,
            'action_user_id' => $userId,
        ]);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<array<string, mixed>> $lockedSessions */
    public function revokeLockedSessions(
        array $lockedSessions,
        DateTimeImmutable $now
    ): void {
        foreach ($lockedSessions as $row) {
            $id = $this->positiveInteger($row['id'] ?? null);
            if ($id === null) {
                throw new UserManagementStorageException();
            }
            $statement = $this->prepare(
                'UPDATE ' . $this->tables->table('sessions') . ' SET '
                . 'revoked_at = :revoked_at WHERE id = :id '
                . 'AND revoked_at IS NULL'
            );
            $this->execute($statement, [
                'revoked_at' => self::format($now),
                'id' => $id,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new UserManagementStorageException();
            }
        }
    }

    public function revokeLiveActionTokens(
        int $userId,
        DateTimeImmutable $now
    ): void {
        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('action_tokens') . ' SET '
            . 'revoked_at = :revoked_at WHERE user_id = :user_id '
            . 'AND used_at IS NULL AND revoked_at IS NULL'
        );
        $this->execute($statement, [
            'revoked_at' => self::format($now),
            'user_id' => $userId,
        ]);
    }

    /** @param list<array<string, mixed>> $lockedRows */
    public function terminalizeOpenOutbox(
        array $lockedRows,
        string $reasonCode
    ): void {
        if (preg_match('/\A[a-z][a-z0-9_.-]{2,95}\z/', $reasonCode) !== 1) {
            throw new UserManagementStorageException();
        }
        foreach ($lockedRows as $row) {
            $id = $this->positiveInteger($row['id'] ?? null);
            if ($id === null) {
                throw new UserManagementStorageException();
            }
            $statement = $this->prepare(
                'UPDATE ' . $this->tables->table('outbox') . ' SET '
                . "status = 'failed', locked_at = NULL, "
                . 'lock_token_hash = NULL, action_token_id = NULL, '
                . 'last_error_code = :reason, sent_at = NULL WHERE id = :id '
                . "AND status IN ('pending', 'processing')"
            );
            $this->execute($statement, ['reason' => $reasonCode, 'id' => $id]);
            if ($statement->rowCount() !== 1) {
                throw new UserManagementStorageException();
            }
        }
    }

    public function updateUserStatus(
        int $userId,
        int $expectedAuthVersion,
        string $expectedStatus,
        string $nextStatus,
        DateTimeImmutable $now
    ): void {
        if (
            !in_array($expectedStatus, ['invited', 'active', 'suspended'], true)
            || !in_array($nextStatus, ['invited', 'active', 'suspended'], true)
            || $expectedAuthVersion < 1
            || $expectedAuthVersion >= PHP_INT_MAX
        ) {
            throw new UserManagementStorageException();
        }
        $sql = 'UPDATE ' . $this->tables->table('users') . ' SET '
            . 'status = :next_status, auth_version = :next_auth_version, '
            . 'updated_at = :updated_at, suspended_at = :suspended_at '
            . 'WHERE id = :user_id AND auth_version = :expected_version '
            . 'AND status = :expected_status';
        $statement = $this->prepare($sql);
        $formatted = self::format($now);
        $statement->bindValue('next_status', $nextStatus);
        $statement->bindValue(
            'next_auth_version',
            $expectedAuthVersion + 1,
            PDO::PARAM_INT
        );
        $statement->bindValue('updated_at', $formatted);
        $statement->bindValue(
            'suspended_at',
            $nextStatus === 'suspended' ? $formatted : null,
            $nextStatus === 'suspended' ? PDO::PARAM_STR : PDO::PARAM_NULL
        );
        $statement->bindValue('user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(
            'expected_version',
            $expectedAuthVersion,
            PDO::PARAM_INT
        );
        $statement->bindValue('expected_status', $expectedStatus);
        $this->execute($statement);
        if ($statement->rowCount() !== 1) {
            throw new UserManagementStorageException();
        }
    }

    public function insertAudit(
        string $requestId,
        int $actorUserId,
        string $actorSessionPublicId,
        string $eventCode,
        string $outcome,
        ?string $reasonCode,
        ?string $targetPublicId,
        DateTimeImmutable $now
    ): void {
        if (
            preg_match('/\A[a-z][a-z0-9_.-]{2,95}\z/', $eventCode) !== 1
            || !in_array($outcome, ['success', 'denied'], true)
            || (
                $reasonCode !== null
                && preg_match('/\A[a-z][a-z0-9_.-]{2,95}\z/', $reasonCode)
                    !== 1
            )
        ) {
            throw new UserManagementStorageException();
        }
        $statement = $this->prepare(
            'INSERT INTO ' . $this->tables->table('audit_log') . ' '
            . '(request_id, actor_user_id, actor_session_public_id, '
            . 'event_code, outcome, reason_code, target_type, '
            . 'target_public_id, metadata_json, ip_hash, user_agent_hash, '
            . 'occurred_at) VALUES (:request_id, :actor_id, :session_id, '
            . ':event_code, :outcome, :reason_code, :target_type, '
            . ':target_id, NULL, NULL, NULL, :occurred_at)'
        );
        $statement->bindValue('request_id', $requestId);
        $statement->bindValue('actor_id', $actorUserId, PDO::PARAM_INT);
        $statement->bindValue('session_id', $actorSessionPublicId);
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
            'target_id',
            $targetPublicId,
            $targetPublicId === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue('occurred_at', self::format($now));
        $this->execute($statement);
    }

    public static function parseTimestamp(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new UserManagementStorageException();
        }
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            $value,
            new DateTimeZone('UTC')
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (
            !$parsed instanceof DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format(self::UTC_FORMAT) !== $value
        ) {
            throw new UserManagementStorageException();
        }

        return $parsed;
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))
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
            throw new UserManagementStorageException();
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
            throw new UserManagementStorageException();
        }
    }

    /** @param array<string, mixed> $parameters @return array<string, mixed>|null */
    private function one(string $sql, array $parameters = []): ?array
    {
        $statement = $this->prepare($sql);
        $this->execute($statement, $parameters === [] ? null : $parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new UserManagementStorageException();
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $rows): array
    {
        if (!is_array($rows)) {
            throw new UserManagementStorageException();
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new UserManagementStorageException();
            }
        }

        /** @var list<array<string, mixed>> $rows */
        return array_values($rows);
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

    private function isRetryableMySqlConflict(Throwable $exception): bool
    {
        if ($this->driver() !== 'mysql') {
            return false;
        }
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (!$current instanceof PDOException) {
                continue;
            }
            $driverCode = $current->errorInfo[1] ?? null;
            if (in_array($driverCode, [1062, 1205, 1213], true)) {
                return true;
            }
            if (in_array((string) $current->getCode(), ['40001', '41000'], true)) {
                return true;
            }
        }

        return false;
    }
}
