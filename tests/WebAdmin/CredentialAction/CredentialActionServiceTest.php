<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\CredentialAction\CredentialActionCompletion;
use App\Core\WebAdmin\CredentialAction\CredentialActionCsrfToken;
use App\Core\WebAdmin\CredentialAction\CredentialActionRateLimitPolicy;
use App\Core\WebAdmin\CredentialAction\CredentialActionRepository;
use App\Core\WebAdmin\CredentialAction\CredentialActionService;
use App\Core\WebAdmin\CredentialAction\CredentialActionSessionSecrets;
use App\Core\WebAdmin\CredentialAction\CredentialActionStorageException;
use App\Core\WebAdmin\CredentialAction\PasswordResetRequestResult;
use App\Core\WebAdmin\CredentialAction\PasswordResetDelivery;
use App\Core\WebAdmin\Mail\PasswordResetMailSenderInterface;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\InvalidPassword;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use PHPUnit\Framework\TestCase;

final class CredentialActionServiceTest extends TestCase
{
    private const OLD_PASSWORD = 'Correct horse battery staple 1!';
    private const NEW_PASSWORD = 'Aa1!bbbb';

    private PDO $pdo;
    private CredentialActionTestClock $clock;
    private CredentialActionTestUuidGenerator $uuids;
    private PasswordHasher $hasher;
    private SecureTokenGenerator $tokens;
    private SecurityKey $securityKey;
    private CredentialActionTestPasswordResetMailSender $mailSender;
    private CredentialActionService $service;
    private string $previousExceptionTraceSetting;

    protected function setUp(): void
    {
        $this->previousExceptionTraceSetting = (string) ini_get(
            'zend.exception_ignore_args'
        );
        ini_set('zend.exception_ignore_args', '1');
        $this->pdo = $this->sqlite();
        $this->applySchema($this->pdo);
        $this->clock = new CredentialActionTestClock(
            '2026-08-01 06:00:00.000000'
        );
        $this->uuids = new CredentialActionTestUuidGenerator();
        $this->hasher = PasswordHasher::productive();
        $this->tokens = new SecureTokenGenerator();
        $this->securityKey = SecurityKey::fromRawBytes(str_repeat('C', 32));
        $this->mailSender = new CredentialActionTestPasswordResetMailSender(
            $this->pdo
        );
        $this->service = $this->service();
    }

    protected function tearDown(): void
    {
        ini_set(
            'zend.exception_ignore_args',
            $this->previousExceptionTraceSetting
        );
    }

    public function testPasswordResetRequestSendsImmediatelyOnlyForEligibleIdentity(): void
    {
        $activeId = $this->seedUser('active@example.test', 'active');
        $this->seedUser('invited@example.test', 'invited');
        $this->seedUser('suspended@example.test', 'suspended');
        $cases = [
            [' ACTIVE@example.test ', '192.0.2.1'],
            ['missing@example.test', '192.0.2.2'],
            ['not-an-email', '192.0.2.3'],
            ['invited@example.test', '192.0.2.4'],
            ['suspended@example.test', '192.0.2.5'],
        ];
        $results = [];
        foreach ($cases as [$email, $address]) {
            $results[] = $this->service->requestPasswordReset(
                $email,
                $address,
                'Secret Browser/1.0',
                'es-ES'
            );
        }

        foreach ($results as $result) {
            self::assertTrue($result->accepted());
            self::assertFalse($result->deliveryFailed());
            self::assertSame(
                PasswordResetRequestResult::PUBLIC_MESSAGE,
                $result->publicMessageCode()
            );
        }
        self::assertSame(0, $this->countOutbox('password_reset'));
        self::assertCount(1, $this->mailSender->deliveries);
        self::assertFalse($this->mailSender->transactionObserved);
        self::assertSame(
            'active@example.test',
            $this->mailSender->deliveries[0]->recipientEmail()
        );
        self::assertSame('es-ES', $this->mailSender->deliveries[0]->locale());
        $firstAction = $this->actionById(
            $this->mailSender->deliveries[0]->actionTokenId()
        );
        self::assertSame($activeId, $firstAction['user_id']);
        self::assertNotNull($firstAction['delivered_at']);
        self::assertNull($firstAction['revoked_at']);

        $this->service->requestPasswordReset(
            'active@example.test',
            '192.0.2.9'
        );
        self::assertSame(0, $this->countOutbox('password_reset'));
        self::assertCount(2, $this->mailSender->deliveries);
        self::assertNotNull($this->actionById(
            $this->mailSender->deliveries[0]->actionTokenId()
        )['revoked_at']);
        self::assertNotNull($this->actionById(
            $this->mailSender->deliveries[1]->actionTokenId()
        )['delivered_at']);
        self::assertSame(count($cases) + 1, $this->tableCount('audit_log'));

        $persisted = json_encode([
            $this->pdo->query(
                'SELECT * FROM ls_webadmin_rate_limits'
            )->fetchAll(PDO::FETCH_ASSOC),
            $this->pdo->query(
                'SELECT * FROM ls_webadmin_audit_log'
            )->fetchAll(PDO::FETCH_ASSOC),
        ], JSON_THROW_ON_ERROR);
        foreach ([
            'not-an-email',
            'missing@example.test',
            '192.0.2.1',
            'Secret Browser/1.0',
        ] as $secret) {
            self::assertStringNotContainsString($secret, $persisted);
        }
    }

