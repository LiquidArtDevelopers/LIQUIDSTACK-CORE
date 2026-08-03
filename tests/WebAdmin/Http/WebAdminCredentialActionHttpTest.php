<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationRepository;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\CredentialAction\CredentialActionRepository;
use App\Core\WebAdmin\CredentialAction\CredentialActionService;
use App\Core\WebAdmin\CredentialAction\PasswordResetDelivery;
use App\Core\WebAdmin\Http\WebAdminHttpController;
use App\Core\WebAdmin\Http\WebAdminHttpRuntime;
use App\Core\WebAdmin\Mail\PasswordResetMailSenderInterface;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use PHPUnit\Framework\TestCase;

final class WebAdminCredentialActionHttpTest extends TestCase
{
    private const OLD_PASSWORD = 'Correct horse battery staple 1!';
    private const NEW_PASSWORD = 'Aa1!bbbb';

    private PDO $pdo;
    private WebAdminCredentialHttpClock $clock;
    private PasswordHasher $hasher;
    private SecureTokenGenerator $tokens;
    private WebAdminConfig $config;
    private WebAdminAuthenticationService $authentication;
    private CredentialActionService $credentialActions;
    private WebAdminCredentialHttpPasswordResetMailSender $mailSender;
    private WebAdminHttpController $controller;
    private string $previousExceptionTraceSetting;

    protected function setUp(): void
    {
        $this->previousExceptionTraceSetting = (string) ini_get(
            'zend.exception_ignore_args'
        );
        ini_set('zend.exception_ignore_args', '1');

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
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
        foreach ($migration->statementsFor('sqlite', $scope) as $statement) {
            self::assertNotFalse($this->pdo->exec($statement));
        }

        $this->clock = new WebAdminCredentialHttpClock(
            '2026-08-01 06:00:00.000000'
        );
        $this->hasher = PasswordHasher::productive();
        $this->tokens = new SecureTokenGenerator();
        $this->config = new WebAdminConfig(
            '/admin',
            'ls_webadmin_',
            'LS_WEBADMIN_SID',
            300,
            600,
            'test'
        );
        $tables = WebAdminTableNames::fromPdo(
            $this->pdo,
            'ls_webadmin_'
        );
        $securityKey = SecurityKey::fromRawBytes(str_repeat('W', 32));
        $uuids = new WebAdminCredentialHttpUuidGenerator();
        $this->authentication = new WebAdminAuthenticationService(
            new WebAdminAuthenticationRepository($this->pdo, $tables),
            $this->config,
            $securityKey,
            $this->clock,
            $uuids,
            $this->hasher,
            $this->tokens
        );
        $this->mailSender =
            new WebAdminCredentialHttpPasswordResetMailSender();
        $this->credentialActions = new CredentialActionService(
            new CredentialActionRepository($this->pdo, $tables),
            $this->config,
            $securityKey,
            $this->clock,
            $uuids,
            $this->hasher,
            $this->tokens,
            passwordResetMailSender: $this->mailSender
        );
        $this->controller = new WebAdminHttpController(
            new WebAdminHttpRuntime(
                $this->config,
                $this->authentication,
                new WebAdminAuthorizationService(
                    $this->pdo,
                    $tables,
                    $this->clock,
                    $this->tokens,
                    $this->hasher
                ),
                $this->credentialActions
            )
        );
    }

    protected function tearDown(): void
    {
        ini_set(
            'zend.exception_ignore_args',
            $this->previousExceptionTraceSetting
        );
    }

