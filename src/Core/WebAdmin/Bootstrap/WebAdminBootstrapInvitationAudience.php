<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Bootstrap;

use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\UnsupportedPasswordPolicy;
use App\Core\WebAdmin\Support\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Resolves only the two protected identities created by the bootstrap.
 *
 * Recipient addresses never leave the outbox boundary. This projection uses
 * internal IDs solely to scope invitation delivery and returns safe counters.
 */
final class WebAdminBootstrapInvitationAudience
{
    private const UTC_FORMAT = 'Y-m-d H:i:s.u';
    private const ROLE_CODES = ['site_admin', 'system_superadmin'];

    private readonly PasswordHasher $passwordHasher;

    public function __construct(
        private readonly PDO $pdo,
        private readonly WebAdminTableNames $tables,
        private readonly ClockInterface $clock,
        ?PasswordHasher $passwordHasher = null
    ) {
        $this->passwordHasher = $passwordHasher
            ?? PasswordHasher::productive();
        if (
            $this->passwordHasher->policyId()
                !== PasswordHasher::PRODUCTIVE_POLICY_ID
        ) {
            throw new UnsupportedPasswordPolicy();
        }
    }

    /** @return list<int> */
    public function recipientIds(): array
    {
        return array_values(array_map(
            static fn (array $identity): int => $identity['user_id'],
            $this->identities()
        ));
    }

    public function readiness(): BootstrapInvitationReadiness
    {
        $active = 0;
        $delivered = 0;
        $incomplete = 0;
        $now = $this->now();

        foreach ($this->identities() as $identity) {
            $invitations = $this->invitationState(
                $identity['user_id'],
                $identity['auth_version'],
                $now
            );
            $this->assertNoOutboxCollision($identity, $invitations);
            if ($this->isActiveIdentity($identity, $now)) {
                ++$active;
                continue;
            }
            if (
                $this->isInvitedIdentity($identity, $now)
                && $invitations['live'] === 1
            ) {
                ++$delivered;
                continue;
            }

            ++$incomplete;
        }

        return new BootstrapInvitationReadiness(
            $active,
            $delivered,
            $incomplete
        );
    }

    /**
     * Returns only protected invited identities with exactly one open invite
     * and no already-live invitation. Backoff and an active lease remain open
     * work; the outbox repository decides whether either is claimable now.
     *
     * @return list<int>
     */
    public function dispatchableRecipientIds(): array
    {
        $recipientIds = [];
        $now = $this->now();

        foreach ($this->identities() as $identity) {
            $invitations = $this->invitationState(
                $identity['user_id'],
                $identity['auth_version'],
                $now
            );
            $this->assertNoOutboxCollision($identity, $invitations);
            if (
                $this->isInvitedIdentity($identity, $now)
                && $invitations['live'] === 0
                && $invitations['open'] === 1
            ) {
                $recipientIds[] = $identity['user_id'];
            }
        }

        return $recipientIds;
    }

