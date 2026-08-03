<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;
use InvalidArgumentException;

final class WebAdminHtmlRenderer
{
    private readonly WebAdminPageDocumentRenderer $documentRenderer;
    private readonly WebAdminAuthHtmlRenderer $authRenderer;
    private readonly WebAdminShellRenderer $shellRenderer;

    public function __construct(
        ?WebAdminPageDocumentRenderer $documentRenderer = null,
        ?WebAdminAuthHtmlRenderer $authRenderer = null,
        ?WebAdminShellRenderer $shellRenderer = null
    ) {
        $this->documentRenderer = $documentRenderer
            ?? new WebAdminPageDocumentRenderer();
        $this->authRenderer = $authRenderer
            ?? new WebAdminAuthHtmlRenderer(
                documents: $this->documentRenderer
            );
        $this->shellRenderer = $shellRenderer
            ?? new WebAdminShellRenderer($this->documentRenderer);
    }

    public function login(
        string $basePath,
        string $csrf,
        bool $failed,
        ?string $notice = null
    ): string {
        return $this->authRenderer->login(
            $basePath,
            $csrf,
            $failed,
            $notice
        );
    }

    /** @param list<WebAdminNavigationItem> $moduleNavigation */
    public function dashboard(
        string $basePath,
        string $csrf,
        bool $showUsersLink = false,
        array $moduleNavigation = [],
        ?WebAdminShellContext $shell = null
    ): string {
        if (!array_is_list($moduleNavigation)) {
            throw new InvalidArgumentException(
                'Invalid WebAdmin navigation presentation list.'
            );
        }

        foreach ($moduleNavigation as $item) {
            if (!$item instanceof WebAdminNavigationItem) {
                throw new InvalidArgumentException(
                    'Invalid WebAdmin navigation presentation item.'
                );
            }
        }

        $shell ??= new WebAdminShellContext(
            basePath: $basePath,
            logoutCsrf: $csrf,
            activePath: '',
            showUsersLink: $showUsersLink,
            moduleNavigation: $moduleNavigation
        );

        return $this->shellRenderer->render(
            'Gesti&oacute;n web',
            '<article aria-labelledby="webadmin-title">'
            . '<h1 id="webadmin-title">Gesti&oacute;n web</h1>'
            . '<p id="webadmin-dashboard-description">La sesi&oacute;n segura '
            . 'est&aacute; activa.</p>'
            . '</article>',
            $shell
        );
    }

    /**
     * @param list<array{
     *     public_id: string,
     *     email: string,
     *     display_name: ?string,
     *     status: string
     * }> $editors
     */
    public function editorList(
        string $basePath,
        array $editors,
        ?string $nextAfter,
        bool $canInvite,
        ?WebAdminShellContext $shell = null
    ): string {
        $rows = '';
        foreach ($editors as $editor) {
            $editor = $this->editorRow($editor);
            $displayName = $editor['display_name'] !== null
                && trim($editor['display_name']) !== ''
                    ? $editor['display_name']
                    : 'Sin nombre indicado';
            $editUrl = $this->pathWithQuery(
                $basePath,
                '/users/edit',
                ['user' => $editor['public_id']]
            );

            $rows .= '<tr><th scope="row">'
                . $this->escape($displayName) . '</th><td>'
                . $this->escape($editor['email']) . '</td><td>'
                . $this->statusLabel($editor['status'])
                . '</td><td><a href="' . $editUrl
                . '">Gestionar editor</a></td></tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="4">No hay editores para mostrar.</td></tr>';
        }

        $inviteLink = $canInvite
            ? '<p><a href="' . $this->path($basePath, '/users/invite')
                . '">Invitar editor</a></p>'
            : '';
        $nextLink = $nextAfter !== null && $nextAfter !== ''
            ? '<p><a rel="next" href="'
                . $this->pathWithQuery(
                    $basePath,
                    '/users',
                    ['after' => $nextAfter]
                )
                . '">Ver m&aacute;s editores</a></p>'
            : '';

        $shell ??= new WebAdminShellContext(
            basePath: $basePath,
            logoutCsrf: null,
            activePath: '/users',
            showUsersLink: true
        );

        return $this->shellRenderer->render(
            'Editores',
            '<article aria-labelledby="webadmin-users-title">'
            . '<h1 id="webadmin-users-title">Editores</h1>'
            . '<p>Consulta y gestiona las personas con acceso editorial.</p>'
            . $inviteLink
            . '<table><caption>Listado de editores</caption><thead><tr>'
            . '<th scope="col">Nombre</th><th scope="col">Correo</th>'
            . '<th scope="col">Estado</th><th scope="col">Acciones</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . $nextLink
            . $this->backToDashboard($basePath)
            . '</article>',
            $shell
        );
    }

