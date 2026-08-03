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
use App\Core\WebAdmin\Http\WebAdminHttpController;
use App\Core\WebAdmin\Http\WebAdminHttpRuntime;
use App\Core\WebAdmin\Navigation\WebAdminNavigationCatalog;
use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use App\Core\WebAdmin\UserManagement\ActiveModuleSet;
use App\Core\WebAdmin\UserManagement\UserManagementRepository;
use App\Core\WebAdmin\UserManagement\UserManagementService;
use PHPUnit\Framework\TestCase;

final class WebAdminUserManagementHttpTest extends TestCase
{
    private const NOW = '2026-08-01 08:00:00.000000';
    private const PASSWORD = 'Correct horse battery staple 1!';
    private const VIEW = 'webadmin.users.view';
    private const INVITE = 'webadmin.users.invite';
    private const SUSPEND = 'webadmin.users.suspend';
    private const MANAGE = 'webadmin.users.capabilities.manage';
    private const AUDIT = 'webadmin.audit.view';
    private const FEATURE_VIEW = 'feature.dashboard.view';

    private static ?string $passwordHash = null;

    private PDO $pdo;
    private WebAdminHttpController $controller;
    private SecurityKey $securityKey;
    private SecureTokenGenerator $tokens;
    private string $previousExceptionTraceSetting;
    private int $userSequence = 1;
    private int $sessionSequence = 1;

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
            1800,
            7200,
            'test'
        );
        $tables = WebAdminTableNames::fromPdo($this->pdo, 'ls_webadmin_');
        $clock = new WebAdminUserManagementHttpClock(self::NOW);
        $uuid = new WebAdminUserManagementHttpUuidGenerator();
        $hasher = PasswordHasher::productive();
        self::$passwordHash ??= $hasher->hash(self::PASSWORD);
        $this->securityKey = SecurityKey::fromRawBytes(str_repeat('U', 32));
        $this->tokens = new SecureTokenGenerator();
        $authentication = new WebAdminAuthenticationService(
            new WebAdminAuthenticationRepository($this->pdo, $tables),
            $config,
            $this->securityKey,
            $clock,
            $uuid,
            $hasher,
            $this->tokens
        );
        $authorization = new WebAdminAuthorizationService(
            $this->pdo,
            $tables,
            $clock,
            $this->tokens,
            $hasher
        );
        $management = new UserManagementService(
            new UserManagementRepository($this->pdo, $tables),
            new ActiveModuleSet(['webadmin', 'blog']),
            $config,
            $this->securityKey,
            $clock,
            $uuid,
            $hasher,
            $this->tokens
        );
        $this->controller = new WebAdminHttpController(
            new WebAdminHttpRuntime(
                $config,
                $authentication,
                $authorization,
                null,
                $management,
                new WebAdminNavigationCatalog([
                    new WebAdminNavigationItem(
                        'feature',
                        'Funcionalidad privada',
                        '/feature',
                        self::FEATURE_VIEW
                    ),
                ])
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

    public function testDashboardAndUserRoutesFollowLiveViewCapability(): void
    {
        $admin = $this->seedUser(
            'admin@example.test',
            'Administradora',
            'active',
            'site_admin'
        );
        $editor = $this->seedUser(
            'basic-editor@example.test',
            'Editora b&aacute;sica',
            'active',
            'editor'
        );
        [$adminToken] = $this->seedSession($admin['id']);
        [$editorToken] = $this->seedSession($editor['id']);

        $adminDashboard = $this->controller->root(
            $this->get('/admin', $adminToken)
        );
        self::assertSame(200, $adminDashboard->status());
        self::assertStringContainsString(
            'href="/admin/users">Editores</a>',
            $adminDashboard->body()
        );
        $this->assertHtmlResponse($adminDashboard);

        $editorDashboard = $this->controller->root(
            $this->get('/admin', $editorToken)
        );
        self::assertSame(200, $editorDashboard->status());
        self::assertStringNotContainsString(
            '/admin/users',
            $editorDashboard->body()
        );
        $this->assertHtmlResponse($editorDashboard);

        $allowed = $this->controller->users(
            $this->get('/admin/users', $adminToken)
        );
        self::assertSame(200, $allowed->status());
        self::assertStringContainsString('Listado de editores', $allowed->body());
        $this->assertHtmlResponse($allowed);

        $denied = $this->controller->users(
            $this->get('/admin/users', $editorToken)
        );
        self::assertSame(403, $denied->status());
        self::assertSame('Forbidden', $denied->body());
        $this->assertPlainResponse($denied);
    }

    public function testDashboardFiltersModuleNavigationWithLiveCapability(): void
    {
        $this->pdo->exec(
            "INSERT INTO ls_webadmin_capabilities "
            . "(module_id, code, label_key, is_delegable) VALUES "
            . "('feature', 'feature.dashboard.view', "
            . "'feature.dashboard.view', 0)"
        );
        $this->pdo->exec(
            'INSERT INTO ls_webadmin_role_capabilities '
            . '(role_id, capability_id) '
            . 'SELECT r.id, c.id FROM ls_webadmin_roles r '
            . 'CROSS JOIN ls_webadmin_capabilities c '
            . "WHERE r.code = 'site_admin' "
            . "AND c.code = 'feature.dashboard.view'"
        );

        $admin = $this->seedUser(
            'navigation-admin@example.test',
            'Administradora',
            'active',
            'site_admin'
        );
        $editor = $this->seedUser(
            'navigation-editor@example.test',
            'Editora',
            'active',
            'editor'
        );
        [$adminToken] = $this->seedSession($admin['id']);
        [$editorToken] = $this->seedSession($editor['id']);

        $visible = $this->controller->root(
            $this->get('/admin', $adminToken)
        );
        self::assertStringContainsString(
            'href="/admin/feature">Funcionalidad privada</a>',
            $visible->body()
        );

        $hidden = $this->controller->root(
            $this->get('/admin', $editorToken)
        );
        self::assertStringNotContainsString(
            '/admin/feature',
            $hidden->body()
        );

        $this->pdo->exec(
            'DELETE FROM ls_webadmin_role_capabilities '
            . 'WHERE capability_id = (SELECT id '
            . 'FROM ls_webadmin_capabilities '
            . "WHERE code = 'feature.dashboard.view')"
        );
        $revokedLive = $this->controller->root(
            $this->get('/admin', $adminToken)
        );
        self::assertStringNotContainsString(
            '/admin/feature',
            $revokedLive->body()
        );
    }

    public function testManagementHeadRequestsAreEmptyAndDoNotTouchState(): void
    {
        $admin = $this->seedAdmin();
        $target = $this->seedEditor('head-target@example.test');
        [$token] = $this->seedSession($admin['id']);
        $before = $this->databaseFingerprint();

        $responses = [
            $this->controller->root($this->head('/admin')),
            $this->controller->users($this->head(
                '/admin/users',
                ['after' => 'opaque-cursor']
            )),
            $this->controller->inviteEditorForm(
                $this->head('/admin/users/invite')
            ),
            $this->controller->editEditor($this->head(
                '/admin/users/edit',
                ['user' => $target['public_id']]
            )),
            $this->controller->usersUpdated(
                $this->head('/admin/users/updated')
            ),
        ];

        foreach ($responses as $response) {
            self::assertSame(200, $response->status());
            self::assertSame('', $response->body());
            $this->assertHtmlResponse($response);
        }
        self::assertSame($before, $this->databaseFingerprint());
        self::assertNotSame('', $token);
    }

    public function testInvalidManagementSessionExpiresOnlyTheAuthCookie(): void
    {
        $staleToken = $this->tokens->generate();

        $response = $this->controller->users(
            $this->get('/admin/users', $staleToken)
        );

        self::assertSame(303, $response->status());
        self::assertSame('', $response->body());
        self::assertSame('/admin/login', $response->headers()['Location']);
        $cookies = $response->headerValues('Set-Cookie');
        self::assertCount(1, $cookies);
        self::assertStringStartsWith('LS_WEBADMIN_SID=;', $cookies[0]);
        self::assertStringContainsString('; Path=/admin', $cookies[0]);
        self::assertStringContainsString('; Max-Age=0', $cookies[0]);
        self::assertStringContainsString('; Secure', $cookies[0]);
        self::assertStringContainsString('; HttpOnly', $cookies[0]);
        self::assertStringContainsString('; SameSite=Strict', $cookies[0]);
        self::assertStringNotContainsString('; Domain=', $cookies[0]);
        self::assertStringNotContainsString('LS_WEBADMIN_PREAUTH', $cookies[0]);
        self::assertStringNotContainsString('LS_WEBADMIN_ACTION', $cookies[0]);
        $this->assertCommonSecurityHeaders($response);
        self::assertSame(
            "default-src 'none'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'",
            $response->headers()['Content-Security-Policy']
        );
    }

    public function testEditorListPaginatesAndDetailEscapesStoredValues(): void
    {
        $admin = $this->seedAdmin();
        [$token] = $this->seedSession($admin['id']);
        $malicious = $this->seedUser(
            'evil<script>@example.test',
            '<img src=x onerror="alert(1)"> & equipo',
            'active',
            'editor'
        );
        $last = $malicious;
        for ($index = 2; $index <= 51; $index++) {
            $last = $this->seedEditor(sprintf(
                'editor-%02d@example.test',
                $index
            ));
        }

        $first = $this->controller->users(
            $this->get('/admin/users', $token)
        );
        self::assertSame(200, $first->status());
        self::assertSame(
            50,
            substr_count($first->body(), '>Gestionar editor</a>')
        );
        self::assertStringNotContainsString('<script>', $first->body());
        self::assertStringNotContainsString('<img', $first->body());
        self::assertStringContainsString(
            'evil&lt;script&gt;@example.test',
            $first->body()
        );
        self::assertStringContainsString(
            '&lt;img src=x onerror=&quot;alert(1)&quot;&gt; &amp; equipo',
            $first->body()
        );
        self::assertSame(1, preg_match(
            '/href="\/admin\/users\?after=([^"&]+)"/',
            $first->body(),
            $cursorMatch
        ));
        $cursor = rawurldecode($cursorMatch[1]);
        self::assertNotSame('', $cursor);
        $this->assertHtmlResponse($first);

        $second = $this->controller->users($this->get(
            '/admin/users',
            $token,
            ['after' => $cursor]
        ));
        self::assertSame(200, $second->status());
        self::assertStringContainsString($last['email'], $second->body());
        self::assertSame(
            1,
            substr_count($second->body(), '>Gestionar editor</a>')
        );
        self::assertStringNotContainsString('rel="next"', $second->body());

        $detail = $this->controller->editEditor($this->get(
            '/admin/users/edit',
            $token,
            ['user' => $malicious['public_id']]
        ));
        self::assertSame(200, $detail->status());
        self::assertStringNotContainsString('<script>', $detail->body());
        self::assertStringNotContainsString('<img', $detail->body());
        self::assertStringContainsString(
            'name="target" value="' . $malicious['public_id'] . '"',
            $detail->body()
        );
        self::assertStringNotContainsString(
            'name="role"',
            $detail->body()
        );
        self::assertStringNotContainsString(
            'value="' . $malicious['id'] . '"',
            $detail->body()
        );
        $this->assertHtmlResponse($detail);

        $invalidCursor = $this->controller->users($this->get(
            '/admin/users',
            $token,
            ['after' => 'not-a-valid-cursor']
        ));
        self::assertSame(400, $invalidCursor->status());
        self::assertSame('Bad request', $invalidCursor->body());
    }

    public function testCapabilityFormsRenderClearLabelsForBlogAndMedia(): void
    {
        $capabilities = [
            [
                'webadmin',
                'webadmin.media.view',
                'webadmin.capabilities.media_view',
                'Consultar la biblioteca de medios',
            ],
            [
                'webadmin',
                'webadmin.media.upload',
                'webadmin.capabilities.media_upload',
                'Subir imágenes a la biblioteca',
            ],
            [
                'blog',
                'blog.articles.view',
                'blog.capabilities.articles_view',
                'Consultar artículos del Blog',
            ],
            [
                'blog',
                'blog.articles.edit',
                'blog.capabilities.articles_edit',
                'Crear y editar artículos del Blog',
            ],
            [
                'blog',
                'blog.articles.publish',
                'blog.capabilities.articles_publish',
                'Publicar y retirar artículos del Blog',
            ],
            [
                'blog',
                'blog.categories.view',
                'blog.capabilities.categories_view',
                'Consultar categorías del Blog',
            ],
            [
                'blog',
                'blog.categories.edit',
                'blog.capabilities.categories_edit',
                'Crear y editar categorías del Blog',
            ],
        ];
        $insert = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_capabilities '
            . '(module_id, code, label_key, is_delegable) '
            . 'VALUES (:module_id, :code, :label_key, 1)'
        );
        $grant = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_role_capabilities '
            . '(role_id, capability_id) SELECT r.id, c.id '
            . 'FROM ls_webadmin_roles r CROSS JOIN '
            . 'ls_webadmin_capabilities c WHERE r.code = :role '
            . 'AND c.code = :code'
        );
        foreach ($capabilities as [$module, $code, $labelKey]) {
            self::assertTrue($insert->execute([
                'module_id' => $module,
                'code' => $code,
                'label_key' => $labelKey,
            ]));
            self::assertTrue($grant->execute([
                'role' => 'site_admin',
                'code' => $code,
            ]));
            self::assertSame(1, $grant->rowCount());
        }

        $admin = $this->seedAdmin();
        $target = $this->seedEditor('labels-target@example.test');
        [$token] = $this->seedSession($admin['id']);
        $responses = [
            $this->controller->inviteEditorForm(
                $this->get('/admin/users/invite', $token)
            ),
            $this->controller->editEditor($this->get(
                '/admin/users/edit',
                $token,
                ['user' => $target['public_id']]
            )),
        ];

        foreach ($responses as $response) {
            self::assertSame(200, $response->status());
            foreach ($capabilities as [, $code, $labelKey, $label]) {
                self::assertStringContainsString(
                    'value="' . $code . '"',
                    $response->body()
                );
                self::assertStringContainsString($label, $response->body());
                self::assertStringNotContainsString(
                    $labelKey,
                    $response->body()
                );
            }
            $this->assertHtmlResponse($response);
        }
    }

    public function testInvitationUsesPrgAndCreatesACompletePendingIdentity(): void
    {
        $admin = $this->seedAdmin();
        [$token] = $this->seedSession($admin['id']);
        $form = $this->controller->inviteEditorForm(
            $this->get('/admin/users/invite', $token)
        );
        self::assertSame(200, $form->status());
        self::assertStringContainsString('name="email"', $form->body());
        self::assertStringContainsString('name="display_name"', $form->body());
        self::assertStringContainsString(
            'value="' . self::VIEW . '"',
            $form->body()
        );
        $csrf = $this->hiddenCsrf($form->body());
        $this->assertHtmlResponse($form);

        $response = $this->controller->inviteEditor($this->post(
            '/admin/users/invite',
            $token,
            [
                'csrf' => $csrf,
                'display_name' => 'Nueva editora',
                'email' => 'new-editor@example.test',
                'capabilities' => [self::VIEW],
            ]
        ));
        $this->assertRedirect($response, '/admin/users/updated');

        $user = $this->userByEmail('new-editor@example.test');
        self::assertSame('Nueva editora', $user['display_name']);
        self::assertSame('invited', $user['status']);
        self::assertNull($user['activated_at']);
        self::assertSame($admin['id'], (int) $user['created_by_user_id']);
        $credential = $this->row(
            'SELECT password_hash, password_set_at FROM '
            . 'ls_webadmin_credentials WHERE user_id = :user_id',
            ['user_id' => $user['id']]
        );
        self::assertNull($credential['password_hash']);
        self::assertNull($credential['password_set_at']);
        self::assertSame(['editor'], $this->rolesFor((int) $user['id']));
        self::assertSame([self::VIEW], $this->directCapabilitiesFor(
            (int) $user['id']
        ));
        $outbox = $this->row(
            'SELECT kind, locale, status, attempts FROM ls_webadmin_outbox '
            . 'WHERE user_id = :user_id',
            ['user_id' => $user['id']]
        );
        self::assertSame('invite', $outbox['kind']);
        self::assertSame('und', $outbox['locale']);
        self::assertSame('pending', $outbox['status']);
        self::assertSame(0, (int) $outbox['attempts']);
        self::assertSame(1, $this->auditCount('webadmin.editor.invited'));

        $updated = $this->controller->usersUpdated(
            $this->get('/admin/users/updated', $token)
        );
        self::assertSame(200, $updated->status());
        self::assertStringContainsString(
            'La operaci&oacute;n se ha completado correctamente.',
            $updated->body()
        );
        self::assertStringNotContainsString(
            'new-editor@example.test',
            $updated->body()
        );
        $this->assertHtmlResponse($updated);
    }

    public function testExistingAndInvalidInvitationResponsesDoNotEnumerate(): void
    {
        $admin = $this->seedAdmin();
        $this->seedEditor('taken@example.test');
        [$token, $csrf] = $this->seedSession($admin['id']);
        $beforeUsers = $this->tableCount('users');

        $existing = $this->controller->inviteEditor($this->post(
            '/admin/users/invite',
            $token,
            [
                'csrf' => $csrf,
                'display_name' => 'Nombre v&aacute;lido',
                'email' => 'taken@example.test',
                'capabilities' => [self::VIEW],
            ]
        ));
        $invalid = $this->controller->inviteEditor($this->post(
            '/admin/users/invite',
            $token,
            [
                'csrf' => $csrf,
                'display_name' => 'Nombre v&aacute;lido',
                'email' => 'not-an-email',
                'capabilities' => [self::VIEW],
            ]
        ));

        self::assertSame(422, $existing->status());
        self::assertSame(422, $invalid->status());
        self::assertSame($existing->body(), $invalid->body());
        self::assertSame($existing->headers(), $invalid->headers());
        self::assertStringContainsString('role="alert"', $existing->body());
        self::assertStringNotContainsString('taken@example.test', $existing->body());
        self::assertStringNotContainsString('not-an-email', $invalid->body());
        self::assertSame($beforeUsers, $this->tableCount('users'));
        self::assertSame(0, $this->tableCount('outbox'));
        $this->assertHtmlResponse($existing);
        $this->assertHtmlResponse($invalid);
    }

    public function testInviteOnlyActorCanReachGenericCompletionWithoutView(): void
    {
        $admin = $this->seedAdmin();
        $actor = $this->seedEditor('invite-only@example.test');
        $this->assignCapability($actor['id'], self::INVITE, $admin['id']);
        [$token, $csrf] = $this->seedSession($actor['id']);

        $response = $this->controller->inviteEditor($this->post(
            '/admin/users/invite',
            $token,
            [
                'csrf' => $csrf,
                'display_name' => 'Editora invitada',
                'email' => 'invite-only-target@example.test',
            ]
        ));

        $this->assertRedirect($response, '/admin/users/updated');
        $this->assertCompletionReturnsToDashboard($token);
    }

    public function testSuspendOnlyActorCanReachGenericCompletionWithoutView(): void
    {
        $admin = $this->seedAdmin();
        $actor = $this->seedEditor('suspend-only@example.test');
        $target = $this->seedEditor('suspend-only-target@example.test');
        $this->assignCapability($actor['id'], self::SUSPEND, $admin['id']);
        [$token, $csrf] = $this->seedSession($actor['id']);

        $response = $this->controller->suspendEditor($this->post(
            '/admin/users/suspend',
            $token,
            ['csrf' => $csrf, 'target' => $target['public_id']]
        ));

        $this->assertRedirect($response, '/admin/users/updated');
        self::assertSame(
            'suspended',
            $this->userById($target['id'])['status']
        );
        $this->assertCompletionReturnsToDashboard($token);
    }

    public function testCapabilityManagerCanReachGenericCompletionWithoutView(): void
    {
        $admin = $this->seedAdmin();
        $actor = $this->seedEditor('capability-only@example.test');
        $target = $this->seedEditor('capability-only-target@example.test');
        $this->assignCapability($actor['id'], self::MANAGE, $admin['id']);
        [$token, $csrf] = $this->seedSession($actor['id']);

        $response = $this->controller->replaceEditorCapabilities($this->post(
            '/admin/users/capabilities',
            $token,
            ['csrf' => $csrf, 'target' => $target['public_id']]
        ));

        $this->assertRedirect($response, '/admin/users/updated');
        $this->assertCompletionReturnsToDashboard($token);
    }

    public function testCapabilityReplacementPreservesOutOfScopeAssignments(): void
    {
        $admin = $this->seedAdmin();
        $target = $this->seedEditor('capabilities@example.test');
        $this->assignCapability($target['id'], self::VIEW, $admin['id']);
        $this->assignCapability($target['id'], self::AUDIT, $admin['id']);
        [$token, $csrf] = $this->seedSession($admin['id']);

        $remove = $this->controller->replaceEditorCapabilities($this->post(
            '/admin/users/capabilities',
            $token,
            ['csrf' => $csrf, 'target' => $target['public_id']]
        ));
        $this->assertRedirect($remove, '/admin/users/updated');
        self::assertSame(
            [self::AUDIT],
            $this->directCapabilitiesFor($target['id'])
        );

        $add = $this->controller->replaceEditorCapabilities($this->post(
            '/admin/users/capabilities',
            $token,
            [
                'csrf' => $csrf,
                'target' => $target['public_id'],
                'capabilities' => [self::VIEW],
            ]
        ));
        $this->assertRedirect($add, '/admin/users/updated');
        self::assertSame(
            [self::AUDIT, self::VIEW],
            $this->directCapabilitiesFor($target['id'])
        );

        $unchanged = $this->controller->replaceEditorCapabilities($this->post(
            '/admin/users/capabilities',
            $token,
            [
                'csrf' => $csrf,
                'target' => $target['public_id'],
                'capabilities' => [self::VIEW],
            ]
        ));
        $this->assertRedirect($unchanged, '/admin/users/updated');
        self::assertSame(3, $this->auditCount(
            'webadmin.editor.capabilities.replaced'
        ));
    }

    public function testSuspendResumeAndResendMaintainLifecycleInvariants(): void
    {
        $admin = $this->seedAdmin();
        $active = $this->seedEditor('active-target@example.test');
        $invited = $this->seedUser(
            'pending-target@example.test',
            'Pendiente',
            'invited',
            'editor'
        );
        [$token, $csrf] = $this->seedSession($admin['id']);
        [$targetToken] = $this->seedSession($active['id']);

        $suspend = $this->controller->suspendEditor($this->post(
            '/admin/users/suspend',
            $token,
            ['csrf' => $csrf, 'target' => $active['public_id']]
        ));
        $this->assertRedirect($suspend, '/admin/users/updated');
        $suspended = $this->userById($active['id']);
        self::assertSame('suspended', $suspended['status']);
        self::assertSame(2, (int) $suspended['auth_version']);
        self::assertNotNull($suspended['suspended_at']);
        self::assertNotNull($this->sessionByToken($targetToken)['revoked_at']);

        $resume = $this->controller->resumeEditor($this->post(
            '/admin/users/resume',
            $token,
            ['csrf' => $csrf, 'target' => $active['public_id']]
        ));
        $this->assertRedirect($resume, '/admin/users/updated');
        $resumed = $this->userById($active['id']);
        self::assertSame('active', $resumed['status']);
        self::assertSame(3, (int) $resumed['auth_version']);
        self::assertNull($resumed['suspended_at']);
        self::assertSame(0, $this->outboxCountFor($active['id']));

        $resend = $this->controller->resendEditorInvitation($this->post(
            '/admin/users/invite/resend',
            $token,
            ['csrf' => $csrf, 'target' => $invited['public_id']]
        ));
        $this->assertRedirect($resend, '/admin/users/updated');
        self::assertSame(1, $this->outboxCountFor($invited['id']));

        $alreadyQueued = $this->controller->resendEditorInvitation($this->post(
            '/admin/users/invite/resend',
            $token,
            ['csrf' => $csrf, 'target' => $invited['public_id']]
        ));
        $this->assertRedirect($alreadyQueued, '/admin/users/updated');
        self::assertSame(1, $this->outboxCountFor($invited['id']));
        self::assertSame('invited', $this->userById($invited['id'])['status']);
    }

    public function testProtectedAndSelfTargetsNeverExposeOrApplyActions(): void
    {
        $admin = $this->seedAdmin();
        $protected = $this->seedUser(
            'protected@example.test',
            'Protegida',
            'active',
            'site_admin'
        );
        $this->assignRole($protected['id'], 'editor', $admin['id']);
        [$adminToken, $adminCsrf] = $this->seedSession($admin['id']);

        $protectedDetail = $this->controller->editEditor($this->get(
            '/admin/users/edit',
            $adminToken,
            ['user' => $protected['public_id']]
        ));
        self::assertSame(404, $protectedDetail->status());
        $protectedSuspend = $this->controller->suspendEditor($this->post(
            '/admin/users/suspend',
            $adminToken,
            [
                'csrf' => $adminCsrf,
                'target' => $protected['public_id'],
            ]
        ));
        self::assertSame(403, $protectedSuspend->status());
        self::assertSame(
            'active',
            $this->userById($protected['id'])['status']
        );
        self::assertSame(
            ['editor', 'site_admin'],
            $this->rolesFor($protected['id'])
        );

        $self = $this->seedUser(
            'self-editor@example.test',
            'Autogestora',
            'active',
            'editor'
        );
        foreach ([self::VIEW, self::INVITE, self::SUSPEND, self::MANAGE] as $code) {
            $this->assignCapability($self['id'], $code, $admin['id']);
        }
        [$selfToken, $selfCsrf] = $this->seedSession($self['id']);
        $selfDetail = $this->controller->editEditor($this->get(
            '/admin/users/edit',
            $selfToken,
            ['user' => $self['public_id']]
        ));
        self::assertSame(200, $selfDetail->status());
        self::assertStringContainsString(
            'No tienes acciones disponibles para este editor.',
            $selfDetail->body()
        );
        self::assertStringNotContainsString(
            'action="/admin/users/capabilities"',
            $selfDetail->body()
        );
        self::assertStringNotContainsString(
            'action="/admin/users/suspend"',
            $selfDetail->body()
        );
        self::assertStringNotContainsString('name="target"', $selfDetail->body());

        $selfReplace = $this->controller->replaceEditorCapabilities($this->post(
            '/admin/users/capabilities',
            $selfToken,
            [
                'csrf' => $selfCsrf,
                'target' => $self['public_id'],
                'capabilities' => [self::VIEW],
            ]
        ));
        self::assertSame(403, $selfReplace->status());
        $selfSuspend = $this->controller->suspendEditor($this->post(
            '/admin/users/suspend',
            $selfToken,
            ['csrf' => $selfCsrf, 'target' => $self['public_id']]
        ));
        self::assertSame(403, $selfSuspend->status());
        self::assertSame('active', $this->userById($self['id'])['status']);
    }

    public function testMalformedCsrfCapabilitiesAndInputsFailClosed(): void
    {
        $admin = $this->seedAdmin();
        $target = $this->seedEditor('input-target@example.test');
        [$token, $csrf] = $this->seedSession($admin['id']);
        $beforeUsers = $this->tableCount('users');
        $beforeOutbox = $this->tableCount('outbox');

        $badCsrf = $this->controller->suspendEditor($this->post(
            '/admin/users/suspend',
            $token,
            ['csrf' => 'wrong-csrf', 'target' => $target['public_id']]
        ));
        self::assertSame(403, $badCsrf->status());

        $associative = $this->controller->inviteEditor($this->post(
            '/admin/users/invite',
            $token,
            [
                'csrf' => $csrf,
                'display_name' => 'Nueva',
                'email' => 'assoc@example.test',
                'capabilities' => ['bad' => self::VIEW],
            ]
        ));
        self::assertSame(400, $associative->status());

        $duplicates = $this->controller->inviteEditor($this->post(
            '/admin/users/invite',
            $token,
            [
                'csrf' => $csrf,
                'display_name' => 'Nueva',
                'email' => 'duplicate@example.test',
                'capabilities' => [self::VIEW, self::VIEW],
            ]
        ));
        self::assertSame(400, $duplicates->status());

        $extraField = $this->controller->inviteEditor($this->post(
            '/admin/users/invite',
            $token,
            [
                'csrf' => $csrf,
                'display_name' => 'Nueva',
                'email' => 'extra@example.test',
                'role' => 'site_admin',
            ]
        ));
        self::assertSame(400, $extraField->status());

        $unknownCapability = $this->controller->inviteEditor($this->post(
            '/admin/users/invite',
            $token,
            [
                'csrf' => $csrf,
                'display_name' => 'Nueva',
                'email' => 'scope@example.test',
                'capabilities' => ['blog.posts.manage'],
            ]
        ));
        self::assertSame(403, $unknownCapability->status());

        $invalidTarget = $this->controller->suspendEditor($this->post(
            '/admin/users/suspend',
            $token,
            ['csrf' => $csrf, 'target' => 'not-a-uuid']
        ));
        self::assertSame(400, $invalidTarget->status());

        $postWithQuery = $this->controller->suspendEditor($this->post(
            '/admin/users/suspend',
            $token,
            ['csrf' => $csrf, 'target' => $target['public_id']],
            ['next' => '/admin']
        ));
        self::assertSame(400, $postWithQuery->status());

        foreach ([
            $badCsrf,
            $associative,
            $duplicates,
            $extraField,
            $unknownCapability,
            $invalidTarget,
            $postWithQuery,
        ] as $response) {
            self::assertSame([], $response->headerValues('Set-Cookie'));
            $this->assertCommonSecurityHeaders($response);
        }
        self::assertSame($beforeUsers, $this->tableCount('users'));
        self::assertSame($beforeOutbox, $this->tableCount('outbox'));
        self::assertSame('active', $this->userById($target['id'])['status']);
    }

    /** @return array{id: int, public_id: string, email: string} */
    private function seedAdmin(): array
    {
        return $this->seedUser(
            'site-admin@example.test',
            'Administradora del sitio',
            'active',
            'site_admin'
        );
    }

    private function assertCompletionReturnsToDashboard(string $token): void
    {
        $response = $this->controller->usersUpdated(
            $this->get('/admin/users/updated', $token)
        );

        self::assertSame(200, $response->status());
        self::assertStringContainsString(
            'La operaci&oacute;n se ha completado correctamente.',
            $response->body()
        );
        self::assertStringContainsString('href="/admin"', $response->body());
        self::assertStringNotContainsString(
            'href="/admin/users"',
            $response->body()
        );
        self::assertStringNotContainsString('@example.test', $response->body());
        $this->assertHtmlResponse($response);
    }

    /** @return array{id: int, public_id: string, email: string} */
    private function seedEditor(string $email): array
    {
        return $this->seedUser(
            $email,
            'Editor ' . $this->userSequence,
            'active',
            'editor'
        );
    }

    /**
     * @return array{id: int, public_id: string, email: string}
     */
    private function seedUser(
        string $email,
        string $displayName,
        string $status,
        string $role,
        bool $activatedBeforeSuspension = true
    ): array {
        $publicId = sprintf(
            '81000000-0000-4000-8000-%012x',
            $this->userSequence++
        );
        $activatedAt = $status === 'active'
            || ($status === 'suspended' && $activatedBeforeSuspension)
                ? self::NOW
                : null;
        $suspendedAt = $status === 'suspended' ? self::NOW : null;
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_users '
            . '(public_id, email_canonical, display_name, status, '
            . 'auth_version, invited_at, activated_at, suspended_at, '
            . 'created_at, updated_at) VALUES (:public_id, :email, '
            . ':display_name, :status, 1, :invited_at, :activated_at, '
            . ':suspended_at, :created_at, :updated_at)'
        );
        $statement->execute([
            'public_id' => $publicId,
            'email' => strtolower($email),
            'display_name' => $displayName,
            'status' => $status,
            'invited_at' => self::NOW,
            'activated_at' => $activatedAt,
            'suspended_at' => $suspendedAt,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $credential = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_credentials '
            . '(user_id, password_hash, password_set_at, created_at, '
            . 'updated_at) VALUES (:user_id, :password_hash, '
            . ':password_set_at, :created_at, :updated_at)'
        );
        $passwordHash = $activatedAt === null ? null : self::$passwordHash;
        $credential->execute([
            'user_id' => $id,
            'password_hash' => $passwordHash,
            'password_set_at' => $passwordHash === null ? null : self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->assignRole($id, $role);

        return ['id' => $id, 'public_id' => $publicId, 'email' => $email];
    }

    private function assignRole(
        int $userId,
        string $role,
        ?int $assignedBy = null
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_user_roles '
            . '(user_id, role_id, assigned_by_user_id, source, created_at) '
            . 'SELECT :user_id, id, :assigned_by, :source, :created_at '
            . 'FROM ls_webadmin_roles WHERE code = :role'
        );
        $statement->execute([
            'user_id' => $userId,
            'assigned_by' => $assignedBy,
            'source' => $assignedBy === null ? 'bootstrap' : 'manual',
            'created_at' => self::NOW,
            'role' => $role,
        ]);
        self::assertSame(1, $statement->rowCount());
    }

    private function assignCapability(
        int $userId,
        string $capability,
        ?int $assignedBy = null
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_user_capabilities '
            . '(user_id, capability_id, assigned_by_user_id, created_at) '
            . 'SELECT :user_id, id, :assigned_by, :created_at '
            . 'FROM ls_webadmin_capabilities WHERE code = :capability'
        );
        $statement->execute([
            'user_id' => $userId,
            'assigned_by' => $assignedBy,
            'created_at' => self::NOW,
            'capability' => $capability,
        ]);
        self::assertSame(1, $statement->rowCount());
    }

    /** @return array{string, string} */
    private function seedSession(int $userId): array
    {
        $token = $this->tokens->generate();
        $csrf = $this->securityKey->deriveToken('csrf.session', $token);
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
                '83000000-0000-4000-8000-%012x',
                $this->sessionSequence++
            ),
            'user_id' => $userId,
            'token_hash' => $this->tokens->hashForStorage($token),
            'csrf_hash' => $this->tokens->hashForStorage($csrf),
            'auth_version' => $authVersion,
            'created_at' => '2026-08-01 07:59:00.000000',
            'last_seen_at' => '2026-08-01 07:59:00.000000',
            'idle_expires_at' => '2026-08-01 08:30:00.000000',
            'absolute_expires_at' => '2026-08-01 10:00:00.000000',
        ]);

        return [$token, $csrf];
    }

    /** @param array<string, string> $query */
    private function get(
        string $path,
        string $token,
        array $query = []
    ): Request {
        return $this->request('GET', $path, $token, $query);
    }

    /** @param array<string, string> $query */
    private function head(string $path, array $query = []): Request
    {
        return $this->request('HEAD', $path, '', $query);
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, string> $query
     */
    private function post(
        string $path,
        string $token,
        array $form,
        array $query = []
    ): Request {
        return $this->request('POST', $path, $token, $query, $form);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $form
     */
    private function request(
        string $method,
        string $path,
        string $token,
        array $query = [],
        array $form = []
    ): Request {
        $uri = $path . ($query === []
            ? ''
            : '?' . http_build_query($query));
        $cookies = $token === ''
            ? []
            : ['LS_WEBADMIN_SID' => $token];
        $headers = ['User-Agent' => 'WebAdmin management HTTP test'];
        if ($method === 'POST') {
            $headers['Content-Type'] =
                'application/x-www-form-urlencoded';
        }

        return Request::fromInput(
            [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => $uri,
                'REMOTE_ADDR' => '192.0.2.90',
                'HTTPS' => 'on',
            ],
            $query,
            $form,
            $cookies,
            $headers
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

    private function assertHtmlResponse(Response $response): void
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
        self::assertSame('es', $response->headers()['Content-Language']);
        self::assertSame([], $response->headerValues('Set-Cookie'));
    }

    private function assertPlainResponse(Response $response): void
    {
        $this->assertCommonSecurityHeaders($response);
        self::assertSame(
            "default-src 'none'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'",
            $response->headers()['Content-Security-Policy']
        );
        self::assertSame(
            'text/plain; charset=utf-8',
            $response->headers()['Content-Type']
        );
        self::assertSame([], $response->headerValues('Set-Cookie'));
    }

    private function assertRedirect(Response $response, string $location): void
    {
        self::assertSame(303, $response->status());
        self::assertSame('', $response->body());
        self::assertSame($location, $response->headers()['Location']);
        $this->assertCommonSecurityHeaders($response);
        self::assertSame(
            "default-src 'none'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'",
            $response->headers()['Content-Security-Policy']
        );
        self::assertSame([], $response->headerValues('Set-Cookie'));
    }

    private function assertCommonSecurityHeaders(Response $response): void
    {
        self::assertSame(
            'no-store, no-cache, must-revalidate, max-age=0',
            $response->headers()['Cache-Control']
        );
        self::assertSame('no-cache', $response->headers()['Pragma']);
        self::assertSame(
            'noindex, nofollow, noarchive',
            $response->headers()['X-Robots-Tag']
        );
        self::assertSame('no-referrer', $response->headers()['Referrer-Policy']);
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
        self::assertSame('DENY', $response->headers()['X-Frame-Options']);
        self::assertSame(
            'same-origin',
            $response->headers()['Cross-Origin-Opener-Policy']
        );
        self::assertSame(
            'same-origin',
            $response->headers()['Cross-Origin-Resource-Policy']
        );
    }

    /** @return array<string, mixed> */
    private function userById(int $userId): array
    {
        return $this->row(
            'SELECT * FROM ls_webadmin_users WHERE id = :id',
            ['id' => $userId]
        );
    }

    /** @return array<string, mixed> */
    private function userByEmail(string $email): array
    {
        return $this->row(
            'SELECT * FROM ls_webadmin_users WHERE email_canonical = :email',
            ['email' => strtolower($email)]
        );
    }

    /** @return array<string, mixed> */
    private function sessionByToken(string $token): array
    {
        return $this->row(
            'SELECT * FROM ls_webadmin_sessions WHERE token_hash = :hash',
            ['hash' => $this->tokens->hashForStorage($token)]
        );
    }

    /** @param array<string, scalar|null> $params @return array<string, mixed> */
    private function row(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    /** @return list<string> */
    private function rolesFor(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.code FROM ls_webadmin_user_roles ur '
            . 'JOIN ls_webadmin_roles r ON r.id = ur.role_id '
            . 'WHERE ur.user_id = :user_id ORDER BY r.code'
        );
        $statement->execute(['user_id' => $userId]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    private function directCapabilitiesFor(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.code FROM ls_webadmin_user_capabilities uc '
            . 'JOIN ls_webadmin_capabilities c ON c.id = uc.capability_id '
            . 'WHERE uc.user_id = :user_id ORDER BY c.code'
        );
        $statement->execute(['user_id' => $userId]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function auditCount(string $eventCode): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ls_webadmin_audit_log '
            . 'WHERE event_code = :event_code'
        );
        $statement->execute(['event_code' => $eventCode]);

        return (int) $statement->fetchColumn();
    }

    private function outboxCountFor(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ls_webadmin_outbox WHERE user_id = :user_id'
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

    private function databaseFingerprint(): string
    {
        $state = [];
        foreach ([
            'users',
            'credentials',
            'user_roles',
            'user_capabilities',
            'sessions',
            'action_tokens',
            'outbox',
            'audit_log',
        ] as $table) {
            $state[$table] = $this->pdo->query(
                'SELECT * FROM "ls_webadmin_' . $table . '" ORDER BY 1'
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        return json_encode($state, JSON_THROW_ON_ERROR);
    }
}

final class WebAdminUserManagementHttpClock implements ClockInterface
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
            throw new RuntimeException('Invalid management test timestamp.');
        }
        $this->now = $parsed;
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class WebAdminUserManagementHttpUuidGenerator implements
    UuidGeneratorInterface
{
    private int $sequence = 1;

    public function generateV4(): string
    {
        return sprintf(
            '82000000-0000-4000-8000-%012x',
            $this->sequence++
        );
    }
}