    public function testPasswordResetRateLimitsUseHmacAndRemainGeneric(): void
    {
        $userId = $this->seedUser('limited@example.test', 'active');
        $this->service = $this->service(
            new CredentialActionRateLimitPolicy(60, 1, 10, 120)
        );
        $first = $this->service->requestPasswordReset(
            'limited@example.test',
            '198.51.100.4'
        );
        $second = $this->service->requestPasswordReset(
            'limited@example.test',
            '198.51.100.4'
        );

        self::assertSame(
            $first->publicMessageCode(),
            $second->publicMessageCode()
        );
        self::assertFalse($first->deliveryFailed());
        self::assertFalse($second->deliveryFailed());
        self::assertSame(0, $this->countOutbox('password_reset'));
        self::assertCount(1, $this->mailSender->deliveries);
        $limits = $this->pdo->query(
            'SELECT action, subject_hash, attempts, blocked_until FROM '
            . 'ls_webadmin_rate_limits ORDER BY action'
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $limits);
        foreach ($limits as $limit) {
            self::assertMatchesRegularExpression(
                '/\A[0-9a-f]{64}\z/',
                $limit['subject_hash']
            );
            if ($limit['action'] === 'password_reset.identifier') {
                self::assertSame(1, $limit['attempts']);
                self::assertNotNull($limit['blocked_until']);
            } else {
                self::assertSame('password_reset.ip', $limit['action']);
                self::assertSame(2, $limit['attempts']);
                self::assertNull($limit['blocked_until']);
            }
        }
        self::assertStringNotContainsString(
            'limited@example.test',
            json_encode($limits, JSON_THROW_ON_ERROR)
        );

        $this->clock->advanceSeconds(121);
        $this->service->requestPasswordReset(
            'limited@example.test',
            '198.51.100.4'
        );
        self::assertSame(0, $this->countOutbox('password_reset'));
        self::assertCount(2, $this->mailSender->deliveries);
        self::assertSame(
            $userId,
            $this->mailSender->deliveries[1]->userId()
        );
    }

    public function testPasswordResetDeliveryFailureRevokesTokenAndReportsOnlyGenericState(): void
    {
        $this->seedUser('smtp-failure@example.test', 'active');
        $this->mailSender->fail = true;

        $result = $this->service->requestPasswordReset(
            'smtp-failure@example.test',
            '203.0.113.7'
        );

        self::assertTrue($result->accepted());
        self::assertTrue($result->deliveryFailed());
        self::assertSame(
            PasswordResetRequestResult::PUBLIC_MESSAGE,
            $result->publicMessageCode()
        );
        self::assertSame(0, $this->countOutbox('password_reset'));
        self::assertCount(1, $this->mailSender->deliveries);
        $action = $this->actionById(
            $this->mailSender->deliveries[0]->actionTokenId()
        );
        self::assertNull($action['delivered_at']);
        self::assertNotNull($action['revoked_at']);
        self::assertNull($this->service->bindActionToken(
            $this->mailSender->deliveries[0]->rawToken(),
            CredentialActionService::PASSWORD_RESET
        ));
    }

    public function testMissingMailSenderFailsOnlyEligibleRecoveryAndNeverQueues(): void
    {
        $this->seedUser('mail-unavailable@example.test', 'active');
        $service = new CredentialActionService(
            new CredentialActionRepository(
                $this->pdo,
                WebAdminTableNames::fromPdo($this->pdo, 'ls_webadmin_')
            ),
            new WebAdminConfig(
                '/admin',
                'ls_webadmin_',
                'LS_WEBADMIN_SID',
                300,
                600,
                'test'
            ),
            $this->securityKey,
            $this->clock,
            $this->uuids,
            $this->hasher,
            $this->tokens
        );

        $missing = $service->requestPasswordReset(
            'absent@example.test',
            '203.0.113.8'
        );
        $eligible = $service->requestPasswordReset(
            'mail-unavailable@example.test',
            '203.0.113.9'
        );

        self::assertFalse($missing->deliveryFailed());
        self::assertTrue($eligible->deliveryFailed());
        self::assertSame(0, $this->countOutbox('password_reset'));
        self::assertSame(1, $this->tableCount('action_tokens'));
        $action = $this->pdo->query(
            'SELECT delivered_at, revoked_at FROM ls_webadmin_action_tokens'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($action);
        self::assertNull($action['delivered_at']);
        self::assertNotNull($action['revoked_at']);
    }

    public function testResetTokenCannotBeConsumedInsideTransportBeforeDeliveryAck(): void
    {
        $this->seedUser('ack-boundary@example.test', 'active');
        $boundDuringSend = true;
        $this->mailSender->onSend = function (
            PasswordResetDelivery $delivery
        ) use (
            &$boundDuringSend
        ): void {
            $boundDuringSend = $this->service->bindActionToken(
                $delivery->rawToken(),
                CredentialActionService::PASSWORD_RESET
            );
        };

        $result = $this->service->requestPasswordReset(
            'ack-boundary@example.test',
            '203.0.113.10'
        );

        self::assertFalse($result->deliveryFailed());
        self::assertNull($boundDuringSend);
        self::assertNotNull($this->service->bindActionToken(
            $this->mailSender->deliveries[0]->rawToken(),
            CredentialActionService::PASSWORD_RESET
        ));
    }

    public function testDirectRecoveryLeavesLegacyOutboxRowUntouched(): void
    {
        $userId = $this->seedUser('legacy-outbox@example.test', 'active');
        $timestamp = CredentialActionRepository::format($this->clock->now());
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_outbox '
            . '(kind, user_id, locale, status, attempts, available_at, '
            . 'created_at) VALUES '
            . "('password_reset', :user_id, 'es', 'pending', 0, "
            . ':available_at, :created_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'available_at' => $timestamp,
            'created_at' => $timestamp,
        ]);
        $outboxId = (int) $this->pdo->lastInsertId();

