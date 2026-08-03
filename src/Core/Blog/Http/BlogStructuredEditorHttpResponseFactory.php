<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Http\Response;
use App\Core\WebAdmin\Configuration\WebAdminConfig;

final class BlogStructuredEditorHttpResponseFactory
{
    public function __construct(private readonly WebAdminConfig $config)
    {
    }

    public function html(int $status, string $body): Response
    {
        return new Response($status, $body, $this->headers(
            "default-src 'none'; img-src 'self' data:; style-src 'self'; "
                . "script-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors "
                . "'none'; base-uri 'none'"
        ) + [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Language' => 'es',
        ]);
    }

    public function plain(int $status, string $body): Response
    {
        return new Response($status, $body, $this->headers(
            "default-src 'none'; form-action 'none'; frame-ancestors "
                . "'none'; base-uri 'none'"
        ) + ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** @param array<string, mixed> $payload */
    public function json(int $status, array $payload): Response
    {
        return new Response(
            $status,
            json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            ),
            $this->headers(
                "default-src 'none'; form-action 'none'; frame-ancestors "
                    . "'none'; base-uri 'none'"
            ) + ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public function redirect(string $path): Response
    {
        return new Response(303, '', ['Location' => $path] + $this->headers(
            "default-src 'none'; form-action 'none'; frame-ancestors "
                . "'none'; base-uri 'none'"
        ));
    }

    public function expireSession(Response $response): Response
    {
        return $response->withAddedHeader(
            'Set-Cookie',
            $this->config->cookieName() . '=; Path='
                . $this->config->cookiePath()
                . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0'
                . '; Secure; HttpOnly; SameSite='
                . WebAdminConfig::COOKIE_SAME_SITE
        );
    }

    /** @return array<string, string> */
    private function headers(string $csp): array
    {
        return [
            'Cache-Control' =>
                'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Content-Security-Policy' => $csp,
            'Permissions-Policy' =>
                'camera=(), microphone=(), geolocation=()',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
    }
}
