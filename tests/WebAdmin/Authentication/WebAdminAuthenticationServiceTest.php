<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Authentication\AuthenticatedSession;
use App\Core\WebAdmin\Authentication\AuthenticationAttempt;
use App\Core\WebAdmin\Authentication\AuthenticationStorageException;
use App\Core\WebAdmin\Authentication\LoginRateLimitPolicy;
use App\Core\WebAdmin\Authentication\PreAuthenticationRateLimited;
use App\Core\WebAdmin\Authentication\SessionSecrets;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationRepository;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use PHPUnit\Framework\TestCase;

final class WebAdminAuthenticationServiceTest extends TestCase
{
    private const PASSWORD = 'Correct horse battery staple 1!';
    private const WRONG_PASSWORD = 'Incorrect password value';

    private PDO $pdo;
    private AuthTestClock $clock;
    private AuthTestUuidGenerator $uuids;
    private PasswordHasher $hasher;
    private WebAdminAuthenticationService $service;
    private string $previousExceptionTraceSetting;

    protected function setUp(): void
    {
        $this->previousExceptionTraceSetting = (string) ini_get(
            'zend.exception_ignore_args'
        );
        ini_set('zend.exception_ignore_args', '1');
        $this->pdo = $this->sqlite();
        $this->applySchema($this->pdo);
        $this->clock = new AuthTestClock('2026-08-01 01:00:00.000000');
        $this->uuids = new AuthTestUuidGenerator();
        $this->hasher = PasswordHasher::productive();
        $this->service = $this->service(
            $this->pdo,
            $this->clock,
            $this->uuids,
            $this->hasher
        );
    }

    protected function tearDown(): void
    {
        ini_set(
            'zend.exception_ignore_args',
            $this->previousExceptionTraceSetting
        );
    }

    public function testPreAuthenticationSessionStoresOnlyHashesAndReuseKeepsStableCsrf(): void
    {
        $first = $this->service->openPreAuthenticationSession(null);
        $row = $this->singleSession();

        self::assertSame(SessionSecrets::PREAUTHENTICATED, $first->sessionType());
        self::assertSame('preauth', $row['session_type']);
        self::assertNotSame($first->sessionToken(), $row['token_hash']);
        self::assertNotSame($first->csrfToken(), $row['csrf_token_hash']);
        self::assertSame(hash('sha256', $first->sessionToken()), $row['token_hash']);
        self::assertSame(hash('sha256', $first->csrfToken()), $row['csrf_token_hash']);

        $second = $this->service->openPreAuthenticationSession(
            $first->sessionToken()
        );
        $reused = $this->singleSession();

        self::assertSame($first->sessionToken(), $second->sessionToken());
        self::assertSame($first->csrfToken(), $second->csrfToken());
        self::assertSame(1, $this->tableCount('sessions'));
        self::assertSame(
            hash('sha256', $second->csrfToken()),
            $reused['csrf_token_hash']
        );
    }