        $result = $this->service->requestPasswordReset(
            'legacy-outbox@example.test',
            '203.0.113.11'
        );

        self::assertFalse($result->deliveryFailed());
        self::assertSame(1, $this->countOutbox('password_reset'));
        $outbox = $this->pdo->query(
            'SELECT id, status, attempts, action_token_id, sent_at '
            . 'FROM ls_webadmin_outbox'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame([
            'id' => $outboxId,
            'status' => 'pending',
            'attempts' => 0,
            'action_token_id' => null,
            'sent_at' => null,
        ], $outbox);
    }

    public function testBindingStoresOnlyHashesUsesDistinctCsrfAndDoesNotConsumeToken(): void
    {
        $userId = $this->seedUser('invite@example.test', 'invited');
        $rawActionToken = $this->tokens->generate();
        $actionId = $this->seedAction(
            $userId,
            CredentialActionService::INVITATION,
            $rawActionToken
        );

        $secrets = $this->service->bindActionToken(
            $rawActionToken,
            CredentialActionService::INVITATION
        );

        self::assertInstanceOf(CredentialActionSessionSecrets::class, $secrets);
        self::assertSame(
            CredentialActionService::INVITATION,
            $secrets->purpose()
        );
        $session = $this->sessionByToken($secrets->sessionToken());
        self::assertSame('preauth', $session['session_type']);
        self::assertNull($session['user_id']);
        self::assertNull($session['auth_version']);
        self::assertSame($actionId, $session['pending_action_token_id']);
        self::assertSame(
            hash('sha256', $secrets->sessionToken()),
            $session['token_hash']
        );
        self::assertSame(
            hash('sha256', $secrets->csrfToken()),
            $session['csrf_token_hash']
        );
        self::assertNotSame(
            $this->securityKey->deriveToken(
                'csrf.session',
                $secrets->sessionToken()
            ),
            $secrets->csrfToken()
        );
        self::assertNull($this->actionById($actionId)['used_at']);
        self::assertNull($this->actionById($actionId)['revoked_at']);

        $bound = $this->service->resolveBoundAction(
            $secrets->sessionToken(),
            CredentialActionService::INVITATION
        );
        self::assertNotNull($bound);
        self::assertSame(CredentialActionService::INVITATION, $bound->purpose());
        $resolvedCsrf = $this->service->boundActionCsrfToken(
            $secrets->sessionToken(),
            CredentialActionService::INVITATION
        );
        self::assertInstanceOf(CredentialActionCsrfToken::class, $resolvedCsrf);
        self::assertSame($secrets->csrfToken(), $resolvedCsrf->csrfToken());
        self::assertSame(
            CredentialActionService::INVITATION,
            $resolvedCsrf->purpose()
        );
        self::assertNull($this->actionById($actionId)['used_at']);

        $persistence = json_encode([
            $session,
            $this->actionById($actionId),
        ], JSON_THROW_ON_ERROR);
        foreach ([
            $rawActionToken,
            $secrets->sessionToken(),
            $secrets->csrfToken(),
        ] as $secret) {
            self::assertStringNotContainsString($secret, $persistence);
        }
    }

