<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Http;

use App\Core\Http\Response;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Media\MediaFilePayload;
use App\Core\WebAdmin\Media\MediaFileMetadata;

final class WebAdminMediaHttpResponseFactory
{
    public function __construct(private readonly WebAdminConfig $config)
    {
    }

    public function html(int $status, string $body): Response
    {
        return new Response($status, $body, $this->headers(
            "default-src 'none'; img-src 'self'; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"
        ) + [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Language' => 'es',
        ]);
    }

    public function plain(int $status, string $body): Response
    {
        return new Response($status, $body, $this->headers(
            "default-src 'none'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'"
        ) + ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function avif(MediaFilePayload $file): Response
    {
        return new Response(200, $file->contents(), $this->headers(
            "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"
        ) + [
            'Content-Type' => 'image/avif',
            'Content-Length' => (string) $file->bytes(),
        ]);
    }

    public function avifMetadata(MediaFileMetadata $file): Response
    {
        return new Response(200, '', $this->headers(
            "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"
        ) + [
            'Content-Type' => 'image/avif',
            'Content-Length' => (string) $file->bytes(),
        ]);
    }

    public function redirect(string $path): Response
    {
        return new Response(303, '', ['Location' => $path] + $this->headers(
            "default-src 'none'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'"
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
