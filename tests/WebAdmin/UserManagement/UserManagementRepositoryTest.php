<?php

declare(strict_types=1);

use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\UserManagement\UserManagementRepository;
use PHPUnit\Framework\TestCase;

final class UserManagementZeroChangeStatement extends PDOStatement
{
    /** @var array<string, mixed>|null */
    public ?array $parameters = null;

    public function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->parameters = $params;

        return true;
    }

    public function rowCount(): int
    {
        // MySQL reports changed rows by default, not matching rows.
        return 0;
    }

    public function fetchAll(
        int $mode = PDO::FETCH_DEFAULT,
        mixed ...$args
    ): array {
        return [];
    }
}

final class UserManagementZeroChangePdo extends PDO
{
    public ?UserManagementZeroChangeStatement $statement = null;
    public string $preparedSql = '';

    public function __construct()
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'mysql',
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            default => null,
        };
    }

    public function prepare(
        string $query,
        array $options = []
    ): PDOStatement|false {
        $this->preparedSql = $query;
        $this->statement = new UserManagementZeroChangeStatement();

        return $this->statement;
    }
}

final class UserManagementRepositoryTest extends TestCase
{
    public function testIdempotentSessionTouchDoesNotTreatZeroChangedRowsAsMissing(): void
    {
        $pdo = new UserManagementZeroChangePdo();
        $repository = new UserManagementRepository(
            $pdo,
            WebAdminTableNames::fromPdo($pdo, 'ls_webadmin_')
        );
        $now = new DateTimeImmutable('2026-08-01 08:00:00.000000 UTC');
        $idle = new DateTimeImmutable('2026-08-01 08:30:00.000000 UTC');

        $repository->touchSession(17, $now, $idle);

        self::assertStringContainsString(
            'UPDATE `ls_webadmin_sessions`',
            $pdo->preparedSql
        );
        self::assertNotNull($pdo->statement);
        self::assertSame([
            'last_seen' => '2026-08-01 08:00:00.000000',
            'idle_expires' => '2026-08-01 08:30:00.000000',
            'id' => 17,
        ], $pdo->statement->parameters);
    }

    public function testSessionLockUsesConstantParametersForAnyTokenHistory(): void
    {
        $pdo = new UserManagementZeroChangePdo();
        $repository = new UserManagementRepository(
            $pdo,
            WebAdminTableNames::fromPdo($pdo, 'ls_webadmin_')
        );

        self::assertSame([], $repository->lockActionTokensForUser(17));
        self::assertStringContainsString(
            'used_at IS NULL AND revoked_at IS NULL',
            $pdo->preparedSql
        );
        self::assertSame(['user_id' => 17], $pdo->statement?->parameters);

        self::assertSame([], $repository->lockTargetSessions(17));
        self::assertStringContainsString('EXISTS (SELECT 1 FROM', $pdo->preparedSql);
        self::assertStringNotContainsString(
            'pending_action_token_id IN',
            $pdo->preparedSql
        );
        self::assertSame([
            'session_user_id' => 17,
            'action_user_id' => 17,
        ], $pdo->statement?->parameters);
    }
}
