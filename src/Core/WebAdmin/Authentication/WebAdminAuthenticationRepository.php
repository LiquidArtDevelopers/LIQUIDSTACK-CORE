<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Authentication;

use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * PDO boundary for the WebAdmin authentication domain.
 *
 * Every value is bound. Only identifiers validated by WebAdminTableNames are
 * interpolated. Callers receive generic storage failures without SQL or input
 * values.
 */
final class WebAdminAuthenticationRepository
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
                throw new AuthenticationStorageException();
            }

            if ($tables->driver() === 'sqlite') {
                $statement = $pdo->query('PRAGMA foreign_keys');
                $foreignKeys = $statement instanceof PDOStatement
                    ? $statement->fetchColumn()
                    : false;
                if (!in_array($foreignKeys, [1, '1'], true)) {
                    throw new AuthenticationStorageException();
                }
            }
        } catch (AuthenticationStorageException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AuthenticationStorageException();
        }
    }

    public function driver(): string
    {
        return $this->tables->driver();
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed
    {
        if ($this->transactionActive || $this->pdo->inTransaction()) {
            throw new AuthenticationStorageException();
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

                if ($exception instanceof AuthenticationStorageException) {
                    throw $exception;
                }
                if ($exception instanceof PreAuthenticationRateLimited) {
                    throw $exception;
                }

                throw new AuthenticationStorageException();
            }
        }

        throw new AuthenticationStorageException();
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
                    throw new AuthenticationStorageException();
                }
            } else {
                if (!$this->pdo->beginTransaction()) {
                    throw new AuthenticationStorageException();
                }
            }
            $started = true;

            $result = $operation();
            if ($sqlite) {
                if ($this->pdo->exec('COMMIT') === false) {
                    throw new AuthenticationStorageException();
                }
            } elseif (!$this->pdo->commit()) {
                throw new AuthenticationStorageException();
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
                    } elseif (
                        !$sqlite
                        && !$this->pdo->inTransaction()
                    ) {
                        // MySQL may already have aborted the transaction on a
                        // deadlock/serialization error. There is then nothing
                        // left to roll back, but the repository must be
                        // reusable so the bounded retry can run.
                        $started = false;
                    }
                }
            } catch (Throwable) {
                // The public failure stays intentionally generic.
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
        $statement = $this->prepare(
            'SELECT id, user_id, session_type FROM '
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
            'SELECT '
            . 'id, public_id, user_id, session_type, token_hash, '
            . 'csrf_token_hash, auth_version, pending_action_token_id, '
            . 'created_at, last_seen_at, idle_expires_at, '
            . 'absolute_expires_at, revoked_at '
            . 'FROM ' . $this->tables->table('sessions') . ' '
            . 'WHERE token_hash = :token_hash'
            . $this->forUpdate()
        );
        $this->execute($statement, ['token_hash' => $tokenHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function insertSession(
        string $publicId,
        ?int $userId,
        string $sessionType,
        string $tokenHash,
        string $csrfTokenHash,
        ?int $authVersion,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $idleExpiresAt,
        DateTimeImmutable $absoluteExpiresAt
    ): void {
        $statement = $this->prepare(
            'INSERT INTO ' . $this->tables->table('sessions') . ' '
            . '(public_id, user_id, session_type, token_hash, '
            . 'csrf_token_hash, auth_version, pending_action_token_id, '
            . 'created_at, last_seen_at, idle_expires_at, '
            . 'absolute_expires_at, revoked_at) '
            . 'VALUES (:public_id, :user_id, :session_type, :token_hash, '
            . ':csrf_token_hash, :auth_version, NULL, :created_at, '
            . ':last_seen_at, :idle_expires_at, :absolute_expires_at, NULL)'
        );
        $statement->bindValue('public_id', $publicId);
        $this->bindNullableInteger($statement, 'user_id', $userId);
        $statement->bindValue('session_type', $sessionType);
        $statement->bindValue('token_hash', $tokenHash);
        $statement->bindValue('csrf_token_hash', $csrfTokenHash);
        $this->bindNullableInteger($statement, 'auth_version', $authVersion);
        $statement->bindValue('created_at', self::format($createdAt));
        $statement->bindValue('last_seen_at', self::format($createdAt));
        $statement->bindValue(
            'idle_expires_at',
            self::format($idleExpiresAt)
        );
        $statement->bindValue(
            'absolute_expires_at',
            self::format($absoluteExpiresAt)
        );
        $this->execute($statement);
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
            'SELECT u.id, u.public_id, u.email_canonical, u.display_name, '
            . 'u.status, u.auth_version, u.activated_at, u.suspended_at, '
            . 'c.user_id AS credential_user_id, c.password_hash, '
            . 'c.password_set_at '
            . 'FROM ' . $this->tables->table('users') . ' AS u '
            . 'LEFT JOIN ' . $this->tables->table('credentials') . ' AS c '
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
            'SELECT u.id, u.public_id, u.email_canonical, u.display_name, '
            . 'u.status, u.auth_version, u.activated_at, u.suspended_at, '
            . 'c.user_id AS credential_user_id, c.password_hash, '
            . 'c.password_set_at '
            . 'FROM ' . $this->tables->table('users') . ' AS u '
            . 'LEFT JOIN ' . $this->tables->table('credentials') . ' AS c '
            . 'ON c.user_id = u.id '
            . 'WHERE u.id = :user_id'
            . $this->forUpdate()
        );
        $statement->bindValue('user_id', $userId, PDO::PARAM_INT);
        $this->execute($statement);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function userHasCapability(int $userId, string $capability): bool
    {
        $statement = $this->prepare(
            'SELECT CASE WHEN EXISTS ('
            . 'SELECT 1 FROM ' . $this->tables->table('user_roles') . ' ur '
            . 'INNER JOIN ' . $this->tables->table('role_capabilities') . ' rc '
            . 'ON rc.role_id = ur.role_id '
            . 'INNER JOIN ' . $this->tables->table('capabilities') . ' c '
            . 'ON c.id = rc.capability_id '
            . 'WHERE ur.user_id = :role_user_id AND c.code = :role_code'
            . ') OR EXISTS ('
            . 'SELECT 1 FROM ' . $this->tables->table('user_capabilities') . ' uc '
            . 'INNER JOIN ' . $this->tables->table('capabilities') . ' dc '
            . 'ON dc.id = uc.capability_id '
            . 'WHERE uc.user_id = :direct_user_id '
            . 'AND dc.code = :direct_code'
            . ') THEN 1 ELSE 0 END'
        );
        $statement->bindValue('role_user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue('role_code', $capability);
        $statement->bindValue('direct_user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue('direct_code', $capability);
        $this->execute($statement);
        $value = $statement->fetchColumn();
        if (!in_array($value, [0, 1, '0', '1'], true)) {
            throw new AuthenticationStorageException();
        }

        return in_array($value, [1, '1'], true);
    }

    public function replacePasswordHash(
        int $userId,
        string $expectedHash,
        string $replacementHash,
        DateTimeImmutable $updatedAt
    ): void {
        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('credentials') . ' SET '
            . 'password_hash = :replacement_hash, '
            . 'updated_at = :updated_at '
            . 'WHERE user_id = :user_id AND password_hash = :expected_hash'
        );
        $statement->bindValue('replacement_hash', $replacementHash);
        $statement->bindValue('updated_at', self::format($updatedAt));
        $statement->bindValue('user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue('expected_hash', $expectedHash);
        $this->execute($statement);
        if ($statement->rowCount() !== 1) {
            throw new AuthenticationStorageException();
        }
    }

    public function recordSuccessfulLogin(
        int $userId,
        DateTimeImmutable $loggedInAt
    ): void {
        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('users') . ' SET '
            . 'last_login_at = :last_login_at, updated_at = :updated_at '
            . 'WHERE id = :user_id'
        );
        $formatted = self::format($loggedInAt);
        $statement->bindValue('last_login_at', $formatted);
        $statement->bindValue('updated_at', $formatted);
        $statement->bindValue('user_id', $userId, PDO::PARAM_INT);
        $this->execute($statement);
    }

    /** @return array<string, mixed>|null */
    public function findRateLimitForUpdate(
        string $action,
        string $subjectHash
    ): ?array {
        $statement = $this->prepare(
            'SELECT action, subject_hash, window_started_at, attempts, '
            . 'blocked_until, updated_at '
            . 'FROM ' . $this->tables->table('rate_limits') . ' '
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
        int $failureLimit,
        DateTimeImmutable $newBlockedUntil
    ): void {
        $sql =
            'INSERT INTO ' . $this->tables->table('rate_limits') . ' '
            . '(action, subject_hash, window_started_at, attempts, '
            . 'blocked_until, updated_at) VALUES '
            . '(:action, :subject_hash, :window_started_at, :attempts, '
            . ':blocked_until, :updated_at)';
        if ($this->driver() === 'mysql') {
            // Handles a concurrent first failure for the same absent PK. The
            // normal existing-row path remains protected by SELECT FOR UPDATE.
            $sql .= ' ON DUPLICATE KEY UPDATE '
                . 'blocked_until = CASE '
                . 'WHEN blocked_until IS NOT NULL THEN blocked_until '
                . 'WHEN attempts >= :failure_threshold '
                . 'THEN :new_blocked_until ELSE NULL END, '
                . 'attempts = CASE WHEN attempts >= :failure_cap '
                . 'THEN :failure_cap_value ELSE attempts + 1 END, '
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
                'failure_threshold',
                $failureLimit - 1,
                PDO::PARAM_INT
            );
            $statement->bindValue(
                'failure_cap',
                $failureLimit,
                PDO::PARAM_INT
            );
            $statement->bindValue(
                'failure_cap_value',
                $failureLimit,
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

    /**
     * Bounded opportunistic collection for anonymous authentication state.
     * Audit retention is deliberately separate and must follow the site's
     * explicit security/legal retention policy.
     *
     * @return array{sessions: int, rate_limits: int}
     */
    public function purgeAuthenticationGarbage(
        DateTimeImmutable $now,
        DateTimeImmutable $staleRateLimitCutoff,
        int $batchSize = 250
    ): array {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new AuthenticationStorageException();
        }

        $sessions = $this->tables->table('sessions');
        $rates = $this->tables->table('rate_limits');
        if ($this->driver() === 'mysql') {
            $sessionSql = 'DELETE FROM ' . $sessions . ' WHERE '
                . "(session_type = 'preauth' AND (revoked_at IS NOT NULL "
                . 'OR idle_expires_at <= :idle_now '
                . 'OR absolute_expires_at <= :absolute_now)) '
                . "OR (session_type = 'authenticated' AND ("
                . 'absolute_expires_at <= :authenticated_expired '
                . 'OR (revoked_at IS NOT NULL '
                . 'AND revoked_at <= :session_cutoff))) '
                . 'ORDER BY id LIMIT ' . $batchSize;
            $rateSql = 'DELETE FROM ' . $rates . ' WHERE '
                . 'updated_at < :cutoff AND '
                . '(blocked_until IS NULL OR blocked_until <= :now) '
                . 'LIMIT ' . $batchSize;
        } else {
            $sessionSql = 'DELETE FROM ' . $sessions . ' WHERE id IN ('
                . 'SELECT id FROM ' . $sessions . ' WHERE '
                . "(session_type = 'preauth' AND (revoked_at IS NOT NULL "
                . 'OR idle_expires_at <= :idle_now '
                . 'OR absolute_expires_at <= :absolute_now)) '
                . "OR (session_type = 'authenticated' AND ("
                . 'absolute_expires_at <= :authenticated_expired '
                . 'OR (revoked_at IS NOT NULL '
                . 'AND revoked_at <= :session_cutoff))) '
                . 'ORDER BY id LIMIT ' . $batchSize . ')';
            $rateSql = 'DELETE FROM ' . $rates . ' WHERE rowid IN ('
                . 'SELECT rowid FROM ' . $rates . ' WHERE '
                . 'updated_at < :cutoff AND '
                . '(blocked_until IS NULL OR blocked_until <= :now) '
                . 'LIMIT ' . $batchSize . ')';
        }

        $sessionStatement = $this->prepare($sessionSql);
        $this->execute($sessionStatement, [
            'idle_now' => self::format($now),
            'absolute_now' => self::format($now),
            'authenticated_expired' => self::format($now),
            'session_cutoff' => self::format($staleRateLimitCutoff),
        ]);
        $rateStatement = $this->prepare($rateSql);
        $this->execute($rateStatement, [
            'cutoff' => self::format($staleRateLimitCutoff),
            'now' => self::format($now),
        ]);

        return [
            'sessions' => $sessionStatement->rowCount(),
            'rate_limits' => $rateStatement->rowCount(),
        ];
    }

    public function insertAuditEvent(
        string $requestId,
        ?int $actorUserId,
        ?string $actorSessionPublicId,
        string $eventCode,
        string $outcome,
        ?string $reasonCode,
        ?string $ipHash,
        ?string $userAgentHash,
        DateTimeImmutable $occurredAt
    ): void {
        $statement = $this->prepare(
            'INSERT INTO ' . $this->tables->table('audit_log') . ' '
            . '(request_id, actor_user_id, actor_session_public_id, '
            . 'event_code, outcome, reason_code, target_type, '
            . 'target_public_id, metadata_json, ip_hash, user_agent_hash, '
            . 'occurred_at) VALUES '
            . '(:request_id, :actor_user_id, :actor_session_public_id, '
            . ':event_code, :outcome, :reason_code, NULL, NULL, NULL, '
            . ':ip_hash, :user_agent_hash, :occurred_at)'
        );
        $statement->bindValue('request_id', $requestId);
        $this->bindNullableInteger(
            $statement,
            'actor_user_id',
            $actorUserId
        );
        $statement->bindValue(
            'actor_session_public_id',
            $actorSessionPublicId,
            $actorSessionPublicId === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue('event_code', $eventCode);
        $statement->bindValue('outcome', $outcome);
        $statement->bindValue(
            'reason_code',
            $reasonCode,
            $reasonCode === null ? PDO::PARAM_NULL : PDO::PARAM_STR
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
            throw new AuthenticationStorageException();
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
            throw new AuthenticationStorageException();
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
            throw new AuthenticationStorageException();
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
            throw new AuthenticationStorageException();
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
        \PDOStatement $statement,
        string $parameter,
        ?int $value
    ): void {
        $statement->bindValue(
            $parameter,
            $value,
            $value === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
    }

    private function bindNullableDateTime(
        \PDOStatement $statement,
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