    /**
     * @return list<array{
     *   role_code: string,
     *   user_id: int,
     *   status: string,
     *   auth_version: int,
     *   invited_at: mixed,
     *   activated_at: mixed,
     *   suspended_at: mixed,
     *   password_hash: mixed,
     *   password_set_at: mixed
     * }>
     */
    private function identities(): array
    {
        try {
            $statement = $this->pdo->query(
                'SELECT r.code AS role_code, r.is_protected, '
                . 'r.is_delegable, ur.user_id, ur.source, u.status, '
                . 'ur.assigned_by_user_id, '
                . 'u.auth_version, u.invited_at, u.activated_at, '
                . 'u.suspended_at, '
                . 'u.created_by_user_id AS user_created_by_user_id, '
                . 'c.user_id AS credential_user_id, '
                . 'c.password_hash, c.password_set_at FROM '
                . $this->tables->table('roles') . ' r INNER JOIN '
                . $this->tables->table('user_roles') . ' ur '
                . 'ON ur.role_id = r.id INNER JOIN '
                . $this->tables->table('users') . ' u '
                . 'ON u.id = ur.user_id INNER JOIN '
                . $this->tables->table('credentials') . ' c '
                . 'ON c.user_id = u.id WHERE r.code IN '
                . "('site_admin', 'system_superadmin') "
                . 'ORDER BY r.code, ur.user_id'
            );
            if (!$statement instanceof PDOStatement) {
                throw new BootstrapException(
                    'bootstrap.persistence_unavailable'
                );
            }
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (BootstrapException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BootstrapException(
                'bootstrap.persistence_unavailable'
            );
        }

        if (!is_array($rows) || count($rows) !== 2) {
            throw new BootstrapException(
                'bootstrap.completed_state_incompatible'
            );
        }

        $identities = [];
        $seenUsers = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new BootstrapException(
                    'bootstrap.completed_state_incompatible'
                );
            }
            $roleCode = $row['role_code'] ?? null;
            $userId = $this->positiveInteger($row['user_id'] ?? null);
            $credentialUserId = $this->positiveInteger(
                $row['credential_user_id'] ?? null
            );
            $authVersion = $this->positiveInteger(
                $row['auth_version'] ?? null
            );
            $status = $row['status'] ?? null;
            if (
                !is_string($roleCode)
                || !in_array($roleCode, self::ROLE_CODES, true)
                || isset($identities[$roleCode])
                || $userId === null
                || isset($seenUsers[$userId])
                || $credentialUserId !== $userId
                || $authVersion === null
                || !is_string($status)
                || !in_array($status, ['invited', 'active', 'suspended'], true)
                || !$this->integerEquals($row['is_protected'] ?? null, 1)
                || !$this->integerEquals($row['is_delegable'] ?? null, 0)
                || ($row['source'] ?? null) !== 'bootstrap'
                || ($row['assigned_by_user_id'] ?? null) !== null
                || ($row['user_created_by_user_id'] ?? null) !== null
            ) {
                throw new BootstrapException(
                    'bootstrap.completed_state_incompatible'
                );
            }

            $identities[$roleCode] = [
                'role_code' => $roleCode,
                'user_id' => $userId,
                'status' => $status,
                'auth_version' => $authVersion,
                'invited_at' => $row['invited_at'] ?? null,
                'activated_at' => $row['activated_at'] ?? null,
                'suspended_at' => $row['suspended_at'] ?? null,
                'password_hash' => $row['password_hash'] ?? null,
                'password_set_at' => $row['password_set_at'] ?? null,
            ];
            $seenUsers[$userId] = true;
        }

        if (array_keys($identities) !== self::ROLE_CODES) {
            throw new BootstrapException(
                'bootstrap.completed_state_incompatible'
            );
        }

