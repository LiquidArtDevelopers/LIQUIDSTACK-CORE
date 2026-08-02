<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Bootstrap;

use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Persistence boundary for the one-time initial-accounts bootstrap.
 *
 * The state row is the lock: MySQL locks it with SELECT ... FOR UPDATE and
 * SQLite obtains the equivalent write reservation with BEGIN IMMEDIATE before
 * reading it. No application value is interpolated into SQL.
 */
final class PdoBootstrapRepository
{
    public const STATE_KEY = 'bootstrap.initial_accounts';
    public const STATE_PENDING = 'pending';
    public const STATE_COMPLETED = 'completed';

    private bool $transactionActive = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly WebAdminTableNames $tables
    ) {
        try {
            $exceptionMode = $this->pdo->getAttribute(PDO::ATTR_ERRMODE);
            $emulatedPrepares = $this->tables->driver() === 'mysql'
                ? $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)
                : false;
        } catch (Throwable) {
            throw new BootstrapException(
                'bootstrap.pdo_configuration_invalid'
            );
        }

        if (
            $exceptionMode !== PDO::ERRMODE_EXCEPTION
            || ($this->tables->driver() === 'mysql'
                && !in_array($emulatedPrepares, [false, 0, '0'], true))
        ) {
            throw new BootstrapException(
                'bootstrap.pdo_configuration_invalid'
            );
        }

        if ($this->tables->driver() === 'sqlite') {
            try {
                $statement = $this->pdo->query('PRAGMA foreign_keys');
                $foreignKeys = $statement instanceof PDOStatement
                    ? $statement->fetchColumn()
                    : false;
            } catch (Throwable) {
                $foreignKeys = false;
            }

            if (!in_array($foreignKeys, [1, '1'], true)) {
                throw new BootstrapException(
                    'bootstrap.pdo_configuration_invalid'
                );
            }
        }
    }

    /** @template T @param callable(self): T $operation @return T */
    public function transaction(callable $operation): mixed
    {
        $started = false;
        $sqlite = $this->tables->driver() === 'sqlite';

        try {
            if ($this->transactionActive || $this->pdo->inTransaction()) {
                throw new BootstrapException(
                    'bootstrap.transaction_already_active'
                );
            }

            if ($sqlite) {
                if ($this->pdo->exec('BEGIN IMMEDIATE') === false) {
                    throw new BootstrapException(
                        'bootstrap.persistence_failed'
                    );
                }
            } else {
                if (!$this->pdo->beginTransaction()) {
                    throw new BootstrapException(
                        'bootstrap.persistence_failed'
                    );
                }
            }
            $started = true;
            $this->transactionActive = true;

            $result = $operation($this);
            if ($sqlite) {
                if ($this->pdo->exec('COMMIT') === false) {
                    throw new BootstrapException(
                        'bootstrap.persistence_failed'
                    );
                }
            } else {
                if (!$this->pdo->commit()) {
                    throw new BootstrapException(
                        'bootstrap.persistence_failed'
                    );
                }
            }
            $started = false;
            $this->transactionActive = false;

            return $result;
        } catch (BootstrapException $exception) {
            if (!$this->rollback($started, $sqlite)) {
                throw new BootstrapException('bootstrap.rollback_failed');
            }
            if ($started) {
                $this->transactionActive = false;
            }

            throw $exception;
        } catch (Throwable) {
            if (!$this->rollback($started, $sqlite)) {
                throw new BootstrapException('bootstrap.rollback_failed');
            }
            if ($started) {
                $this->transactionActive = false;
            }

            throw new BootstrapException('bootstrap.persistence_failed');
        }
    }

    public function lockInitialAccountsState(): string
    {
        $sql = 'SELECT value_text FROM ' . $this->tables->table('state')
            . ' WHERE state_key = :state_key';
        if ($this->tables->driver() === 'mysql') {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->prepare($sql);
        $this->execute($statement, ['state_key' => self::STATE_KEY]);
        $value = $statement->fetchColumn();

        if (!is_string($value)) {
            throw new BootstrapException('bootstrap.schema_not_ready');
        }
        if (!in_array(
            $value,
            [self::STATE_PENDING, self::STATE_COMPLETED],
            true
        )) {
            throw new BootstrapException('bootstrap.state_invalid');
        }

        return $value;
    }

    public function mediaFeatureIsApplied(): bool
    {
        $statement = $this->prepare(
            'SELECT value_text FROM ' . $this->tables->table('state')
            . ' WHERE state_key = :state_key'
        );
        $this->execute($statement, ['state_key' => 'media.quota_lock']);
        $values = $statement->fetchAll(PDO::FETCH_COLUMN);
        if ($values === []) {
            return false;
        }
        if ($values !== ['v1']) {
            throw new BootstrapException('bootstrap.schema_not_ready');
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    public function roleByCode(string $code): ?array
    {
        $sql =
            'SELECT id, code, label_key, is_protected, is_delegable FROM '
            . $this->tables->table('roles')
            . ' WHERE code = :code';
        if ($this->tables->driver() === 'mysql') {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->prepare($sql);
        $this->execute($statement, ['code' => $code]);

        return $this->oneOrNull($statement->fetch(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function roleCapabilities(int $roleId): array
    {
        $statement = $this->prepare(
            'SELECT c.module_id, c.code, c.label_key, c.is_delegable FROM '
            . $this->tables->table('role_capabilities') . ' rc '
            . 'INNER JOIN ' . $this->tables->table('capabilities') . ' c '
            . 'ON c.id = rc.capability_id '
            . 'WHERE rc.role_id = :role_id ORDER BY c.code'
        );
        $this->execute($statement, ['role_id' => $roleId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function roleOwners(int $roleId): array
    {
        $statement = $this->prepare(
            'SELECT ur.user_id, ur.source, u.email_canonical FROM '
            . $this->tables->table('user_roles') . ' ur '
            . 'INNER JOIN ' . $this->tables->table('users') . ' u '
            . 'ON u.id = ur.user_id '
            . 'WHERE ur.role_id = :role_id ORDER BY ur.user_id'
        );
        $this->execute($statement, ['role_id' => $roleId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function userByEmail(string $email): ?array
    {
        $statement = $this->prepare(
            'SELECT id, public_id, email_canonical, status, auth_version, '
            . 'created_by_user_id, invited_at, activated_at, suspended_at '
            . 'FROM ' . $this->tables->table('users')
            . ' WHERE email_canonical = :email'
        );
        $this->execute($statement, ['email' => $email]);

        return $this->oneOrNull($statement->fetch(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function userById(int $userId): ?array
    {
        $statement = $this->prepare(
            'SELECT id, public_id, email_canonical, status, auth_version, '
            . 'created_by_user_id, invited_at, activated_at, suspended_at '
            . 'FROM ' . $this->tables->table('users')
            . ' WHERE id = :user_id'
        );
        $this->execute($statement, ['user_id' => $userId]);

        return $this->oneOrNull($statement->fetch(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function userByIdForUpdate(int $userId): ?array
    {
        $sql = 'SELECT id, public_id, email_canonical, status, auth_version, '
            . 'created_by_user_id, invited_at, activated_at, suspended_at '
            . 'FROM ' . $this->tables->table('users')
            . ' WHERE id = :user_id';
        if ($this->tables->driver() === 'mysql') {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->prepare($sql);
        $this->execute($statement, ['user_id' => $userId]);

        return $this->oneOrNull($statement->fetch(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function credentialForUser(int $userId): ?array
    {
        $statement = $this->prepare(
            'SELECT user_id, password_hash, password_set_at FROM '
            . $this->tables->table('credentials')
            . ' WHERE user_id = :user_id'
        );
        $this->execute($statement, ['user_id' => $userId]);

        return $this->oneOrNull($statement->fetch(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function roleAssignment(int $userId, int $roleId): ?array
    {
        $statement = $this->prepare(
            'SELECT user_id, role_id, assigned_by_user_id, source FROM '
            . $this->tables->table('user_roles')
            . ' WHERE user_id = :user_id AND role_id = :role_id'
        );
        $this->execute($statement, [
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);

        return $this->oneOrNull($statement->fetch(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function inviteOutboxForUser(int $userId): array
    {
        $statement = $this->prepare(
            'SELECT id, locale, status, attempts, locked_at, lock_token_hash, '
            . 'action_token_id, last_error_code, available_at, created_at, '
            . 'sent_at FROM '
            . $this->tables->table('outbox')
            . " WHERE user_id = :user_id AND kind = 'invite' ORDER BY id"
        );
        $this->execute($statement, ['user_id' => $userId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function directCapabilityCount(int $userId): int
    {
        return $this->countByUser('user_capabilities', $userId);
    }

    public function sessionCount(int $userId): int
    {
        return $this->countByUser('sessions', $userId);
    }

    public function actionTokenCount(int $userId): int
    {
        return $this->countByUser('action_tokens', $userId);
    }

    public function insertInvitedUser(
        string $publicId,
        string $email,
        string $timestamp
    ): int {
        $statement = $this->prepare(
            'INSERT INTO ' . $this->tables->table('users')
            . ' (public_id, email_canonical, status, auth_version, invited_at, '
            . 'created_at, updated_at) VALUES '
            . "(:public_id, :email, 'invited', 1, :invited_at, "
            . ':created_at, :updated_at)'
        );
        $this->execute($statement, [
            'public_id' => $publicId,
            'email' => $email,
            'invited_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $user = $this->userByEmail($email);
        if ($user === null || !$this->isPositiveInteger($user['id'] ?? null)) {
            throw new BootstrapException('bootstrap.persistence_failed');
        }

        return (int) $user['id'];
    }

    public function insertNullCredential(int $userId, string $timestamp): void
    {
        $statement = $this->prepare(
            'INSERT INTO ' . $this->tables->table('credentials')
            . ' (user_id, password_hash, password_set_at, created_at, updated_at) '
            . 'VALUES (:user_id, NULL, NULL, :created_at, :updated_at)'
        );
        $this->execute($statement, [
            'user_id' => $userId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function insertBootstrapRole(
        int $userId,
        int $roleId,
        string $timestamp
    ): void {
        $statement = $this->prepare(
            'INSERT INTO ' . $this->tables->table('user_roles')
            . " (user_id, role_id, assigned_by_user_id, source, created_at) "
            . "VALUES (:user_id, :role_id, NULL, 'bootstrap', :created_at)"
        );
        $this->execute($statement, [
            'user_id' => $userId,
            'role_id' => $roleId,
            'created_at' => $timestamp,
        ]);
    }

    public function insertPendingInvite(int $userId, string $timestamp): void
    {
        $statement = $this->prepare(
            'INSERT INTO ' . $this->tables->table('outbox')
            . ' (kind, user_id, locale, status, attempts, available_at, '
            . 'locked_at, lock_token_hash, action_token_id, last_error_code, '
            . "created_at, sent_at) VALUES ('invite', :user_id, 'und', "
            . "'pending', 0, :available_at, NULL, NULL, NULL, NULL, "
            . ':created_at, NULL)'
        );
        $this->execute($statement, [
            'user_id' => $userId,
            'available_at' => $timestamp,
            'created_at' => $timestamp,
        ]);
    }

    public function revokeLiveInvitationTokens(
        int $userId,
        string $timestamp
    ): void {
        $sql = 'SELECT id FROM ' . $this->tables->table('action_tokens')
            . ' WHERE user_id = :user_id '
            . "AND purpose = 'invite' AND used_at IS NULL "
            . 'AND revoked_at IS NULL ORDER BY id';
        if ($this->tables->driver() === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->prepare($sql);
        $this->execute($statement, ['user_id' => $userId]);
        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($ids)) {
            throw new BootstrapException('bootstrap.persistence_failed');
        }
        foreach ($ids as $id) {
            if (!$this->isPositiveInteger($id)) {
                throw new BootstrapException('bootstrap.persistence_failed');
            }
            $session = $this->prepare(
                'UPDATE ' . $this->tables->table('sessions')
                . ' SET revoked_at = :revoked_at '
                . 'WHERE pending_action_token_id = :action_token_id '
                . 'AND revoked_at IS NULL'
            );
            $this->execute($session, [
                'revoked_at' => $timestamp,
                'action_token_id' => (int) $id,
            ]);
        }

        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('action_tokens')
            . ' SET revoked_at = :revoked_at WHERE user_id = :user_id '
            . "AND purpose = 'invite' AND used_at IS NULL "
            . 'AND revoked_at IS NULL'
        );
        $this->execute($statement, [
            'revoked_at' => $timestamp,
            'user_id' => $userId,
        ]);
    }

    public function markInitialAccountsCompleted(string $timestamp): void
    {
        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('state')
            . ' SET value_text = :completed, updated_at = :updated_at '
            . 'WHERE state_key = :state_key AND value_text = :pending'
        );
        $this->execute($statement, [
            'completed' => self::STATE_COMPLETED,
            'updated_at' => $timestamp,
            'state_key' => self::STATE_KEY,
            'pending' => self::STATE_PENDING,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new BootstrapException('bootstrap.state_changed');
        }
    }

    public function insertAuditEvent(
        string $requestId,
        string $eventCode,
        ?string $targetPublicId,
        ?string $metadataJson,
        string $timestamp
    ): void {
        $statement = $this->prepare(
            'INSERT INTO ' . $this->tables->table('audit_log')
            . ' (request_id, actor_user_id, actor_session_public_id, '
            . 'event_code, outcome, reason_code, target_type, '
            . 'target_public_id, metadata_json, ip_hash, user_agent_hash, '
            . 'occurred_at) VALUES (:request_id, NULL, NULL, :event_code, '
            . "'success', NULL, :target_type, :target_public_id, "
            . ':metadata_json, NULL, NULL, :occurred_at)'
        );
        $this->execute($statement, [
            'request_id' => $requestId,
            'event_code' => $eventCode,
            'target_type' => $targetPublicId === null
                ? null
                : 'webadmin_user',
            'target_public_id' => $targetPublicId,
            'metadata_json' => $metadataJson,
            'occurred_at' => $timestamp,
        ]);
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new BootstrapException('bootstrap.persistence_failed');
        }

        return $statement;
    }

    /** @param array<string, mixed> $parameters */
    private function execute(PDOStatement $statement, array $parameters): void
    {
        if (!$statement->execute($parameters)) {
            throw new BootstrapException('bootstrap.persistence_failed');
        }
    }

    private function rollback(bool $started, bool $sqlite): bool
    {
        if (!$started) {
            return true;
        }

        try {
            // PDO does not report BEGIN IMMEDIATE through inTransaction().
            // SQLite therefore needs its matching SQL statement explicitly.
            if ($sqlite) {
                return $this->pdo->exec('ROLLBACK') !== false;
            }
            if ($this->pdo->inTransaction()) {
                return $this->pdo->rollBack();
            }
        } catch (Throwable) {
            return false;
        }

        // A driver that reports the transaction as already closed needs no
        // compensating rollback (for example, a disconnected MySQL session).
        return true;
    }

    /** @return array<string, mixed>|null */
    private function oneOrNull(mixed $row): ?array
    {
        return is_array($row) ? $row : null;
    }

    private function isPositiveInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_INT) !== false
            && (int) $value > 0;
    }

    private function countByUser(string $table, int $userId): int
    {
        $statement = $this->prepare(
            'SELECT COUNT(*) FROM ' . $this->tables->table($table)
            . ' WHERE user_id = :user_id'
        );
        $this->execute($statement, ['user_id' => $userId]);
        $count = $statement->fetchColumn();

        if (
            !is_int($count)
            && !(is_string($count) && preg_match('/\A[0-9]+\z/', $count) === 1)
        ) {
            throw new BootstrapException('bootstrap.persistence_failed');
        }

        return (int) $count;
    }
}
