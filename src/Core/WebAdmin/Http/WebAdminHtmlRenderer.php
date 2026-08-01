<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;
use InvalidArgumentException;

final class WebAdminHtmlRenderer
{
    public function login(
        string $basePath,
        string $csrf,
        bool $failed,
        ?string $notice = null
    ): string {
        $descriptionIds = ['webadmin-login-description'];
        $feedback = '';

        if ($notice !== null && $notice !== '') {
            $descriptionIds[] = 'webadmin-login-notice';
            $feedback .= '<p id="webadmin-login-notice" role="status" '
                . 'aria-live="polite">' . $this->escape($notice) . '</p>';
        }
        if ($failed) {
            $descriptionIds[] = 'webadmin-login-error';
            $feedback .= '<p id="webadmin-login-error" role="alert" '
                . 'aria-live="assertive">No se pudo iniciar sesi&oacute;n '
                . 'con esos datos.</p>';
        }

        return $this->document(
            'Acceso a la gesti&oacute;n web',
            '<main><article aria-labelledby="webadmin-login-title">'
            . '<h1 id="webadmin-login-title">Acceso a la gesti&oacute;n web</h1>'
            . '<p id="webadmin-login-description">Introduce tus credenciales '
            . 'de administraci&oacute;n.</p>'
            . $feedback
            . '<form method="post" action="'
            . $this->path($basePath, '/login') . '" aria-describedby="'
            . implode(' ', $descriptionIds) . '">'
            . $this->csrfInput($csrf)
            . '<div><label for="webadmin-email">Correo electr&oacute;nico</label>'
            . '<p id="webadmin-email-description">Usa el correo asociado a '
            . 'tu acceso de gesti&oacute;n.</p>'
            . '<input id="webadmin-email" name="email" type="email" '
            . 'autocomplete="username" autocapitalize="none" '
            . 'spellcheck="false" maxlength="254" '
            . 'aria-describedby="webadmin-email-description" required></div>'
            . '<div><label for="webadmin-password">Contrase&ntilde;a</label>'
            . '<p id="webadmin-password-description">Introduce tu contrase&ntilde;a '
            . 'actual.</p>'
            . '<input id="webadmin-password" name="password" type="password" '
            . 'autocomplete="current-password" '
            . 'aria-describedby="webadmin-password-description" required></div>'
            . '<button type="submit">Entrar</button>'
            . '</form>'
            . '<p><a href="' . $this->path($basePath, '/password/forgot')
            . '">He olvidado mi contrase&ntilde;a</a></p>'
            . '</article></main>'
        );
    }

