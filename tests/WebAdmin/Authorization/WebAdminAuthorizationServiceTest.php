<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Authorization\AuthorizationStorageException;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Support\ClockInterface;
use PHPUnit\Framework\TestCase;

final class WebAdminAuthorizationServiceTest extends TestCase
{
    private PDO $pdo;
    private int $userId;
    private string $sessionToken;
    private AuthorizationTestClock $clock;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $migration = null;
        foreach (WebAdminMigrationProvider::migrations() as $candidate) {
            if ($candidate->id() === '0001_webadmin_identity_and_access') {
                $migration = $candidate;
                break;
            }
        }
        self::assertNotNull($migration);
        $scope = MigrationScope::forTablePrefix('webadmin', 'ls_webadmin_');
        foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
            self::assertNotFalse($this->pdo->exec($sql));
        }
        $this->pdo->exec(
            "INSERT INTO ls_webadmin_users "
            . "(public_id, email_canonical, status, auth_version, "
            . "activated_at) VALUES "
            . "('10000000-0000-4000-8000-000000000001', "
            . "'editor@example.test', 'active', 1, "
            . "'2030-01-01 00:00:00.000000')"
        );
        $this->userId = (int) $this->pdo->lastInsertId();
        $credential = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_credentials '
                . '(user_id, password_hash, password_set_at) '
                . 'VALUES (:user_id, :password_hash, :password_set_at)'
        );
        self::assertNotFalse($credential);
        self::assertTrue($credential->execute([
            'user_id' => $this->userId,
            'password_hash' => PasswordHasher::productive()
                ->verificationDummyHash(),
            'password_set_at' => '2030-01-01 00:00:00.000000',
        ]));
        $now = new DateTimeImmutable('2030-01-01 00:00:00.000000 UTC');
        $this->clock = new AuthorizationTestClock($now);
        $this->sessionToken = rtrim(strtr(
            base64_encode(str_repeat('A', 32)),
            '+/',
            '-_'
        ), '=');
        $this->pdo->exec(
            "INSERT INTO ls_webadmin_sessions "
            . "(public_id, user_id, session_type, token_hash, "
            . "csrf_token_hash, auth_version, pending_action_token_id, "
            . "created_at, last_seen_at, idle_expires_at, "
            . "absolute_expires_at, revoked_at) VALUES "
            . "('20000000-0000-4000-8000-000000000002', {$this->userId}, "
            . "'authenticated', '" . hash('sha256', $this->sessionToken) . "', '"
            . str_repeat('b', 64) . "', 1, NULL, "
            . "'2030-01-01 00:00:00.000000', "
            . "'2030-01-01 00:00:00.000000', "
            . "'2030-01-01 00:05:00.000000', "
            . "'2030-01-01 01:00:00.000000', NULL)"
        );
    }

    public function testAuthenticationAloneNeverGrantsWebAdminAccess(): void
    {
        self::assertFalse($this->service()->mayAccessWebAdmin(
            $this->sessionToken
        ));
    }

    public function testRoleAndDirectAssignmentsBothGrantEffectiveCapability(): void
    {
        $this->pdo->exec(
            'INSERT INTO ls_webadmin_user_roles (user_id, role_id, source) '
            . "SELECT {$this->userId}, id, 'manual' FROM ls_webadmin_roles "
            . "WHERE code = 'editor'"
        );
        self::assertTrue($this->service()->mayAccessWebAdmin(
            $this->sessionToken
        ));

        $this->pdo->exec('DELETE FROM ls_webadmin_user_roles');
        self::assertFalse($this->service()->mayAccessWebAdmin(
            $this->sessionToken
        ));
        $this->pdo->exec(
            'INSERT INTO ls_webadmin_user_capabilities '
            . '(user_id, capability_id) '
            . "SELECT {$this->userId}, id FROM ls_webadmin_capabilities "
            . "WHERE code = 'webadmin.access'"
        );
        self::assertTrue($this->service()->mayAccessWebAdmin(
            $this->sessionToken
        ));
    }

    public function testInvalidCapabilityFailsClosedWithoutQueryMutation(): void
    {
        self::assertFalse($this->service()->hasCapability(
            $this->sessionToken,
            'webadmin.access;drop'
        ));
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_users'
        )->fetchColumn());
    }

    public function testInvalidOrDifferentRawTokenCannotAuthorize(): void
    {
        $this->grantEditorRole();
        self::assertFalse($this->service()->mayAccessWebAdmin('invalid'));
        self::assertFalse($this->service()->mayAccessWebAdmin(rtrim(strtr(
            base64_encode(str_repeat('B', 32)),
            '+/',
            '-_'
        ), '=')));
    }

    public function testRevokedDatabaseSessionIsRejected(): void
    {
        $this->grantEditorRole();
        $this->pdo->exec(
            "UPDATE ls_webadmin_sessions SET revoked_at = "
            . "'2030-01-01 00:00:01.000000'"
        );

        self::assertFalse($this->service()->mayAccessWebAdmin(
            $this->sessionToken
        ));
    }

    public function testSuspendedOrAuthVersionChangedUserIsRejected(): void
    {
        $this->grantEditorRole();
        $this->pdo->exec(
            "UPDATE ls_webadmin_users SET status = 'suspended', "
            . 'auth_version = 2'
        );

        self::assertFalse($this->service()->mayAccessWebAdmin(
            $this->sessionToken
        ));
    }

    public function testMissingOrLegacyCredentialIsRejected(): void
    {
        $this->grantEditorRole();
        $legacy = PasswordHasher::bcryptFallback()
            ->verificationDummyHash();
        $statement = $this->pdo->prepare(
            'UPDATE ls_webadmin_credentials SET password_hash = :hash'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute(['hash' => $legacy]));

        self::assertFalse($this->service()->mayAccessWebAdmin(
            $this->sessionToken
        ));

        $this->pdo->exec('DELETE FROM ls_webadmin_credentials');
        self::assertFalse($this->service()->mayAccessWebAdmin(
            $this->sessionToken
        ));
    }

    public function testLifecycleAndCredentialDriftFailsClosed(): void
    {
        $this->grantEditorRole();
        $validTimestamp = '2030-01-01 00:00:00.000000';
        $mutations = [
            "UPDATE ls_webadmin_users SET activated_at = NULL",
            "UPDATE ls_webadmin_users SET activated_at = 'invalid'",
            "UPDATE ls_webadmin_users SET suspended_at = "
                . "'2030-01-01 00:00:01.000000'",
            "UPDATE ls_webadmin_credentials SET password_set_at = 'invalid'",
        ];

        foreach ($mutations as $mutation) {
            $this->pdo->exec(
                "UPDATE ls_webadmin_users SET activated_at = '"
                . $validTimestamp . "', suspended_at = NULL"
            );
            $this->pdo->exec(
                "UPDATE ls_webadmin_credentials SET password_set_at = '"
                . $validTimestamp . "'"
            );
            $this->pdo->exec($mutation);

            self::assertFalse(
                $this->service()->mayAccessWebAdmin($this->sessionToken),
                $mutation
            );
        }

        $this->pdo->exec('DELETE FROM ls_webadmin_credentials');
        self::assertFalse($this->service()->mayAccessWebAdmin(
            $this->sessionToken
        ));
    }

    public function testUnsafeSqliteConnectionIsRejected(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->expectException(AuthorizationStorageException::class);
        new WebAdminAuthorizationService(
            $pdo,
            WebAdminTableNames::fromPdo($pdo, 'ls_webadmin_')
        );
    }

    private function service(): WebAdminAuthorizationService
    {
        return new WebAdminAuthorizationService(
            $this->pdo,
            WebAdminTableNames::fromPdo($this->pdo, 'ls_webadmin_'),
            $this->clock
        );
    }

    private function grantEditorRole(): void
    {
        $this->pdo->exec(
            'INSERT INTO ls_webadmin_user_roles (user_id, role_id, source) '
            . "SELECT {$this->userId}, id, 'manual' "
            . "FROM ls_webadmin_roles WHERE code = 'editor'"
        );
    }
}

final class AuthorizationTestClock implements ClockInterface
{
    public function __construct(private readonly DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
