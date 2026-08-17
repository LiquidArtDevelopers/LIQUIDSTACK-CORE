<?php

declare(strict_types=1);

namespace App\Core\Blog\Preview;

use App\Core\Blog\StructuredContent\Document\BlogSafeIframePolicy;
use InvalidArgumentException;
use ValueError;

/**
 * Validated assets and CSP additions for the isolated private preview.
 *
 * The adapter resolves Vite development/build details. CORE only serializes
 * the resulting URLs and derives a closed CSP; it never guesses an entrypoint
 * or a hashed filename from the consumer.
 */
final class BlogPreviewAssetSet
{
    private const MAX_ITEMS = 24;

    /** @var list<string> */
    private readonly array $stylesheets;
    /** @var list<string> */
    private readonly array $moduleScripts;
    /** @var list<string> */
    private readonly array $deferredScripts;
    /** @var list<string> */
    private readonly array $connectSources;
    /** @var list<string> */
    private readonly array $imageSources;
    /** @var list<string> */
    private readonly array $fontSources;
    /** @var list<string> */
    private readonly array $frameSources;
    /** @var list<string> */
    private readonly array $mediaSources;
    /** @var list<string> */
    private readonly array $workerSources;
    /** @var list<string> */
    private readonly array $editorStylesheets;

    /**
     * @param list<string> $stylesheets
     * @param list<string> $moduleScripts
     * @param list<string> $deferredScripts
     * @param list<string> $connectSources
     * @param list<string> $imageSources
     * @param list<string> $fontSources
     * @param list<string> $frameSources
     * @param list<string> $mediaSources
     * @param list<string> $workerSources
     * @param list<string> $editorStylesheets
     */
    public function __construct(
        private readonly BlogPreviewAssetContext $context,
        array $stylesheets = [],
        array $moduleScripts = [],
        array $deferredScripts = [],
        array $connectSources = [],
        array $imageSources = [],
        array $fontSources = [],
        array $frameSources = [],
        array $mediaSources = [],
        array $workerSources = [],
        array $editorStylesheets = []
    ) {
        $this->stylesheets = $this->normalizeAssets($stylesheets);
        $this->moduleScripts = $this->normalizeAssets($moduleScripts);
        $this->deferredScripts = $this->normalizeAssets($deferredScripts);
        $this->connectSources = $this->normalizeSources(
            $connectSources,
            'connect'
        );
        $this->imageSources = $this->normalizeSources($imageSources, 'image');
        $this->fontSources = $this->normalizeSources($fontSources, 'font');
        $this->frameSources = $this->normalizeSources($frameSources, 'frame');
        $this->mediaSources = $this->normalizeSources($mediaSources, 'media');
        $this->workerSources = $this->normalizeSources($workerSources, 'worker');
        $this->editorStylesheets = $this->normalizeEditorStylesheets(
            $editorStylesheets
        );
    }

    public static function standalone(BlogPreviewAssetContext $context): self
    {
        return new self(
            $context,
            ['/assets/modules/blog/blog-public.css'],
            deferredScripts: ['/assets/modules/blog/blog-public.js']
        );
    }

    /** @return list<string> */
    public function stylesheets(): array
    {
        return $this->stylesheets;
    }

    /** @return list<string> */
    public function moduleScripts(): array
    {
        return $this->moduleScripts;
    }

    /** @return list<string> */
    public function deferredScripts(): array
    {
        return $this->deferredScripts;
    }

    /** @return list<string> */
    public function editorStylesheets(): array
    {
        return $this->editorStylesheets;
    }

    public function belongsTo(BlogPreviewAssetContext $context): bool
    {
        return $this->context->matches($context);
    }

