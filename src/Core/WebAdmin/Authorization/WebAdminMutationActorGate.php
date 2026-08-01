<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Authorization;

use App\Core\WebAdmin\Authentication\SessionSecrets;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationRepository;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\ConstantTime;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\SystemClock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Revalidates a WebAdmin actor inside an already-open module mutation.
 *
 * The caller owns commit and rollback. This gate never starts, commits or
 * rolls back a transaction, so the session idle slide is atomic with the
 * module mutation that follows it.
 */
final class WebAdminMutationActorGate
{
    private const CSRF_PURPOSE = 'csrf.session';
    private const CAPABILITY_PATTERN =
        '/\A[a-z][a-z0-9_.-]{2,127}\z/';

    private readonly PasswordHasher $passwordHasher;

    public function __construct(
        private readonly PDO $pdo,
        private readonly WebAdminTableNames $tables,
        private readonly WebAdminConfig $config,
        private readonly SecurityKey $securityKey,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly SecureTokenGenerator $tokenGenerator =
            new SecureTokenGenerator(),
        ?PasswordHasher $passwordHasher = null
    ) {
        $this->passwordHasher = $passwordHasher
            ?? PasswordHasher::productive();
        $this->assertSafeConnection();
        if (
            $config->idleTtlSeconds() < 1
            || $config->absoluteTtlSeconds() < 1
        ) {
            throw new AuthorizationStorageException();
        }
    }

    /**
     * Returns null for an invalid or unauthorized browser actor.
     *
     * Storage/configuration failures remain indistinguishable through the
     * generic AuthorizationStorageException boundary.
     */
    public function authorize(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        string $capability
    ): ?WebAdminAuthorizedActor {
        $this->assertActiveTransaction();
        if (
            !$this->tokenGenerator->hasValidFormat($sessionToken)
            || !$this->tokenGenerator->hasValidFormat($csrfToken)
            || preg_match(self::CAPABILITY_PATTERN, $capability) !== 1
        ) {
            return null;
        }

        try {
            $tokenHash = $this->tokenGenerator
                ->hashForStorage($sessionToken);
            $reference = $this->sessionReference($tokenHash);
            if ($reference === null) {
                return null;
            }
            if (
                ($reference['session_type'] ?? null)
                    !== SessionSecrets::AUTHENTICATED
            ) {
                return null;
            }

            $sessionId = $this->positiveInteger(
                $reference['id'] ?? null
            );
            $userId = $this->positiveInteger(
                $reference['user_id'] ?? null
            );

            // Canonical lock order shared by every module mutation:
            // identity/credential first, then the authenticated session.
            $user = $this->lockUserCredential($userId);
            if ($user === null) {
                return null;
            }
            $session = $this->lockSession($sessionId, $tokenHash);
            if ($session === null) {
                return null;
            }

            $now = $this->clock->now()->setTimezone(
                new DateTimeZone('UTC')
            );
            $absolute = $this->validContext(
                $user,
                $session,
                $sessionId,
                $userId,
                $sessionToken,
                $csrfToken,
                $now
            );
            if ($absolute === null) {
                return null;
            }
            if (!$this->hasEffectiveCapability($userId, $capability)) {
                return null;
            }

            $nextIdle = $now->modify(
                '+' . $this->config->idleTtlSeconds() . ' seconds'
            );
            if ($nextIdle > $absolute) {
                $nextIdle = $absolute;
            }
            $this->touchSession($sessionId, $now, $nextIdle);

            return new WebAdminAuthorizedActor(
                $userId,
                $this->uuid($user['public_id'] ?? null),
                $this->uuid($session['public_id'] ?? null)
            );
        } catch (AuthorizationStorageException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AuthorizationStorageException();
        }
    }

