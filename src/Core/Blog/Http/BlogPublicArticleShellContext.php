<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\PublicShell\BlogPublicShellSecurityContext;

/** Typed public-shell state exposed to a project-owned article view. */
final class BlogPublicArticleShellContext
{
    public const PUBLIC_RUNTIME_URL = '/assets/modules/blog/blog-public.js';

    public function __construct(
        private readonly BlogPublicShellSecurityContext $security
    ) {
    }

    public function nonce(): string
    {
        return $this->security->nonce();
    }

    public function publicRuntimeUrl(): string
    {
        return self::PUBLIC_RUNTIME_URL;
    }

    public function security(): BlogPublicShellSecurityContext
    {
        return $this->security;
    }

    /** @return array<string, bool|string> */
    public function __debugInfo(): array
    {
        return [
            'nonce' => '[redacted]',
            'public_runtime_url' => self::PUBLIC_RUNTIME_URL,
            'security_headers' => array_fill_keys(
                array_keys($this->security->headers()),
                true
            ),
        ];
    }
}
