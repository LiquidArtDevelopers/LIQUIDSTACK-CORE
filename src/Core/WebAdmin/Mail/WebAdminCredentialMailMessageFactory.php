<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Mail;

use App\Core\WebAdmin\Outbox\WebAdminOutboxMessageFactoryInterface;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Support\WebAdminLocale;
use SensitiveParameter;

/** Builds the two credential-bearing messages currently owned by WebAdmin. */
final class WebAdminCredentialMailMessageFactory implements
    WebAdminOutboxMessageFactoryInterface
{
    public const KIND_INVITE = 'invite';
    public const KIND_PASSWORD_RESET = 'password_reset';

    public function __construct(
        private readonly WebAdminMailConfiguration $configuration,
        private readonly string $basePath,
        private readonly SecureTokenGenerator $tokenGenerator = new SecureTokenGenerator()
    ) {
        if (!$this->validPublicOrigin($configuration->publicOrigin())) {
            throw new WebAdminMailMessageFactoryException(
                'mail.public_origin_invalid'
            );
        }
        if (
            preg_match(
                '#\A/[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:/[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\z#',
                $basePath
            ) !== 1
        ) {
            throw new WebAdminMailMessageFactoryException(
                'mail.base_path_invalid'
            );
        }
    }

    public function create(
        string $kind,
        string $recipientEmail,
        string $locale,
        #[SensitiveParameter] string $rawToken
    ): WebAdminMailMessage {
        if (!$this->tokenGenerator->hasValidFormat($rawToken)) {
            throw new WebAdminMailMessageFactoryException(
                'mail.action_token_invalid'
            );
        }
        if (!WebAdminLocale::isCanonical($locale)) {
            throw new WebAdminMailMessageFactoryException(
                'mail.locale_invalid'
            );
        }

        return match ($kind) {
            self::KIND_INVITE => $this->invitation(
                $recipientEmail,
                $rawToken
            ),
            self::KIND_PASSWORD_RESET => $this->passwordReset(
                $recipientEmail,
                $rawToken
            ),
            default => throw new WebAdminMailMessageFactoryException(
                'mail.kind_unsupported'
            ),
        };
    }

    private function invitation(
        string $recipientEmail,
        #[SensitiveParameter] string $rawToken
    ): WebAdminMailMessage {
        $url = $this->actionUrl('/activate', $rawToken);
        $subject = 'Activa tu acceso a la gestión web';
        $text = "Hola,\n\n"
            . "Te han invitado a gestionar este sitio web. Crea tu "
            . "contraseña segura desde este enlace:\n\n{$url}\n\n"
            . "El enlace es personal, de un solo uso y caduca. Si no "
            . 'esperabas esta invitación, puedes ignorar el mensaje.';
        $html = $this->htmlDocument(
            $subject,
            'Te han invitado a gestionar este sitio web.',
            'Crear mi contraseña',
            $url,
            'El enlace es personal, de un solo uso y caduca. Si no '
                . 'esperabas esta invitación, puedes ignorar el mensaje.'
        );

        return new WebAdminMailMessage(
            $recipientEmail,
            null,
            $subject,
            $text,
            $html
        );
    }

    private function passwordReset(
        string $recipientEmail,
        #[SensitiveParameter] string $rawToken
    ): WebAdminMailMessage {
        $url = $this->actionUrl('/password/reset', $rawToken);
        $subject = 'Restablece tu contraseña de gestión web';
        $text = "Hola,\n\n"
            . "Se ha solicitado restablecer la contraseña de tu acceso a "
            . "la gestión web. Puedes crear una nueva desde este enlace:\n\n"
            . "{$url}\n\nEl enlace es personal, de un solo uso y caduca. "
            . 'Si no hiciste esta solicitud, puedes ignorar el mensaje.';
        $html = $this->htmlDocument(
            $subject,
            'Se ha solicitado restablecer la contraseña de tu acceso a la gestión web.',
            'Crear una nueva contraseña',
            $url,
            'El enlace es personal, de un solo uso y caduca. Si no hiciste '
                . 'esta solicitud, puedes ignorar el mensaje.'
        );

        return new WebAdminMailMessage(
            $recipientEmail,
            null,
            $subject,
            $text,
            $html
        );
    }

    private function actionUrl(
        string $path,
        #[SensitiveParameter] string $rawToken
    ): string {
        return $this->configuration->publicOrigin()
            . $this->basePath
            . $path
            . '?token='
            . rawurlencode($rawToken);
    }

    private function htmlDocument(
        string $heading,
        string $introduction,
        string $callToAction,
        #[SensitiveParameter] string $url,
        string $notice
    ): string {
        $escape = static fn (string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );

        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $escape($heading) . '</title></head><body>'
            . '<main><h1>' . $escape($heading) . '</h1>'
            . '<p>' . $escape($introduction) . '</p>'
            . '<p><a href="' . $escape($url) . '">'
            . $escape($callToAction) . '</a></p>'
            . '<p>' . $escape($notice) . '</p></main></body></html>';
    }

    private function validPublicOrigin(string $origin): bool
    {
        if (
            $origin === ''
            || rtrim($origin, '/') !== $origin
            || preg_match('/[\x00-\x20\x7F]/', $origin) === 1
            || filter_var($origin, FILTER_VALIDATE_URL) === false
        ) {
            return false;
        }

        $parts = parse_url($origin);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && is_string($parts['host'] ?? null)
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && in_array($parts['path'] ?? '', ['', '/'], true)
            && (!isset($parts['port'])
                || ((int) $parts['port'] >= 1
                    && (int) $parts['port'] <= 65535));
    }
}
