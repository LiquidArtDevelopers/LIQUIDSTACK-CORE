<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicShell;

use App\Core\Blog\StructuredContent\Document\BlogSafeIframePolicy;
use App\Core\Environment\DevelopmentServerOrigin;
use App\Core\Environment\ProjectRuntimeProfile;
use InvalidArgumentException;
use Throwable;

/** Secure default CSP with bounded project-owned origin extensions. */
class BlogPublicShellDefaultSecurityPolicy implements
    BlogPublicShellSecurityPolicyInterface
{
    /** @var list<string> */
    private readonly array $scriptSources;
    /** @var list<string> */
    private readonly array $styleSources;
    /** @var list<string> */
    private readonly array $imageSources;
    /** @var list<string> */
    private readonly array $fontSources;
    /** @var list<string> */
    private readonly array $connectSources;
    /** @var list<string> */
    private readonly array $frameSources;

    /**
     * Sources are exact origins. HTTP and WS are accepted only for loopback.
     * A project needing a different policy can inject the interface through
     * App/config/modules/blog-public.php.
     *
     * @param list<string> $scriptSources
     * @param list<string> $styleSources
     * @param list<string> $imageSources
     * @param list<string> $fontSources
     * @param list<string> $connectSources
     * @param list<string> $frameSources
     */
    public function __construct(
        array $scriptSources = [],
        array $styleSources = [],
        array $imageSources = [],
        array $fontSources = [],
        array $connectSources = [],
        array $frameSources = [],
        private readonly bool $upgradeInsecureRequests = true
    ) {
        $this->scriptSources = $this->normalizeSources(
            $scriptSources,
            false
        );
        $this->styleSources = $this->normalizeSources(
            $styleSources,
            false
        );
        $this->imageSources = $this->normalizeSources(
            $imageSources,
            false
        );
        $this->fontSources = $this->normalizeSources(
            $fontSources,
            false
        );
        $this->connectSources = $this->normalizeSources(
            $connectSources,
            true
        );
        $this->frameSources = $this->normalizeSources(
            $frameSources,
            false
        );
    }

    /** @param array<string, mixed> $environment */
    public static function fromEnvironment(
        array $environment,
        bool $environmentUsable = true
    ): static {
        $development = false;
        if (
            $environmentUsable
            && ($environment['DEV_MODE'] ?? null) === '1'
        ) {
            try {
                $development = ProjectRuntimeProfile::fromEnvironment(
                    $environment
                )->isDevelopmentLoopbackHttp();
            } catch (Throwable) {
                $development = false;
            }
        }
        if (!$development) {
            return new static();
        }

        try {
            $viteOrigin = DevelopmentServerOrigin::viteFromEnvironment(
                $environment
            );
        } catch (Throwable) {
            return new static();
        }

        $vite = $viteOrigin->httpOrigin();

        return new static(
            scriptSources: [$vite],
            styleSources: [$vite],
            imageSources: [$vite],
            fontSources: [$vite],
            connectSources: [$vite, $viteOrigin->webSocketOrigin()],
            upgradeInsecureRequests: false
        );
    }

    public function context(): BlogPublicShellSecurityContext
    {
        $nonce = rtrim(strtr(
            base64_encode(random_bytes(24)),
            '+/',
            '-_'
        ), '=');
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            'script-src ' . implode(' ', array_merge([
                "'nonce-{$nonce}'",
                "'strict-dynamic'",
                "'self'",
            ], $this->scriptSources)),
            "script-src-attr 'none'",
            'style-src ' . implode(' ', array_merge([
                "'self'",
                "'unsafe-inline'",
            ], $this->styleSources)),
            "style-src-attr 'unsafe-inline'",
            'img-src ' . implode(' ', array_merge([
                "'self'",
                'data:',
                'blob:',
            ], $this->imageSources)),
            'font-src ' . implode(' ', array_merge([
                "'self'",
                'data:',
            ], $this->fontSources)),
            'connect-src ' . implode(' ', array_merge([
                "'self'",
            ], $this->connectSources)),
            "media-src 'self'",
            'frame-src ' . implode(' ', array_values(array_unique(
                array_merge(
                    BlogSafeIframePolicy::cspSources(),
                    $this->frameSources
                )
            ))),
            "worker-src 'self' blob:",
            "manifest-src 'self'",
        ];
        if ($this->upgradeInsecureRequests) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return new BlogPublicShellSecurityContext($nonce, [
            'Content-Security-Policy' => implode('; ', $directives),
            'Permissions-Policy' =>
                'camera=(), microphone=(), geolocation=()',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }

    /** @param array<string, mixed> $sources */
    public function withAdditionalSources(array $sources): static
    {
        $allowed = ['script', 'style', 'image', 'font', 'connect', 'frame'];
        foreach (array_keys($sources) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new InvalidArgumentException(
                    'Unknown Blog public-shell CSP source family.'
                );
            }
        }
        foreach ($allowed as $key) {
            if (
                array_key_exists($key, $sources)
                && (!is_array($sources[$key])
                    || !array_is_list($sources[$key]))
            ) {
                throw new InvalidArgumentException(
                    'Blog public-shell CSP sources must be lists.'
                );
            }
        }

        return new static(
            array_merge($this->scriptSources, $sources['script'] ?? []),
            array_merge($this->styleSources, $sources['style'] ?? []),
            array_merge($this->imageSources, $sources['image'] ?? []),
            array_merge($this->fontSources, $sources['font'] ?? []),
            array_merge($this->connectSources, $sources['connect'] ?? []),
            array_merge($this->frameSources, $sources['frame'] ?? []),
            $this->upgradeInsecureRequests
        );
    }

    /**
     * @param list<string> $sources
     * @return list<string>
     */
    private function normalizeSources(
        array $sources,
        bool $allowWebSocket
    ): array {
        $normalized = [];
        foreach ($sources as $source) {
            if (!is_string($source) || !$this->isSafeOrigin(
                $source,
                $allowWebSocket
            )) {
                throw new InvalidArgumentException(
                    'Invalid Blog public-shell CSP source.'
                );
            }
            $source = rtrim($source, '/');
            $normalized[$source] = $source;
        }

        return array_values($normalized);
    }

    private function isSafeOrigin(
        string $source,
        bool $allowWebSocket
    ): bool {
        if (
            $source === ''
            || trim($source) !== $source
            || strlen($source) > 2_048
            || preg_match('/[\x00-\x20\x7F;,\'\"]/', $source) === 1
            || filter_var($source, FILTER_VALIDATE_URL) === false
        ) {
            return false;
        }
        $parts = parse_url($source);
        if (!is_array($parts) || !is_string($parts['host'] ?? null)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $loopback = $this->isLoopbackHost($parts['host']);
        if (
            !in_array($scheme, $allowWebSocket
                ? ['https', 'http', 'wss', 'ws']
                : ['https', 'http'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)
        ) {
            return false;
        }
        if ($loopback && $this->upgradeInsecureRequests) {
            return false;
        }
        if (in_array($scheme, ['http', 'ws'], true)) {
            return !$this->upgradeInsecureRequests
                && $loopback;
        }

        return true;
    }

    private function isLoopbackHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || $host === '::1') {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        return str_starts_with($host, '127.');
    }
}
