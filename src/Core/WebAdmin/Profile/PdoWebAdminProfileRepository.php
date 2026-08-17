<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Profile;

use App\Core\WebAdmin\Authorization\WebAdminAuthorizedActor;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use Throwable;

final class PdoWebAdminProfileRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly WebAdminTableNames $tables
    ) {
    }

    /** @template T @param callable(): T $operation @return T */
    public function transactional(callable $operation): mixed
    {
        try {
            $this->pdo->beginTransaction();
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof WebAdminProfileStorageException) {
                throw $exception;
            }
            throw new WebAdminProfileStorageException('', 0, $exception);
        }
    }

    public function liveByPublicId(string $publicId): ?WebAdminPublicProfile
    {
        try {
            $sql = 'SELECT u.public_id, u.display_name, p.time_zone, '
                . 'p.lock_version, r.code AS role_code FROM '
                . $this->tables->table('users') . ' u LEFT JOIN '
                . $this->tables->table('user_profiles')
                . ' p ON p.user_id = u.id LEFT JOIN '
                . $this->tables->table('user_roles')
                . ' ur ON ur.user_id = u.id LEFT JOIN '
                . $this->tables->table('roles')
                . ' r ON r.id = ur.role_id WHERE u.public_id = :public_id '
                . 'ORDER BY CASE r.code WHEN \'system_superadmin\' THEN 1 '
                . 'WHEN \'site_admin\' THEN 2 ELSE 3 END, r.code ASC';
            $statement = $this->prepare($sql);
            $statement->execute(['public_id' => $publicId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $this->profile($row) : null;
        } catch (Throwable $exception) {
            throw new WebAdminProfileStorageException('', 0, $exception);
        }
    }

    /** @return array{exists: bool, lock_version: int} */
    public function lockState(int $userId): array
    {
        $sql = 'SELECT lock_version FROM '
            . $this->tables->table('user_profiles')
            . ' WHERE user_id = :user_id'
            . ($this->tables->driver() === 'mysql' ? ' FOR UPDATE' : '');
        $statement = $this->prepare($sql);
        $statement->execute(['user_id' => $userId]);
        $value = $statement->fetchColumn();
        if ($value === false) {
            return ['exists' => false, 'lock_version' => 0];
        }
        if (!is_numeric($value) || (int) $value < 1) {
            throw new WebAdminProfileStorageException();
        }
        return ['exists' => true, 'lock_version' => (int) $value];
    }

    public function update(
        WebAdminAuthorizedActor $actor,
        ?string $displayName,
        ?WebAdminTimeZone $timeZone,
        int $expectedLockVersion,
        DateTimeImmutable $now
    ): bool {
        $state = $this->lockState($actor->userId());
        if ($state['lock_version'] !== $expectedLockVersion) {
            return false;
        }
        $timestamp = $now->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
        $user = $this->prepare(
            'UPDATE ' . $this->tables->table('users')
            . ' SET display_name = :display_name, updated_at = :updated_at '
            . 'WHERE id = :user_id'
        );
        $user->bindValue(
            'display_name',
            $displayName,
            $displayName === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $user->bindValue('updated_at', $timestamp);
        $user->bindValue('user_id', $actor->userId(), PDO::PARAM_INT);
        $user->execute();
        if ($state['exists']) {
            $profile = $this->prepare(
                'UPDATE ' . $this->tables->table('user_profiles')
                . ' SET time_zone = :time_zone, lock_version = lock_version + 1, '
                . 'updated_by_user_id = :actor, updated_at = :updated_at '
                . 'WHERE user_id = :user_id AND lock_version = :expected'
            );
        } else {
            $profile = $this->prepare(
                'INSERT INTO ' . $this->tables->table('user_profiles')
                . ' (user_id, time_zone, lock_version, updated_by_user_id, '
                . 'updated_at) VALUES (:user_id, :time_zone, 1, :actor, '
                . ':updated_at)'
            );
        }
        $zone = $timeZone?->value();
        $profile->bindValue(
            'time_zone',
            $zone,
            $zone === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $profile->bindValue('actor', $actor->userId(), PDO::PARAM_INT);
        $profile->bindValue('updated_at', $timestamp);
        $profile->bindValue('user_id', $actor->userId(), PDO::PARAM_INT);
        if ($state['exists']) {
            $profile->bindValue('expected', $expectedLockVersion, PDO::PARAM_INT);
        }
        $profile->execute();
        return $profile->rowCount() === 1;
    }

    public function audit(
        string $requestId,
        WebAdminAuthorizedActor $actor,
        DateTimeImmutable $now
    ): void {
        $statement = $this->prepare(
            'INSERT INTO ' . $this->tables->table('audit_log')
            . ' (request_id, actor_user_id, actor_session_public_id, event_code, '
            . 'outcome, reason_code, target_type, target_public_id, metadata_json, '
            . 'ip_hash, user_agent_hash, occurred_at) VALUES (:request_id, '
            . ':actor, :session, \'webadmin.profile.updated\', \'success\', NULL, '
            . '\'webadmin_user\', :target, NULL, NULL, NULL, :occurred_at)'
        );
        $statement->execute([
            'request_id' => $requestId,
            'actor' => $actor->userId(),
            'session' => $actor->sessionPublicId(),
            'target' => $actor->userPublicId(),
            'occurred_at' => $now->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s.u'),
        ]);
    }

    /** @param array<string, mixed> $row */
    private function profile(array $row): WebAdminPublicProfile
    {
        $zone = $row['time_zone'] ?? null;
        $configured = is_string($zone) && $zone !== '';
        $timeZone = $configured
            ? WebAdminTimeZone::fromIana($zone)
            : WebAdminTimeZone::utc();
        $role = is_string($row['role_code'] ?? null)
            ? $row['role_code'] : 'editor';
        $label = match ($role) {
            'system_superadmin' => 'Superadministrador',
            'site_admin' => 'Administrador',
            default => 'Editor',
        };
        $display = $row['display_name'] ?? null;
        return new WebAdminPublicProfile(
            (string) $row['public_id'],
            is_string($display) && trim($display) !== '' ? trim($display) : null,
            $role,
            $label,
            $timeZone,
            $configured,
            isset($row['lock_version']) ? (int) $row['lock_version'] : 0
        );
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new WebAdminProfileStorageException();
        }
        return $statement;
    }
}
