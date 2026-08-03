<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

use InvalidArgumentException;

/**
 * Renders WebAdmin credential screens through canonical LiquidStack
 * resources. It owns presentation only; authentication remains in the
 * application coordinators and services.
 */
final class WebAdminAuthHtmlRenderer
{
    public function __construct(
        private readonly WebAdminResourceTemplateRenderer $resources =
            new WebAdminResourceTemplateRenderer(),
        private readonly WebAdminPageDocumentRenderer $documents =
            new WebAdminPageDocumentRenderer()
    ) {
    }

    public function login(
        string $basePath,
        string $csrf,
        bool $failed,
        ?string $notice = null
    ): string {
        $feedback = '';
        if ($notice !== null && trim($notice) !== '') {
            $feedback .= '<p id="webadmin-login-notice" role="status" '
                . 'aria-live="polite">'
                . $this->escape($notice) . '</p>';
        }
        if ($failed) {
            $feedback .= '<p id="webadmin-login-error" role="alert" '
                . 'aria-live="assertive">'
                . 'No se pudo iniciar sesi&oacute;n con esos datos.</p>';
        }

        $form = $this->resources->render(
            '_moduleFormAuthLogin02.html',
            $this->loginValues($basePath, $csrf, $feedback)
        );

        return $this->authDocument(
            'Acceso a la gesti&oacute;n web',
            'webadmin-login',
            'Acceso a la gesti&oacute;n web',
            'Introduce tus credenciales de administraci&oacute;n.',
            'Zona operativa privada',
            'Este acceso gestiona la web y sus m&oacute;dulos; no corresponde '
                . 'a otras &aacute;reas privadas del negocio.',
            $form
        );
    }

    public function forgotPassword(string $basePath, string $csrf): string
    {
        $prefix = 'webadmin-forgot';
        $form = $this->resources->render(
            '_moduleFormAuthRecover02.html',
            [
                '{root-id}' => $prefix . '-root',
                '{form-id}' => $prefix . '-form',
                '{legend-id}' => $prefix . '-legend',
                '{classVar}' => 'moduleFormAuthRecover02--webadmin',
                '{form-action}' => $this->path($basePath, '/password/forgot'),
                '{form-method}' => 'post',
                '{hidden-fields}' => $this->csrfInput($csrf),
                '{legend-lang-attr}' => '',
                '{legend-text}' => 'Solicitar instrucciones',
                '{intro-lang-attr}' => '',
                '{intro-text}' => 'Si existe una cuenta disponible para ese '
                    . 'correo, enviaremos un enlace de un solo uso.',
                '{email-id}' => $prefix . '-email',
                '{email-name}' => 'email',
                '{email-autocomplete}' => 'username',
                '{email-hint-id}' => $prefix . '-email-description',
                '{email-error-id}' => $prefix . '-email-error',
                '{email-label-lang-attr}' => '',
                '{email-label-text}' => 'Correo electr&oacute;nico',
                '{email-placeholder-lang-attr}' => '',
                '{email-placeholder}' => 'nombre@dominio.com',
                '{email-hint-lang-attr}' => '',
                '{email-hint-text}' => 'Escribe el correo asociado a tu acceso.',
                '{email-error-slot}' => '',
                '{submit-lang-attr}' => '',
                '{submit-text}' => 'Enviar instrucciones',
                '{feedback-slot}' => '',
                '{secondary-action-slot}' => '<a href="'
                    . $this->path($basePath, '/login')
                    . '">Volver al acceso</a>',
            ]
        );

        return $this->authDocument(
            'Recuperar contrase&ntilde;a',
            $prefix,
            'Recuperar contrase&ntilde;a',
            'Indica el correo con el que accedes a la gesti&oacute;n web.',
            'Proceso protegido',
            'La respuesta nunca confirma si existe una cuenta y el enlace '
                . 'caduca tras utilizarse.',
            $form
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
                'prefix' => 'webadmin-credential-action',
                'title' => 'Activar acceso',
                'intro' => 'Crea una contrase&ntilde;a para activar tu acceso '
                    . 'a la gesti&oacute;n web.',
                'action' => '/activate',
                'button' => 'Activar acceso',
            ],
            'password_reset' => [
                'prefix' => 'webadmin-credential-action',
                'title' => 'Cambiar contrase&ntilde;a',
                'intro' => 'Crea una nueva contrase&ntilde;a para tu acceso '
                    . 'a la gesti&oacute;n web.',
                'action' => '/password/reset',
                'button' => 'Guardar contrase&ntilde;a',
            ],
            default => throw new InvalidArgumentException(
                'Unsupported credential action purpose.'
            ),
        };
        $prefix = $presentation['prefix'];
        $feedback = $failed
            ? '<p id="webadmin-credential-action-error" role="alert" '
                . 'aria-live="assertive">No se '
                . 'pudo completar la operaci&oacute;n. Revisa los datos e '
                . 'int&eacute;ntalo de nuevo.</p>'
            : '';
        $form = $this->resources->render(
            '_moduleFormAuthPassword02.html',
            $this->passwordValues(
                $basePath,
                $csrf,
                $presentation['action'],
                $presentation['button'],
                $feedback
            )
        );