    public function contentSecurityPolicy(
        #[\SensitiveParameter] ?string $styleNonce = null
    ): string
    {
        $assetOrigins = $this->assetOrigins(array_merge(
            $this->stylesheets,
            $this->moduleScripts,
            $this->deferredScripts
        ));
        $styleOrigins = $this->assetOrigins($this->stylesheets);
        $scriptOrigins = $this->assetOrigins(array_merge(
            $this->moduleScripts,
            $this->deferredScripts
        ));

        $styleSources = $this->mergeSources(["'self'"], $styleOrigins);
        $scriptSources = $this->mergeSources(["'self'"], $scriptOrigins);
        if ($styleNonce !== null) {
            if (preg_match(
                '/\A[A-Za-z0-9+\/_-]{16,128}={0,2}\z/D',
                $styleNonce
            ) !== 1) {
                throw new InvalidArgumentException(
                    'Invalid private preview style nonce.'
                );
            }
            $nonceSource = "'nonce-" . $styleNonce . "'";
            $styleSources[] = $nonceSource;
            $scriptSources[] = $nonceSource;
        }

        $directives = [
            "default-src 'none'",
            'img-src ' . implode(' ', $this->mergeSources(
                ["'self'", 'data:'],
                $assetOrigins,
                $this->imageSources
            )),
            'font-src ' . implode(' ', $this->mergeSources(
                ["'self'", 'data:'],
                $assetOrigins,
                $this->fontSources
            )),
            'style-src ' . implode(' ', $styleSources),
            'style-src-elem ' . implode(' ', $styleSources),
            "style-src-attr 'none'",
            'script-src ' . implode(' ', $scriptSources),
            'script-src-elem ' . implode(' ', $scriptSources),
            "script-src-attr 'none'",
            'connect-src ' . implode(' ', $this->mergeSources(
                ["'self'"],
                $this->connectSources
            )),
            'frame-src ' . implode(' ', $this->mergeSources(
                BlogSafeIframePolicy::cspSources(),
                $this->frameSources
            )),
            'media-src ' . implode(' ', $this->mergeSources(
                ["'self'"],
                $this->mediaSources
            )),
            'worker-src ' . implode(' ', $this->mergeSources(
                ["'self'"],
                $this->workerSources
            )),
            "form-action 'self'",
            "frame-ancestors 'self'",
            "base-uri 'none'",
            "object-src 'none'",
        ];

        return implode('; ', $directives);
    }