    /**
     * @param list<array{code: string, label: string, selected: bool}> $capabilities
     */
    public function editorInvite(
        string $basePath,
        string $csrf,
        array $capabilities,
        bool $failed = false,
        ?WebAdminShellContext $shell = null
    ): string {
        $feedback = $this->formError(
            $failed,
            'webadmin-user-invite-error'
        );

        $shell ??= new WebAdminShellContext(
            basePath: $basePath,
            logoutCsrf: null,
            activePath: '/users',
            showUsersLink: true
        );

        return $this->shellRenderer->render(
            'Invitar editor',
            '<article aria-labelledby="webadmin-user-invite-title">'
            . '<h1 id="webadmin-user-invite-title">Invitar editor</h1>'
            . '<p id="webadmin-user-invite-description">Crea un acceso '
            . 'editorial y asigna &uacute;nicamente las capacidades necesarias.</p>'
            . $feedback
            . '<form method="post" action="'
            . $this->path($basePath, '/users/invite')
            . '" aria-describedby="webadmin-user-invite-description'
            . ($failed ? ' webadmin-user-invite-error' : '') . '">'
            . $this->csrfInput($csrf)
            . '<div><label for="webadmin-user-email">Correo electr&oacute;nico</label>'
            . '<input id="webadmin-user-email" name="email" type="email" '
            . 'autocomplete="off" autocapitalize="none" spellcheck="false" '
            . 'maxlength="254" required></div>'
            . '<div><label for="webadmin-user-display-name">Nombre visible</label>'
            . '<input id="webadmin-user-display-name" name="display_name" '
            . 'type="text" autocomplete="off" maxlength="120"></div>'
            . $this->capabilityFieldset($capabilities, 'invite')
            . '<button type="submit">Enviar invitaci&oacute;n</button>'
            . '</form>'
            . $this->backToUsers($basePath)
            . '</article>',
            $shell
        );
    }

    /**
     * @param array{
     *     public_id: string,
     *     email: string,
     *     display_name: ?string,
     *     status: string
     * } $editor
     * @param list<array{code: string, label: string, selected: bool}> $capabilities
     */
    public function editorDetail(
        string $basePath,
        string $csrf,
        array $editor,
        array $capabilities,
        bool $canReplaceCapabilities,
        bool $canSuspend,
        bool $canResendInvite,
        bool $failed = false,
        ?WebAdminShellContext $shell = null
    ): string {
        $editor = $this->editorRow($editor);
        $target = $this->targetInput($editor['public_id']);
        $displayName = $editor['display_name'] !== null
            && trim($editor['display_name']) !== ''
                ? $editor['display_name']
                : 'Sin nombre indicado';
        $feedback = $this->formError($failed, 'webadmin-user-edit-error');
        $actions = '';

        if ($canReplaceCapabilities) {
            $actions .= '<section aria-labelledby="webadmin-capabilities-title">'
                . '<h2 id="webadmin-capabilities-title">Capacidades</h2>'
                . '<form method="post" action="'
                . $this->path($basePath, '/users/capabilities') . '">'
                . $this->csrfInput($csrf) . $target
                . $this->capabilityFieldset($capabilities, 'replace')
                . '<button type="submit">Guardar capacidades</button>'
                . '</form></section>';
        }

        if ($canSuspend && $editor['status'] === 'suspended') {
            $actions .= $this->editorStateForm(
                $basePath,
                $csrf,
                $target,
                '/users/resume',
                'Reactivar editor'
            );
        } elseif ($canSuspend && in_array(
            $editor['status'],
            ['active', 'invited'],
            true
        )) {
            $actions .= $this->editorStateForm(
                $basePath,
                $csrf,
                $target,
                '/users/suspend',
                'Suspender editor'
            );
        }

        if ($canResendInvite && $editor['status'] === 'invited') {
            $actions .= $this->editorStateForm(
                $basePath,
                $csrf,
                $target,
                '/users/invite/resend',
                'Reenviar invitaci&oacute;n'
            );
        }

        if ($actions === '') {
            $actions = '<p>No tienes acciones disponibles para este editor.</p>';
        }

        $shell ??= new WebAdminShellContext(
            basePath: $basePath,
            logoutCsrf: null,
            activePath: '/users',
            showUsersLink: true
        );

        return $this->shellRenderer->render(
            'Gestionar editor',
            '<article aria-labelledby="webadmin-user-edit-title">'
            . '<h1 id="webadmin-user-edit-title">Gestionar editor</h1>'
            . $feedback
            . '<dl><div><dt>Nombre</dt><dd>' . $this->escape($displayName)
            . '</dd></div><div><dt>Correo</dt><dd>'
            . $this->escape($editor['email'])
            . '</dd></div><div><dt>Estado</dt><dd>'
            . $this->statusLabel($editor['status'])
            . '</dd></div></dl>'
            . $actions
            . $this->backToUsers($basePath)
            . '</article>',
            $shell
        );
    }