        return $this->authDocument(
            $presentation['title'],
            $prefix,
            $presentation['title'],
            $presentation['intro'],
            'Contrase&ntilde;a segura',
            'Usa al menos 8 caracteres, con min&uacute;scula, may&uacute;scula, '
                . 'n&uacute;mero y signo.',
            $form
        );
    }

    /** @return array<string, string> */
    private function loginValues(
        string $basePath,
        string $csrf,
        string $feedback
    ): array {
        $prefix = 'webadmin-login';

        return [
            '{root-id}' => $prefix . '-root',
            '{form-id}' => $prefix . '-form',
            '{legend-id}' => $prefix . '-legend',
            '{classVar}' => 'moduleFormAuthLogin02--webadmin',
            '{form-action}' => $this->path($basePath, '/login'),
            '{form-method}' => 'post',
            '{hidden-fields}' => $this->csrfInput($csrf),
            '{legend-lang-attr}' => '',
            '{legend-text}' => 'Credenciales de acceso',
            '{intro-lang-attr}' => '',
            '{intro-text}' => 'Usa el correo y la contrase&ntilde;a de tu '
                . 'cuenta de gesti&oacute;n.',
            '{email-id}' => 'webadmin-email',
            '{email-name}' => 'email',
            '{email-hint-id}' => 'webadmin-email-description',
            '{email-error-id}' => 'webadmin-email-error',
            '{email-label-lang-attr}' => '',
            '{email-label-text}' => 'Correo electr&oacute;nico',
            '{email-placeholder-lang-attr}' => '',
            '{email-placeholder}' => 'nombre@dominio.com',
            '{email-hint-lang-attr}' => '',
            '{email-hint-text}' => 'Usa el correo asociado a tu acceso.',
            '{email-error-slot}' => '',
            '{password-id}' => 'webadmin-password',
            '{password-name}' => 'password',
            '{password-hint-id}' => 'webadmin-password-description',
            '{password-error-id}' => 'webadmin-password-error',
            '{password-label-lang-attr}' => '',
            '{password-label-text}' => 'Contrase&ntilde;a',
            '{password-placeholder-lang-attr}' => '',
            '{password-placeholder}' => 'Contrase&ntilde;a',
            '{password-hint-lang-attr}' => '',
            '{password-hint-text}' => 'Introduce tu contrase&ntilde;a actual.',
            '{password-error-slot}' => '',
            '{toggle-show-lang-attr}' => '',
            '{toggle-show-text}' => 'Mostrar contrase&ntilde;a',
            '{toggle-hide-text}' => 'Ocultar contrase&ntilde;a',
            '{submit-lang-attr}' => '',
            '{submit-text}' => 'Entrar',
            '{feedback-slot}' => $feedback,
            '{secondary-action-slot}' => '<a href="'
                . $this->path($basePath, '/password/forgot')
                . '">He olvidado mi contrase&ntilde;a</a>',
        ];
    }

    /** @return array<string, string> */
    private function passwordValues(
        string $basePath,
        string $csrf,
        string $action,
        string $submit,
        string $feedback
    ): array {
        $prefix = 'webadmin-credential-action';

        return [
            '{root-id}' => $prefix . '-root',
            '{form-id}' => $prefix . '-form',
            '{legend-id}' => $prefix . '-legend',
            '{classVar}' => 'moduleFormAuthPassword02--webadmin',
            '{form-action}' => $this->path($basePath, $action),
            '{form-method}' => 'post',
            '{hidden-fields}' => $this->csrfInput($csrf),
            '{legend-lang-attr}' => '',
            '{legend-text}' => 'Define tu contrase&ntilde;a',
            '{intro-lang-attr}' => '',
            '{intro-text}' => 'Completa y confirma la nueva contrase&ntilde;a.',
            '{requirements-label}' => 'Requisitos de la contrase&ntilde;a',
            '{requirement-length-lang-attr}' => '',
            '{requirement-length-text}' => 'M&iacute;nimo 8 caracteres',
            '{requirement-lowercase-lang-attr}' => '',
            '{requirement-lowercase-text}' => 'Al menos una min&uacute;scula',
            '{requirement-uppercase-lang-attr}' => '',
            '{requirement-uppercase-text}' => 'Al menos una may&uacute;scula',
            '{requirement-number-lang-attr}' => '',
            '{requirement-number-text}' => 'Al menos un n&uacute;mero',
            '{requirement-symbol-lang-attr}' => '',
            '{requirement-symbol-text}' => 'Al menos un signo',
            '{requirement-match-lang-attr}' => '',
            '{requirement-match-text}' => 'Las dos contrase&ntilde;as coinciden',
            '{summary-progress}' => 'Has completado %complete% de %total% '
                . 'requisitos.',
            '{summary-complete}' => 'Todos los requisitos est&aacute;n completos.',
            '{password-id}' => 'webadmin-new-password',
            '{password-name}' => 'password',
            '{password-hint-id}' => 'webadmin-new-password-description',
            '{password-error-id}' => 'webadmin-new-password-error',
            '{password-label-lang-attr}' => '',
            '{password-label-text}' => 'Nueva contrase&ntilde;a',
            '{password-placeholder-lang-attr}' => '',
            '{password-placeholder}' => 'Nueva contrase&ntilde;a',
            '{password-hint-lang-attr}' => '',
            '{password-hint-text}' => 'Debe cumplir todos los requisitos '
                . 'indicados.',
            '{password-error-slot}' => '',
            '{confirmation-id}' => 'webadmin-password-confirmation',
            '{confirmation-name}' => 'password_confirmation',
            '{confirmation-hint-id}' =>
                'webadmin-password-confirmation-description',
            '{confirmation-error-id}' =>
                'webadmin-password-confirmation-error',
            '{confirmation-label-lang-attr}' => '',
            '{confirmation-label-text}' => 'Confirma la contrase&ntilde;a',
            '{confirmation-placeholder-lang-attr}' => '',
            '{confirmation-placeholder}' => 'Repite la contrase&ntilde;a',
            '{confirmation-hint-lang-attr}' => '',
            '{confirmation-hint-text}' => 'Repite la nueva contrase&ntilde;a.',
            '{confirmation-error-slot}' => '',
            '{toggle-show-lang-attr}' => '',
            '{toggle-show-text}' => 'Mostrar contrase&ntilde;a',
            '{toggle-hide-text}' => 'Ocultar contrase&ntilde;a',
            '{submit-lang-attr}' => '',
            '{submit-text}' => $submit,
            '{feedback-slot}' => $feedback,
            '{secondary-action-slot}' => '',
        ];
    }

    private function authDocument(
        string $title,
        string $prefix,
        string $heading,
        string $intro,
        string $supportHeading,
        string $support,
        string $form
    ): string {
        $body = '<main>' . $this->resources->render(
            '_artAuth02.html',
            [
                '{article-id}' => $prefix . '-article',
                '{heading-id}' => $prefix . '-title',
                '{classVar}' => 'artAuth02--webadmin',
                '{header-primary}' => '<h1 id="' . $prefix . '-title">'
                    . $heading . '</h1>',
                '{intro-lang-attr}' => '',
                '{intro-text}' => $intro,
                '{header-secondary}' => '<h2 id="' . $prefix
                    . '-support-title">' . $supportHeading . '</h2>',
                '{support-lang-attr}' => '',
                '{support-text}' => $support,
                '{aside-slot}' => '',
                '{form-slot}' => $form,
            ]
        ) . '</main>';

        return $this->documents->render($title, $body);
    }

    private function csrfInput(string $csrf): string
    {
        return '<input type="hidden" name="csrf" value="'
            . $this->escape($csrf) . '">';
    }

    private function path(string $basePath, string $suffix): string
    {
        $basePath = rtrim($basePath, '/');
        $path = ($basePath === '' ? '' : $basePath) . $suffix;

        return $this->escape($path === '' ? '/' : $path);
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
