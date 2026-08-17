<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Http\Response;
use App\Core\Blog\Preview\BlogPreviewAssetSet;
use App\Core\Blog\StructuredContent\Rendering\BlogEditorPreviewSandboxPolicy;
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
                . "script-src 'self'; connect-src 'self'; frame-src 'self'; "
                . "form-action 'self'; frame-ancestors "
                . "'none'; base-uri 'none'"
        ) + [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Language' => 'es',
        ]);
    }

    public function editorHtml(
        int $status,
        string $body,
        BlogEditorPreviewSandboxPolicy $previewSandbox
    ): Response {
        return new Response($status, $body, $this->headers(
            "default-src 'none'; img-src 'self' data:; style-src 'self' "
                . $previewSandbox->styleSource()
                . "; style-src-elem 'self' "
                . $previewSandbox->styleSource()
                . "; style-src-attr 'none'; font-src 'self'; "
                . "script-src 'self'; "
                . "script-src-attr 'none'; connect-src 'self'; "
                . "frame-src 'self'; form-action 'self'; frame-ancestors "
                . "'none'; base-uri 'none'; object-src 'none'"
        ) + [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Language' => 'es',
        ]);
    }

    /** Private SSR preview embeddable only by the same WebAdmin origin. */
    public function previewHtml(
        int $status,
        string $body,
        string $contentLanguage,
        BlogPreviewAssetSet $assets,
        #[\SensitiveParameter] ?string $styleNonce = null
    ): Response
    {
        if (
            preg_match(
                '/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*\z/D',
                $contentLanguage
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Invalid preview content language.'
            );
        }
        $headers = $this->headers(
            $assets->contentSecurityPolicy($styleNonce)
        );
        $headers['X-Frame-Options'] = 'SAMEORIGIN';

        return new Response($status, $body, $headers + [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Language' => $contentLanguage,
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