    public function editorOperationCompleted(
        string $basePath,
        bool $showUsersLink = true,
        ?WebAdminShellContext $shell = null
    ): string {
        $returnLink = $showUsersLink
            ? $this->backToUsers($basePath)
            : $this->backToDashboard($basePath);

        $shell ??= new WebAdminShellContext(
            basePath: $basePath,
            logoutCsrf: null,
            activePath: '/users',
            showUsersLink: $showUsersLink
        );

        return $this->shellRenderer->render(
            'Operaci&oacute;n completada',
            '<article aria-labelledby="webadmin-user-operation-title">'
            . '<h1 id="webadmin-user-operation-title">Operaci&oacute;n completada</h1>'
            . '<p role="status" aria-live="polite">La operaci&oacute;n se ha '
            . 'completado correctamente.</p>'
            . $returnLink
            . '</article>',
            $shell
        );
    }

    public function forgotPassword(string $basePath, string $csrf): string
    {
        return $this->authRenderer->forgotPassword($basePath, $csrf);
    }

    public function forgotPasswordSent(string $basePath): string
    {
        return $this->document(
            'Revisa tu correo',
            '<main><article aria-labelledby="webadmin-forgot-sent-title">'
            . '<h1 id="webadmin-forgot-sent-title">Revisa tu correo</h1>'
            . '<p role="status" aria-live="polite">Si existe una cuenta '
            . 'disponible para el correo indicado, recibir&aacute;s las instrucciones '
            . 'para continuar.</p>'
            . '<p><a href="' . $this->path($basePath, '/login')
            . '">Volver al acceso</a></p>'
            . '</article></main>'
        );
    }

    public function forgotPasswordUnavailable(string $basePath): string
    {
        return $this->document(
            'No se pudo completar la solicitud',
            '<main><article aria-labelledby="webadmin-forgot-unavailable-title">'
            . '<h1 id="webadmin-forgot-unavailable-title">No se pudo completar '
            . 'la solicitud</h1>'
            . '<p role="alert" aria-live="assertive">No hemos podido enviar '
            . 'las instrucciones en este momento. Int&eacute;ntalo de nuevo.</p>'
            . '<p><a href="' . $this->path($basePath, '/password/forgot')
            . '">Volver a intentarlo</a></p>'
            . '<p><a href="' . $this->path($basePath, '/login')
            . '">Volver al acceso</a></p>'
            . '</article></main>'
        );
    }

    public function credentialAction(
        string $basePath,
        string $purpose,
        string $csrf,
        bool $failed
    ): string {
        return $this->authRenderer->credentialAction(
            $basePath,
            $purpose,
            $csrf,
            $failed
        );
    }

    public function actionUnavailable(string $basePath): string
    {
        return $this->document(
            'Enlace no disponible',
            '<main><article aria-labelledby="webadmin-action-unavailable-title">'
            . '<h1 id="webadmin-action-unavailable-title">Enlace no disponible</h1>'
            . '<p role="alert" aria-live="assertive">Este enlace no es v&aacute;lido '
            . 'o ya no est&aacute; disponible.</p>'
            . '<p>Si estabas activando tu acceso, pide al administrador que '
            . 'reenv&iacute;e la invitaci&oacute;n. Si ya ten&iacute;as contrase&ntilde;a, '
            . 'puedes solicitar otro enlace.</p>'
            . '<p><a href="' . $this->path($basePath, '/password/forgot')
            . '">Recuperar una contrase&ntilde;a existente</a></p>'
            . '<p><a href="' . $this->path($basePath, '/login')
            . '">Volver al acceso</a></p>'
            . '</article></main>'
        );
    }

