<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicShell;

use InvalidArgumentException;

/** Validated nonce and security-only response headers for a public shell. */
class BlogPublicShellSecurityContext
{
    private const HEADER_NAMES = [
        'content-security-policy' => 'Content-Security-Policy',
        'content-security-policy-report-only' =>
            'Content-Security-Policy-Report-Only',
        'permissions-policy' => 'Permissions-Policy',
        'referrer-policy' => 'Referrer-Policy',
        'x-content-type-options' => 'X-Content-Type-Options',
        'x-frame-options' => 'X-Frame-Options',
        'cross-origin-opener-policy' => 'Cross-Origin-Opener-Policy',
        'cross-origin-resource-policy' => 'Cross-Origin-Resource-Policy',
        'cross-origin-embedder-policy' => 'Cross-Origin-Embedder-Policy',
        'origin-agent-cluster' => 'Origin-Agent-Cluster',
    ];

    /** @var array<string, string> */
    private readonly array $headers;

    /** @param array<string, string> $headers */
    public function __construct(
        #[\SensitiveParameter] private readonly string $nonce,
        array $headers
    ) {
        if (
            preg_match(
                '/\A[A-Za-z0-9+\/_-]{16,128}={0,2}\z/D',
                $nonce
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog public-shell CSP nonce.'
            );
        }

        $normalized = [];
        foreach ($headers as $name => $value) {
            $key = is_string($name) ? strtolower($name) : '';
            if (
                !isset(self::HEADER_NAMES[$key])
                || isset($normalized[self::HEADER_NAMES[$key]])
                || !is_string($value)
                || $value === ''
                || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1
            ) {
                throw new InvalidArgumentException(
                    'Invalid Blog public-shell security headers.'
                );
            }
            $normalized[self::HEADER_NAMES[$key]] = $value;
        }

        $csp = $normalized['Content-Security-Policy'] ?? null;
        if (
            !is_string($csp)
            || !str_contains($csp, "'nonce-{$nonce}'")
        ) {
            throw new InvalidArgumentException(
                'Blog public-shell CSP must authorize its nonce.'
            );
        }
        $this->headers = $normalized;
    }

    public function nonce(): string
    {
        return $this->nonce;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'nonce' => '[redacted]',
            'security_headers' => array_fill_keys(
                array_keys($this->headers),
                true
            ),
        ];
    }
}