    /** @param list<string> $values @return list<string> */
    private function normalizeAssets(array $values): array
    {
        $this->assertList($values);
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value) || !$this->isSafeAssetUrl($value)) {
                throw new InvalidArgumentException(
                    'Invalid private preview asset URL.'
                );
            }
            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    /** @param list<string> $values @return list<string> */
    private function normalizeEditorStylesheets(array $values): array
    {
        $this->assertList($values);
        $normalized = [];
        foreach ($values as $value) {
            if (
                !is_string($value)
                || preg_match(
                    '#\A/assets/css/[A-Za-z0-9][A-Za-z0-9._-]*\.css\z#D',
                    $value
                ) !== 1
                || str_contains($value, '..')
            ) {
                throw new InvalidArgumentException(
                    'Invalid Blog editor stylesheet URL.'
                );
            }
            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function normalizeSources(array $values, string $kind): array
    {
        $this->assertList($values);
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value) || !$this->isSafeCspSource($value, $kind)) {
                throw new InvalidArgumentException(
                    'Invalid private preview CSP source.'
                );
            }
            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    /** @param array<mixed> $values */
    private function assertList(array $values): void
    {
        if (!array_is_list($values) || count($values) > self::MAX_ITEMS) {
            throw new InvalidArgumentException(
                'Invalid private preview asset collection.'
            );
        }
    }

    private function isSafeAssetUrl(string $value): bool
    {
        if (!$this->hasSafeUrlShape($value)) {
            return false;
        }
        if (str_starts_with($value, '/')) {
            return !str_starts_with($value, '//');
        }

        $parts = $this->urlParts($value);
        if ($parts === null || $this->hasForbiddenUrlParts($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return in_array($scheme, ['https', 'http'], true)
            && $this->allowsSchemeHost($scheme, $parts['host'] ?? null);
    }

    private function isSafeCspSource(string $value, string $kind): bool
    {
        if ($value === 'data:') {
            return in_array($kind, ['image', 'font'], true);
        }
        if ($value === 'blob:') {
            return in_array($kind, ['image', 'worker'], true);
        }
        if (!$this->hasSafeUrlShape($value)) {
            return false;
        }

        $parts = $this->urlParts($value);
        if (
            $parts === null
            || $this->hasForbiddenUrlParts($parts)
            || array_key_exists('query', $parts)
            || !in_array($parts['path'] ?? '', ['', '/'], true)
        ) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (in_array($scheme, ['ws', 'wss'], true)) {
            return $kind === 'connect'
                && ($scheme === 'wss' || $this->allowsSchemeHost(
                    $scheme,
                    $parts['host'] ?? null
                ));
        }

        return in_array($scheme, ['https', 'http'], true)
            && $this->allowsSchemeHost($scheme, $parts['host'] ?? null);
    }

    private function allowsSchemeHost(string $scheme, mixed $host): bool
    {
        $host = is_string($host) ? $this->canonicalHost($host) : null;
        if ($host === null) {
            return false;
        }
        if (in_array($scheme, ['https', 'wss'], true)) {
            return true;
        }

        return $this->context->isDevelopment()
            && in_array($host, [
                'localhost',
                '127.0.0.1',
                '::1',
            ], true);
    }

    private function canonicalHost(string $rawHost): ?string
    {
        $openingBracket = str_starts_with($rawHost, '[');
        $closingBracket = str_ends_with($rawHost, ']');
        if ($openingBracket !== $closingBracket) {
            return null;
        }
        $host = strtolower($openingBracket
            ? substr($rawHost, 1, -1)
            : $rawHost);
        if ($host === '') {
            return null;
        }
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $isDomain = filter_var(
            $host,
            FILTER_VALIDATE_DOMAIN,
            FILTER_FLAG_HOSTNAME
        ) !== false;
        if (!$isIp && !$isDomain) {
            return null;
        }
        if (
            $openingBracket
            && filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6
            ) === false
        ) {
            return null;
        }
        if (!$openingBracket && str_contains($host, ':')) {
            return null;
        }

        return $host;
    }

    private function hasSafeUrlShape(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 2048
            && trim($value) === $value
            && preg_match('/[\x00-\x20\x7F"\'<>]/', $value) !== 1;
    }

    /** @return null|array<string, mixed> */
    private function urlParts(string $value): ?array
    {
        try {
            $parts = parse_url($value);
        } catch (ValueError) {
            return null;
        }

        return is_array($parts) ? $parts : null;
    }

    /** @param array<string, mixed> $parts */
    private function hasForbiddenUrlParts(array $parts): bool
    {
        return array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('fragment', $parts)
            || !is_string($parts['host'] ?? null);
    }

    /** @param list<string> $assets @return list<string> */
    private function assetOrigins(array $assets): array
    {
        $origins = [];
        foreach ($assets as $asset) {
            if (str_starts_with($asset, '/')) {
                continue;
            }
            $parts = $this->urlParts($asset);
            if ($parts === null) {
                continue;
            }
            $scheme = strtolower((string) $parts['scheme']);
            $host = strtolower((string) $parts['host']);
            if (str_contains($host, ':')) {
                $host = '[' . trim($host, '[]') . ']';
            }
            $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
            $origins[$scheme . '://' . $host . $port] = true;
        }

        return array_keys($origins);
    }

    /** @param list<string> ...$groups @return list<string> */
    private function mergeSources(array ...$groups): array
    {
        $merged = [];
        foreach ($groups as $group) {
            foreach ($group as $source) {
                $merged[$source] = true;
            }
        }

        return array_keys($merged);
    }
}