    public function testInvitationCompletionActivatesAndRevokesAllRelatedState(): void
    {
        $userId = $this->seedUser('invite@example.test', 'invited');
        $otherUserId = $this->seedUser('other@example.test', 'active');
        $rawActionToken = $this->tokens->generate();
        $actionId = $this->seedAction(
            $userId,
            CredentialActionService::INVITATION,
            $rawActionToken
        );
        $otherRawToken = $this->tokens->generate();
        $otherActionId = $this->seedAction(
            $userId,
            CredentialActionService::INVITATION,
            $otherRawToken
        );
        $authenticatedSession = $this->seedSession($userId, null);
        $unrelatedSession = $this->seedSession($otherUserId, null);
        $this->seedLoginAndRecoveryRateLimits('invite@example.test');
        $secrets = $this->service->bindActionToken(
            $rawActionToken,
            CredentialActionService::INVITATION
        );
        self::assertNotNull($secrets);

        $completion = $this->service->completeInvitation(
            $secrets->sessionToken(),
            $secrets->csrfToken(),
            self::NEW_PASSWORD,
            '203.0.113.8',
            'Invitation Browser Secret'
        );

        self::assertTrue($completion->isCompleted());
        self::assertNull($completion->publicErrorCode());
        $user = $this->userById($userId);
        self::assertSame('active', $user['status']);
        self::assertSame(2, $user['auth_version']);
        self::assertNotNull($user['activated_at']);
        self::assertNull($user['suspended_at']);
        $credential = $this->credentialByUserId($userId);
        self::assertNotNull($credential['password_set_at']);
        self::assertTrue($this->hasher->verify(
            self::NEW_PASSWORD,
            $credential['password_hash']
        ));
        self::assertNotNull($this->actionById($actionId)['used_at']);
        self::assertNull($this->actionById($actionId)['revoked_at']);
        self::assertNotNull($this->actionById($otherActionId)['revoked_at']);
        self::assertNotNull($this->sessionByToken(
            $authenticatedSession
        )['revoked_at']);
        self::assertNotNull($this->sessionByToken(
            $secrets->sessionToken()
        )['revoked_at']);
        self::assertNull($this->sessionByToken($unrelatedSession)['revoked_at']);
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ls_webadmin_sessions WHERE user_id = {$userId} "
            . "AND session_type = 'authenticated' AND revoked_at IS NULL"
        )->fetchColumn(), 'Completion never auto-authenticates.');

        $rateActions = $this->pdo->query(
            'SELECT action FROM ls_webadmin_rate_limits ORDER BY action'
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotContains('login.identifier', $rateActions);
        self::assertContains('login.ip', $rateActions);
        self::assertContains('password_reset.identifier', $rateActions);
        self::assertContains('password_reset.ip', $rateActions);

        $audit = $this->pdo->query(
            "SELECT * FROM ls_webadmin_audit_log "
            . "WHERE event_code = 'webadmin.invitation.completed'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($audit);
        self::assertSame('success', $audit['outcome']);
        self::assertNull($audit['actor_user_id']);
        self::assertSame(
            $this->userById($userId)['public_id'],
            $audit['target_public_id']
        );
        self::assertNull($audit['metadata_json']);
        $persisted = json_encode([
            $credential,
            $audit,
            $this->pdo->query(
                'SELECT * FROM ls_webadmin_action_tokens'
            )->fetchAll(PDO::FETCH_ASSOC),
            $this->pdo->query(
                'SELECT * FROM ls_webadmin_sessions'
            )->fetchAll(PDO::FETCH_ASSOC),
        ], JSON_THROW_ON_ERROR);
        foreach ([
            self::NEW_PASSWORD,
            $rawActionToken,
            $otherRawToken,
            $secrets->sessionToken(),
            $secrets->csrfToken(),
            '203.0.113.8',
            'Invitation Browser Secret',
        ] as $secret) {
            self::assertStringNotContainsString($secret, $persisted);
        }
    }

    public function testForgedWellFormedSessionIsRejectedBeforePasswordHashing(): void
    {
        $completion = $this->service->completePasswordReset(
            $this->tokens->generate(),
            $this->tokens->generate(),
            'short'
        );

        self::assertFalse($completion->isCompleted());
        self::assertSame(
            CredentialActionCompletion::GENERIC_FAILURE,
            $completion->publicErrorCode()
        );
        self::assertSame(0, $this->tableCount('sessions'));
        self::assertSame(0, $this->tableCount('action_tokens'));
    }

    public function testPasswordResetChangesVersionWithoutChangingActivation(): void
    {
        $userId = $this->seedUser('reset@example.test', 'active', 7);
        $activatedAt = $this->userById($userId)['activated_at'];
        $rawToken = $this->tokens->generate();
        $actionId = $this->seedAction(
            $userId,
            CredentialActionService::PASSWORD_RESET,
            $rawToken,
            ['auth_version' => 7]
        );
        $secrets = $this->service->bindActionToken(
            $rawToken,
            CredentialActionService::PASSWORD_RESET
        );
        self::assertNotNull($secrets);

        $completion = $this->service->completePasswordReset(
            $secrets->sessionToken(),
            $secrets->csrfToken(),
            self::NEW_PASSWORD
        );

        self::assertTrue($completion->isCompleted());
        $user = $this->userById($userId);
        self::assertSame('active', $user['status']);
        self::assertSame(8, $user['auth_version']);
        self::assertSame($activatedAt, $user['activated_at']);
        self::assertTrue($this->hasher->verify(
            self::NEW_PASSWORD,
            $this->credentialByUserId($userId)['password_hash']
        ));
        self::assertNotNull($this->actionById($actionId)['used_at']);
        self::assertSame(
            'webadmin.password_reset.completed',
            $this->pdo->query(
                'SELECT event_code FROM ls_webadmin_audit_log'
            )->fetchColumn()
        );
    }

    public function testUndeliveredUsedRevokedExpiredWrongPurposeVersionAndSuspendedTokensFail(): void
    {
        $cases = [
            ['undelivered', ['delivered' => false]],
            ['used', ['used' => true]],
            ['revoked', ['revoked' => true]],
            ['expired', ['expires_at' => '2026-08-01 05:59:59.000000']],
            ['version', ['auth_version' => 2]],
        ];
        foreach ($cases as $index => [$label, $options]) {
            $userId = $this->seedUser(
                $label . '@example.test',
                'active'
            );
            $raw = $this->tokens->generate();
            $this->seedAction(
                $userId,
                CredentialActionService::PASSWORD_RESET,
                $raw,
                $options
            );
            self::assertNull(
                $this->service->bindActionToken(
                    $raw,
                    CredentialActionService::PASSWORD_RESET
                ),
                $label . ' must fail closed (' . $index . ').'
            );
        }

        $suspendedId = $this->seedUser('suspended@example.test', 'suspended');
        $suspendedRaw = $this->tokens->generate();
        $this->seedAction(
            $suspendedId,
            CredentialActionService::PASSWORD_RESET,
            $suspendedRaw
        );
        self::assertNull($this->service->bindActionToken(
            $suspendedRaw,
            CredentialActionService::PASSWORD_RESET
        ));

        $activeId = $this->seedUser('purpose@example.test', 'active');
        $purposeRaw = $this->tokens->generate();
        $this->seedAction(
            $activeId,
            CredentialActionService::PASSWORD_RESET,
            $purposeRaw
        );
        self::assertNull($this->service->bindActionToken(
            $purposeRaw,
            CredentialActionService::INVITATION
        ));
        self::assertSame(0, $this->tableCount('sessions'));
    }

    public function testPurposeAndSessionCrossingAndInvalidCsrfDoNotMutate(): void
    {
        $inviteUser = $this->seedUser('invite@example.test', 'invited');
        $inviteRaw = $this->tokens->generate();
        $inviteAction = $this->seedAction(
            $inviteUser,
            CredentialActionService::INVITATION,
            $inviteRaw
        );
        $inviteSession = $this->service->bindActionToken(
            $inviteRaw,
            CredentialActionService::INVITATION
        );
        self::assertNotNull($inviteSession);
        $crossed = $this->service->completePasswordReset(
            $inviteSession->sessionToken(),
            $inviteSession->csrfToken(),
            self::NEW_PASSWORD
        );
        self::assertFalse($crossed->isCompleted());
        self::assertSame(
            CredentialActionCompletion::GENERIC_FAILURE,
            $crossed->publicErrorCode()
        );
        self::assertSame('invited', $this->userById($inviteUser)['status']);
        self::assertNull($this->actionById($inviteAction)['used_at']);

        $resetUser = $this->seedUser('reset@example.test', 'active');
        $resetRaw = $this->tokens->generate();
        $resetAction = $this->seedAction(
            $resetUser,
            CredentialActionService::PASSWORD_RESET,
            $resetRaw
        );
        $resetSession = $this->service->bindActionToken(
            $resetRaw,
            CredentialActionService::PASSWORD_RESET
        );
        self::assertNotNull($resetSession);
        $badCsrf = $this->service->completePasswordReset(
            $resetSession->sessionToken(),
            $this->tokens->generate(),
            self::NEW_PASSWORD
        );
        self::assertFalse($badCsrf->isCompleted());
        self::assertSame(1, $this->userById($resetUser)['auth_version']);
        self::assertNull($this->actionById($resetAction)['used_at']);

        self::assertTrue($this->service->completePasswordReset(
            $resetSession->sessionToken(),
            $resetSession->csrfToken(),
            self::NEW_PASSWORD
        )->isCompleted());
    }

    public function testActionCanOnlyBeConsumedOnceAcrossTwoBoundSessions(): void
    {
        $userId = $this->seedUser('double@example.test', 'active');
        $raw = $this->tokens->generate();
        $actionId = $this->seedAction(
            $userId,
            CredentialActionService::PASSWORD_RESET,
            $raw
        );
        $first = $this->service->bindActionToken(
            $raw,
            CredentialActionService::PASSWORD_RESET
        );
        $second = $this->service->bindActionToken(
            $raw,
            CredentialActionService::PASSWORD_RESET
        );
        self::assertNotNull($first);
        self::assertNotNull($second);

        self::assertSame(2, $this->tableCount('sessions'));
        self::assertTrue($this->service->completePasswordReset(
            $first->sessionToken(),
            $first->csrfToken(),
            self::NEW_PASSWORD
        )->isCompleted());
        self::assertFalse($this->service->completePasswordReset(
            $second->sessionToken(),
            $second->csrfToken(),
            'Another sufficiently long replacement password 3!'
        )->isCompleted());
        self::assertSame(2, $this->userById($userId)['auth_version']);
        self::assertNotNull($this->actionById($actionId)['used_at']);
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ls_webadmin_audit_log "
            . "WHERE event_code = 'webadmin.password_reset.completed'"
        )->fetchColumn());
    }

    public function testRepeatedLinkBindingKeepsOnlyThreeNewestLiveSessions(): void
    {
        $userId = $this->seedUser('scanner@example.test', 'active');
        $raw = $this->tokens->generate();
        $this->seedAction(
            $userId,
            CredentialActionService::PASSWORD_RESET,
            $raw
        );
        $sessions = [];
        for ($index = 0; $index < 8; $index++) {
            $sessions[] = $this->service->bindActionToken(
                $raw,
                CredentialActionService::PASSWORD_RESET
            );
        }

        foreach ($sessions as $session) {
            self::assertNotNull($session);
        }
        self::assertSame(3, $this->tableCount('sessions'));
        self::assertNull($this->service->resolveBoundAction(
            $sessions[0]->sessionToken(),
            CredentialActionService::PASSWORD_RESET
        ));
        self::assertNotNull($this->service->resolveBoundAction(
            $sessions[7]->sessionToken(),
            CredentialActionService::PASSWORD_RESET
        ));
    }

    public function testInvalidPasswordDoesNotConsumeOrMutateAction(): void
    {
        $userId = $this->seedUser('password@example.test', 'active');
        $raw = $this->tokens->generate();
        $actionId = $this->seedAction(
            $userId,
            CredentialActionService::PASSWORD_RESET,
            $raw
        );
        $session = $this->service->bindActionToken(
            $raw,
            CredentialActionService::PASSWORD_RESET
        );
        self::assertNotNull($session);
        $before = $this->credentialByUserId($userId)['password_hash'];

        try {
            $this->service->completePasswordReset(
                $session->sessionToken(),
                $session->csrfToken(),
                'Aa1!bbb'
            );
            self::fail('The password policy must reject the completion.');
        } catch (InvalidPassword) {
            self::assertSame($before, $this->credentialByUserId(
                $userId
            )['password_hash']);
            self::assertSame(1, $this->userById($userId)['auth_version']);
            self::assertNull($this->actionById($actionId)['used_at']);
            self::assertNull($this->sessionByToken(
                $session->sessionToken()
            )['revoked_at']);
        }
    }

    public function testInvalidInvitationPasswordDoesNotActivateAccount(): void
    {
        $userId = $this->seedUser('invalid-invite@example.test', 'invited');
        $raw = $this->tokens->generate();
        $actionId = $this->seedAction(
            $userId,
            CredentialActionService::INVITATION,
            $raw
        );
        $session = $this->service->bindActionToken(
            $raw,
            CredentialActionService::INVITATION
        );
        self::assertNotNull($session);

        try {
            $this->service->completeInvitation(
                $session->sessionToken(),
                $session->csrfToken(),
                'aa1!bbbb',
                '127.0.0.1'
            );
            self::fail('The invitation must enforce the creation policy.');
        } catch (InvalidPassword) {
            self::assertSame('invited', $this->userById($userId)['status']);
            self::assertNull(
                $this->credentialByUserId($userId)['password_hash']
            );
            self::assertNull($this->actionById($actionId)['used_at']);
            self::assertNull($this->sessionByToken(
                $session->sessionToken()
            )['revoked_at']);
        }
    }

    public function testRevocationAfterBindingMakesResolveAndCompletionUnavailable(): void
    {
        $userId = $this->seedUser('revoked@example.test', 'active');
        $raw = $this->tokens->generate();
        $actionId = $this->seedAction(
            $userId,
            CredentialActionService::PASSWORD_RESET,
            $raw
        );
        $session = $this->service->bindActionToken(
            $raw,
            CredentialActionService::PASSWORD_RESET
        );
        self::assertNotNull($session);
        $this->pdo->exec(
            "UPDATE ls_webadmin_action_tokens SET revoked_at = "
            . "'2026-08-01 06:00:01.000000' WHERE id = {$actionId}"
        );

        self::assertNull($this->service->resolveBoundAction(
            $session->sessionToken(),
            CredentialActionService::PASSWORD_RESET
        ));
        self::assertNotNull($this->sessionByToken(
            $session->sessionToken()
        )['revoked_at']);
        self::assertFalse($this->service->completePasswordReset(
            $session->sessionToken(),
            $session->csrfToken(),
            self::NEW_PASSWORD
        )->isCompleted());
    }

    public function testActionSessionRevocationRequiresItsCsrfAndExactPurpose(): void
    {
        $userId = $this->seedUser('cancel@example.test', 'active');
        $raw = $this->tokens->generate();
        $this->seedAction(
            $userId,
            CredentialActionService::PASSWORD_RESET,
            $raw
        );
        $session = $this->service->bindActionToken(
            $raw,
            CredentialActionService::PASSWORD_RESET
        );
        self::assertNotNull($session);

        self::assertFalse($this->service->revokeActionSession(
            $session->sessionToken(),
            $this->tokens->generate(),
            CredentialActionService::PASSWORD_RESET
        ));
        self::assertFalse($this->service->revokeActionSession(
            $session->sessionToken(),
            $session->csrfToken(),
            CredentialActionService::INVITATION
        ));
        self::assertNull($this->sessionByToken(
            $session->sessionToken()
        )['revoked_at']);

        self::assertTrue($this->service->revokeActionSession(
            $session->sessionToken(),
            $session->csrfToken(),
            CredentialActionService::PASSWORD_RESET
        ));
        self::assertNotNull($this->sessionByToken(
            $session->sessionToken()
        )['revoked_at']);
        self::assertFalse($this->service->revokeActionSession(
            $session->sessionToken(),
            $session->csrfToken(),
            CredentialActionService::PASSWORD_RESET
        ));
    }

    public function testCredentialActionSecretsRedactDebugExportAndSerialization(): void
    {
        $userId = $this->seedUser('debug@example.test', 'active');
        $raw = $this->tokens->generate();
        $this->seedAction(
            $userId,
            CredentialActionService::PASSWORD_RESET,
            $raw
        );
        $session = $this->service->bindActionToken(
            $raw,
            CredentialActionService::PASSWORD_RESET
        );
        self::assertNotNull($session);

        foreach ([print_r($session, true), var_export($session, true)] as $dump) {
            self::assertStringNotContainsString(
                $session->sessionToken(),
                $dump
            );
            self::assertStringNotContainsString($session->csrfToken(), $dump);
        }
        try {
            serialize($session);
            self::fail('Credential-action secrets must not serialize.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString(
                $session->sessionToken(),
                $exception->getMessage()
            );
        }
    }

    public function testRepositoryRejectsUnsafePdoAndRetriesMysqlDeadlockOnce(): void
    {
        $unsafe = new PDO('sqlite::memory:');
        $unsafe->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $tables = WebAdminTableNames::fromPdo($unsafe, 'ls_webadmin_');
        try {
            new CredentialActionRepository($unsafe, $tables);
            self::fail('Silent PDO must be rejected.');
        } catch (CredentialActionStorageException) {
        }

        $pdo = new CredentialActionDeadlockPdo();
        $repository = new CredentialActionRepository(
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
    }

    public function testSQLiteRollbackReleasesImmediateWriteReservation(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ls-action-lock-');
        self::assertIsString($path);
        try {
            $first = $this->sqlite('sqlite:' . $path);
            $second = $this->sqlite('sqlite:' . $path);
            $first->exec('CREATE TABLE lock_probe (id INTEGER PRIMARY KEY)');
            $repository = new CredentialActionRepository(
                $first,
                WebAdminTableNames::fromPdo($first, 'ls_webadmin_')
            );

            try {
                $repository->transaction(function () use ($first): void {
                    $first->exec('INSERT INTO lock_probe (id) VALUES (1)');
                    throw new RuntimeException('forced rollback');
                });
                self::fail('The injected failure must roll back.');
            } catch (CredentialActionStorageException) {
                self::assertSame(0, (int) $first->query(
                    'SELECT COUNT(*) FROM lock_probe'
                )->fetchColumn());
            }
            self::assertNotFalse($second->exec('BEGIN IMMEDIATE'));
            self::assertNotFalse($second->exec(
                'INSERT INTO lock_probe (id) VALUES (2)'
            ));
            self::assertNotFalse($second->exec('COMMIT'));
        } finally {
            // Windows cannot unlink SQLite while the repository still owns PDO.
            unset($repository, $first, $second);
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

    public function testServiceRequiresExceptionArgumentRedaction(): void
    {
        ini_set('zend.exception_ignore_args', '0');
        try {
            $this->service();
            self::fail('Unsafe exception traces must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'webadmin.security.exception_trace_arguments_enabled',
                $exception->getMessage()
            );
        } finally {
            ini_set('zend.exception_ignore_args', '1');
        }
    }

    private function service(
        ?CredentialActionRateLimitPolicy $policy = null
    ): CredentialActionService {
        return new CredentialActionService(
            new CredentialActionRepository(
                $this->pdo,
                WebAdminTableNames::fromPdo($this->pdo, 'ls_webadmin_')
            ),
            new WebAdminConfig(
                '/admin',
                'ls_webadmin_',
                'LS_WEBADMIN_SID',
                300,
                600,
                'test'
            ),
            $this->securityKey,
            $this->clock,
            $this->uuids,
            $this->hasher,
            $this->tokens,
            $policy ?? new CredentialActionRateLimitPolicy(),
            $this->mailSender
        );
    }

    private function seedUser(
        string $email,
        string $status,
        int $authVersion = 1
    ): int {
        $timestamp = CredentialActionRepository::format($this->clock->now());
        $publicId = sprintf(
            '31000000-0000-4000-8000-%012x',
            $this->tableCount('users') + 1
        );
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_users '
            . '(public_id, email_canonical, status, auth_version, invited_at, '
            . 'activated_at, suspended_at, created_at, updated_at) VALUES '
            . '(:public_id, :email, :status, :auth_version, :invited_at, '
            . ':activated_at, :suspended_at, :created_at, :updated_at)'
        );
        $statement->execute([
            'public_id' => $publicId,
            'email' => strtolower($email),
            'status' => $status,
            'auth_version' => $authVersion,
            'invited_at' => $timestamp,
            'activated_at' => $status === 'invited' ? null : $timestamp,
            'suspended_at' => $status === 'suspended' ? $timestamp : null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $userId = (int) $this->pdo->lastInsertId();
        $credential = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_credentials '
            . '(user_id, password_hash, password_set_at, created_at, '
            . 'updated_at) VALUES (:user_id, :password_hash, '
            . ':password_set_at, :created_at, :updated_at)'
        );
        $hash = $status === 'invited'
            ? null
            : $this->hasher->hash(self::OLD_PASSWORD);
        $credential->execute([
            'user_id' => $userId,
            'password_hash' => $hash,
            'password_set_at' => $hash === null ? null : $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $userId;
    }

    /** @param array<string, int|string|bool> $options */
    private function seedAction(
        int $userId,
        string $purpose,
        string $rawToken,
        array $options = []
    ): int {
        $createdAt = '2026-08-01 05:59:00.000000';
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_action_tokens '
            . '(user_id, purpose, token_hash, auth_version, created_at, '
            . 'expires_at, delivered_at, used_at, revoked_at) VALUES '
            . '(:user_id, :purpose, :token_hash, :auth_version, :created_at, '
            . ':expires_at, :delivered_at, :used_at, :revoked_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $rawToken),
            'auth_version' => $options['auth_version'] ?? 1,
            'created_at' => $createdAt,
            'expires_at' => $options['expires_at']
                ?? '2026-08-01 07:00:00.000000',
            'delivered_at' => ($options['delivered'] ?? true)
                ? '2026-08-01 05:59:30.000000'
                : null,
            'used_at' => ($options['used'] ?? false)
                ? '2026-08-01 05:59:45.000000'
                : null,
            'revoked_at' => ($options['revoked'] ?? false)
                ? '2026-08-01 05:59:45.000000'
                : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedSession(int $userId, ?int $pendingTokenId): string
    {
        $rawToken = $this->tokens->generate();
        $csrf = $this->tokens->generate();
        $publicId = sprintf(
            '32000000-0000-4000-8000-%012x',
            $this->tableCount('sessions') + 1
        );
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_sessions '
            . '(public_id, user_id, session_type, token_hash, '
            . 'csrf_token_hash, auth_version, pending_action_token_id, '
            . 'created_at, last_seen_at, idle_expires_at, '
            . 'absolute_expires_at) VALUES (:public_id, :user_id, '
            . "'authenticated', :token_hash, :csrf_hash, 1, "
            . ':pending_action_token_id, :created_at, :last_seen_at, '
            . ':idle_expires_at, :absolute_expires_at)'
        );
        $statement->execute([
            'public_id' => $publicId,
            'user_id' => $userId,
            'token_hash' => hash('sha256', $rawToken),
            'csrf_hash' => hash('sha256', $csrf),
            'pending_action_token_id' => $pendingTokenId,
            'created_at' => '2026-08-01 05:59:00.000000',
            'last_seen_at' => '2026-08-01 05:59:00.000000',
            'idle_expires_at' => '2026-08-01 06:30:00.000000',
            'absolute_expires_at' => '2026-08-01 07:00:00.000000',
        ]);

        return $rawToken;
    }

    private function seedLoginAndRecoveryRateLimits(string $email): void
    {
        $timestamp = '2026-08-01 05:59:00.000000';
        $rows = [
            ['login.identifier', $this->securityKey->subjectHash(
                'login.identifier',
                'email:' . $email
            )],
            ['login.ip', str_repeat('1', 64)],
            ['password_reset.identifier', str_repeat('2', 64)],
            ['password_reset.ip', str_repeat('3', 64)],
        ];
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_rate_limits '
            . '(action, subject_hash, window_started_at, attempts, updated_at) '
            . 'VALUES (:action, :subject_hash, :window, 1, :updated_at)'
        );
        foreach ($rows as [$action, $hash]) {
            $statement->execute([
                'action' => $action,
                'subject_hash' => $hash,
                'window' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function userById(int $userId): array
    {
        $row = $this->pdo->query(
            'SELECT * FROM ls_webadmin_users WHERE id = ' . $userId
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function credentialByUserId(int $userId): array
    {
        $row = $this->pdo->query(
            'SELECT * FROM ls_webadmin_credentials WHERE user_id = ' . $userId
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function actionById(int $actionId): array
    {
        $row = $this->pdo->query(
            'SELECT * FROM ls_webadmin_action_tokens WHERE id = ' . $actionId
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

    private function countOutbox(string $kind): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ls_webadmin_outbox WHERE kind = :kind'
        );
        $statement->execute(['kind' => $kind]);

        return (int) $statement->fetchColumn();
    }

    private function tableCount(string $suffix): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM "ls_webadmin_' . $suffix . '"'
        )->fetchColumn();
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
}

final class CredentialActionTestPasswordResetMailSender implements
    PasswordResetMailSenderInterface
{
    /** @var list<PasswordResetDelivery> */
    public array $deliveries = [];
    public bool $fail = false;
    public bool $transactionObserved = false;
    public ?Closure $onSend = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function send(PasswordResetDelivery $delivery): void
    {
        $this->transactionObserved = $this->transactionObserved
            || $this->pdo->inTransaction();
        if ($this->onSend !== null) {
            ($this->onSend)($delivery);
        }
        $this->deliveries[] = $delivery;
        if ($this->fail) {
            throw new RuntimeException('Sensitive SMTP diagnostic.');
        }
    }
}

final class CredentialActionTestClock implements ClockInterface
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

final class CredentialActionTestUuidGenerator implements UuidGeneratorInterface
{
    private int $counter = 1;

    public function generateV4(): string
    {
        return sprintf(
            '33000000-0000-4000-8000-%012x',
            $this->counter++
        );
    }
}

final class CredentialActionDeadlockPdo extends PDO
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