    public function testForgotPasswordUsesPreauthCsrfAndKeepsNormalOutcomesGeneric(): void
    {
        $this->seedUser('known@example.test', 'active');

        $knownForm = $this->controller->forgotPasswordForm(
            $this->get('/admin/password/forgot')
        );
        self::assertSame(200, $knownForm->status());
        $this->assertHtmlSecurityHeaders($knownForm);
        [$knownSession, $knownCookie] = $this->cookieFrom(
            $knownForm,
            'LS_WEBADMIN_PREAUTH'
        );
        $this->assertSecureHostOnlyCookie(
            $knownCookie,
            'LS_WEBADMIN_PREAUTH',
            'Lax'
        );
        $knownCsrf = $this->hiddenCsrf($knownForm->body());
        $this->mailSender->onSend = function () use ($knownSession): void {
            self::assertNotNull(
                $this->sessionByToken($knownSession)['revoked_at']
            );
        };

        $wrongCookie = $this->controller->requestPasswordReset($this->post(
            '/admin/password/forgot',
            ['csrf' => $knownCsrf, 'email' => 'known@example.test'],
            ['LS_WEBADMIN_SID' => $knownSession]
        ));
        self::assertSame(400, $wrongCookie->status());
        $this->assertExpiredSecureHostOnlyCookie(
            $this->onlyCookie($wrongCookie),
            'LS_WEBADMIN_PREAUTH',
            'Lax'
        );
        self::assertSame(0, $this->tableCount('outbox'));
        self::assertNull($this->sessionByToken($knownSession)['revoked_at']);

        $known = $this->controller->requestPasswordReset($this->post(
            '/admin/password/forgot',
            ['csrf' => $knownCsrf, 'email' => 'known@example.test'],
            ['LS_WEBADMIN_PREAUTH' => $knownSession]
        ));
        self::assertSame(303, $known->status());
        self::assertSame(
            '/admin/password/forgot/sent',
            $known->headers()['Location']
        );
        $this->assertRedirectSecurityHeaders($known);
        $knownExpiredCookie = $this->onlyCookie($known);
        $this->assertExpiredSecureHostOnlyCookie(
            $knownExpiredCookie,
            'LS_WEBADMIN_PREAUTH',
            'Lax'
        );
        self::assertSame(0, $this->tableCount('outbox'));
        self::assertCount(1, $this->mailSender->deliveries);
        self::assertNotNull($this->sessionByToken($knownSession)['revoked_at']);

        $missingForm = $this->controller->forgotPasswordForm(
            $this->get('/admin/password/forgot')
        );
        [$missingSession] = $this->cookieFrom(
            $missingForm,
            'LS_WEBADMIN_PREAUTH'
        );
        $missing = $this->controller->requestPasswordReset($this->post(
            '/admin/password/forgot',
            [
                'csrf' => $this->hiddenCsrf($missingForm->body()),
                'email' => 'absent@example.test',
            ],
            ['LS_WEBADMIN_PREAUTH' => $missingSession]
        ));

        self::assertSame($known->status(), $missing->status());
        self::assertSame($known->body(), $missing->body());
        self::assertSame(
            $known->headers()['Location'],
            $missing->headers()['Location']
        );
        self::assertSame($knownExpiredCookie, $this->onlyCookie($missing));
        self::assertSame(0, $this->tableCount('outbox'));
        self::assertCount(1, $this->mailSender->deliveries);
        foreach (['known@example.test', 'absent@example.test'] as $email) {
            self::assertStringNotContainsString(
                $email,
                $known->body() . $missing->body()
            );
        }

        $invalidForm = $this->controller->forgotPasswordForm(
            $this->get('/admin/password/forgot')
        );
        [$invalidSession] = $this->cookieFrom(
            $invalidForm,
            'LS_WEBADMIN_PREAUTH'
        );
        $invalid = $this->controller->requestPasswordReset($this->post(
            '/admin/password/forgot',
            ['csrf' => $this->tokens->generate(), 'email' => 'known@example.test'],
            ['LS_WEBADMIN_PREAUTH' => $invalidSession]
        ));
        self::assertSame(400, $invalid->status());
        $this->assertExpiredSecureHostOnlyCookie(
            $this->onlyCookie($invalid),
            'LS_WEBADMIN_PREAUTH',
            'Lax'
        );
        self::assertSame(0, $this->tableCount('outbox'));
    }