    private function assertSafeConnection(): void
    {
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (
                $driver !== $this->tables->driver()
                || $this->pdo->getAttribute(PDO::ATTR_ERRMODE)
                    !== PDO::ERRMODE_EXCEPTION
                || (
                    $driver === 'mysql'
                    && !in_array(
                        $this->pdo->getAttribute(
                            PDO::ATTR_EMULATE_PREPARES
                        ),
                        [false, 0, '0'],
                        true
                    )
                )
            ) {
                throw new AuthorizationStorageException();
            }
            if ($driver === 'sqlite') {
                $statement = $this->pdo->query('PRAGMA foreign_keys');
                if (
                    !$statement instanceof PDOStatement
                    || !in_array(
                        $statement->fetchColumn(),
                        [1, '1'],
                        true
                    )
                ) {
                    throw new AuthorizationStorageException();
                }
            }
        } catch (AuthorizationStorageException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AuthorizationStorageException();
        }
    }

    private function assertActiveTransaction(): void
    {
        try {
            if (!$this->pdo->inTransaction()) {
                throw new AuthorizationStorageException();
            }
        } catch (AuthorizationStorageException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AuthorizationStorageException();
        }
    }

    /** @return array<string, mixed>|null */
    private function sessionReference(
        #[\SensitiveParameter] string $tokenHash
    ): ?array
    {
        return $this->one(
            'SELECT id, user_id, session_type FROM '
            . $this->tables->table('sessions')
            . ' WHERE token_hash = :token_hash',
            ['token_hash' => $tokenHash]
        );
    }

    /** @return array<string, mixed>|null */
    private function lockUserCredential(int $userId): ?array
    {
        return $this->one(
            'SELECT u.id, u.public_id, u.status, u.auth_version, '
            . 'u.activated_at, u.suspended_at, '
            . 'c.user_id AS credential_user_id, c.password_hash, '
            . 'c.password_set_at FROM '
            . $this->tables->table('users') . ' u LEFT JOIN '
            . $this->tables->table('credentials') . ' c '
            . 'ON c.user_id = u.id WHERE u.id = :user_id'
            . $this->forUpdate(),
            ['user_id' => $userId]
        );
    }

    /** @return array<string, mixed>|null */
    private function lockSession(
        int $sessionId,
        #[\SensitiveParameter] string $tokenHash
    ): ?array {
        return $this->one(
            'SELECT id, public_id, user_id, session_type, token_hash, '
            . 'csrf_token_hash, auth_version, pending_action_token_id, '
            . 'created_at, last_seen_at, idle_expires_at, '
            . 'absolute_expires_at, revoked_at FROM '
            . $this->tables->table('sessions')
            . ' WHERE id = :session_id AND token_hash = :token_hash'
            . $this->forUpdate(),
            [
                'session_id' => $sessionId,
                'token_hash' => $tokenHash,
            ]
        );
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $session
     */
    private function validContext(
        array $user,
        array $session,
        int $expectedSessionId,
        int $expectedUserId,
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        DateTimeImmutable $now
    ): ?DateTimeImmutable {
        $storedCsrfHash = $session['csrf_token_hash'] ?? null;
        $passwordHash = $user['password_hash'] ?? null;
        $expectedCsrf = $this->securityKey->deriveToken(
            self::CSRF_PURPOSE,
            $sessionToken
        );
        $submittedCsrfMatches = ConstantTime::equals(
            $expectedCsrf,
            $csrfToken
        );
        $storedCsrfMatches = is_string($storedCsrfHash)
            && $this->tokenGenerator->verify(
                $expectedCsrf,
                $storedCsrfHash
            );

        if (
            $this->positiveInteger($user['id'] ?? null)
                !== $expectedUserId
            || $this->positiveInteger($session['id'] ?? null)
                !== $expectedSessionId
            || $this->positiveInteger($session['user_id'] ?? null)
                !== $expectedUserId
            || $this->nullablePositiveInteger(
                $user['credential_user_id'] ?? null
            ) !== $expectedUserId
            || ($session['session_type'] ?? null)
                !== SessionSecrets::AUTHENTICATED
            || ($session['revoked_at'] ?? null) !== null
            || ($session['pending_action_token_id'] ?? null) !== null
            || $this->positiveInteger($session['auth_version'] ?? null)
                !== $this->positiveInteger(
                    $user['auth_version'] ?? null
                )
            || ($user['status'] ?? null) !== 'active'
            || ($user['suspended_at'] ?? null) !== null
            || !is_string($passwordHash)
            || $passwordHash === ''
            || !$this->passwordHasher->isCurrentHash($passwordHash)
            || !$submittedCsrfMatches
            || !$storedCsrfMatches
        ) {
            return null;
        }

        $created = $this->authenticationTimestamp(
            $session['created_at'] ?? null
        );
        $lastSeen = $this->authenticationTimestamp(
            $session['last_seen_at'] ?? null
        );
        $idle = $this->authenticationTimestamp(
            $session['idle_expires_at'] ?? null
        );
        $absolute = $this->authenticationTimestamp(
            $session['absolute_expires_at'] ?? null
        );
        $activated = $this->authenticationTimestampOrNull(
            $user['activated_at'] ?? null
        );
        $passwordSet = $this->authenticationTimestampOrNull(
            $user['password_set_at'] ?? null
        );
        if (
            $activated === null
            || $passwordSet === null
            || $created > $lastSeen
            || $lastSeen > $now
            || $created >= $idle
            || $idle > $absolute
            || $activated > $now
            || $passwordSet > $now
            || $now >= $idle
            || $now >= $absolute
        ) {
            return null;
        }

        return $absolute;
    }

    private function hasEffectiveCapability(
        int $userId,
        string $capability
    ): bool {
        $statement = $this->prepare(
            'SELECT CASE WHEN EXISTS ('
            . 'SELECT 1 FROM '
            . $this->tables->table('user_roles') . ' ur INNER JOIN '
            . $this->tables->table('role_capabilities') . ' rc '
            . 'ON rc.role_id = ur.role_id INNER JOIN '
            . $this->tables->table('capabilities') . ' c '
            . 'ON c.id = rc.capability_id '
            . 'WHERE ur.user_id = :role_user_id '
            . 'AND c.code = :role_code'
            . ') OR EXISTS ('
            . 'SELECT 1 FROM '
            . $this->tables->table('user_capabilities')
            . ' uc INNER JOIN '
            . $this->tables->table('capabilities') . ' dc '
            . 'ON dc.id = uc.capability_id '
            . 'WHERE uc.user_id = :direct_user_id '
            . 'AND dc.code = :direct_code'
            . ') THEN 1 ELSE 0 END'
        );
        if (!$statement->execute([
            'role_user_id' => $userId,
            'role_code' => $capability,
            'direct_user_id' => $userId,
            'direct_code' => $capability,
        ])) {
            throw new AuthorizationStorageException();
        }
        $value = $statement->fetchColumn();
        if (!in_array($value, [0, 1, '0', '1'], true)) {
            throw new AuthorizationStorageException();
        }

        return in_array($value, [1, '1'], true);
    }

    private function touchSession(
        int $sessionId,
        DateTimeImmutable $now,
        DateTimeImmutable $idleExpiresAt
    ): void {
        $statement = $this->prepare(
            'UPDATE ' . $this->tables->table('sessions') . ' SET '
            . 'last_seen_at = :last_seen_at, '
            . 'idle_expires_at = :idle_expires_at '
            . 'WHERE id = :session_id AND revoked_at IS NULL'
        );
        if (!$statement->execute([
            'last_seen_at' => WebAdminAuthenticationRepository::format($now),
            'idle_expires_at' => WebAdminAuthenticationRepository::format(
                $idleExpiresAt
            ),
            'session_id' => $sessionId,
        ])) {
            throw new AuthorizationStorageException();
        }
    }

    /**
     * @param array<string, int|string> $parameters
     * @return array<string, mixed>|null
     */
    private function one(
        string $sql,
        #[\SensitiveParameter] array $parameters
    ): ?array
    {
        $statement = $this->prepare($sql);
        foreach ($parameters as $name => $value) {
            $statement->bindValue(
                $name,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
        if (!$statement->execute()) {
            throw new AuthorizationStorageException();
        }
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new AuthorizationStorageException();
        }

        return $row;
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new AuthorizationStorageException();
        }

        return $statement;
    }

    private function authenticationTimestamp(mixed $value): DateTimeImmutable
    {
        try {
            return WebAdminAuthenticationRepository::parseTimestamp($value);
        } catch (Throwable) {
            throw new AuthorizationStorageException();
        }
    }

    private function authenticationTimestampOrNull(
        mixed $value
    ): ?DateTimeImmutable {
        try {
            return WebAdminAuthenticationRepository::parseTimestamp($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function positiveInteger(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (
            is_string($value)
            && preg_match('/\A[1-9][0-9]*\z/', $value) === 1
            && (string) (int) $value === $value
        ) {
            return (int) $value;
        }

        throw new AuthorizationStorageException();
    }

    private function nullablePositiveInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return $this->positiveInteger($value);
    }

    private function uuid(mixed $value): string
    {
        if (
            !is_string($value)
            || preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $value
            ) !== 1
        ) {
            throw new AuthorizationStorageException();
        }

        return $value;
    }

    private function forUpdate(): string
    {
        return $this->tables->driver() === 'mysql'
            ? ' FOR UPDATE'
            : '';
    }
}