    public function testGenericPreAuthenticationCsrfRejectsCrossedAndActionSessions(): void
    {
        $first = $this->service->openPreAuthenticationSession(null);
        $second = $this->service->openPreAuthenticationSession(null);

        self::assertTrue($this->service->validatePreAuthenticationCsrf(
            $first->sessionToken(),
            $first->csrfToken()
        ));
        self::assertFalse($this->service->validatePreAuthenticationCsrf(
            $first->sessionToken(),
            $second->csrfToken()
        ));
        self::assertFalse($this->service->validatePreAuthenticationCsrf(
            (new SecureTokenGenerator())->generate(),
            (new SecureTokenGenerator())->generate()
        ));

        $statement = $this->pdo->prepare(
            'UPDATE ls_webadmin_sessions SET pending_action_token_id = 1 '
            . 'WHERE token_hash = :token_hash'
        );
        $this->pdo->exec(
            "INSERT INTO ls_webadmin_users "
            . "(public_id, email_canonical, status) VALUES "
            . "('bb000000-0000-4000-8000-000000000001', "
            . "'action@example.test', 'invited')"
        );
        $userId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            'INSERT INTO ls_webadmin_action_tokens '
            . '(user_id, purpose, token_hash, auth_version, created_at, '
            . 'expires_at) VALUES (' . $userId . ", 'invite', '"
            . str_repeat('a', 64) . "', 1, '2026-08-01 01:00:00.000000', "
            . "'2026-08-01 02:00:00.000000')"
        );
        $statement->execute([
            'token_hash' => hash('sha256', $first->sessionToken()),
        ]);
        self::assertFalse($this->service->validatePreAuthenticationCsrf(
            $first->sessionToken(),
            $first->csrfToken()
        ));
    }

    public function testOpeningPreAuthenticationDoesNotRevokeAuthenticatedSession(): void
    {
        $userId = $this->seedUser('active@example.test', 'active');
        $authenticated = $this->login('active@example.test');

        $newPreAuthentication = $this->service->openPreAuthenticationSession(
            $authenticated->nextSession()->sessionToken()
        );

        self::assertFalse($newPreAuthentication->isAuthenticated());
        self::assertNotNull($this->service->resolveAuthenticatedSession(
            $authenticated->nextSession()->sessionToken()
        ));
        self::assertSame(
            2,
            $this->tableCount('sessions'),
            'The revoked login preauth row is collected opportunistically.'
        );
        self::assertSame($userId, $authenticated->authenticatedSession()->userId());
    }

    public function testLoginRejectsPreAuthenticationBoundToActionToken(): void
    {
        $userId = $this->seedUser('active@example.test', 'active');
        $preAuthentication = $this->service->openPreAuthenticationSession(null);
        $timestamp = '2026-08-01 01:00:00.000000';
        $token = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_action_tokens '
            . '(user_id, purpose, token_hash, auth_version, created_at, '
            . 'expires_at) VALUES (:user_id, :purpose, :token_hash, 1, '
            . ':created_at, :expires_at)'
        );
        $token->execute([
            'user_id' => $userId,
            'purpose' => 'password_reset',
            'token_hash' => str_repeat('a', 64),
            'created_at' => $timestamp,
            'expires_at' => '2026-08-01 02:00:00.000000',
        ]);
        $bind = $this->pdo->prepare(
            'UPDATE ls_webadmin_sessions SET pending_action_token_id = :token '
            . 'WHERE token_hash = :session_hash'
        );
        $bind->execute([
            'token' => (int) $this->pdo->lastInsertId(),
            'session_hash' => hash('sha256', $preAuthentication->sessionToken()),
        ]);

        $attempt = $this->service->authenticate(
            $preAuthentication->sessionToken(),
            $preAuthentication->csrfToken(),
            'active@example.test',
            self::PASSWORD,
            '192.0.2.80'
        );

        self::assertFalse($attempt->isSuccessful());
        self::assertSame(
            0,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM ls_webadmin_sessions "
                . "WHERE session_type = 'authenticated'"
            )->fetchColumn()
        );
    }

    public function testExpiredPreAuthenticationIsRevokedAndReplaced(): void
    {
        $expired = $this->service->openPreAuthenticationSession(null);
        $this->clock->advanceSeconds(300);

        $replacement = $this->service->openPreAuthenticationSession(
            $expired->sessionToken()
        );

        self::assertNotSame(
            $expired->sessionToken(),
            $replacement->sessionToken()
        );
        $expiredStatement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ls_webadmin_sessions WHERE token_hash = :hash'
        );
        $expiredStatement->execute([
            'hash' => hash('sha256', $expired->sessionToken()),
        ]);
        self::assertSame(0, (int) $expiredStatement->fetchColumn());
        self::assertNull($this->sessionByToken(
            $replacement->sessionToken()
        )['revoked_at']);
    }

    public function testPreAuthenticationIssuanceIsShortLivedBoundedAndReusable(): void
    {
        $this->service = $this->service(
            $this->pdo,
            $this->clock,
            $this->uuids,
            $this->hasher,
            new LoginRateLimitPolicy(60, 5, 20, 120, 2)
        );
        $first = $this->service->openPreAuthenticationSession(
            null,
            '192.0.2.70'
        );
        $second = $this->service->openPreAuthenticationSession(
            null,
            '192.0.2.70'
        );

        self::assertSame(600, $first->absoluteExpiresAt()->getTimestamp()
            - $this->clock->now()->getTimestamp());
        self::assertNotSame($first->sessionToken(), $second->sessionToken());
        self::assertSame(2, $this->tableCount('sessions'));

        try {
            $this->service->openPreAuthenticationSession(
                null,
                '192.0.2.70'
            );
            self::fail('The per-IP issuance limit must fail closed.');
        } catch (PreAuthenticationRateLimited $exception) {
            self::assertSame(
                'webadmin.preauthentication.rate_limited',
                $exception->getMessage()
            );
        }

        $reused = $this->service->openPreAuthenticationSession(
            $first->sessionToken(),
            '192.0.2.70'
        );
        self::assertSame($first->sessionToken(), $reused->sessionToken());
        self::assertSame($first->csrfToken(), $reused->csrfToken());
        self::assertSame(2, $this->tableCount('sessions'));

        $this->clock->advanceSeconds(121);
        $afterBlock = $this->service->openPreAuthenticationSession(
            null,
            '192.0.2.70'
        );
        self::assertNotSame($second->sessionToken(), $afterBlock->sessionToken());
    }

    public function testSuccessfulLoginRotatesSessionAndPersistsNoRawSecrets(): void
    {
        $userId = $this->seedUser('Admin@Example.test', 'active');
        $preAuthentication = $this->service->openPreAuthenticationSession(null);
        $attempt = $this->service->authenticate(
            $preAuthentication->sessionToken(),
            $preAuthentication->csrfToken(),
            ' ADMIN@example.test ',
            self::PASSWORD,
            '203.0.113.8',
            'Secret User Agent/1.0'
        );

        self::assertTrue($attempt->isSuccessful());
        self::assertNull($attempt->publicErrorCode());
        self::assertTrue($attempt->nextSession()->isAuthenticated());
        self::assertNotSame(
            $preAuthentication->sessionToken(),
            $attempt->nextSession()->sessionToken()
        );
        self::assertSame($userId, $attempt->authenticatedSession()->userId());
        self::assertSame(
            'admin@example.test',
            $attempt->authenticatedSession()->emailCanonical()
        );

        $rows = $this->pdo->query(
            'SELECT session_type, token_hash, csrf_token_hash, revoked_at '
            . 'FROM ls_webadmin_sessions ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);
        self::assertNotNull($rows[0]['revoked_at']);
        self::assertNull($rows[1]['revoked_at']);
        self::assertSame(
            hash('sha256', $attempt->nextSession()->sessionToken()),
            $rows[1]['token_hash']
        );
        self::assertSame(
            hash('sha256', $attempt->nextSession()->csrfToken()),
            $rows[1]['csrf_token_hash']
        );

        $audit = $this->pdo->query(
            'SELECT event_code, outcome, reason_code, actor_user_id, '
            . 'actor_session_public_id, metadata_json, ip_hash, '
            . 'user_agent_hash FROM ls_webadmin_audit_log'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('webadmin.login', $audit['event_code']);
        self::assertSame('success', $audit['outcome']);
        self::assertNull($audit['reason_code']);
        self::assertSame($userId, $audit['actor_user_id']);
        self::assertNull($audit['metadata_json']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $audit['ip_hash']);
        self::assertMatchesRegularExpression(
            '/\A[0-9a-f]{64}\z/',
            $audit['user_agent_hash']
        );

        $persistence = json_encode([
            $rows,
            $this->pdo->query(
                'SELECT * FROM ls_webadmin_rate_limits'
            )->fetchAll(PDO::FETCH_ASSOC),
            $this->pdo->query(
                'SELECT * FROM ls_webadmin_audit_log'
            )->fetchAll(PDO::FETCH_ASSOC),
        ], JSON_THROW_ON_ERROR);
        foreach ([
            self::PASSWORD,
            $preAuthentication->sessionToken(),
            $preAuthentication->csrfToken(),
            $attempt->nextSession()->sessionToken(),
            $attempt->nextSession()->csrfToken(),
            '203.0.113.8',
            'Secret User Agent/1.0',
        ] as $secret) {
            self::assertStringNotContainsString($secret, $persistence);
        }
    }

    public function testAllCredentialFailuresExposeTheSameContract(): void
    {
        $this->seedUser('invited@example.test', 'invited');
        $this->seedUser('suspended@example.test', 'suspended');
        $this->seedUser('wrong@example.test', 'active');
        $corruptId = $this->seedUser('corrupt@example.test', 'active');
        $statement = $this->pdo->prepare(
            'UPDATE ls_webadmin_credentials SET password_hash = :hash '
            . 'WHERE user_id = :user_id'
        );
        $statement->execute(['hash' => 'not-a-password-hash', 'user_id' => $corruptId]);

        $cases = [
            ['not-an-email', self::PASSWORD],
            ['missing@example.test', self::PASSWORD],
            ['invited@example.test', self::PASSWORD],
            ['suspended@example.test', self::PASSWORD],
            ['wrong@example.test', self::WRONG_PASSWORD],
            ['corrupt@example.test', self::PASSWORD],
        ];
        foreach ($cases as $index => [$email, $password]) {
            $preAuthentication = $this->service->openPreAuthenticationSession(null);
            $attempt = $this->service->authenticate(
                $preAuthentication->sessionToken(),
                $preAuthentication->csrfToken(),
                $email,
                $password,
                '198.51.100.' . ($index + 1)
            );

            self::assertFalse($attempt->isSuccessful());
            self::assertSame(
                AuthenticationAttempt::GENERIC_FAILURE,
                $attempt->publicErrorCode()
            );
            self::assertFalse($attempt->nextSession()->isAuthenticated());
        }

        $failures = $this->pdo->query(
            "SELECT outcome, reason_code, actor_user_id, metadata_json "
            . "FROM ls_webadmin_audit_log WHERE event_code = 'webadmin.login' "
            . 'ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(count($cases), $failures);
        foreach ($failures as $failure) {
            self::assertSame('failure', $failure['outcome']);
            self::assertSame('authentication_failed', $failure['reason_code']);
            self::assertNull($failure['actor_user_id']);
            self::assertNull($failure['metadata_json']);
        }
    }

    public function testLifecycleAndCredentialDriftUsesGenericLoginFailure(): void
    {
        $mutations = [
            'activation_missing' => [
                'users',
                'activated_at = NULL',
            ],
            'activation_invalid' => [
                'users',
                "activated_at = 'invalid'",
            ],
            'suspension_present' => [
                'users',
                "suspended_at = '2026-08-01 01:00:00.000000'",
            ],
            'password_timestamp_invalid' => [
                'credentials',
                "password_set_at = 'invalid'",
            ],
            'credential_missing' => [
                'credentials',
                null,
            ],
        ];

        foreach ($mutations as $label => [$table, $assignment]) {
            $email = $label . '@example.test';
            $userId = $this->seedUser($email, 'active');
            if ($assignment === null) {
                $this->pdo->exec(
                    'DELETE FROM ls_webadmin_credentials WHERE user_id = '
                    . $userId
                );
            } else {
                $this->pdo->exec(
                    'UPDATE ls_webadmin_' . $table . ' SET ' . $assignment
                    . ' WHERE ' . ($table === 'users' ? 'id' : 'user_id')
                    . ' = ' . $userId
                );
            }
            $preAuthentication = $this->service
                ->openPreAuthenticationSession(null);

            $attempt = $this->service->authenticate(
                $preAuthentication->sessionToken(),
                $preAuthentication->csrfToken(),
                $email,
                self::PASSWORD,
                '198.51.100.200'
            );

            self::assertFalse($attempt->isSuccessful(), $label);
            self::assertSame(
                AuthenticationAttempt::GENERIC_FAILURE,
                $attempt->publicErrorCode(),
                $label
            );
            self::assertFalse(
                $attempt->nextSession()->isAuthenticated(),
                $label
            );
        }
    }

    public function testInvalidCsrfCannotAuthenticateAndDoesNotConsumeRateBudget(): void
    {
        $this->seedUser('admin@example.test', 'active');
        $preAuthentication = $this->service->openPreAuthenticationSession(null);
        $attempt = $this->service->authenticate(
            $preAuthentication->sessionToken(),
            (new SecureTokenGenerator())->generate(),
            'admin@example.test',
            self::PASSWORD,
            '192.0.2.3'
        );

        self::assertFalse($attempt->isSuccessful());
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ls_webadmin_rate_limits "
            . "WHERE action LIKE 'login.%'"
        )->fetchColumn());
        self::assertSame('request_rejected', $this->pdo->query(
            'SELECT reason_code FROM ls_webadmin_audit_log'
        )->fetchColumn());
        self::assertNotSame(
            $preAuthentication->sessionToken(),
            $attempt->nextSession()->sessionToken()
        );
    }

    public function testRejectedPostsCannotCreateUnboundedPreAuthenticationRows(): void
    {
        $policy = new LoginRateLimitPolicy(60, 5, 20, 120, 2);
        $this->service = $this->service(
            $this->pdo,
            $this->clock,
            $this->uuids,
            $this->hasher,
            $policy
        );
        $address = '192.0.2.44';
        $initial = $this->service->openPreAuthenticationSession(
            null,
            $address
        );
        $replacement = $this->service->authenticate(
            $initial->sessionToken(),
            (new SecureTokenGenerator())->generate(),
            'admin@example.test',
            self::PASSWORD,
            $address
        )->nextSession();

        try {
            $this->service->authenticate(
                $replacement->sessionToken(),
                (new SecureTokenGenerator())->generate(),
                'admin@example.test',
                self::PASSWORD,
                $address
            );
            self::fail('The pre-authentication allocation limit must close the chain.');
        } catch (PreAuthenticationRateLimited) {
            self::assertSame(2, $this->tableCount('sessions'));
            self::assertSame(1, $this->tableCount('audit_log'));
            self::assertSame(2, (int) $this->pdo->query(
                "SELECT attempts FROM ls_webadmin_rate_limits "
                . "WHERE action = 'preauth.issue'"
            )->fetchColumn());
        }
    }

    public function testIdentifierRateLimitBlocksCorrectPasswordUntilFixedBlockExpires(): void
    {
        $this->seedUser('limited@example.test', 'active');
        $this->service = $this->service(
            $this->pdo,
            $this->clock,
            $this->uuids,
            $this->hasher,
            new LoginRateLimitPolicy(60, 2, 20, 120)
        );
        $session = $this->service->openPreAuthenticationSession(null);

        for ($attemptNumber = 1; $attemptNumber <= 2; $attemptNumber++) {
            $attempt = $this->service->authenticate(
                $session->sessionToken(),
                $session->csrfToken(),
                'limited@example.test',
                self::WRONG_PASSWORD,
                '192.0.2.20'
            );
            self::assertFalse($attempt->isSuccessful());
            $session = $attempt->nextSession();
        }

        $blocked = $this->service->authenticate(
            $session->sessionToken(),
            $session->csrfToken(),
            'limited@example.test',
            self::PASSWORD,
            '192.0.2.20'
        );
        self::assertFalse($blocked->isSuccessful());
        self::assertSame(AuthenticationAttempt::GENERIC_FAILURE, $blocked->publicErrorCode());
        self::assertSame('rate_limited', $this->pdo->query(
            'SELECT reason_code FROM ls_webadmin_audit_log ORDER BY id DESC LIMIT 1'
        )->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query(
            "SELECT attempts FROM ls_webadmin_rate_limits "
            . "WHERE action = 'login.identifier'"
        )->fetchColumn());

        $this->clock->advanceSeconds(121);
        $allowed = $this->service->authenticate(
            $blocked->nextSession()->sessionToken(),
            $blocked->nextSession()->csrfToken(),
            'limited@example.test',
            self::PASSWORD,
            '192.0.2.20'
        );
        self::assertTrue($allowed->isSuccessful());
    }

    public function testLiveBlockOutlivesItsOriginalAccountingWindow(): void
    {
        $this->seedUser('window-edge@example.test', 'active');
        $this->service = $this->service(
            $this->pdo,
            $this->clock,
            $this->uuids,
            $this->hasher,
            new LoginRateLimitPolicy(60, 2, 20, 120)
        );
        $session = $this->service->openPreAuthenticationSession(null);

        for ($index = 0; $index < 2; ++$index) {
            $failed = $this->service->authenticate(
                $session->sessionToken(),
                $session->csrfToken(),
                'window-edge@example.test',
                self::WRONG_PASSWORD,
                '192.0.2.21'
            );
            $session = $failed->nextSession();
        }

        $this->clock->advanceSeconds(61);
        $stillBlocked = $this->service->authenticate(
            $session->sessionToken(),
            $session->csrfToken(),
            'window-edge@example.test',
            self::PASSWORD,
            '192.0.2.21'
        );
        self::assertFalse($stillBlocked->isSuccessful());
        self::assertSame('rate_limited', $this->pdo->query(
            'SELECT reason_code FROM ls_webadmin_audit_log '
            . 'ORDER BY id DESC LIMIT 1'
        )->fetchColumn());

        $this->clock->advanceSeconds(60);
        $allowed = $this->service->authenticate(
            $stillBlocked->nextSession()->sessionToken(),
            $stillBlocked->nextSession()->csrfToken(),
            'window-edge@example.test',
            self::PASSWORD,
            '192.0.2.21'
        );
        self::assertTrue($allowed->isSuccessful());
    }

    public function testIpRateLimitAggregatesDifferentIdentifiersWithoutPersistingIp(): void
    {
        $this->seedUser('valid@example.test', 'active');
        $this->service = $this->service(
            $this->pdo,
            $this->clock,
            $this->uuids,
            $this->hasher,
            new LoginRateLimitPolicy(600, 10, 2, 600)
        );
        $session = $this->service->openPreAuthenticationSession(null);

        foreach (['one@example.test', 'two@example.test'] as $email) {
            $failed = $this->service->authenticate(
                $session->sessionToken(),
                $session->csrfToken(),
                $email,
                self::WRONG_PASSWORD,
                '2001:db8::7'
            );
            $session = $failed->nextSession();
        }
        $denied = $this->service->authenticate(
            $session->sessionToken(),
            $session->csrfToken(),
            'valid@example.test',
            self::PASSWORD,
            '2001:db8::7'
        );

        self::assertFalse($denied->isSuccessful());
        $stored = json_encode($this->pdo->query(
            'SELECT * FROM ls_webadmin_rate_limits'
        )->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('2001:db8::7', $stored);
        self::assertStringNotContainsString('one@example.test', $stored);
        self::assertSame(
            3,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM ls_webadmin_rate_limits "
                . "WHERE action LIKE 'login.%'"
            )->fetchColumn(),
            'A live IP block must not create another identifier bucket.'
        );
    }

    public function testResolveSlidesIdleExpirationButNeverPastAbsoluteBoundary(): void
    {
        $this->seedUser('admin@example.test', 'active');
        $attempt = $this->login('admin@example.test');
        $token = $attempt->nextSession()->sessionToken();

        $this->clock->advanceSeconds(250);
        $first = $this->service->resolveAuthenticatedSession($token);
        self::assertInstanceOf(AuthenticatedSession::class, $first);
        self::assertSame(
            '2026-08-01 01:09:10.000000',
            WebAdminAuthenticationRepository::format($first->idleExpiresAt())
        );

        $this->clock->advanceSeconds(250);
        $second = $this->service->resolveAuthenticatedSession($token);
        self::assertInstanceOf(AuthenticatedSession::class, $second);
        self::assertSame(
            '2026-08-01 01:10:00.000000',
            WebAdminAuthenticationRepository::format($second->idleExpiresAt())
        );

        $this->clock->advanceSeconds(100);
        self::assertNull($this->service->resolveAuthenticatedSession($token));
        self::assertNotNull($this->sessionByToken($token)['revoked_at']);
    }

    public function testIdleExpirationIsExclusiveAtBoundary(): void
    {
        $this->seedUser('admin@example.test', 'active');
        $attempt = $this->login('admin@example.test');
        $token = $attempt->nextSession()->sessionToken();

        $this->clock->advanceSeconds(300);

        self::assertNull($this->service->resolveAuthenticatedSession($token));
    }

    public function testStatusAuthVersionAndCredentialChangesInvalidateSession(): void
    {
        foreach (['status', 'version', 'credential'] as $mutation) {
            $email = $mutation . '@example.test';
            $userId = $this->seedUser($email, 'active');
            $attempt = $this->login($email);
            $token = $attempt->nextSession()->sessionToken();

            if ($mutation === 'status') {
                $this->pdo->exec(
                    "UPDATE ls_webadmin_users SET status = 'suspended' "
                    . 'WHERE id = ' . $userId
                );
            } elseif ($mutation === 'version') {
                $this->pdo->exec(
                    'UPDATE ls_webadmin_users SET auth_version = auth_version + 1 '
                    . 'WHERE id = ' . $userId
                );
            } else {
                $this->pdo->exec(
                    'DELETE FROM ls_webadmin_credentials WHERE user_id = '
                    . $userId
                );
            }

            self::assertNull($this->service->resolveAuthenticatedSession($token));
            self::assertNotNull($this->sessionByToken($token)['revoked_at']);
        }
    }

    public function testLifecycleDriftInvalidatesAndRevokesAuthenticatedSession(): void
    {
        $mutations = [
            'activation_missing' => [
                'users',
                'activated_at = NULL',
            ],
            'activation_invalid' => [
                'users',
                "activated_at = 'invalid'",
            ],
            'suspension_present' => [
                'users',
                "suspended_at = '2026-08-01 01:00:00.000000'",
            ],
            'password_timestamp_invalid' => [
                'credentials',
                "password_set_at = 'invalid'",
            ],
        ];

        foreach ($mutations as $label => [$table, $assignment]) {
            $email = 'session-' . $label . '@example.test';
            $userId = $this->seedUser($email, 'active');
            $token = $this->login($email)->nextSession()->sessionToken();
            $this->pdo->exec(
                'UPDATE ls_webadmin_' . $table . ' SET ' . $assignment
                . ' WHERE ' . ($table === 'users' ? 'id' : 'user_id')
                . ' = ' . $userId
            );

            self::assertNull(
                $this->service->resolveAuthenticatedSession($token),
                $label
            );
            self::assertNotNull(
                $this->sessionByToken($token)['revoked_at'],
                $label
            );
        }
    }

    public function testAuthenticatedCsrfIsStableAcrossRepeatedRequestsAndTabs(): void
    {
        $this->seedUser('admin@example.test', 'active');
        $attempt = $this->login('admin@example.test');
        $secrets = $attempt->nextSession();

        $firstTab = $this->service->authenticatedCsrfToken(
            $secrets->sessionToken()
        );
        $secondTab = $this->service->authenticatedCsrfToken(
            $secrets->sessionToken()
        );
        self::assertNotNull($firstTab);
        self::assertNotNull($secondTab);
        self::assertSame($secrets->csrfToken(), $firstTab->csrfToken());
        self::assertSame($firstTab->csrfToken(), $secondTab->csrfToken());
        self::assertSame(
            hash('sha256', $secrets->csrfToken()),
            $this->sessionByToken($secrets->sessionToken())['csrf_token_hash']
        );
    }

    public function testSafeGetReturnsBoundAuthenticatedCsrfToken(): void
    {
        $this->seedUser('csrf-get@example.test', 'active');
        $secrets = $this->login('csrf-get@example.test')->nextSession();

        $issued = $this->service->authenticatedCsrfToken(
            $secrets->sessionToken()
        );
        self::assertNotNull($issued);
        self::assertSame($secrets->csrfToken(), $issued->csrfToken());
        self::assertSame(
            hash('sha256', $issued->csrfToken()),
            $this->sessionByToken(
                $secrets->sessionToken()
            )['csrf_token_hash']
        );
    }

    public function testPersistedCsrfBindingMismatchRevokesSession(): void
    {
        $this->seedUser('csrf-binding@example.test', 'active');
        $secrets = $this->login('csrf-binding@example.test')->nextSession();
        $statement = $this->pdo->prepare(
            'UPDATE ls_webadmin_sessions SET csrf_token_hash = :hash '
            . 'WHERE token_hash = :token_hash'
        );
        $statement->execute([
            'hash' => str_repeat('c', 64),
            'token_hash' => hash('sha256', $secrets->sessionToken()),
        ]);

        self::assertNull($this->service->authenticatedCsrfToken(
            $secrets->sessionToken()
        ));
        self::assertNotNull(
            $this->sessionByToken($secrets->sessionToken())['revoked_at']
        );
    }

    public function testCorrectCredentialsWithoutAccessStayGenericAndCreateNoSession(): void
    {
        $userId = $this->seedUser(
            'no-access@example.test',
            'active',
            null,
            1,
            false
        );
        $attempt = $this->login('no-access@example.test');

        self::assertFalse($attempt->isSuccessful());
        self::assertSame(
            AuthenticationAttempt::GENERIC_FAILURE,
            $attempt->publicErrorCode()
        );
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ls_webadmin_sessions "
            . "WHERE session_type = 'authenticated'"
        )->fetchColumn());
        self::assertNull($this->pdo->query(
            'SELECT last_login_at FROM ls_webadmin_users WHERE id = ' . $userId
        )->fetchColumn());
        $audit = $this->pdo->query(
            'SELECT outcome, reason_code, actor_user_id '
            . 'FROM ls_webadmin_audit_log ORDER BY id DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('failure', $audit['outcome']);
        self::assertSame('authorization_denied', $audit['reason_code']);
        self::assertSame($userId, $audit['actor_user_id']);
    }

    public function testLogoutRequiresValidCsrfAndOnlyThenSignalsCookieExpiry(): void
    {
        $this->seedUser('admin@example.test', 'active');
        $attempt = $this->login('admin@example.test');
        $secrets = $attempt->nextSession();

        self::assertFalse($this->service->logout(
            $secrets->sessionToken(),
            (new SecureTokenGenerator())->generate(),
            '203.0.113.1'
        ));
        self::assertNotNull($this->service->resolveAuthenticatedSession(
            $secrets->sessionToken()
        ));

        self::assertTrue($this->service->logout(
            $secrets->sessionToken(),
            $secrets->csrfToken(),
            '203.0.113.1',
            'Browser Secret'
        ));
        self::assertNull($this->service->resolveAuthenticatedSession(
            $secrets->sessionToken()
        ));
        self::assertSame('webadmin.logout', $this->pdo->query(
            'SELECT event_code FROM ls_webadmin_audit_log ORDER BY id DESC LIMIT 1'
        )->fetchColumn());
    }

    public function testPrivilegedRevocationIsIdempotent(): void
    {
        $this->seedUser('admin@example.test', 'active');
        $token = $this->login('admin@example.test')
            ->nextSession()
            ->sessionToken();

        $this->service->revokeSession($token);
        $this->service->revokeSession($token);

        self::assertNull($this->service->resolveAuthenticatedSession($token));
    }

    public function testLegacyCredentialRequiresResetInsteadOfTimingDistinctRehash(): void
    {
        $legacyHasher = PasswordHasher::bcryptFallback();
        $legacyHash = password_hash(
            self::PASSWORD,
            PASSWORD_BCRYPT,
            ['cost' => 4]
        );
        self::assertIsString($legacyHash);
        $userId = $this->seedUser(
            'legacy@example.test',
            'active',
            $legacyHash
        );

        self::assertFalse($this->login('legacy@example.test')->isSuccessful());
        $stored = $this->pdo->query(
            'SELECT password_hash FROM ls_webadmin_credentials WHERE user_id = '
            . $userId
        )->fetchColumn();
        self::assertIsString($stored);
        self::assertSame($legacyHash, $stored);
        self::assertFalse($this->hasher->isCurrentHash($stored));
        self::assertTrue($legacyHasher->verify(self::PASSWORD, $legacyHash));
    }

    public function testCurrentArgonHashWithLegacyCompositionStillLogsIn(): void
    {
        $legacyPassword = '123legacy123legacy';
        $legacyHash = password_hash($legacyPassword, PASSWORD_ARGON2ID, [
            'memory_cost' => PasswordHasher::ARGON2_MEMORY_COST,
            'time_cost' => PasswordHasher::ARGON2_TIME_COST,
            'threads' => PasswordHasher::ARGON2_THREADS,
        ]);
        self::assertIsString($legacyHash);
        self::assertTrue($this->hasher->isCurrentHash($legacyHash));
        $this->seedUser('composition-legacy@example.test', 'active', $legacyHash);
        $preAuthentication = $this->service->openPreAuthenticationSession(null);

        $attempt = $this->service->authenticate(
            $preAuthentication->sessionToken(),
            $preAuthentication->csrfToken(),
            'composition-legacy@example.test',
            $legacyPassword,
            '127.0.0.1'
        );

        self::assertTrue($attempt->isSuccessful());
        self::assertNotNull($attempt->nextSession());
    }

    public function testDummyCredentialIsPrecomputedForProductivePolicy(): void
    {
        $hash = $this->hasher->verificationDummyHash();

        self::assertFalse(method_exists(
            WebAdminAuthenticationService::class,
            'dummyPasswordHash'
        ));
        self::assertSame('argon2id', password_get_info($hash)['algoName']);
        self::assertTrue($this->hasher->isCurrentHash($hash));
    }

    public function testFailureDuringLegacyCredentialDenialRollsBackEverything(): void
    {
        $legacyHash = password_hash(
            self::PASSWORD,
            PASSWORD_BCRYPT,
            ['cost' => 4]
        );
        self::assertIsString($legacyHash);
        $userId = $this->seedUser(
            'rollback@example.test',
            'active',
            $legacyHash
        );
        $preAuthentication = $this->service->openPreAuthenticationSession(null);
        $this->uuids->failOnNextCall();

        try {
            $this->service->authenticate(
                $preAuthentication->sessionToken(),
                $preAuthentication->csrfToken(),
                'rollback@example.test',
                self::PASSWORD,
                '192.0.2.44'
            );
            self::fail('The injected UUID failure should abort authentication.');
        } catch (AuthenticationStorageException $exception) {
            self::assertSame(
                'WebAdmin authentication storage is unavailable.',
                $exception->getMessage()
            );
        }

        self::assertNull($this->sessionByToken(
            $preAuthentication->sessionToken()
        )['revoked_at']);
        self::assertSame($legacyHash, $this->pdo->query(
            'SELECT password_hash FROM ls_webadmin_credentials WHERE user_id = '
            . $userId
        )->fetchColumn());
        self::assertSame(1, $this->tableCount('sessions'));
        self::assertSame(0, $this->tableCount('audit_log'));
    }

    public function testInvalidInjectedUuidFailsClosedWithoutPersistence(): void
    {
        $invalid = new AuthTestInvalidUuidGenerator();
        $service = $this->service(
            $this->pdo,
            $this->clock,
            $invalid,
            $this->hasher
        );

        $this->expectException(AuthenticationStorageException::class);
        try {
            $service->openPreAuthenticationSession(null);
        } finally {
            self::assertSame(0, $this->tableCount('sessions'));
        }
    }

    public function testSecretValueObjectsRedactDebugAndRejectSerialization(): void
    {
        $session = $this->service->openPreAuthenticationSession(null);
        $debug = print_r($session, true);
        self::assertStringNotContainsString($session->sessionToken(), $debug);
        self::assertStringNotContainsString($session->csrfToken(), $debug);
        self::assertStringContainsString('[redacted]', $debug);
        $exported = var_export($session, true);
        self::assertStringNotContainsString(
            $session->sessionToken(),
            $exported
        );
        self::assertStringNotContainsString($session->csrfToken(), $exported);

        try {
            serialize($session);
            self::fail('Session secrets must not serialize.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString(
                $session->sessionToken(),
                $exception->getMessage()
            );
        }

        $this->seedUser('debug@example.test', 'active');
        $authenticated = $this->login('debug@example.test')->nextSession();
        $csrf = $this->service->authenticatedCsrfToken(
            $authenticated->sessionToken()
        );
        self::assertNotNull($csrf);
        self::assertStringNotContainsString(
            $csrf->csrfToken(),
            print_r($csrf, true)
        );
        self::assertStringNotContainsString(
            $csrf->csrfToken(),
            var_export($csrf, true)
        );
    }

    public function testAuthenticationRequiresExceptionArgumentRedaction(): void
    {
        ini_set('zend.exception_ignore_args', '0');
        try {
            $this->service(
                $this->pdo,
                $this->clock,
                $this->uuids,
                $this->hasher
            );
            self::fail('Unsafe PHP exception traces must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'webadmin.security.exception_trace_arguments_enabled',
                $exception->getMessage()
            );
        } finally {
            ini_set('zend.exception_ignore_args', '1');
        }
    }

    public function testEscapedAuthenticationExceptionContainsNoRawArguments(): void
    {
        $session = $this->service->openPreAuthenticationSession(null);
        $this->uuids->failOnNextCall();
        $password = 'trace-password-must-never-appear';

        try {
            $this->service->authenticate(
                $session->sessionToken(),
                $session->csrfToken(),
                'trace@example.test',
                $password,
                '192.0.2.99',
                'Trace Browser'
            );
            self::fail('The UUID fault must escape as a generic failure.');
        } catch (AuthenticationStorageException $exception) {
            $trace = var_export($exception->getTrace(), true);
            foreach ([
                $session->sessionToken(),
                $session->csrfToken(),
                'trace@example.test',
                $password,
                '192.0.2.99',
                'Trace Browser',
            ] as $secret) {
                self::assertStringNotContainsString($secret, $trace);
            }
        }
    }

    public function testRepositoryRejectsSilentPdoBeforeAnyMutation(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $tables = WebAdminTableNames::fromPdo($pdo, 'ls_webadmin_');

        $this->expectException(AuthenticationStorageException::class);
        new WebAdminAuthenticationRepository($pdo, $tables);
    }

    public function testRepositoryRejectsSqliteWithoutForeignKeys(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = WebAdminTableNames::fromPdo($pdo, 'ls_webadmin_');

        $this->expectException(AuthenticationStorageException::class);
        new WebAdminAuthenticationRepository($pdo, $tables);
    }

    public function testMysqlDeadlockAlreadyRolledBackByServerRetriesAndRecovers(): void
    {
        $pdo = new AuthDeadlockPdo();
        $repository = new WebAdminAuthenticationRepository(
            $pdo,
            WebAdminTableNames::fromPdo($pdo, 'ls_webadmin_')
        );
        $calls = 0;

        $result = $repository->transaction(
            static function () use ($pdo, &$calls): string {
                ++$calls;
                if ($calls === 1) {
                    $pdo->abortWithDeadlock();
                }

                return 'retried';
            }
        );

        self::assertSame('retried', $result);
        self::assertSame(2, $calls);
        self::assertSame(2, $pdo->beginCalls);
        self::assertSame(
            'reusable',
            $repository->transaction(static fn (): string => 'reusable')
        );
    }

    public function testSQLiteRollbackReleasesImmediateLockForAnotherConnection(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ls-auth-lock-');
        self::assertIsString($path);
        try {
            $first = $this->sqlite('sqlite:' . $path);
            $second = $this->sqlite('sqlite:' . $path);
            $first->exec('CREATE TABLE lock_probe (id INTEGER PRIMARY KEY)');
            $repository = new WebAdminAuthenticationRepository(
                $first,
                WebAdminTableNames::fromPdo($first, 'ls_webadmin_')
            );

            try {
                $repository->transaction(function () use ($first): void {
                    $first->exec('INSERT INTO lock_probe (id) VALUES (1)');
                    throw new RuntimeException('forced rollback');
                });
                self::fail('The forced rollback should be generic externally.');
            } catch (AuthenticationStorageException) {
                self::assertSame(0, $first->query(
                    'SELECT COUNT(*) FROM lock_probe'
                )->fetchColumn());
            }

            self::assertNotFalse($second->exec('BEGIN IMMEDIATE'));
            self::assertNotFalse($second->exec(
                'INSERT INTO lock_probe (id) VALUES (2)'
            ));
            self::assertNotFalse($second->exec('COMMIT'));
            self::assertSame(1, $first->query(
                'SELECT COUNT(*) FROM lock_probe'
            )->fetchColumn());
        } finally {
            // Windows cannot unlink SQLite while the repository still owns PDO.
            unset($repository, $first, $second);
            $this->removeTemporarySqliteDatabase($path);
        }
    }

    public function testSQLiteImmediateTransactionsSerializeConcurrentWriters(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ls-auth-concurrency-');
        self::assertIsString($path);
        try {
            $first = $this->sqlite('sqlite:' . $path);
            $second = $this->sqlite('sqlite:' . $path);
            $first->exec('PRAGMA busy_timeout = 0');
            $second->exec('PRAGMA busy_timeout = 0');
            $firstRepository = new WebAdminAuthenticationRepository(
                $first,
                WebAdminTableNames::fromPdo($first, 'ls_webadmin_')
            );
            $secondRepository = new WebAdminAuthenticationRepository(
                $second,
                WebAdminTableNames::fromPdo($second, 'ls_webadmin_')
            );
            $blocked = false;

            $firstRepository->transaction(function () use (
                $secondRepository,
                &$blocked
            ): void {
                try {
                    $secondRepository->transaction(static function (): void {
                    });
                } catch (AuthenticationStorageException) {
                    $blocked = true;
                }
            });

            self::assertTrue($blocked);
            $secondRepository->transaction(static function (): void {
            });
        } finally {
            // Both repositories must release PDO before removing the database.
            unset($firstRepository, $secondRepository, $first, $second);
            $this->removeTemporarySqliteDatabase($path);
        }
    }

    private function removeTemporarySqliteDatabase(string $path): void
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        $parent = realpath(dirname($path));
        self::assertNotFalse($temporaryRoot);
        self::assertSame($temporaryRoot, $parent);

        foreach ([$path, $path . '-journal', $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                self::assertTrue(
                    unlink($file),
                    'Could not remove an isolated SQLite test file.'
                );
            }
        }
    }

    private function login(string $email): AuthenticationAttempt
    {
        $preAuthentication = $this->service->openPreAuthenticationSession(null);

        return $this->service->authenticate(
            $preAuthentication->sessionToken(),
            $preAuthentication->csrfToken(),
            $email,
            self::PASSWORD,
            '127.0.0.1'
        );
    }

    private function seedUser(
        string $email,
        string $status,
        ?string $passwordHash = null,
        int $authVersion = 1,
        bool $grantAccess = true
    ): int {
        $canonical = strtolower($email);
        $publicId = sprintf(
            '10000000-0000-4000-8000-%012x',
            $this->tableCount('users') + 1
        );
        $timestamp = WebAdminAuthenticationRepository::format(
            $this->clock->now()
        );
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_users '
            . '(public_id, email_canonical, display_name, status, auth_version, '
            . 'invited_at, activated_at, created_at, updated_at) VALUES '
            . '(:public_id, :email, :display_name, :status, :auth_version, '
            . ':invited_at, :activated_at, :created_at, :updated_at)'
        );
        $statement->execute([
            'public_id' => $publicId,
            'email' => $canonical,
            'display_name' => 'Test User',
            'status' => $status,
            'auth_version' => $authVersion,
            'invited_at' => $timestamp,
            'activated_at' => $status === 'invited' ? null : $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $userId = (int) $this->pdo->lastInsertId();
        $hash = $passwordHash ?? $this->hasher->hash(self::PASSWORD);
        $credential = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_credentials '
            . '(user_id, password_hash, password_set_at, created_at, updated_at) '
            . 'VALUES (:user_id, :password_hash, :password_set_at, '
            . ':created_at, :updated_at)'
        );
        $credential->execute([
            'user_id' => $userId,
            'password_hash' => $hash,
            'password_set_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        if ($grantAccess) {
            $this->pdo->exec(
                'INSERT INTO ls_webadmin_user_roles '
                . '(user_id, role_id, source) '
                . "SELECT {$userId}, id, 'manual' FROM ls_webadmin_roles "
                . "WHERE code = 'editor'"
            );
        }

        return $userId;
    }

    private function service(
        PDO $pdo,
        ClockInterface $clock,
        UuidGeneratorInterface $uuids,
        PasswordHasher $hasher,
        ?LoginRateLimitPolicy $policy = null
    ): WebAdminAuthenticationService {
        $tables = WebAdminTableNames::fromPdo($pdo, 'ls_webadmin_');
        $repository = new WebAdminAuthenticationRepository($pdo, $tables);
        $config = new WebAdminConfig(
            '/admin',
            'ls_webadmin_',
            'LS_WEBADMIN_SID',
            300,
            600,
            'test'
        );

        return new WebAdminAuthenticationService(
            $repository,
            $config,
            SecurityKey::fromRawBytes(str_repeat('S', 32)),
            $clock,
            $uuids,
            $hasher,
            new SecureTokenGenerator(),
            $policy ?? new LoginRateLimitPolicy()
        );
    }

    private function sqlite(string $dsn = 'sqlite::memory:'): PDO
    {
        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function applySchema(PDO $pdo): void
    {
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
            self::assertNotFalse($pdo->exec($sql));
        }
    }

    /** @return array<string, mixed> */
    private function singleSession(): array
    {
        $row = $this->pdo->query(
            'SELECT * FROM ls_webadmin_sessions'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function sessionByToken(string $token): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM ls_webadmin_sessions WHERE token_hash = :hash'
        );
        $statement->execute(['hash' => hash('sha256', $token)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function tableCount(string $suffix): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM "ls_webadmin_' . $suffix . '"'
        )->fetchColumn();
    }
}

final class AuthTestClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $timestamp)
    {
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            $timestamp,
            new DateTimeZone('UTC')
        );
        if (!$parsed instanceof DateTimeImmutable) {
            throw new RuntimeException('Invalid test timestamp.');
        }
        $this->now = $parsed;
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->modify('+' . $seconds . ' seconds');
    }
}

final class AuthTestUuidGenerator implements UuidGeneratorInterface
{
    private int $counter = 1;
    private bool $failNext = false;

    public function generateV4(): string
    {
        if ($this->failNext) {
            $this->failNext = false;
            throw new RuntimeException('Injected UUID failure with no secret.');
        }

        return sprintf(
            '20000000-0000-4000-8000-%012x',
            $this->counter++
        );
    }

    public function failOnNextCall(): void
    {
        $this->failNext = true;
    }
}

final class AuthTestInvalidUuidGenerator implements UuidGeneratorInterface
{
    public function generateV4(): string
    {
        return 'invalid-uuid';
    }
}

final class AuthDeadlockPdo extends PDO
{
    public int $beginCalls = 0;
    private bool $active = false;

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

    public function beginTransaction(): bool
    {
        ++$this->beginCalls;
        $this->active = true;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->active;
    }

    public function commit(): bool
    {
        $this->active = false;

        return true;
    }

    public function rollBack(): bool
    {
        $this->active = false;

        return true;
    }

    public function abortWithDeadlock(): never
    {
        $this->active = false;
        $exception = new PDOException('simulated deadlock', 40001);
        $exception->errorInfo = ['40001', 1213, 'simulated'];

        throw $exception;
    }
}