    public function testPasswordResetTransportFailureShowsOnlyGenericRetryResult(): void
    {
        $this->seedUser('smtp-failure@example.test', 'active');
        $this->mailSender->fail = true;
        $form = $this->controller->forgotPasswordForm(
            $this->get('/admin/password/forgot')
        );
        [$preauth] = $this->cookieFrom($form, 'LS_WEBADMIN_PREAUTH');

        $failed = $this->controller->requestPasswordReset($this->post(
            '/admin/password/forgot',
            [
                'csrf' => $this->hiddenCsrf($form->body()),
                'email' => 'smtp-failure@example.test',
            ],
            ['LS_WEBADMIN_PREAUTH' => $preauth]
        ));

        self::assertSame(303, $failed->status());
        self::assertSame(
            '/admin/password/forgot/unavailable',
            $failed->headers()['Location']
        );
        $this->assertExpiredSecureHostOnlyCookie(
            $this->onlyCookie($failed),
            'LS_WEBADMIN_PREAUTH',
            'Lax'
        );
        self::assertSame(0, $this->tableCount('outbox'));
        self::assertSame(1, $this->tableCount('action_tokens'));
        $action = $this->pdo->query(
            'SELECT delivered_at, revoked_at FROM ls_webadmin_action_tokens'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($action);
        self::assertNull($action['delivered_at']);
        self::assertNotNull($action['revoked_at']);

        $result = $this->controller->forgotPasswordUnavailable(
            $this->get('/admin/password/forgot/unavailable')
        );
        self::assertSame(200, $result->status());
        $this->assertHtmlSecurityHeaders($result);
        self::assertStringContainsString(
            '/admin/password/forgot',
            $result->body()
        );
        foreach ([
            'smtp-failure@example.test',
            'SMTP',
            'Sensitive transport diagnostic',
        ] as $detail) {
            self::assertStringNotContainsString($detail, $result->body());
        }
    }

    public function testCredentialLinkBindsToCleanUrlAndSeparateSecureCookie(): void
    {
        $invitedId = $this->seedUser('invited@example.test', 'invited');
        $otherId = $this->seedUser('signed-in@example.test', 'active');
        $authenticatedToken = $this->seedAuthenticatedSession($otherId);
        $rawToken = $this->tokens->generate();
        $actionId = $this->seedAction(
            $invitedId,
            CredentialActionService::INVITATION,
            $rawToken
        );

        $binding = $this->controller->activate($this->getWithQuery(
            '/admin/activate',
            ['token' => $rawToken],
            ['LS_WEBADMIN_SID' => $authenticatedToken]
        ));

        self::assertSame(303, $binding->status());
        self::assertSame('/admin/activate', $binding->headers()['Location']);
        $this->assertRedirectSecurityHeaders($binding);
        self::assertStringNotContainsString(
            $rawToken,
            $binding->body() . json_encode(
                $binding->headerLines(),
                JSON_THROW_ON_ERROR
            )
        );
        [$actionSession, $actionCookie] = $this->cookieFrom(
            $binding,
            'LS_WEBADMIN_ACTION'
        );
        self::assertNotSame($rawToken, $actionSession);
        $this->assertSecureHostOnlyCookie(
            $actionCookie,
            'LS_WEBADMIN_ACTION',
            'Lax'
        );
        self::assertStringNotContainsString('LS_WEBADMIN_SID=', $actionCookie);
        self::assertNull($this->sessionByToken(
            $authenticatedToken
        )['revoked_at']);
        self::assertNull($this->actionById($actionId)['used_at']);

        $clean = $this->controller->activate($this->get(
            '/admin/activate',
            [
                'LS_WEBADMIN_SID' => $authenticatedToken,
                'LS_WEBADMIN_ACTION' => $actionSession,
            ]
        ));
        self::assertSame(200, $clean->status());
        $this->assertHtmlSecurityHeaders($clean);
        self::assertSame([], $clean->headerValues('Set-Cookie'));
        self::assertStringContainsString(
            'action="/admin/activate"',
            $clean->body()
        );
        self::assertStringNotContainsString($rawToken, $clean->body());
        self::assertStringNotContainsString($actionSession, $clean->body());
        self::assertMatchesRegularExpression(
            '/\A[A-Za-z0-9_-]{43}\z/',
            $this->hiddenCsrf($clean->body())
        );
        self::assertNull($this->actionById($actionId)['used_at']);
    }

    public function testCrossPurposeAndExpiredLinksShareUnavailableOutcome(): void
    {
        $activeId = $this->seedUser('active@example.test', 'active');
        $wrongPurposeToken = $this->tokens->generate();
        $wrongPurposeAction = $this->seedAction(
            $activeId,
            CredentialActionService::PASSWORD_RESET,
            $wrongPurposeToken
        );
        $expiredToken = $this->tokens->generate();
        $expiredAction = $this->seedAction(
            $this->seedUser('expired@example.test', 'invited'),
            CredentialActionService::INVITATION,
            $expiredToken,
            '2026-08-01 05:59:59.000000'
        );

        $crossed = $this->controller->activate($this->getWithQuery(
            '/admin/activate',
            ['token' => $wrongPurposeToken]
        ));
        $expired = $this->controller->activate($this->getWithQuery(
            '/admin/activate',
            ['token' => $expiredToken]
        ));

        foreach ([$crossed, $expired] as $response) {
            self::assertSame(303, $response->status());
            self::assertSame(
                '/admin/action-unavailable',
                $response->headers()['Location']
            );
            $this->assertRedirectSecurityHeaders($response);
            $this->assertExpiredSecureHostOnlyCookie(
                $this->onlyCookie($response),
                'LS_WEBADMIN_ACTION',
                'Lax'
            );
        }
        self::assertSame($crossed->body(), $expired->body());
        self::assertSame(
            $crossed->headers()['Location'],
            $expired->headers()['Location']
        );
        self::assertNull($this->actionById($wrongPurposeAction)['used_at']);
        self::assertNull($this->actionById($expiredAction)['used_at']);
        self::assertSame(0, $this->tableCount('sessions'));
    }

    public function testMismatchAndWeakPasswordKeepBoundActionUsable(): void
    {
        $userId = $this->seedUser('reset-errors@example.test', 'active');
        $rawToken = $this->tokens->generate();
        $actionId = $this->seedAction(
            $userId,
            CredentialActionService::PASSWORD_RESET,
            $rawToken
        );
        [$actionSession, $csrf] = $this->bindAndOpen(
            '/admin/password/reset',
            $rawToken,
            false
        );
        $oldHash = $this->credentialByUserId($userId)['password_hash'];

        $mismatch = $this->controller->completePasswordReset($this->post(
            '/admin/password/reset',
            [
                'csrf' => $csrf,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD . ' mismatch',
            ],
            ['LS_WEBADMIN_ACTION' => $actionSession]
        ));
        self::assertSame(422, $mismatch->status());
        $this->assertHtmlSecurityHeaders($mismatch);
        self::assertSame([], $mismatch->headerValues('Set-Cookie'));
        self::assertStringContainsString('role="alert"', $mismatch->body());
        self::assertNull($this->sessionByToken($actionSession)['revoked_at']);
        self::assertNull($this->actionById($actionId)['used_at']);

        $weak = $this->controller->completePasswordReset($this->post(
            '/admin/password/reset',
            [
                'csrf' => $this->hiddenCsrf($mismatch->body()),
                'password' => 'Aa1!bbb',
                'password_confirmation' => 'Aa1!bbb',
            ],
            ['LS_WEBADMIN_ACTION' => $actionSession]
        ));
        self::assertSame(422, $weak->status());
        $this->assertHtmlSecurityHeaders($weak);
        self::assertSame([], $weak->headerValues('Set-Cookie'));
        self::assertNull($this->sessionByToken($actionSession)['revoked_at']);
        self::assertNull($this->actionById($actionId)['used_at']);
        self::assertSame(
            $oldHash,
            $this->credentialByUserId($userId)['password_hash']
        );
        self::assertSame(1, $this->userById($userId)['auth_version']);
    }

    public function testActivationConsumesStateWithoutLoginAndPreservesForeignAuthCookie(): void
    {
        $invitedId = $this->seedUser('activate@example.test', 'invited');
        $foreignId = $this->seedUser('foreign@example.test', 'active');
        $foreignAuth = $this->seedAuthenticatedSession($foreignId);
        $rawToken = $this->tokens->generate();
        $actionId = $this->seedAction(
            $invitedId,
            CredentialActionService::INVITATION,
            $rawToken
        );
        [$actionSession, $csrf] = $this->bindAndOpen(
            '/admin/activate',
            $rawToken,
            true,
            ['LS_WEBADMIN_SID' => $foreignAuth]
        );

        $completed = $this->controller->completeActivation($this->post(
            '/admin/activate',
            [
                'csrf' => $csrf,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ],
            [
                'LS_WEBADMIN_SID' => $foreignAuth,
                'LS_WEBADMIN_ACTION' => $actionSession,
            ]
        ));

        self::assertSame(303, $completed->status());
        self::assertSame(
            '/admin/login/activated',
            $completed->headers()['Location']
        );
        $this->assertRedirectSecurityHeaders($completed);
        $cookie = $this->onlyCookie($completed);
        $this->assertExpiredSecureHostOnlyCookie(
            $cookie,
            'LS_WEBADMIN_ACTION',
            'Lax'
        );
        self::assertStringNotContainsString('LS_WEBADMIN_SID=', $cookie);
        self::assertNull($this->sessionByToken($foreignAuth)['revoked_at']);
        self::assertNotNull($this->sessionByToken($actionSession)['revoked_at']);
        self::assertNotNull($this->actionById($actionId)['used_at']);
        $user = $this->userById($invitedId);
        self::assertSame('active', $user['status']);
        self::assertSame(2, $user['auth_version']);
        self::assertNotNull($user['activated_at']);
        self::assertTrue($this->hasher->verify(
            self::NEW_PASSWORD,
            $this->credentialByUserId($invitedId)['password_hash']
        ));
        self::assertSame(0, $this->activeAuthenticatedSessions($invitedId));
    }

    public function testPasswordResetRevokesOldSessionsAndNeverAutoLogsIn(): void
    {
        $userId = $this->seedUser('reset-success@example.test', 'active');
        $oldAuth = $this->seedAuthenticatedSession($userId);
        $rawToken = $this->tokens->generate();
        $actionId = $this->seedAction(
            $userId,
            CredentialActionService::PASSWORD_RESET,
            $rawToken
        );
        [$actionSession, $csrf] = $this->bindAndOpen(
            '/admin/password/reset',
            $rawToken,
            false
        );

        $completed = $this->controller->completePasswordReset($this->post(
            '/admin/password/reset',
            [
                'csrf' => $csrf,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ],
            ['LS_WEBADMIN_ACTION' => $actionSession]
        ));

        self::assertSame(303, $completed->status());
        self::assertSame(
            '/admin/login/password-reset',
            $completed->headers()['Location']
        );
        $this->assertRedirectSecurityHeaders($completed);
        $this->assertExpiredSecureHostOnlyCookie(
            $this->onlyCookie($completed),
            'LS_WEBADMIN_ACTION',
            'Lax'
        );
        self::assertSame(2, $this->userById($userId)['auth_version']);
        self::assertTrue($this->hasher->verify(
            self::NEW_PASSWORD,
            $this->credentialByUserId($userId)['password_hash']
        ));
        self::assertNotNull($this->actionById($actionId)['used_at']);
        self::assertNotNull($this->sessionByToken($oldAuth)['revoked_at']);
        self::assertNotNull($this->sessionByToken($actionSession)['revoked_at']);
        self::assertSame(0, $this->activeAuthenticatedSessions($userId));
        self::assertStringNotContainsString(
            'LS_WEBADMIN_SID=',
            $this->onlyCookie($completed)
        );
    }

    public function testForgedCsrfWithMismatchFailsClosedAndExpiresActionCookie(): void
    {
        $userId = $this->seedUser('forged-csrf@example.test', 'active');
        $rawToken = $this->tokens->generate();
        $actionId = $this->seedAction(
            $userId,
            CredentialActionService::PASSWORD_RESET,
            $rawToken
        );
        [$actionSession] = $this->bindAndOpen(
            '/admin/password/reset',
            $rawToken,
            false
        );
        $oldHash = $this->credentialByUserId($userId)['password_hash'];

        $response = $this->controller->completePasswordReset($this->post(
            '/admin/password/reset',
            [
                'csrf' => $this->tokens->generate(),
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD . ' mismatch',
            ],
            ['LS_WEBADMIN_ACTION' => $actionSession]
        ));

        self::assertSame(303, $response->status());
        self::assertSame(
            '/admin/action-unavailable',
            $response->headers()['Location']
        );
        $this->assertExpiredSecureHostOnlyCookie(
            $this->onlyCookie($response),
            'LS_WEBADMIN_ACTION',
            'Lax'
        );
        self::assertStringNotContainsString($rawToken, $response->body());
        self::assertStringNotContainsString($actionSession, $response->body());
        self::assertNull($this->actionById($actionId)['used_at']);
        self::assertSame(
            $oldHash,
            $this->credentialByUserId($userId)['password_hash']
        );
        self::assertSame(1, $this->userById($userId)['auth_version']);
    }

    public function testCredentialHeadRequestsNeverBindOrTouchActionState(): void
    {
        $invitedId = $this->seedUser('head-invite@example.test', 'invited');
        $inviteRaw = $this->tokens->generate();
        $inviteAction = $this->seedAction(
            $invitedId,
            CredentialActionService::INVITATION,
            $inviteRaw
        );
        $activeId = $this->seedUser('head-reset@example.test', 'active');
        $resetRaw = $this->tokens->generate();
        $resetAction = $this->seedAction(
            $activeId,
            CredentialActionService::PASSWORD_RESET,
            $resetRaw
        );

        $inviteHead = $this->controller->activate($this->headWithQuery(
            '/admin/activate',
            ['token' => $inviteRaw]
        ));
        $resetHead = $this->controller->resetPassword($this->headWithQuery(
            '/admin/password/reset',
            ['token' => $resetRaw]
        ));
        foreach ([$inviteHead, $resetHead] as $response) {
            self::assertSame(200, $response->status());
            self::assertSame('', $response->body());
            self::assertSame([], $response->headerValues('Set-Cookie'));
            $this->assertHtmlSecurityHeaders($response);
        }
        self::assertSame(0, $this->tableCount('sessions'));
        self::assertNull($this->actionById($inviteAction)['used_at']);
        self::assertNull($this->actionById($resetAction)['used_at']);

        $bound = $this->credentialActions->bindActionToken(
            $resetRaw,
            CredentialActionService::PASSWORD_RESET
        );
        self::assertNotNull($bound);
        $before = $this->sessionByToken($bound->sessionToken());
        $cleanHead = $this->controller->resetPassword($this->head(
            '/admin/password/reset',
            ['LS_WEBADMIN_ACTION' => $bound->sessionToken()]
        ));
        self::assertSame(200, $cleanHead->status());
        self::assertSame('', $cleanHead->body());
        self::assertSame([], $cleanHead->headerValues('Set-Cookie'));
        self::assertSame(
            $before,
            $this->sessionByToken($bound->sessionToken())
        );
        self::assertNull($this->actionById($resetAction)['used_at']);
    }

    public function testCredentialPostsRejectEveryQueryBeforeMutation(): void
    {
        $userId = $this->seedUser('query@example.test', 'active');
        $forgotForm = $this->controller->forgotPasswordForm(
            $this->get('/admin/password/forgot')
        );
        [$preauth] = $this->cookieFrom(
            $forgotForm,
            'LS_WEBADMIN_PREAUTH'
        );
        $forgot = $this->controller->requestPasswordReset($this->post(
            '/admin/password/forgot',
            [
                'csrf' => $this->hiddenCsrf($forgotForm->body()),
                'email' => 'query@example.test',
            ],
            ['LS_WEBADMIN_PREAUTH' => $preauth],
            ['next' => '/admin']
        ));
        self::assertSame(400, $forgot->status());
        self::assertSame(0, $this->tableCount('outbox'));
        self::assertNull($this->sessionByToken($preauth)['revoked_at']);

        $rawToken = $this->tokens->generate();
        $actionId = $this->seedAction(
            $userId,
            CredentialActionService::PASSWORD_RESET,
            $rawToken
        );
        [$actionSession, $csrf] = $this->bindAndOpen(
            '/admin/password/reset',
            $rawToken,
            false
        );
        $completion = $this->controller->completePasswordReset($this->post(
            '/admin/password/reset',
            [
                'csrf' => $csrf,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ],
            ['LS_WEBADMIN_ACTION' => $actionSession],
            ['token' => $rawToken]
        ));
        self::assertSame(400, $completion->status());
        self::assertNull($this->actionById($actionId)['used_at']);
        self::assertNull($this->sessionByToken($actionSession)['revoked_at']);
        self::assertSame(1, $this->userById($userId)['auth_version']);
    }

    /**
     * @param array<string, string> $initialCookies
     * @return array{0: string, 1: string}
     */
    private function bindAndOpen(
        string $path,
        string $rawToken,
        bool $activation,
        array $initialCookies = []
    ): array {
        $binding = $activation
            ? $this->controller->activate($this->getWithQuery(
                $path,
                ['token' => $rawToken],
                $initialCookies
            ))
            : $this->controller->resetPassword($this->getWithQuery(
                $path,
                ['token' => $rawToken],
                $initialCookies
            ));
        self::assertSame(303, $binding->status());
        self::assertSame($path, $binding->headers()['Location']);
        [$actionSession] = $this->cookieFrom(
            $binding,
            'LS_WEBADMIN_ACTION'
        );
        $cleanCookies = $initialCookies;
        $cleanCookies['LS_WEBADMIN_ACTION'] = $actionSession;
        $form = $activation
            ? $this->controller->activate($this->get($path, $cleanCookies))
            : $this->controller->resetPassword($this->get(
                $path,
                $cleanCookies
            ));
        self::assertSame(200, $form->status());
        self::assertStringNotContainsString($rawToken, $form->body());

        return [$actionSession, $this->hiddenCsrf($form->body())];
    }

    private function seedUser(
        string $email,
        string $status,
        int $authVersion = 1
    ): int {
        $timestamp = '2026-08-01 05:50:00.000000';
        $publicId = sprintf(
            '71000000-0000-4000-8000-%012x',
            $this->tableCount('users') + 1
        );
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_users '
            . '(public_id, email_canonical, status, auth_version, invited_at, '
            . 'activated_at, suspended_at, created_at, updated_at) VALUES '
            . '(:public_id, :email, :status, :auth_version, :invited_at, '
            . ':activated_at, NULL, :created_at, :updated_at)'
        );
        $statement->execute([
            'public_id' => $publicId,
            'email' => strtolower($email),
            'status' => $status,
            'auth_version' => $authVersion,
            'invited_at' => $timestamp,
            'activated_at' => $status === 'invited' ? null : $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $userId = (int) $this->pdo->lastInsertId();
        $passwordHash = $status === 'invited'
            ? null
            : $this->hasher->hash(self::OLD_PASSWORD);
        $credential = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_credentials '
            . '(user_id, password_hash, password_set_at, created_at, '
            . 'updated_at) VALUES (:user_id, :password_hash, '
            . ':password_set_at, :created_at, :updated_at)'
        );
        $credential->execute([
            'user_id' => $userId,
            'password_hash' => $passwordHash,
            'password_set_at' => $passwordHash === null ? null : $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $userId;
    }

    private function seedAction(
        int $userId,
        string $purpose,
        string $rawToken,
        string $expiresAt = '2026-08-01 07:00:00.000000'
    ): int {
        $authVersion = (int) $this->userById($userId)['auth_version'];
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_action_tokens '
            . '(user_id, purpose, token_hash, auth_version, created_at, '
            . 'expires_at, delivered_at) VALUES (:user_id, :purpose, '
            . ':token_hash, :auth_version, :created_at, :expires_at, '
            . ':delivered_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $rawToken),
            'auth_version' => $authVersion,
            'created_at' => '2026-08-01 05:59:00.000000',
            'expires_at' => $expiresAt,
            'delivered_at' => '2026-08-01 05:59:30.000000',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedAuthenticatedSession(int $userId): string
    {
        $token = $this->tokens->generate();
        $csrf = $this->tokens->generate();
        $authVersion = (int) $this->userById($userId)['auth_version'];
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_sessions '
            . '(public_id, user_id, session_type, token_hash, '
            . 'csrf_token_hash, auth_version, pending_action_token_id, '
            . 'created_at, last_seen_at, idle_expires_at, '
            . 'absolute_expires_at) VALUES (:public_id, :user_id, '
            . "'authenticated', :token_hash, :csrf_hash, :auth_version, "
            . 'NULL, :created_at, :last_seen_at, :idle_expires_at, '
            . ':absolute_expires_at)'
        );
        $statement->execute([
            'public_id' => sprintf(
                '72000000-0000-4000-8000-%012x',
                $this->tableCount('sessions') + 1
            ),
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'csrf_hash' => hash('sha256', $csrf),
            'auth_version' => $authVersion,
            'created_at' => '2026-08-01 05:59:00.000000',
            'last_seen_at' => '2026-08-01 05:59:00.000000',
            'idle_expires_at' => '2026-08-01 06:05:00.000000',
            'absolute_expires_at' => '2026-08-01 06:10:00.000000',
        ]);

        return $token;
    }

    /** @param array<string, string> $cookies */
    private function get(string $path, array $cookies = []): Request
    {
        return Request::fromInput(
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => $path,
                'REMOTE_ADDR' => '192.0.2.80',
                'HTTPS' => 'on',
            ],
            [],
            [],
            $cookies,
            ['User-Agent' => 'Credential HTTP test browser']
        );
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $cookies
     */
    private function getWithQuery(
        string $path,
        array $query,
        array $cookies = []
    ): Request {
        return Request::fromInput(
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => $path . '?' . http_build_query($query),
                'REMOTE_ADDR' => '192.0.2.80',
                'HTTPS' => 'on',
            ],
            $query,
            [],
            $cookies,
            ['User-Agent' => 'Credential HTTP test browser']
        );
    }

    /** @param array<string, string> $cookies */
    private function head(string $path, array $cookies = []): Request
    {
        return Request::fromInput(
            [
                'REQUEST_METHOD' => 'HEAD',
                'REQUEST_URI' => $path,
                'REMOTE_ADDR' => '192.0.2.80',
                'HTTPS' => 'on',
            ],
            [],
            [],
            $cookies,
            ['User-Agent' => 'Credential HTTP test browser']
        );
    }

    /** @param array<string, string> $query */
    private function headWithQuery(string $path, array $query): Request
    {
        return Request::fromInput(
            [
                'REQUEST_METHOD' => 'HEAD',
                'REQUEST_URI' => $path . '?' . http_build_query($query),
                'REMOTE_ADDR' => '192.0.2.80',
                'HTTPS' => 'on',
            ],
            $query,
            [],
            [],
            ['User-Agent' => 'Credential HTTP test browser']
        );
    }

    /**
     * @param array<string, string> $form
     * @param array<string, string> $cookies
     */
    private function post(
        string $path,
        array $form,
        array $cookies,
        array $query = []
    ): Request {
        return Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => $path . ($query === []
                    ? ''
                    : '?' . http_build_query($query)),
                'REMOTE_ADDR' => '192.0.2.80',
                'HTTPS' => 'on',
            ],
            $query,
            $form,
            $cookies,
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent' => 'Credential HTTP test browser',
            ]
        );
    }

    /** @return array{0: string, 1: string} */
    private function cookieFrom(Response $response, string $name): array
    {
        $matches = array_values(array_filter(
            $response->headerValues('Set-Cookie'),
            static fn (string $line): bool => str_starts_with(
                $line,
                $name . '='
            )
        ));
        self::assertCount(1, $matches);
        $pair = explode(';', $matches[0], 2)[0];
        [, $encoded] = explode('=', $pair, 2);

        return [rawurldecode($encoded), $matches[0]];
    }

    private function onlyCookie(Response $response): string
    {
        $cookies = $response->headerValues('Set-Cookie');
        self::assertCount(1, $cookies);

        return $cookies[0];
    }

    private function assertSecureHostOnlyCookie(
        string $line,
        string $name,
        string $sameSite
    ): void {
        self::assertStringStartsWith($name . '=', $line);
        self::assertStringContainsString('; Path=/admin', $line);
        self::assertStringContainsString('; Secure', $line);
        self::assertStringContainsString('; HttpOnly', $line);
        self::assertStringContainsString('; SameSite=' . $sameSite, $line);
        self::assertStringContainsString('; Expires=', $line);
        self::assertStringNotContainsString('; Domain=', $line);
    }

    private function assertExpiredSecureHostOnlyCookie(
        string $line,
        string $name,
        string $sameSite
    ): void {
        $this->assertSecureHostOnlyCookie($line, $name, $sameSite);
        self::assertStringStartsWith($name . '=;', $line);
        self::assertStringContainsString('; Max-Age=0', $line);
    }

    private function assertHtmlSecurityHeaders(Response $response): void
    {
        $this->assertCommonSecurityHeaders($response);
        self::assertSame(
            "default-src 'none'; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
            $response->headers()['Content-Security-Policy']
        );
        self::assertSame(
            'text/html; charset=utf-8',
            $response->headers()['Content-Type']
        );
    }

    private function assertRedirectSecurityHeaders(Response $response): void
    {
        $this->assertCommonSecurityHeaders($response);
        self::assertSame(
            "default-src 'none'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'",
            $response->headers()['Content-Security-Policy']
        );
    }

    private function assertCommonSecurityHeaders(Response $response): void
    {
        self::assertSame(
            'no-store, no-cache, must-revalidate, max-age=0',
            $response->headers()['Cache-Control']
        );
        self::assertSame(
            'no-referrer',
            $response->headers()['Referrer-Policy']
        );
        self::assertSame('DENY', $response->headers()['X-Frame-Options']);
        self::assertSame(
            'nosniff',
            $response->headers()['X-Content-Type-Options']
        );
    }

    private function hiddenCsrf(string $html): string
    {
        self::assertSame(1, preg_match(
            '/name="csrf" value="([A-Za-z0-9_-]{43})"/',
            $html,
            $matches
        ));

        return $matches[1];
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

    private function activeAuthenticatedSessions(int $userId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM ls_webadmin_sessions WHERE user_id = "
            . ":user_id AND session_type = 'authenticated' "
            . 'AND revoked_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    private function tableCount(string $suffix): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM "ls_webadmin_' . $suffix . '"'
        )->fetchColumn();
    }
}

final class WebAdminCredentialHttpPasswordResetMailSender implements
    PasswordResetMailSenderInterface
{
    /** @var list<PasswordResetDelivery> */
    public array $deliveries = [];
    public bool $fail = false;
    public ?Closure $onSend = null;

    public function send(PasswordResetDelivery $delivery): void
    {
        if ($this->onSend !== null) {
            ($this->onSend)();
        }
        $this->deliveries[] = $delivery;
        if ($this->fail) {
            throw new RuntimeException('Sensitive transport diagnostic.');
        }
    }
}

final class WebAdminCredentialHttpClock implements ClockInterface
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
}

final class WebAdminCredentialHttpUuidGenerator implements UuidGeneratorInterface
{
    private int $counter = 1;

    public function generateV4(): string
    {
        return sprintf(
            '73000000-0000-4000-8000-%012x',
            $this->counter++
        );
    }
}
