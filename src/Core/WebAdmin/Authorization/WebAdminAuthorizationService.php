<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Authorization;

use App\Core\WebAdmin\Authentication\SessionSecrets;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationRepository;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\SystemClock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use Throwable;

/** Revalidates the live session, user and effective capability on every gate. */
final class WebAdminAuthorizationService
{
    public const ACCESS_CAPABILITY = 'webadmin.access';

    private readonly PasswordHasher $passwordHasher;

    public function __construct(
        private readonly PDO $pdo,
        private readonly WebAdminTableNames $tables,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly SecureTokenGenerator $tokenGenerator =
            new SecureTokenGenerator(),
        ?PasswordHasher $passwordHasher = null
    ) {
        $this->passwordHasher = $passwordHasher
            ?? PasswordHasher::productive();
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
                throw new AuthorizationStorageException();
            }
            if ($tables->driver() === 'sqlite') {
                $statement = $pdo->query('PRAGMA foreign_keys');
                if (
                    !$statement instanceof PDOStatement
                    || !in_array($statement->fetchColumn(), [1, '1'], true)
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

    public function hasCapability(
        #[\SensitiveParameter] string $sessionToken,
        string $capability
    ): bool {
        if (
            !$this->tokenGenerator->hasValidFormat($sessionToken)
            ||
            preg_match('/\A[a-z][a-z0-9_.-]{2,127}\z/', $capability) !== 1
        ) {
            return false;
        }

        try {
            $statement = $this->pdo->prepare(
                'SELECT s.user_id, s.session_type, s.auth_version, '
                . 's.pending_action_token_id, s.idle_expires_at, '
                . 's.absolute_expires_at, s.revoked_at, '
                . 'u.status, u.auth_version AS user_auth_version, '
                . 'u.activated_at, u.suspended_at, '
                . 'cr.user_id AS credential_user_id, cr.password_hash, '
                . 'cr.password_set_at, '
                . 'CASE WHEN EXISTS ('
                . 'SELECT 1 FROM ' . $this->tables->table('user_roles') . ' ur '
                . 'INNER JOIN ' . $this->tables->table('role_capabilities') . ' rc '
                . 'ON rc.role_id = ur.role_id '
                . 'INNER JOIN ' . $this->tables->table('capabilities') . ' c '
                . 'ON c.id = rc.capability_id '
                . 'WHERE ur.user_id = s.user_id AND c.code = :role_code'
                . ') OR EXISTS ('
                . 'SELECT 1 FROM ' . $this->tables->table('user_capabilities') . ' uc '
                . 'INNER JOIN ' . $this->tables->table('capabilities') . ' dc '
                . 'ON dc.id = uc.capability_id '
                . 'WHERE uc.user_id = s.user_id '
                . 'AND dc.code = :direct_code'
                . ') THEN 1 ELSE 0 END AS has_capability '
                . 'FROM ' . $this->tables->table('sessions') . ' s '
                . 'INNER JOIN ' . $this->tables->table('users') . ' u '
                . 'ON u.id = s.user_id '
                . 'INNER JOIN ' . $this->tables->table('credentials') . ' cr '
                . 'ON cr.user_id = u.id '
                . 'WHERE s.token_hash = :session_token_hash'
            );
            if (!$statement instanceof PDOStatement) {
                throw new AuthorizationStorageException();
            }
            if (!$statement->execute([
                'role_code' => $capability,
                'direct_code' => $capability,
                'session_token_hash' => $this->tokenGenerator
                    ->hashForStorage($sessionToken),
            ])) {
                throw new AuthorizationStorageException();
            }
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return false;
            }
            if (!is_array($row)) {
                throw new AuthorizationStorageException();
            }
            if (!$this->isCurrentContext($row)) {
                return false;
            }
            $value = $row['has_capability'] ?? null;
            if (!in_array($value, [0, 1, '0', '1'], true)) {
                throw new AuthorizationStorageException();
            }

            return in_array($value, [1, '1'], true);
        } catch (AuthorizationStorageException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AuthorizationStorageException();
        }
    }

    public function mayAccessWebAdmin(
        #[\SensitiveParameter] string $sessionToken
    ): bool
    {
        return $this->hasCapability(
            $sessionToken,
            self::ACCESS_CAPABILITY
        );
    }

    /** @param array<string, mixed> $row */
    private function isCurrentContext(array $row): bool
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $idleExpiresAt = $this->timestamp($row['idle_expires_at'] ?? null);
        $absoluteExpiresAt = $this->timestamp(
            $row['absolute_expires_at'] ?? null
        );
        $sessionUserId = $this->positiveInteger($row['user_id'] ?? null);
        $sessionAuthVersion = $this->positiveInteger(
            $row['auth_version'] ?? null
        );
        $userAuthVersion = $this->positiveInteger(
            $row['user_auth_version'] ?? null
        );
        $credentialUserId = $this->positiveInteger(
            $row['credential_user_id'] ?? null
        );
        $passwordHash = $row['password_hash'] ?? null;

        if (
            ($row['session_type'] ?? null)
                === SessionSecrets::AUTHENTICATED
            && ($row['revoked_at'] ?? null) === null
            && ($row['pending_action_token_id'] ?? null) === null
            && $sessionUserId > 0
            && $credentialUserId === $sessionUserId
            && $sessionAuthVersion === $userAuthVersion
            && ($row['status'] ?? null) === 'active'
            && ($row['suspended_at'] ?? null) === null
            && is_string($passwordHash)
            && $passwordHash !== ''
            && $this->passwordHasher->isCurrentHash($passwordHash)
            && $this->isValidLifecycleTimestamp(
                $row['activated_at'] ?? null
            )
            && $this->isValidLifecycleTimestamp(
                $row['password_set_at'] ?? null
            )
        ) {
            return $now < $idleExpiresAt && $now < $absoluteExpiresAt;
        }

        return false;
    }

    private function isValidLifecycleTimestamp(mixed $value): bool
    {
        // Authorization fails closed for lifecycle drift without turning an
        // invalid identity into an externally distinguishable storage error.
        try {
            $this->timestamp($value);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function timestamp(mixed $value): DateTimeImmutable
    {
        return WebAdminAuthenticationRepository::parseTimestamp($value);
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
}