    /** @param list<WebAdminNavigationItem> $moduleNavigation */
    public function dashboard(
        string $basePath,
        string $csrf,
        bool $showUsersLink = false,
        array $moduleNavigation = []
    ): string {
        if (!array_is_list($moduleNavigation)) {
            throw new InvalidArgumentException(
                'Invalid WebAdmin navigation presentation list.'
            );
        }

        $links = $showUsersLink
            ? '<li><a href="' . $this->path($basePath, '/users')
                . '">Gestionar editores</a></li>'
            : '';
        foreach ($moduleNavigation as $item) {
            if (!$item instanceof WebAdminNavigationItem) {
                throw new InvalidArgumentException(
                    'Invalid WebAdmin navigation presentation item.'
                );
            }

            $links .= '<li><a href="'
                . $this->path($basePath, $item->suffix()) . '">'
                . $this->escape($item->label()) . '</a></li>';
        }

        $navigation = $links === ''
            ? ''
            : '<nav aria-label="Administraci&oacute;n"><ul>'
                . $links . '</ul></nav>';

        return $this->document(
            'Gesti&oacute;n web',
            '<main><article aria-labelledby="webadmin-title">'
            . '<h1 id="webadmin-title">Gesti&oacute;n web</h1>'
            . '<p id="webadmin-dashboard-description">La sesi&oacute;n segura '
            . 'est&aacute; activa.</p>'
            . $navigation
            . '<form method="post" action="'
            . $this->path($basePath, '/logout')
            . '" aria-describedby="webadmin-dashboard-description">'
            . $this->csrfInput($csrf)
            . '<button type="submit">Cerrar sesi&oacute;n</button>'
            . '</form></article></main>'
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
        bool $canInvite
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

        return $this->document(
            'Editores',
            '<main><article aria-labelledby="webadmin-users-title">'
            . '<h1 id="webadmin-users-title">Editores</h1>'
            . '<p>Consulta y gestiona las personas con acceso editorial.</p>'
            . $inviteLink
            . '<table><caption>Listado de editores</caption><thead><tr>'
            . '<th scope="col">Nombre</th><th scope="col">Correo</th>'
            . '<th scope="col">Estado</th><th scope="col">Acciones</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . $nextLink
            . $this->backToDashboard($basePath)
            . '</article></main>'
        );
    }

    /**
     * @param list<array{code: string, label: string, selected: bool}> $capabilities
     */
    public function editorInvite(
        string $basePath,
        string $csrf,
        array $capabilities,
        bool $failed = false
    ): string {
        $feedback = $this->formError(
            $failed,
            'webadmin-user-invite-error'
        );

        return $this->document(
            'Invitar editor',
            '<main><article aria-labelledby="webadmin-user-invite-title">'
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
            . '</article></main>'
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
        bool $failed = false
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

        return $this->document(
            'Gestionar editor',
            '<main><article aria-labelledby="webadmin-user-edit-title">'
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
            . '</article></main>'
        );
    }

    public function editorOperationCompleted(
        string $basePath,
        bool $showUsersLink = true
    ): string
    {
        $returnLink = $showUsersLink
            ? $this->backToUsers($basePath)
            : $this->backToDashboard($basePath);

        return $this->document(
            'Operaci&oacute;n completada',
            '<main><article aria-labelledby="webadmin-user-operation-title">'
            . '<h1 id="webadmin-user-operation-title">Operaci&oacute;n completada</h1>'
            . '<p role="status" aria-live="polite">La operaci&oacute;n se ha '
            . 'completado correctamente.</p>'
            . $returnLink
            . '</article></main>'
        );
    }

    public function forgotPassword(string $basePath, string $csrf): string
    {
        return $this->document(
            'Recuperar contrase&ntilde;a',
            '<main><article aria-labelledby="webadmin-forgot-title">'
            . '<h1 id="webadmin-forgot-title">Recuperar contrase&ntilde;a</h1>'
            . '<p id="webadmin-forgot-description">Indica el correo con el '
            . 'que accedes a la gesti&oacute;n web. Si existe una cuenta disponible, '
            . 'recibir&aacute;s las instrucciones.</p>'
            . '<form method="post" action="'
            . $this->path($basePath, '/password/forgot')
            . '" aria-describedby="webadmin-forgot-description">'
            . $this->csrfInput($csrf)
            . '<div><label for="webadmin-forgot-email">Correo electr&oacute;nico</label>'
            . '<p id="webadmin-forgot-email-description">Escribe el correo '
            . 'asociado a tu acceso.</p>'
            . '<input id="webadmin-forgot-email" name="email" type="email" '
            . 'autocomplete="username" autocapitalize="none" '
            . 'spellcheck="false" maxlength="254" '
            . 'aria-describedby="webadmin-forgot-email-description" required></div>'
            . '<button type="submit">Enviar instrucciones</button>'
            . '</form>'
            . '<p><a href="' . $this->path($basePath, '/login')
            . '">Volver al acceso</a></p>'
            . '</article></main>'
        );
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

    public function credentialAction(
        string $basePath,
        string $purpose,
        string $csrf,
        bool $failed
    ): string {
        $presentation = match ($purpose) {
            'invite' => [
                'title' => 'Activar acceso',
                'description' => 'Crea una contrase&ntilde;a para activar tu '
                    . 'acceso a la gesti&oacute;n web.',
                'action' => '/activate',
                'button' => 'Activar acceso',
            ],
            'password_reset' => [
                'title' => 'Cambiar contrase&ntilde;a',
                'description' => 'Crea una nueva contrase&ntilde;a para tu '
                    . 'acceso a la gesti&oacute;n web.',
                'action' => '/password/reset',
                'button' => 'Guardar contrase&ntilde;a',
            ],
            default => throw new InvalidArgumentException(
                'Unsupported credential action purpose.'
            ),
        };
        $descriptionIds = ['webadmin-credential-action-description'];
        $feedback = '';
        if ($failed) {
            $descriptionIds[] = 'webadmin-credential-action-error';
            $feedback = '<p id="webadmin-credential-action-error" role="alert" '
                . 'aria-live="assertive">No se pudo completar la operaci&oacute;n. '
                . 'Revisa los datos e int&eacute;ntalo de nuevo.</p>';
        }

        return $this->document(
            $presentation['title'],
            '<main><article aria-labelledby="webadmin-credential-action-title">'
            . '<h1 id="webadmin-credential-action-title">'
            . $presentation['title'] . '</h1>'
            . '<p id="webadmin-credential-action-description">'
            . $presentation['description'] . '</p>'
            . $feedback
            . '<form method="post" action="'
            . $this->path($basePath, $presentation['action'])
            . '" aria-describedby="' . implode(' ', $descriptionIds) . '">'
            . $this->csrfInput($csrf)
            . '<div><label for="webadmin-new-password">Nueva contrase&ntilde;a</label>'
            . '<p id="webadmin-new-password-description">Debe tener entre '
            . '15 y 1024 bytes en UTF-8.</p>'
            . '<input id="webadmin-new-password" name="password" '
            . 'type="password" autocomplete="new-password" '
            . 'aria-describedby="webadmin-new-password-description" required></div>'
            . '<div><label for="webadmin-password-confirmation">Confirma la '
            . 'contrase&ntilde;a</label>'
            . '<p id="webadmin-password-confirmation-description">Repite la '
            . 'nueva contrase&ntilde;a.</p>'
            . '<input id="webadmin-password-confirmation" '
            . 'name="password_confirmation" type="password" '
            . 'autocomplete="new-password" '
            . 'aria-describedby="webadmin-password-confirmation-description" '
            . 'required></div>'
            . '<button type="submit">' . $presentation['button'] . '</button>'
            . '</form></article></main>'
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
        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow,noarchive">'
            . '<title>' . $title . '</title></head><body>'
            . $body . '</body></html>';
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