        return array_values($identities);
    }

    /** @param array<string, mixed> $identity */
    private function isActiveIdentity(
        array $identity,
        DateTimeImmutable $now
    ): bool
    {
        $invitedAt = $this->timestamp($identity['invited_at']);
        $activatedAt = $this->timestamp($identity['activated_at']);
        $passwordSetAt = $this->timestamp($identity['password_set_at']);

        return $identity['status'] === 'active'
            && is_string($identity['password_hash'])
            && $identity['password_hash'] !== ''
            && $this->passwordHasher->isCurrentHash(
                $identity['password_hash']
            )
            && $invitedAt instanceof DateTimeImmutable
            && $activatedAt instanceof DateTimeImmutable
            && $passwordSetAt instanceof DateTimeImmutable
            && $invitedAt <= $activatedAt
            && $invitedAt <= $passwordSetAt
            && $activatedAt <= $now
            && $passwordSetAt <= $now
            && $identity['suspended_at'] === null;
    }

    /** @param array<string, mixed> $identity */
    private function isInvitedIdentity(
        array $identity,
        DateTimeImmutable $now
    ): bool
    {
        $invitedAt = $this->timestamp($identity['invited_at']);

        return $identity['status'] === 'invited'
            && $identity['password_hash'] === null
            && $identity['password_set_at'] === null
            && $invitedAt instanceof DateTimeImmutable
            && $invitedAt <= $now
            && $identity['activated_at'] === null
            && $identity['suspended_at'] === null;
    }

    /** @return array{open: int, live: int} */
    private function invitationState(
        int $userId,
        int $authVersion,
        DateTimeImmutable $now
    ): array {
        try {
            $statement = $this->pdo->prepare(
                'SELECT o.status, o.action_token_id, o.sent_at, '
                . 't.id AS token_id, t.user_id AS token_user_id, '
                . 't.purpose, t.auth_version, t.created_by_user_id, '
                . 't.created_at AS token_created_at, t.expires_at, '
                . 't.delivered_at, t.used_at, t.revoked_at '
                . 'FROM ' . $this->tables->table('outbox') . ' o '
                . 'LEFT JOIN ' . $this->tables->table('action_tokens') . ' t '
                . 'ON t.id = o.action_token_id WHERE o.user_id = :user_id '
                . "AND o.kind = 'invite' ORDER BY o.id"
            );
            if (!$statement instanceof PDOStatement) {
                throw new BootstrapException(
                    'bootstrap.persistence_unavailable'
                );
            }
            if (!$statement->execute(['user_id' => $userId])) {
                throw new BootstrapException(
                    'bootstrap.persistence_unavailable'
                );
            }
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (BootstrapException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BootstrapException(
                'bootstrap.persistence_unavailable'
            );
        }

        if (!is_array($rows)) {
            throw new BootstrapException(
                'bootstrap.persistence_unavailable'
            );
        }

        $open = 0;
        $live = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new BootstrapException(
                    'bootstrap.persistence_unavailable'
                );
            }
            $status = $row['status'] ?? null;
            if (in_array($status, ['pending', 'processing'], true)) {
                ++$open;
                continue;
            }
            if (
                $status !== 'sent'
                || $this->positiveInteger($row['action_token_id'] ?? null)
                    !== $this->positiveInteger($row['token_id'] ?? null)
                || $this->positiveInteger($row['token_user_id'] ?? null)
                    !== $userId
                || ($row['purpose'] ?? null) !== 'invite'
                || $this->positiveInteger($row['auth_version'] ?? null)
                    !== $authVersion
                || ($row['created_by_user_id'] ?? null) !== null
                || ($row['used_at'] ?? null) !== null
                || ($row['revoked_at'] ?? null) !== null
            ) {
                continue;
            }

            $sentAt = $this->timestamp($row['sent_at'] ?? null);
            $createdAt = $this->timestamp(
                $row['token_created_at'] ?? null
            );
            $deliveredAt = $this->timestamp(
                $row['delivered_at'] ?? null
            );
            $expiresAt = $this->timestamp($row['expires_at'] ?? null);
            if (
                $sentAt instanceof DateTimeImmutable
                && $createdAt instanceof DateTimeImmutable
                && $deliveredAt instanceof DateTimeImmutable
                && $createdAt <= $deliveredAt
                && $createdAt <= $sentAt
                && $deliveredAt <= $now
                && $sentAt <= $now
                && $expiresAt instanceof DateTimeImmutable
                && $expiresAt > $createdAt
                && $expiresAt > $now
            ) {
                ++$live;
            }
        }

        return ['open' => $open, 'live' => $live];
    }

    /**
     * @param array<string, mixed> $identity
     * @param array{open: int, live: int} $invitations
     */
    private function assertNoOutboxCollision(
        array $identity,
        array $invitations
    ): void {
        if (
            $invitations['open'] > 1
            || $invitations['live'] > 1
            || (
                $invitations['live'] === 1
                && $invitations['open'] > 0
            )
            || (
                $identity['status'] === 'active'
                && $invitations['open'] > 0
            )
        ) {
            throw new BootstrapException('bootstrap.outbox_collision');
        }
    }

    private function now(): DateTimeImmutable
    {
        try {
            return $this->clock->now()->setTimezone(
                new DateTimeZone('UTC')
            );
        } catch (Throwable) {
            throw new BootstrapException('bootstrap.clock_failed');
        }
    }

    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat(
            '!' . self::UTC_FORMAT,
            $value,
            new DateTimeZone('UTC')
        );
        $errors = DateTimeImmutable::getLastErrors();

        return $parsed instanceof DateTimeImmutable
            && ($errors === false || (
                $errors['warning_count'] === 0
                && $errors['error_count'] === 0
            ))
            && $parsed->format(self::UTC_FORMAT) === $value
                ? $parsed
                : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value)
            && preg_match('/\A[1-9][0-9]*\z/', $value) === 1
            && (string) (int) $value === $value
                ? (int) $value
                : null;
    }

    private function integerEquals(mixed $value, int $expected): bool
    {
        return $value === $expected || $value === (string) $expected;
    }
}