    private function document(string $title, string $body): string
    {
        return $this->documentRenderer->render($title, $body);
    }

    /**
     * @param array<string, mixed> $editor
     * @return array{
     *     public_id: string,
     *     email: string,
     *     display_name: ?string,
     *     status: string
     * }
     */
    private function editorRow(array $editor): array
    {
        $requiredStrings = ['public_id', 'email', 'status'];
        foreach ($requiredStrings as $key) {
            if (!isset($editor[$key]) || !is_string($editor[$key])) {
                throw new InvalidArgumentException(
                    'Invalid editor presentation row.'
                );
            }
        }

        $displayName = $editor['display_name'] ?? null;
        if ($displayName !== null && !is_string($displayName)) {
            throw new InvalidArgumentException(
                'Invalid editor presentation row.'
            );
        }

        return [
            'public_id' => $editor['public_id'],
            'email' => $editor['email'],
            'display_name' => $displayName,
            'status' => $editor['status'],
        ];
    }

    /**
     * @param list<array{code: string, label: string, selected: bool}> $capabilities
     */
    private function capabilityFieldset(
        array $capabilities,
        string $idPrefix
    ): string {
        $checkboxes = '';
        foreach ($capabilities as $index => $capability) {
            if (
                !isset($capability['code'], $capability['label'])
                || !is_string($capability['code'])
                || !is_string($capability['label'])
                || !array_key_exists('selected', $capability)
                || !is_bool($capability['selected'])
            ) {
                throw new InvalidArgumentException(
                    'Invalid capability presentation row.'
                );
            }

            $id = 'webadmin-' . $idPrefix . '-capability-' . $index;
            $checkboxes .= '<div><input id="' . $id
                . '" name="capabilities[]" type="checkbox" value="'
                . $this->escape($capability['code']) . '"'
                . ($capability['selected'] ? ' checked' : '') . '>'
                . '<label for="' . $id . '">'
                . $this->escape($capability['label']) . '</label></div>';
        }

        if ($checkboxes === '') {
            $checkboxes = '<p>No hay capacidades delegables disponibles.</p>';
        }

        return '<fieldset><legend>Capacidades permitidas</legend>'
            . $checkboxes . '</fieldset>';
    }

    private function editorStateForm(
        string $basePath,
        string $csrf,
        string $targetInput,
        string $action,
        string $button
    ): string {
        return '<form method="post" action="'
            . $this->path($basePath, $action) . '">'
            . $this->csrfInput($csrf) . $targetInput
            . '<button type="submit">' . $button . '</button></form>';
    }

    private function targetInput(string $publicId): string
    {
        return '<input type="hidden" name="target" value="'
            . $this->escape($publicId) . '">';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'invited' => 'Invitaci&oacute;n pendiente',
            'active' => 'Activo',
            'suspended' => 'Suspendido',
            default => 'Estado no disponible',
        };
    }

    private function formError(bool $failed, string $id): string
    {
        if (!$failed) {
            return '';
        }

        return '<p id="' . $id . '" role="alert" aria-live="assertive">'
            . 'No se pudo completar la operaci&oacute;n. Revisa los datos e '
            . 'int&eacute;ntalo de nuevo.</p>';
    }

    private function backToUsers(string $basePath): string
    {
        return '<p><a href="' . $this->path($basePath, '/users')
            . '">Volver a editores</a></p>';
    }

    private function backToDashboard(string $basePath): string
    {
        return '<p><a href="' . $this->path($basePath, '')
            . '">Volver a la gesti&oacute;n web</a></p>';
    }

    /** @param array<string, string> $query */
    private function pathWithQuery(
        string $basePath,
        string $suffix,
        array $query
    ): string {
        $encoded = http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        return $this->escape(
            rtrim($basePath, '/') . $suffix . '?' . $encoded
        );
    }

    private function path(string $basePath, string $suffix): string
    {
        $path = rtrim($basePath, '/') . $suffix;

        return $this->escape($path === '' ? '/' : $path);
    }

    private function csrfInput(string $csrf): string
    {
        return '<input type="hidden" name="csrf" value="'
            . $this->escape($csrf) . '">';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
