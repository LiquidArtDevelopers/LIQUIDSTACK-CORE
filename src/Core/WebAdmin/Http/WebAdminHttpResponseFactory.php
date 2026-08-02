<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

use App\Core\Http\Response;
use App\Core\WebAdmin\Authentication\SessionSecrets;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\CredentialAction\CredentialActionSessionSecrets;
use DateTimeZone;

/**
 * Owns the common private-response and cookie contract for every WebAdmin
 * HTTP coordinator.
 */
final class WebAdminHttpResponseFactory
{
    public function __construct(
        private readonly WebAdminConfig $config
    ) {
    }

    public function rootPath(): string
    {
        return $this->config->basePath();
    }

    public function loginPath(): string
    {
        return $this->rootPath() . '/login';
    }

    /** @param array<string, string> $headers */
    public function html(
        int $status,
        string $body,
        array $headers = []
    ): Response {
        return new Response($status, $body, $headers + $this->headers(
            "default-src 'none'; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"
        ) + [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Language' => 'es',
        ]);
    }

    /** @param array<string, string> $headers */
    public function plain(
        int $status,
        string $body,
        array $headers = []
    ): Response {
        return new Response($status, $body, $headers + $this->headers(
            "default-src 'none'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'"
        ) + ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function redirect(string $path): Response
    {
        return new Response(303, '', ['Location' => $path] + $this->headers(
            "default-src 'none'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'"
        ));
    }

    public function withSessionCookie(
        Response $response,
        SessionSecrets $secrets
    ): Response {
        $expires = $secrets->absoluteExpiresAt()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('D, d M Y H:i:s \G\M\T');
        $authenticated = $secrets->isAuthenticated();
        $value = ($authenticated
                ? $this->config->cookieName()
                : $this->config->preAuthenticationCookieName())
            . '=' . rawurlencode($secrets->sessionToken())
            . '; Path=' . $this->config->cookiePath()
            . '; Expires=' . $expires
            . '; Secure; HttpOnly; SameSite='
            . ($authenticated
                ? WebAdminConfig::COOKIE_SAME_SITE
                : WebAdminConfig::PREAUTH_COOKIE_SAME_SITE);

        $response = $response->withAddedHeader('Set-Cookie', $value);

        return $authenticated
            ? $this->withExpiredPreAuthenticationCookie($response)
            : $response;
    }

    public function withExpiredCookie(Response $response): Response
    {
        $value = $this->config->cookieName()
            . '=; Path=' . $this->config->cookiePath()
            . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0'
            . '; Secure; HttpOnly; SameSite='
            . WebAdminConfig::COOKIE_SAME_SITE;

        return $response->withAddedHeader('Set-Cookie', $value);
    }

    public function withExpiredPreAuthenticationCookie(
        Response $response
    ): Response {
        $value = $this->config->preAuthenticationCookieName()
            . '=; Path=' . $this->config->cookiePath()
            . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0'
            . '; Secure; HttpOnly; SameSite='
            . WebAdminConfig::PREAUTH_COOKIE_SAME_SITE;

        return $response->withAddedHeader('Set-Cookie', $value);
    }

    public function withActionCookie(
        Response $response,
        CredentialActionSessionSecrets $secrets
    ): Response {
        $expires = $secrets->absoluteExpiresAt()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('D, d M Y H:i:s \G\M\T');
        $value = $this->config->actionCookieName()
            . '=' . rawurlencode($secrets->sessionToken())
            . '; Path=' . $this->config->cookiePath()
            . '; Expires=' . $expires
            . '; Secure; HttpOnly; SameSite='
            . WebAdminConfig::ACTION_COOKIE_SAME_SITE;

        return $response->withAddedHeader('Set-Cookie', $value);
    }

    public function withExpiredActionCookie(Response $response): Response
    {
        $value = $this->config->actionCookieName()
            . '=; Path=' . $this->config->cookiePath()
            . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0'
            . '; Secure; HttpOnly; SameSite='
            . WebAdminConfig::ACTION_COOKIE_SAME_SITE;

        return $response->withAddedHeader('Set-Cookie', $value);
    }

    /** @return array<string, string> */
    private function headers(string $csp): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Content-Security-Policy' => $csp,
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
    }
}
