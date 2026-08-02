<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Authorization\AuthorizationStorageException;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizedActor;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use PHPUnit\Framework\TestCase;

final class WebAdminMutationActorGateTest extends TestCase
{
    private const USER_PUBLIC_ID =
        '10000000-0000-4000-8000-000000000001';
    private const SESSION_PUBLIC_ID =
        '20000000-0000-4000-8000-000000000002';
    private const NOW = '2030-01-01 00:10:00.000000';
    private const INITIAL_LAST_SEEN = '2030-01-01 00:05:00.000000';
    private const INITIAL_IDLE = '2030-01-01 00:20:00.000000';
    private const ABSOLUTE = '2030-01-01 01:00:00.000000';
    private const CAPABILITY = 'webadmin.access';

    private PDO $pdo;
    private int $userId;
    private string $sessionToken;
    private string $csrfToken;
    private SecurityKey $securityKey;
    private MutationGateTestClock $clock;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );
        $this->pdo->setAttribute(
            PDO::ATTR_DEFAULT_FETCH_MODE,
            PDO::FETCH_ASSOC
        );
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $migration = null;
        foreach (WebAdminMigrationProvider::migrations() as $candidate) {
            if ($candidate->id() === '0001_webadmin_identity_and_access') {
                $migration = $candidate;
                break;
            }
        }
        self::assertNotNull($migration);
        $scope = MigrationScope::forTablePrefix(
            'webadmin',
            'ls_webadmin_'
        );
        foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
            self::assertNotFalse($this->pdo->exec($sql));
        }

        $this->securityKey = SecurityKey::fromRawBytes(
            str_repeat('K', 32)
        );
        $this->sessionToken = self::token('A');
        $this->csrfToken = $this->securityKey->deriveToken(
            'csrf.session',
            $this->sessionToken
        );
        $this->clock = new MutationGateTestClock(
            new DateTimeImmutable(self::NOW . ' UTC')
        );
        $this->seedCurrentIdentity();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testGateRequiresAnExistingTransaction(): void
    {
        $this->grantRoleCapability();
        $before = $this->sessionTimes();

        try {
            $this->gate()->authorize(
                $this->sessionToken,
                $this->csrfToken,
                self::CAPABILITY
            );
            self::fail('Authorization outside a transaction must fail.');
        } catch (AuthorizationStorageException $exception) {
            self::assertSame(
                'WebAdmin authorization is unavailable.',
                $exception->getMessage()
            );
        }

        self::assertFalse($this->pdo->inTransaction());
        self::assertSame($before, $this->sessionTimes());
    }

    public function testRoleCapabilityAuthorizesAndSlidesIdleWithinCallerTransaction(): void
    {
        $this->grantRoleCapability();
        $this->begin();

        $actor = $this->gate()->authorize(
            $this->sessionToken,
            $this->csrfToken,
            self::CAPABILITY
        );

        self::assertInstanceOf(WebAdminAuthorizedActor::class, $actor);
        self::assertSame($this->userId, $actor->userId());
        self::assertSame(self::USER_PUBLIC_ID, $actor->userPublicId());
        self::assertSame(
            self::SESSION_PUBLIC_ID,
            $actor->sessionPublicId()
        );
        self::assertTrue($this->pdo->inTransaction());
        self::assertSame([
            'last_seen_at' => self::NOW,
            'idle_expires_at' => '2030-01-01 00:40:00.000000',
        ], $this->sessionTimes());

        self::assertTrue($this->pdo->rollBack());
        self::assertSame([
            'last_seen_at' => self::INITIAL_LAST_SEEN,
            'idle_expires_at' => self::INITIAL_IDLE,
        ], $this->sessionTimes());
    }

    public function testDirectCapabilityAlsoAuthorizes(): void
    {
        $this->grantDirectCapability();
        $this->begin();

        $actor = $this->gate()->authorize(
            $this->sessionToken,
            $this->csrfToken,
            self::CAPABILITY
        );

        self::assertInstanceOf(WebAdminAuthorizedActor::class, $actor);
        self::assertTrue($this->pdo->inTransaction());
    }

    public function testAuthorizeAllRequiresEveryDistinctCapabilityAtomically(): void
    {
        $this->grantRoleCapability();
        $this->begin();

        self::assertInstanceOf(
            WebAdminAuthorizedActor::class,
            $this->gate()->authorizeAll(
                $this->sessionToken,
                $this->csrfToken,
                ['webadmin.access', 'webadmin.profile.manage_self']
            )
        );
        self::assertTrue($this->pdo->rollBack());

        $before = $this->sessionTimes();
        $this->begin();
        self::assertNull($this->gate()->authorizeAll(
            $this->sessionToken,
            $this->csrfToken,
            ['webadmin.access', 'webadmin.audit.view']
        ));
        self::assertSame($before, $this->sessionTimes());
        self::assertNull($this->gate()->authorizeAll(
            $this->sessionToken,
            $this->csrfToken,
            ['webadmin.access', 'webadmin.access']
        ));
    }

    public function testMissingCapabilityDeniesWithoutSlidingSession(): void
    {
        $this->begin();

        self::assertNull($this->gate()->authorize(
            $this->sessionToken,
            $this->csrfToken,
            self::CAPABILITY
        ));
        self::assertSame([
            'last_seen_at' => self::INITIAL_LAST_SEEN,
            'idle_expires_at' => self::INITIAL_IDLE,
        ], $this->sessionTimes());
        self::assertTrue($this->pdo->inTransaction());
    }

    public function testMalformedBrowserInputsDenyWithoutDatabaseMutation(): void
    {
        $this->grantRoleCapability();
        $otherToken = self::token('B');
        $before = $this->sessionTimes();
        $attempts = [
            ['invalid', $this->csrfToken, self::CAPABILITY],
            [$otherToken, $this->csrfToken, self::CAPABILITY],
            [$this->sessionToken, 'invalid', self::CAPABILITY],
            [$this->sessionToken, self::token('C'), self::CAPABILITY],
            [$this->sessionToken, $this->csrfToken, 'x'],
            [
                $this->sessionToken,
                $this->csrfToken,
                'webadmin.access;drop',
            ],
        ];
        $this->begin();

        foreach ($attempts as [$session, $csrf, $capability]) {
            self::assertNull($this->gate()->authorize(
                $session,
                $csrf,
                $capability
            ));
        }

        self::assertSame($before, $this->sessionTimes());
        self::assertTrue($this->pdo->inTransaction());
    }

    public function testCsrfMustMatchBothBrowserBindingAndStoredHash(): void
    {
        $this->grantRoleCapability();
        $otherCsrf = self::token('D');
        $this->begin();

        self::assertNull($this->gate()->authorize(
            $this->sessionToken,
            $otherCsrf,
            self::CAPABILITY
        ));
        self::assertSame(self::INITIAL_LAST_SEEN, $this->sessionTimes()[
            'last_seen_at'
        ]);
        self::assertTrue($this->pdo->rollBack());

        $statement = $this->pdo->prepare(
            'UPDATE ls_webadmin_sessions SET csrf_token_hash = :hash'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            'hash' => (new SecureTokenGenerator())->hashForStorage(
                $otherCsrf
            ),
        ]));
        $this->begin();

        self::assertNull($this->gate()->authorize(
            $this->sessionToken,
            $this->csrfToken,
            self::CAPABILITY
        ));
        self::assertSame(self::INITIAL_LAST_SEEN, $this->sessionTimes()[
            'last_seen_at'
        ]);
    }

    public function testSessionLifecycleAndAuthVersionAreRevalidated(): void
    {
        $this->grantRoleCapability();
        $actionId = $this->insertActionToken();
        $cases = [
            "session_type = 'preauth', user_id = NULL, auth_version = NULL",
            "revoked_at = '2030-01-01 00:09:00.000000'",
            'pending_action_token_id = ' . $actionId,
            'auth_version = 2',
        ];

        foreach ($cases as $mutation) {
            $this->resetSession();
            if (str_starts_with($mutation, 'pending_action')) {
                $this->pdo->exec('PRAGMA ignore_check_constraints = ON');
            }
            $this->pdo->exec(
                'UPDATE ls_webadmin_sessions SET ' . $mutation
            );
            $this->pdo->exec('PRAGMA ignore_check_constraints = OFF');
            $this->begin();

            self::assertNull(
                $this->gate()->authorize(
                    $this->sessionToken,
                    $this->csrfToken,
                    self::CAPABILITY
                ),
                $mutation
            );
            self::assertTrue($this->pdo->inTransaction());
            self::assertTrue($this->pdo->rollBack());
        }
    }

    public function testIdentityAndCredentialLifecycleAreRevalidated(): void
    {
        $this->grantRoleCapability();
        $legacy = PasswordHasher::bcryptFallback()
            ->verificationDummyHash();
        $cases = [
            [
                "UPDATE ls_webadmin_users SET status = 'suspended', "
                    . "suspended_at = '2030-01-01 00:09:00.000000'",
                null,
            ],
            [
                'UPDATE ls_webadmin_users SET activated_at = NULL',
                null,
            ],
            [
                "UPDATE ls_webadmin_users SET activated_at = "
                    . "'2030-01-01 00:11:00.000000'",
                null,
            ],
            [
                null,
                "UPDATE ls_webadmin_credentials SET password_set_at = "
                    . "'invalid'",
            ],
            [
                null,
                "UPDATE ls_webadmin_credentials SET password_set_at = "
                    . "'2030-01-01 00:11:00.000000'",
            ],
        ];

        foreach ($cases as [$userMutation, $credentialMutation]) {
            $this->resetUserCredential();
            if ($userMutation !== null) {
                $this->pdo->exec($userMutation);
            }
            if ($credentialMutation !== null) {
                $this->pdo->exec($credentialMutation);
            }
            $this->begin();

            self::assertNull($this->gate()->authorize(
                $this->sessionToken,
                $this->csrfToken,
                self::CAPABILITY
            ));
            self::assertTrue($this->pdo->rollBack());
        }

        $this->resetUserCredential();
        $statement = $this->pdo->prepare(
            'UPDATE ls_webadmin_credentials SET password_hash = :hash'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute(['hash' => $legacy]));
        $this->begin();
        self::assertNull($this->gate()->authorize(
            $this->sessionToken,
            $this->csrfToken,
            self::CAPABILITY
        ));
        self::assertTrue($this->pdo->rollBack());

        $this->resetUserCredential();
        $this->pdo->exec('DELETE FROM ls_webadmin_credentials');
        $this->begin();
        self::assertNull($this->gate()->authorize(
            $this->sessionToken,
            $this->csrfToken,
            self::CAPABILITY
        ));
    }

    public function testIdleAndAbsoluteExpirationBoundariesDeny(): void
    {
        $this->grantRoleCapability();
        $cases = [
            [self::NOW, self::ABSOLUTE],
            [self::NOW, self::NOW],
        ];

        foreach ($cases as [$idle, $absolute]) {
            $this->resetSession();
            $statement = $this->pdo->prepare(
                'UPDATE ls_webadmin_sessions SET '
                . 'idle_expires_at = :idle, '
                . 'absolute_expires_at = :absolute'
            );
            self::assertNotFalse($statement);
            self::assertTrue($statement->execute([
                'idle' => $idle,
                'absolute' => $absolute,
            ]));
            $this->begin();

            self::assertNull($this->gate()->authorize(
                $this->sessionToken,
                $this->csrfToken,
                self::CAPABILITY
            ));
            self::assertTrue($this->pdo->rollBack());
        }
    }

    public function testIdleSlideIsCappedByAbsoluteExpiration(): void
    {
        $this->grantRoleCapability();
        $this->pdo->exec(
            "UPDATE ls_webadmin_sessions SET idle_expires_at = "
            . "'2030-01-01 00:15:00.000000', absolute_expires_at = "
            . "'2030-01-01 00:20:00.000000'"
        );
        $this->begin();

        self::assertInstanceOf(
            WebAdminAuthorizedActor::class,
            $this->gate()->authorize(
                $this->sessionToken,
                $this->csrfToken,
                self::CAPABILITY
            )
        );
        self::assertSame(
            '2030-01-01 00:20:00.000000',
            $this->sessionTimes()['idle_expires_at']
        );
    }

    public function testMalformedStoredSessionTimestampIsAStorageFailure(): void
    {
        $this->grantRoleCapability();
        $this->pdo->exec(
            "UPDATE ls_webadmin_sessions SET last_seen_at = 'invalid'"
        );
        $this->begin();

        try {
            $this->gate()->authorize(
                $this->sessionToken,
                $this->csrfToken,
                self::CAPABILITY
            );
            self::fail('Malformed storage must not look like authorization.');
        } catch (AuthorizationStorageException $exception) {
            self::assertSame(
                'WebAdmin authorization is unavailable.',
                $exception->getMessage()
            );
        }

        self::assertTrue($this->pdo->inTransaction());
    }

    public function testActorDebugAndSerializationNeverExposePiiOrSecrets(): void
    {
        $this->grantRoleCapability();
        $this->begin();
        $actor = $this->gate()->authorize(
            $this->sessionToken,
            $this->csrfToken,
            self::CAPABILITY
        );
        self::assertInstanceOf(WebAdminAuthorizedActor::class, $actor);

        $debug = print_r($actor, true);
        self::assertStringContainsString(self::USER_PUBLIC_ID, $debug);
        self::assertStringContainsString(self::SESSION_PUBLIC_ID, $debug);
        self::assertStringNotContainsString(
            'private-editor@example.test',
            $debug
        );
        self::assertStringNotContainsString($this->sessionToken, $debug);
        self::assertStringNotContainsString($this->csrfToken, $debug);
        self::assertStringNotContainsString('argon2id', $debug);

        try {
            serialize($actor);
            self::fail('Authorized actors must not be serializable.');
        } catch (LogicException $exception) {
            self::assertSame(
                'WebAdmin authorized actors cannot be serialized.',
                $exception->getMessage()
            );
        }

        $class = WebAdminAuthorizedActor::class;
        $payload = 'O:' . strlen($class) . ':"' . $class . '":0:{}';
        try {
            unserialize($payload, ['allowed_classes' => [$class]]);
            self::fail('Authorized actors must not be unserializable.');
        } catch (LogicException $exception) {
            self::assertSame(
                'WebAdmin authorized actors cannot be unserialized.',
                $exception->getMessage()
            );
        }
    }

    public function testUnsafeSqliteConnectionIsRejected(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->expectException(AuthorizationStorageException::class);
        new WebAdminMutationActorGate(
            $pdo,
            WebAdminTableNames::fromPdo($pdo, 'ls_webadmin_'),
            WebAdminConfig::defaults(),
            $this->securityKey,
            $this->clock
        );
    }

    private function gate(): WebAdminMutationActorGate
    {
        return new WebAdminMutationActorGate(
            $this->pdo,
            WebAdminTableNames::fromPdo(
                $this->pdo,
                'ls_webadmin_'
            ),
            WebAdminConfig::defaults(),
            $this->securityKey,
            $this->clock
        );
    }

    private function seedCurrentIdentity(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_users '
            . '(public_id, email_canonical, display_name, status, '
            . 'auth_version, activated_at) VALUES '
            . '(:public_id, :email, :display_name, :status, '
            . ':auth_version, :activated_at)'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            'public_id' => self::USER_PUBLIC_ID,
            'email' => 'private-editor@example.test',
            'display_name' => 'Private Editor',
            'status' => 'active',
            'auth_version' => 1,
            'activated_at' => '2030-01-01 00:00:00.000000',
        ]));
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

        $session = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_sessions '
            . '(public_id, user_id, session_type, token_hash, '
            . 'csrf_token_hash, auth_version, pending_action_token_id, '
            . 'created_at, last_seen_at, idle_expires_at, '
            . 'absolute_expires_at, revoked_at) VALUES '
            . '(:public_id, :user_id, :session_type, :token_hash, '
            . ':csrf_token_hash, :auth_version, NULL, :created_at, '
            . ':last_seen_at, :idle_expires_at, :absolute_expires_at, NULL)'
        );
        self::assertNotFalse($session);
        self::assertTrue($session->execute([
            'public_id' => self::SESSION_PUBLIC_ID,
            'user_id' => $this->userId,
            'session_type' => 'authenticated',
            'token_hash' => hash('sha256', $this->sessionToken),
            'csrf_token_hash' => hash('sha256', $this->csrfToken),
            'auth_version' => 1,
            'created_at' => '2030-01-01 00:00:00.000000',
            'last_seen_at' => self::INITIAL_LAST_SEEN,
            'idle_expires_at' => self::INITIAL_IDLE,
            'absolute_expires_at' => self::ABSOLUTE,
        ]));
    }

    private function resetUserCredential(): void
    {
        $this->pdo->exec(
            "UPDATE ls_webadmin_users SET status = 'active', "
            . 'auth_version = 1, suspended_at = NULL, activated_at = '
            . "'2030-01-01 00:00:00.000000'"
        );
        $statement = $this->pdo->prepare(
            'UPDATE ls_webadmin_credentials SET password_hash = :hash, '
            . 'password_set_at = :set_at'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            'hash' => PasswordHasher::productive()->verificationDummyHash(),
            'set_at' => '2030-01-01 00:00:00.000000',
        ]));
    }

    private function resetSession(): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE ls_webadmin_sessions SET user_id = :user_id, '
            . "session_type = 'authenticated', auth_version = 1, "
            . 'pending_action_token_id = NULL, revoked_at = NULL, '
            . 'created_at = :created_at, last_seen_at = :last_seen_at, '
            . 'idle_expires_at = :idle, absolute_expires_at = :absolute'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            'user_id' => $this->userId,
            'created_at' => '2030-01-01 00:00:00.000000',
            'last_seen_at' => self::INITIAL_LAST_SEEN,
            'idle' => self::INITIAL_IDLE,
            'absolute' => self::ABSOLUTE,
        ]));
    }

    private function grantRoleCapability(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_user_roles (user_id, role_id, source) '
            . "SELECT :user_id, id, 'manual' FROM ls_webadmin_roles "
            . "WHERE code = 'editor'"
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute(['user_id' => $this->userId]));
    }

    private function grantDirectCapability(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_user_capabilities '
            . '(user_id, capability_id) '
            . 'SELECT :user_id, id FROM ls_webadmin_capabilities '
            . 'WHERE code = :code'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            'user_id' => $this->userId,
            'code' => self::CAPABILITY,
        ]));
    }

    private function insertActionToken(): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_action_tokens '
            . '(user_id, purpose, token_hash, auth_version, created_at, '
            . 'expires_at) VALUES (:user_id, :purpose, :token_hash, '
            . ':auth_version, :created_at, :expires_at)'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            'user_id' => $this->userId,
            'purpose' => 'password_reset',
            'token_hash' => str_repeat('e', 64),
            'auth_version' => 1,
            'created_at' => '2030-01-01 00:00:00.000000',
            'expires_at' => '2030-01-01 01:00:00.000000',
        ]));

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{last_seen_at: string, idle_expires_at: string} */
    private function sessionTimes(): array
    {
        $row = $this->pdo->query(
            'SELECT last_seen_at, idle_expires_at '
            . 'FROM ls_webadmin_sessions'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return [
            'last_seen_at' => (string) $row['last_seen_at'],
            'idle_expires_at' => (string) $row['idle_expires_at'],
        ];
    }

    private function begin(): void
    {
        self::assertTrue($this->pdo->beginTransaction());
        self::assertTrue($this->pdo->inTransaction());
    }

    private static function token(string $byte): string
    {
        return rtrim(strtr(
            base64_encode(str_repeat($byte, 32)),
            '+/',
            '-_'
        ), '=');
    }
}

final class MutationGateTestClock implements ClockInterface
{
    public function __construct(private readonly DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
