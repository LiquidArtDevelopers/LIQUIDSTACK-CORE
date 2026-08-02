<?php

declare(strict_types=1);

use App\Core\WebAdmin\Http\WebAdminHtmlRenderer;
use App\Core\WebAdmin\Http\WebAdminPageDocumentRenderer;
use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;
use PHPUnit\Framework\TestCase;

final class WebAdminHtmlRendererTest extends TestCase
{
    private WebAdminHtmlRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new WebAdminHtmlRenderer();
    }

    public function testEveryScreenUsesTheModuleOwnedDocumentShell(): void
    {
        $html = $this->renderer->login('/admin', 'csrf', false);

        self::assertStringContainsString('<body class="webadmin">', $html);
        self::assertStringContainsString(
            '<link rel="stylesheet" href="'
                . WebAdminPageDocumentRenderer::STYLESHEET_PATH . '">',
            $html
        );
        self::assertStringContainsString(
            '<script src="' . WebAdminPageDocumentRenderer::SCRIPT_PATH
                . '" defer></script>',
            $html
        );
        self::assertStringContainsString(
            '<meta name="robots" content="noindex,nofollow,noarchive">',
            $html
        );
    }

    public function testCredentialScreensUseTheCanonicalAuthResources(): void
    {
        $login = $this->renderer->login('/admin', 'csrf', false);
        $recover = $this->renderer->forgotPassword('/admin', 'csrf');
        $password = $this->renderer->credentialAction(
            '/admin',
            'password_reset',
            'csrf',
            false
        );

        self::assertStringContainsString('class="artAuth01 ', $login);
        self::assertStringContainsString('moduleFormAuthLogin01', $login);
        self::assertStringContainsString('data-auth-password-toggle', $login);
        self::assertStringContainsString('moduleFormAuthRecover01', $recover);
        self::assertStringContainsString('moduleFormAuthPassword01', $password);
        self::assertSame(2, substr_count(
            $password,
            'data-auth-password-toggle data-auth-label-show='
        ));
        foreach ([$login, $recover, $password] as $html) {
            self::assertDoesNotMatchRegularExpression(
                '/\{[A-Za-z][A-Za-z0-9-]*\}/',
                $html
            );
            self::assertStringNotContainsString('data-lang=', $html);
        }
    }

    public function testEveryViewIsACompleteSemanticPrivateDocument(): void
    {
        $documents = [
            $this->renderer->login('/admin', 'csrf', false),
            $this->renderer->dashboard('/admin', 'csrf'),
            $this->renderer->editorList(
                '/admin',
                [$this->editor()],
                null,
                true
            ),
            $this->renderer->editorInvite(
                '/admin',
                'csrf',
                $this->capabilities()
            ),
            $this->renderer->editorDetail(
                '/admin',
                'csrf',
                $this->editor(),
                $this->capabilities(),
                true,
                true,
                true
            ),
            $this->renderer->editorOperationCompleted('/admin'),
            $this->renderer->forgotPassword('/admin', 'csrf'),
            $this->renderer->forgotPasswordSent('/admin'),
            $this->renderer->credentialAction(
                '/admin',
                'invite',
                'csrf',
                false
            ),
            $this->renderer->credentialAction(
                '/admin',
                'password_reset',
                'csrf',
                false
            ),
            $this->renderer->actionUnavailable('/admin'),
        ];

        foreach ($documents as $html) {
            self::assertStringStartsWith(
                '<!doctype html><html lang="es"><head>',
                $html
            );
            self::assertStringContainsString('<meta charset="utf-8">', $html);
            self::assertStringContainsString(
                '<meta name="viewport" content="width=device-width,initial-scale=1">',
                $html
            );
            self::assertStringContainsString(
                '<meta name="robots" content="noindex,nofollow,noarchive">',
                $html
            );
            self::assertStringContainsString('<title>', $html);
            self::assertStringContainsString('<main><article ', $html);
            self::assertStringContainsString('aria-labelledby="', $html);
            self::assertStringContainsString('<h1 id="', $html);
            self::assertStringEndsWith(
                '<script src="'
                    . WebAdminPageDocumentRenderer::SCRIPT_PATH
                    . '" defer></script></body></html>',
                $html
            );
            self::assertStringNotContainsString('<style', $html);
            self::assertSame(1, substr_count($html, '<script '));
            self::assertStringNotContainsString('<script>', $html);
        }
    }

    public function testLoginEscapesEveryDynamicValueAndExposesAccessibleFeedback(): void
    {
        $basePath = '/admin"><script>alert("base")</script>/';
        $csrf = 'csrf"><img src=x onerror="csrf">&';
        $notice = '<svg onload="notice">Aviso & "seguro"</svg>';

        $html = $this->renderer->login(
            $basePath,
            $csrf,
            true,
            $notice
        );

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<svg', $html);
        self::assertStringContainsString(
            'action="/admin&quot;&gt;&lt;script&gt;alert(&quot;base&quot;)'
            . '&lt;/script&gt;/login"',
            $html
        );
        self::assertStringContainsString(
            'value="csrf&quot;&gt;&lt;img src=x onerror=&quot;csrf&quot;&gt;&amp;"',
            $html
        );
        self::assertStringContainsString(
            '&lt;svg onload=&quot;notice&quot;&gt;Aviso &amp; '
            . '&quot;seguro&quot;&lt;/svg&gt;',
            $html
        );
        self::assertStringContainsString(
            'id="webadmin-login-notice" role="status" aria-live="polite"',
            $html
        );
        self::assertStringContainsString(
            'id="webadmin-login-error" role="alert" aria-live="assertive"',
            $html
        );
        self::assertStringContainsString(
            'class="moduleFormAuth-feedback" aria-live="polite"',
            $html
        );
    }

    public function testLoginUsesExactActionsAndCredentialAutocomplete(): void
    {
        $html = $this->renderer->login('/admin/', 'csrf-value', false);

        self::assertMatchesRegularExpression(
            '/<form(?=[^>]*method="post")(?=[^>]*action="\/admin\/login")[^>]*>/',
            $html
        );
        self::assertStringContainsString(
            '<a href="/admin/password/forgot">',
            $html
        );
        self::assertMatchesRegularExpression(
            '/<input[^>]+name="email"[^>]+autocomplete="username"[^>]+required>/',
            $html
        );
        self::assertMatchesRegularExpression(
            '/<input[^>]+name="password"[^>]+autocomplete="current-password"'
            . '[^>]+required>/',
            $html
        );
        self::assertStringNotContainsString('minlength=', $html);
        self::assertStringNotContainsString('maxlength="1024"', $html);
        self::assertDoesNotMatchRegularExpression(
            '/<input[^>]+name="(?:email|password)"[^>]+value=/',
            $html
        );
        self::assertStringNotContainsString('webadmin-login-error', $html);
        self::assertStringNotContainsString('webadmin-login-notice', $html);
    }

    public function testDashboardPostsLogoutWithCsrf(): void
    {
        $html = $this->renderer->dashboard('/gestion', 'csrf-dashboard');

        self::assertStringContainsString(
            '<form method="post" action="/gestion/logout"',
            $html
        );
        self::assertStringContainsString(
            '<input type="hidden" name="csrf" value="csrf-dashboard">',
            $html
        );
        self::assertStringContainsString('Cerrar sesi&oacute;n', $html);
    }

    public function testDashboardOnlyExposesUserManagementWhenAuthorized(): void
    {
        $withoutUsers = $this->renderer->dashboard('/admin', 'csrf');
        $withUsers = $this->renderer->dashboard('/admin', 'csrf', true);

        self::assertStringNotContainsString('/admin/users', $withoutUsers);
        self::assertStringNotContainsString('Gestionar editores', $withoutUsers);
        self::assertStringContainsString(
            '<nav aria-label="Administraci&oacute;n">',
            $withUsers
        );
        self::assertStringContainsString(
            '<a href="/admin/users">Gestionar editores</a>',
            $withUsers
        );
    }

    public function testDashboardRendersAccessibleEscapedModuleNavigation(): void
    {
        $html = $this->renderer->dashboard(
            '/admin',
            'csrf',
            true,
            [new WebAdminNavigationItem(
                'blog',
                'Noticias & "publicaciones"',
                '/blog',
                'blog.articles.view'
            )]
        );

        self::assertSame(1, substr_count(
            $html,
            '<nav aria-label="Administraci&oacute;n">'
        ));
        self::assertStringContainsString(
            '<a href="/admin/users">Gestionar editores</a>',
            $html
        );
        self::assertStringContainsString(
            '<a href="/admin/blog">Noticias &amp; '
                . '&quot;publicaciones&quot;</a>',
            $html
        );
        self::assertStringNotContainsString(
            'Noticias & "publicaciones"',
            $html
        );

        $escapedPath = $this->renderer->dashboard(
            '/admin"><svg onload="base">',
            'csrf',
            false,
            [new WebAdminNavigationItem(
                'blog',
                'Noticias',
                '/blog',
                'blog.articles.view'
            )]
        );
        self::assertStringNotContainsString('<svg', $escapedPath);
        self::assertStringContainsString(
            'href="/admin&quot;&gt;&lt;svg onload=&quot;base&quot;&gt;/blog"',
            $escapedPath
        );
    }

    public function testDashboardRejectsInvalidNavigationPresentationInput(): void
    {
        try {
            $this->renderer->dashboard(
                '/admin',
                'csrf',
                false,
                ['item' => new stdClass()]
            );
            self::fail('Associative navigation must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->renderer->dashboard('/admin', 'csrf', false, [new stdClass()]);
    }

    public function testEditorListEscapesRowsAndUsesOnlyPublicIdentifiers(): void
    {
        $internalId = 'internal-uuid-must-never-leave-the-server';
        $publicId = '018f47a8-7e75-7cc4-9a67-85d4b38e0021';
        $html = $this->renderer->editorList(
            '/admin"><svg onload="base">',
            [[
                'id' => $internalId,
                'public_id' => $publicId,
                'email' => 'editor+<script>@example.test',
                'display_name' => '<img src=x onerror="name"> & equipo',
                'status' => 'active',
            ]],
            'cursor"><script>alert(1)</script>',
            true
        );

        self::assertStringNotContainsString($internalId, $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<svg', $html);
        self::assertStringContainsString(
            '&lt;img src=x onerror=&quot;name&quot;&gt; &amp; equipo',
            $html
        );
        self::assertStringContainsString(
            'editor+&lt;script&gt;@example.test',
            $html
        );
        self::assertStringContainsString(
            '/users/edit?user=' . $publicId,
            $html
        );
        self::assertStringContainsString(
            'rel="next"',
            $html
        );
        self::assertStringContainsString(
            'after=cursor%22%3E%3Cscript%3Ealert%281%29%3C%2Fscript%3E',
            $html
        );
        self::assertStringContainsString('>Activo</td>', $html);
        self::assertStringContainsString('Invitar editor', $html);
        self::assertStringNotContainsString('name="role"', $html);
    }

    public function testEditorListHidesInviteAndPaginationWithoutPermissionOrCursor(): void
    {
        $html = $this->renderer->editorList('/', [], null, false);

        self::assertStringContainsString('No hay editores para mostrar.', $html);
        self::assertStringNotContainsString('Invitar editor', $html);
        self::assertStringNotContainsString('rel="next"', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringContainsString(
            '<a href="/">Volver a la gesti&oacute;n web</a>',
            $html
        );
    }

    public function testEditorInviteHasExactFieldsAndEscapedCapabilities(): void
    {
        $html = $this->renderer->editorInvite(
            '/admin/',
            'csrf"><script>csrf</script>',
            [
                [
                    'code' => 'blog.posts.manage"><img src=x>',
                    'label' => 'Gestionar <b>entradas</b> & publicar',
                    'selected' => true,
                ],
                [
                    'code' => 'blog.media.manage',
                    'label' => 'Gestionar medios',
                    'selected' => false,
                ],
            ],
            true
        );

        self::assertStringContainsString(
            '<form method="post" action="/admin/users/invite"',
            $html
        );
        self::assertSame(1, substr_count($html, 'name="csrf"'));
        self::assertSame(1, substr_count($html, 'name="email"'));
        self::assertSame(1, substr_count($html, 'name="display_name"'));
        self::assertStringContainsString('maxlength="120"', $html);
        self::assertStringNotContainsString('maxlength="160"', $html);
        self::assertSame(2, substr_count($html, 'name="capabilities[]"'));
        self::assertSame(1, substr_count($html, ' checked'));
        self::assertStringContainsString(
            'value="blog.posts.manage&quot;&gt;&lt;img src=x&gt;" checked',
            $html
        );
        self::assertStringContainsString(
            'Gestionar &lt;b&gt;entradas&lt;/b&gt; &amp; publicar',
            $html
        );
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('name="target"', $html);
        self::assertStringNotContainsString('name="role"', $html);
        self::assertStringContainsString(
            'id="webadmin-user-invite-error" role="alert"',
            $html
        );
    }

    public function testActiveEditorDetailUsesSeparateAuthorizedForms(): void
    {
        $internalId = '7d967c4c-c04c-4de7-9d1f-internal-only';
        $editor = $this->editor('active') + ['id' => $internalId];
        $html = $this->renderer->editorDetail(
            '/admin',
            'csrf-edit',
            $editor,
            $this->capabilities(),
            true,
            true,
            true,
            true
        );

        self::assertStringNotContainsString($internalId, $html);
        self::assertSame(2, substr_count($html, '<form method="post"'));
        self::assertStringContainsString(
            'action="/admin/users/capabilities"',
            $html
        );
        self::assertStringContainsString(
            'action="/admin/users/suspend"',
            $html
        );
        self::assertStringNotContainsString(
            'action="/admin/users/resume"',
            $html
        );
        self::assertStringNotContainsString(
            'action="/admin/users/invite/resend"',
            $html
        );
        self::assertSame(2, substr_count($html, 'name="csrf"'));
        self::assertSame(2, substr_count($html, 'name="target"'));
        self::assertSame(
            2,
            substr_count(
                $html,
                'value="018f47a8-7e75-7cc4-9a67-85d4b38e0021"'
            )
        );
        self::assertStringContainsString('name="capabilities[]"', $html);
        self::assertStringNotContainsString('name="role"', $html);
        self::assertStringContainsString(
            'id="webadmin-user-edit-error" role="alert"',
            $html
        );
    }

    public function testSuspendedEditorOnlyExposesResumeWhenThatIsAuthorized(): void
    {
        $html = $this->renderer->editorDetail(
            '/admin',
            'csrf',
            $this->editor('suspended'),
            $this->capabilities(),
            false,
            true,
            true
        );

        self::assertSame(1, substr_count($html, '<form method="post"'));
        self::assertStringContainsString(
            'action="/admin/users/resume"',
            $html
        );
        self::assertStringNotContainsString('/users/suspend', $html);
        self::assertStringNotContainsString('/users/capabilities', $html);
        self::assertStringNotContainsString('/users/invite/resend', $html);
        self::assertStringContainsString('>Suspendido</dd>', $html);
    }

    public function testInvitedEditorCanExposeSuspendAndResendAsSeparateForms(): void
    {
        $html = $this->renderer->editorDetail(
            '/admin',
            'csrf',
            $this->editor('invited'),
            $this->capabilities(),
            false,
            true,
            true
        );

        self::assertSame(2, substr_count($html, '<form method="post"'));
        self::assertStringContainsString('/admin/users/suspend', $html);
        self::assertStringContainsString('/admin/users/invite/resend', $html);
        self::assertStringContainsString('Invitaci&oacute;n pendiente', $html);
    }

    public function testEditorDetailRendersNoFormOrTargetWithoutAnyPermission(): void
    {
        $html = $this->renderer->editorDetail(
            '/admin',
            'csrf-secret',
            $this->editor('active'),
            $this->capabilities(),
            false,
            false,
            false
        );

        self::assertStringContainsString(
            'No tienes acciones disponibles para este editor.',
            $html
        );
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('name="target"', $html);
        self::assertStringNotContainsString('csrf-secret', $html);
        self::assertStringNotContainsString('name="capabilities[]"', $html);
    }

    public function testEditorOperationCompletedIsAReadOnlyPrgDestination(): void
    {
        $html = $this->renderer->editorOperationCompleted(
            '/admin"><script>alert(1)</script>'
        );

        self::assertStringContainsString(
            'La operaci&oacute;n se ha completado correctamente.',
            $html
        );
        self::assertStringContainsString('role="status" aria-live="polite"', $html);
        self::assertStringContainsString(
            '/admin&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;/users',
            $html
        );
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('name="csrf"', $html);
        self::assertStringNotContainsString('name="target"', $html);

        $dashboardReturn = $this->renderer->editorOperationCompleted(
            '/admin',
            false
        );
        self::assertStringContainsString('href="/admin"', $dashboardReturn);
        self::assertStringNotContainsString(
            'href="/admin/users"',
            $dashboardReturn
        );
    }

    public function testForgotPasswordIsGenericAndDoesNotReinjectEmail(): void
    {
        $html = $this->renderer->forgotPassword('/', 'csrf-forgot');

        self::assertMatchesRegularExpression(
            '/<form(?=[^>]*method="post")(?=[^>]*action="\/password\/forgot")[^>]*>/',
            $html
        );
        self::assertStringContainsString(
            'autocomplete="username"',
            $html
        );
        self::assertStringContainsString(
            'aria-describedby="webadmin-forgot-email-description '
                . 'webadmin-forgot-email-error"',
            $html
        );
        self::assertDoesNotMatchRegularExpression(
            '/<input[^>]+name="email"[^>]+value=/',
            $html
        );
        self::assertStringContainsString('Si existe una cuenta disponible', $html);
    }

    public function testForgotPasswordSentDoesNotDiscloseAccountExistence(): void
    {
        $html = $this->renderer->forgotPasswordSent('/admin');

        self::assertStringContainsString('role="status" aria-live="polite"', $html);
        self::assertStringContainsString('Si existe una cuenta disponible', $html);
        self::assertStringContainsString('<a href="/admin/login">', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('name="email"', $html);
    }

    /**
     * @dataProvider credentialActionProvider
     */
    public function testCredentialActionUsesPurposeSpecificExactAction(
        string $purpose,
        string $action,
        string $heading,
        string $button
    ): void {
        $html = $this->renderer->credentialAction(
            '/admin/',
            $purpose,
            'credential-csrf',
            true
        );

        self::assertMatchesRegularExpression(
            '/<form(?=[^>]*method="post")(?=[^>]*action="'
                . preg_quote($action, '/') . '")[^>]*>/',
            $html
        );
        self::assertStringContainsString($heading, $html);
        self::assertStringContainsString($button, $html);
        self::assertSame(2, substr_count($html, 'autocomplete="new-password"'));
        self::assertStringContainsString('15 y 1024 bytes en UTF-8', $html);
        self::assertStringNotContainsString('minlength=', $html);
        self::assertStringNotContainsString('maxlength="1024"', $html);
        self::assertStringContainsString(
            'name="password_confirmation"',
            $html
        );
        self::assertStringContainsString(
            'role="alert" aria-live="assertive"',
            $html
        );
        self::assertDoesNotMatchRegularExpression(
            '/<input[^>]+name="(?:password|password_confirmation)"[^>]+value=/',
            $html
        );
        self::assertStringNotContainsString('name="token"', $html);
        self::assertStringNotContainsString('?token=', $html);
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function credentialActionProvider(): array
    {
        return [
            'invite' => [
                'invite',
                '/admin/activate',
                'Activar acceso',
                'Activar acceso',
            ],
            'password reset' => [
                'password_reset',
                '/admin/password/reset',
                'Cambiar contrase&ntilde;a',
                'Guardar contrase&ntilde;a',
            ],
        ];
    }

    public function testCredentialActionRejectsAnUnknownPurpose(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Unsupported credential action purpose.'
        );

        $this->renderer->credentialAction(
            '/admin',
            'credential_export',
            'csrf',
            false
        );
    }

    public function testActionUnavailableIsGenericAndContainsNoSecret(): void
    {
        $html = $this->renderer->actionUnavailable('/admin');

        self::assertStringContainsString(
            'role="alert" aria-live="assertive"',
            $html
        );
        self::assertStringContainsString(
            '<a href="/admin/password/forgot">',
            $html
        );
        self::assertStringContainsString(
            'pide al administrador que reenv&iacute;e la invitaci&oacute;n',
            $html
        );
        self::assertStringContainsString('<a href="/admin/login">', $html);
        self::assertStringNotContainsString('name="token"', $html);
        self::assertStringNotContainsString('?token=', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    /**
     * @return array{
     *     public_id: string,
     *     email: string,
     *     display_name: string,
     *     status: string
     * }
     */
    private function editor(string $status = 'active'): array
    {
        return [
            'public_id' => '018f47a8-7e75-7cc4-9a67-85d4b38e0021',
            'email' => 'editor@example.test',
            'display_name' => 'Editora de contenidos',
            'status' => $status,
        ];
    }

    /**
     * @return list<array{code: string, label: string, selected: bool}>
     */
    private function capabilities(): array
    {
        return [
            [
                'code' => 'blog.posts.manage',
                'label' => 'Gestionar entradas',
                'selected' => true,
            ],
            [
                'code' => 'blog.posts.publish',
                'label' => 'Publicar entradas',
                'selected' => false,
            ],
        ];
    }
}
