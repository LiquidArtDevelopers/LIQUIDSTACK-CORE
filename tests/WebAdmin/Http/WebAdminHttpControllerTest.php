<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationRepository;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Http\WebAdminHttpController;
use App\Core\WebAdmin\Http\WebAdminHttpRuntime;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\SystemClock;
use PHPUnit\Framework\TestCase;

final class WebAdminHttpControllerTest extends TestCase
{
    private const PASSWORD = 'Correct horse battery staple';

    private PDO $pdo;
    private WebAdminHttpController $controller;
    private WebAdminAuthenticationService $authentication;
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
        $config = new WebAdminConfig(
            '/admin',
            'ls_webadmin_',
            'LS_WEBADMIN_SID',
            300,
            600,
            'test'
        );
        $tables = WebAdminTableNames::fromPdo($this->pdo, 'ls_webadmin_');
        $this->authentication = new WebAdminAuthenticationService(
            new WebAdminAuthenticationRepository($this->pdo, $tables),
            $config,
            SecurityKey::fromRawBytes(str_repeat('H', 32)),
            new SystemClock(),
            new RandomUuidV4Generator()
        );
        $this->controller = new WebAdminHttpController(
            new WebAdminHttpRuntime(
                $config,
                $this->authentication,
                new WebAdminAuthorizationService($this->pdo, $tables)
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

    public function testCompleteLoginDashboardAndLogoutFlow(): void
    {
        $this->seedActiveUser('admin@example.test', true);
        $form = $this->controller->loginForm($this->get('/admin/login'));

        self::assertSame(200, $form->status());
        self::assertStringContainsString('<h1', $form->body());
        self::assertStringContainsString('autocomplete="username"', $form->body());
        self::assertSame(
            "default-src 'none'; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
            $form->headers()['Content-Security-Policy']
        );
        [$preAuthToken, $preAuthCookie] = $this->cookieFrom(
            $form,
            'LS_WEBADMIN_PREAUTH'
        );
        self::assertStringContainsString(
            '; Secure; HttpOnly; SameSite=Lax',
            $preAuthCookie
        );
        self::assertStringNotContainsString('Domain=', $preAuthCookie);
        $csrf = $this->hiddenCsrf($form->body());

        $login = $this->controller->login($this->post(
            '/admin/login',
            [
                'csrf' => $csrf,
                'email' => 'admin@example.test',
                'password' => self::PASSWORD,
            ],
            $preAuthToken,
            'LS_WEBADMIN_PREAUTH'
        ));
        self::assertSame(303, $login->status());
        self::assertSame('/admin', $login->headers()['Location']);
        [$authenticatedToken, $authenticatedCookie] = $this->cookieFrom(
            $login,
            'LS_WEBADMIN_SID'
        );
        self::assertNotSame($preAuthToken, $authenticatedToken);
        self::assertStringContainsString(
            '; Secure; HttpOnly; SameSite=Strict',
            $authenticatedCookie
        );
        self::assertStringNotContainsString('Domain=', $authenticatedCookie);
        [, $expiredPreauth] = $this->cookieFrom(
            $login,
            'LS_WEBADMIN_PREAUTH'
        );
        self::assertStringContainsString('Max-Age=0', $expiredPreauth);
        self::assertStringContainsString('SameSite=Lax', $expiredPreauth);

        $dashboard = $this->controller->root(
            $this->get('/admin', $authenticatedToken)
        );
        self::assertSame(200, $dashboard->status());
        self::assertStringContainsString('Gesti&oacute;n web', $dashboard->body());
        self::assertStringNotContainsString(
            'admin@example.test',
            $dashboard->body()
        );
        $logoutCsrf = $this->hiddenCsrf($dashboard->body());

        $logout = $this->controller->logout($this->post(
            '/admin/logout',
            ['csrf' => $logoutCsrf],
            $authenticatedToken
        ));
        self::assertSame(303, $logout->status());
        self::assertSame('/admin/login', $logout->headers()['Location']);
        self::assertStringContainsString('Max-Age=0', $logout->headerValues(
            'Set-Cookie'
        )[0]);
        self::assertNull($this->authentication->resolveAuthenticatedSession(
            $authenticatedToken
        ));
    }

    public function testValidCredentialWithoutAccessCapabilityIsGenericFailure(): void
    {
        $this->seedActiveUser('no-access@example.test', false);
        $form = $this->controller->loginForm($this->get('/admin/login'));
        [$preAuthToken] = $this->cookieFrom(
            $form,
            'LS_WEBADMIN_PREAUTH'
        );

        $response = $this->controller->login($this->post(
            '/admin/login',
            [
                'csrf' => $this->hiddenCsrf($form->body()),
                'email' => 'no-access@example.test',
                'password' => self::PASSWORD,
            ],
            $preAuthToken,
            'LS_WEBADMIN_PREAUTH'
        ));

        self::assertSame(401, $response->status());
        self::assertStringContainsString('role="alert"', $response->body());
        self::assertStringNotContainsString('Forbidden', $response->body());
        self::assertStringNotContainsString(
            'no-access@example.test',
            $response->body()
        );
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ls_webadmin_sessions "
            . "WHERE session_type = 'authenticated' AND revoked_at IS NULL"
        )->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ls_webadmin_audit_log "
            . "WHERE event_code = 'webadmin.login' AND outcome = 'success'"
        )->fetchColumn());
    }

    public function testCredentialAndCsrfFailuresRemainGeneric(): void
    {
        $this->seedActiveUser('private@example.test', true);
        $form = $this->controller->loginForm($this->get('/admin/login'));
        [$token] = $this->cookieFrom($form, 'LS_WEBADMIN_PREAUTH');

        $failure = $this->controller->login($this->post(
            '/admin/login',
            [
                'csrf' => $this->hiddenCsrf($form->body()),
                'email' => 'private@example.test',
                'password' => 'wrong password value',
            ],
            $token,
            'LS_WEBADMIN_PREAUTH'
        ));
        self::assertSame(401, $failure->status());
        self::assertStringContainsString('role="alert"', $failure->body());
        self::assertStringNotContainsString('private@example.test', $failure->body());
        self::assertStringNotContainsString('wrong password value', $failure->body());
    }

    public function testPostContractRejectsWrongMediaTypeAndUnexpectedFields(): void
    {
        $wrongType = Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/login',
                'REMOTE_ADDR' => '192.0.2.80',
            ],
            [],
            ['csrf' => 'x', 'email' => 'x', 'password' => 'x'],
            ['LS_WEBADMIN_PREAUTH' => 'x'],
            ['Content-Type' => 'application/json']
        );
        self::assertSame(400, $this->controller->login($wrongType)->status());

        $extra = $this->post(
            '/admin/login',
            ['csrf' => 'x', 'email' => 'x', 'password' => 'x', 'role' => 'admin'],
            'x',
            'LS_WEBADMIN_PREAUTH'
        );
        self::assertSame(400, $this->controller->login($extra)->status());
    }

    public function testHeadNavigationsAreEmptyAndNeverAllocateState(): void
    {
        $beforeSessions = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_sessions'
        )->fetchColumn();
        $beforeLimits = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_rate_limits'
        )->fetchColumn();

        $responses = [
            $this->controller->root($this->head('/admin')),
            $this->controller->loginForm($this->head('/admin/login')),
            $this->controller->forgotPasswordForm(
                $this->head('/admin/password/forgot')
            ),
            $this->controller->forgotPasswordSent(
                $this->head('/admin/password/forgot/sent')
            ),
            $this->controller->forgotPasswordUnavailable(
                $this->head('/admin/password/forgot/unavailable')
            ),
            $this->controller->actionUnavailable(
                $this->head('/admin/action-unavailable')
            ),
            $this->controller->activationCompleted(
                $this->head('/admin/login/activated')
            ),
            $this->controller->passwordResetCompleted(
                $this->head('/admin/login/password-reset')
            ),
        ];
        foreach ($responses as $response) {
            self::assertSame(200, $response->status());
            self::assertSame('', $response->body());
            self::assertSame([], $response->headerValues('Set-Cookie'));
            self::assertSame(
                'no-store, no-cache, must-revalidate, max-age=0',
                $response->headers()['Cache-Control']
            );
        }
        self::assertSame($beforeSessions, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_sessions'
        )->fetchColumn());
        self::assertSame($beforeLimits, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_rate_limits'
        )->fetchColumn());
    }

    public function testDashboardHeadDoesNotSlideAuthenticatedSession(): void
    {
        $this->seedActiveUser('head-probe@example.test', true);
        $form = $this->controller->loginForm($this->get('/admin/login'));
        [$preAuthenticationToken] = $this->cookieFrom(
            $form,
            'LS_WEBADMIN_PREAUTH'
        );
        $login = $this->controller->login($this->post(
            '/admin/login',
            [
                'csrf' => $this->hiddenCsrf($form->body()),
                'email' => 'head-probe@example.test',
                'password' => self::PASSWORD,
            ],
            $preAuthenticationToken,
            'LS_WEBADMIN_PREAUTH'
        ));
        [$authenticatedToken] = $this->cookieFrom(
            $login,
            'LS_WEBADMIN_SID'
        );
        $fixedLastSeenAt = '2026-07-31 00:00:00.000000';
        $statement = $this->pdo->prepare(
            'UPDATE ls_webadmin_sessions SET last_seen_at = :last_seen_at '
            . 'WHERE token_hash = :token_hash'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            'last_seen_at' => $fixedLastSeenAt,
            'token_hash' => hash('sha256', $authenticatedToken),
        ]));

        $response = $this->controller->root(
            $this->head('/admin', $authenticatedToken)
        );
        self::assertSame(200, $response->status());
        self::assertSame('', $response->body());
        self::assertSame([], $response->headerValues('Set-Cookie'));
        $lastSeenAt = $this->pdo->prepare(
            'SELECT last_seen_at FROM ls_webadmin_sessions '
            . 'WHERE token_hash = :token_hash'
        );
        self::assertNotFalse($lastSeenAt);
        self::assertTrue($lastSeenAt->execute([
            'token_hash' => hash('sha256', $authenticatedToken),
        ]));
        self::assertSame($fixedLastSeenAt, $lastSeenAt->fetchColumn());
    }

    public function testRepeatedDashboardRequestsKeepFormsValidAcrossTabs(): void
    {
        $this->seedActiveUser('tabs@example.test', true);
        $form = $this->controller->loginForm($this->get('/admin/login'));
        [$preAuthToken] = $this->cookieFrom(
            $form,
            'LS_WEBADMIN_PREAUTH'
        );
        $login = $this->controller->login($this->post(
            '/admin/login',
            [
                'csrf' => $this->hiddenCsrf($form->body()),
                'email' => 'tabs@example.test',
                'password' => self::PASSWORD,
            ],
            $preAuthToken,
            'LS_WEBADMIN_PREAUTH'
        ));
        [$authenticatedToken] = $this->cookieFrom(
            $login,
            'LS_WEBADMIN_SID'
        );

        $firstTab = $this->controller->root(
            $this->get('/admin', $authenticatedToken)
        );
        $secondTab = $this->controller->root(
            $this->get('/admin', $authenticatedToken)
        );
        self::assertSame(
            $this->hiddenCsrf($firstTab->body()),
            $this->hiddenCsrf($secondTab->body())
        );

        $logout = $this->controller->logout($this->post(
            '/admin/logout',
            ['csrf' => $this->hiddenCsrf($firstTab->body())],
            $authenticatedToken
        ));
        self::assertSame(303, $logout->status());
        self::assertStringContainsString(
            'Max-Age=0',
            $logout->headerValues('Set-Cookie')[0]
        );
    }

    public function testSyntacticallyValidLogoutFailuresAreIndistinguishable(): void
    {
        $missingCookie = Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/logout',
                'REMOTE_ADDR' => '192.0.2.80',
            ],
            [],
            ['csrf' => str_repeat('A', 43)],
            [],
            ['Content-Type' => 'application/x-www-form-urlencoded']
        );
        $invalidCookie = $this->post(
            '/admin/logout',
            ['csrf' => str_repeat('A', 43)],
            str_repeat('B', 43)
        );

        $first = $this->controller->logout($missingCookie);
        $second = $this->controller->logout($invalidCookie);
        self::assertSame(303, $first->status());
        self::assertSame($first->status(), $second->status());
        self::assertSame($first->body(), $second->body());
        self::assertSame(
            $first->headers()['Location'],
            $second->headers()['Location']
        );
        self::assertSame([], $first->headerValues('Set-Cookie'));
        self::assertSame([], $second->headerValues('Set-Cookie'));
    }

    public function testRejectedPostAllocationLimitReturns429AndExpiresCookie(): void
    {
        $form = $this->controller->loginForm($this->get('/admin/login'));
        [$token] = $this->cookieFrom($form, 'LS_WEBADMIN_PREAUTH');
        $blockedUntil = (new DateTimeImmutable(
            '+15 minutes',
            new DateTimeZone('UTC')
        ))->format('Y-m-d H:i:s.u');
        $statement = $this->pdo->prepare(
            "UPDATE ls_webadmin_rate_limits SET blocked_until = :blocked "
            . "WHERE action = 'preauth.issue'"
        );
        $statement->execute(['blocked' => $blockedUntil]);
        self::assertSame(1, $statement->rowCount());

        $response = $this->controller->login($this->post(
            '/admin/login',
            [
                'csrf' => (new App\Core\WebAdmin\Security\SecureTokenGenerator())
                    ->generate(),
                'email' => 'admin@example.test',
                'password' => self::PASSWORD,
            ],
            $token,
            'LS_WEBADMIN_PREAUTH'
        ));

        self::assertSame(429, $response->status());
        self::assertSame('900', $response->headers()['Retry-After']);
        self::assertStringContainsString(
            'Max-Age=0',
            $this->cookieFrom(
                $response,
                'LS_WEBADMIN_PREAUTH'
            )[1]
        );
    }

    public function testCrossSiteNavigationCannotOverwriteAuthenticatedCookie(): void
    {
        $this->seedActiveUser('cross-site@example.test', true);
        $loginForm = $this->controller->loginForm(
            $this->get('/admin/login')
        );
        [$preauth] = $this->cookieFrom(
            $loginForm,
            'LS_WEBADMIN_PREAUTH'
        );
        $login = $this->controller->login($this->post(
            '/admin/login',
            [
                'csrf' => $this->hiddenCsrf($loginForm->body()),
                'email' => 'cross-site@example.test',
                'password' => self::PASSWORD,
            ],
            $preauth,
            'LS_WEBADMIN_PREAUTH'
        ));
        [$authenticated] = $this->cookieFrom(
            $login,
            'LS_WEBADMIN_SID'
        );
        self::assertNotNull(
            $this->authentication->resolveAuthenticatedSession($authenticated)
        );

        // A top-level cross-site GET omits the Strict SID. It may create or
        // reuse only the Lax pre-authentication cookie.
        $navigations = [];
        $freshLogin = $this->controller->loginForm(
            $this->get('/admin/login')
        );
        $navigations[] = $freshLogin;
        [$crossSitePreauth] = $this->cookieFrom(
            $freshLogin,
            'LS_WEBADMIN_PREAUTH'
        );
        $navigations[] = $this->controller->forgotPasswordForm($this->get(
            '/admin/password/forgot',
            $crossSitePreauth,
            'LS_WEBADMIN_PREAUTH'
        ));
        $navigations[] = $this->controller->activationCompleted($this->get(
            '/admin/login/activated',
            $crossSitePreauth,
            'LS_WEBADMIN_PREAUTH'
        ));
        $navigations[] = $this->controller->passwordResetCompleted($this->get(
            '/admin/login/password-reset',
            $crossSitePreauth,
            'LS_WEBADMIN_PREAUTH'
        ));

        foreach ($navigations as $response) {
            self::assertSame(200, $response->status());
            foreach ($response->headerValues('Set-Cookie') as $cookie) {
                self::assertStringStartsWith(
                    'LS_WEBADMIN_PREAUTH=',
                    $cookie
                );
                self::assertStringContainsString('SameSite=Lax', $cookie);
                self::assertStringNotContainsString(
                    'LS_WEBADMIN_SID=',
                    $cookie
                );
            }
        }
        self::assertNotNull(
            $this->authentication->resolveAuthenticatedSession($authenticated)
        );
    }

    public function testLoginPostAcceptsOnlyPreAuthenticationCookie(): void
    {
        $this->seedActiveUser('cookie-contract@example.test', true);
        $form = $this->controller->loginForm($this->get('/admin/login'));
        [$preauth] = $this->cookieFrom(
            $form,
            'LS_WEBADMIN_PREAUTH'
        );
        $payload = [
            'csrf' => $this->hiddenCsrf($form->body()),
            'email' => 'cookie-contract@example.test',
            'password' => self::PASSWORD,
        ];

        $wrongCookie = $this->controller->login($this->post(
            '/admin/login',
            $payload,
            $preauth,
            'LS_WEBADMIN_SID'
        ));
        self::assertSame(400, $wrongCookie->status());
        self::assertSame([], $wrongCookie->headerValues('Set-Cookie'));
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ls_webadmin_sessions "
            . "WHERE session_type = 'authenticated' AND revoked_at IS NULL"
        )->fetchColumn());

        $accepted = $this->controller->login($this->post(
            '/admin/login',
            $payload,
            $preauth,
            'LS_WEBADMIN_PREAUTH'
        ));
        self::assertSame(303, $accepted->status());
        [, $sid] = $this->cookieFrom($accepted, 'LS_WEBADMIN_SID');
        [, $expiredPreauth] = $this->cookieFrom(
            $accepted,
            'LS_WEBADMIN_PREAUTH'
        );
        self::assertStringContainsString('SameSite=Strict', $sid);
        self::assertStringContainsString('Max-Age=0', $expiredPreauth);
        self::assertStringContainsString('SameSite=Lax', $expiredPreauth);
    }

    private function seedActiveUser(string $email, bool $access): void
    {
        $hash = PasswordHasher::productive()->hash(self::PASSWORD);
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_users '
            . '(public_id, email_canonical, status, auth_version, activated_at) '
            . "VALUES (:public_id, :email, 'active', 1, :activated_at)"
        );
        $statement->execute([
            'public_id' => sprintf(
                '10000000-0000-4000-8000-%012x',
                (int) $this->pdo->query(
                    'SELECT COUNT(*) + 1 FROM ls_webadmin_users'
                )->fetchColumn()
            ),
            'email' => $email,
            'activated_at' => '2026-08-01 00:00:00.000000',
        ]);
        $userId = (int) $this->pdo->lastInsertId();
        $credential = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_credentials '
            . '(user_id, password_hash, password_set_at) '
            . 'VALUES (:user_id, :password_hash, :password_set_at)'
        );
        $credential->execute([
            'user_id' => $userId,
            'password_hash' => $hash,
            'password_set_at' => '2026-08-01 00:00:00.000000',
        ]);
        if ($access) {
            $this->pdo->exec(
                'INSERT INTO ls_webadmin_user_roles (user_id, role_id, source) '
                . "SELECT {$userId}, id, 'manual' FROM ls_webadmin_roles "
                . "WHERE code = 'editor'"
            );
        }
    }

    private function get(
        string $path,
        ?string $token = null,
        string $cookieName = 'LS_WEBADMIN_SID'
    ): Request
    {
        return Request::fromInput(
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => $path,
                'REMOTE_ADDR' => '192.0.2.80',
            ],
            [],
            [],
            $token === null ? [] : [$cookieName => $token],
            ['User-Agent' => 'WebAdmin test browser']
        );
    }

    /** @param array<string, string> $form */
    private function post(
        string $path,
        array $form,
        string $token,
        string $cookieName = 'LS_WEBADMIN_SID'
    ): Request {
        return Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => $path,
                'REMOTE_ADDR' => '192.0.2.80',
            ],
            [],
            $form,
            [$cookieName => $token],
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent' => 'WebAdmin test browser',
            ]
        );
    }

    /** @return array{0: string, 1: string} */
    private function cookieFrom(
        App\Core\Http\Response $response,
        string $name
    ): array
    {
        $matches = array_values(array_filter(
            $response->headerValues('Set-Cookie'),
            static fn (string $line): bool => str_starts_with(
                $line,
                $name . '='
            )
        ));
        self::assertCount(1, $matches);
        $line = $matches[0];
        $pair = explode(';', $line, 2)[0];
        [, $value] = explode('=', $pair, 2);

        return [rawurldecode($value), $line];
    }

    private function head(
        string $path,
        ?string $token = null,
        string $cookieName = 'LS_WEBADMIN_SID'
    ): Request
    {
        return Request::fromInput(
            [
                'REQUEST_METHOD' => 'HEAD',
                'REQUEST_URI' => $path,
                'REMOTE_ADDR' => '192.0.2.80',
            ],
            [],
            [],
            $token === null ? [] : [$cookieName => $token]
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
}
