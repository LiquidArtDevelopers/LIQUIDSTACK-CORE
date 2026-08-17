<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

use ValueError;

/** Shared, fail-closed policy for iframe HTML and matching CSP sources. */
final class BlogSafeIframePolicy
{
    public const DEFAULT_TITLE = 'Contenido multimedia incrustado';

    /** @var list<array{host: string, path_prefix: string}> */
    private const SOURCES = [
        ['host' => 'www.youtube-nocookie.com', 'path_prefix' => '/embed/'],
        ['host' => 'youtube-nocookie.com', 'path_prefix' => '/embed/'],
        ['host' => 'www.youtube.com', 'path_prefix' => '/embed/'],
        ['host' => 'youtube.com', 'path_prefix' => '/embed/'],
        ['host' => 'player.vimeo.com', 'path_prefix' => '/video/'],
        ['host' => 'www.google.com', 'path_prefix' => '/maps/embed'],
        ['host' => 'maps.google.com', 'path_prefix' => '/maps/embed'],
    ];

    /** @return list<string> */
    public static function attributes(): array
    {
        return [
            'src', 'title', 'width', 'height', 'loading', 'referrerpolicy',
            'allow', 'allowfullscreen', 'sandbox',
        ];
    }

    /** @return list<array{host: string, path_prefix: string}> */
    public static function sources(): array
    {
        return self::SOURCES;
    }

    /** @return list<string> */
    public static function cspSources(): array
    {
        $sources = [];
        foreach (self::SOURCES as $source) {
            $sources['https://' . $source['host']] = true;
        }

        return array_keys($sources);
    }

    /**
     * User-supplied capability attributes never pass through. The canonical
     * iframe receives a fixed least-privilege baseline suitable for the
     * explicitly allowed cross-origin providers.
     *
     * @param array<string, string> $raw
     * @return array<string, string>|null
     */
    public function canonicalAttributes(array $raw): ?array
    {
        $src = $this->safeSource($raw['src'] ?? null);
        if ($src === null) {
            return null;
        }

        $attributes = [
            'allow' => 'accelerometer; autoplay; clipboard-write; '
                . 'encrypted-media; fullscreen; gyroscope; '
                . 'picture-in-picture; web-share',
            'allowfullscreen' => 'allowfullscreen',
            'loading' => 'lazy',
            'referrerpolicy' => 'strict-origin-when-cross-origin',
            'sandbox' => 'allow-scripts allow-same-origin allow-presentation',
            'src' => $src,
            'title' => $this->plainAttribute($raw['title'] ?? null)
                ?? self::DEFAULT_TITLE,
        ];
        foreach (['height', 'width'] as $name) {
            $value = $raw[$name] ?? null;
            if (
                is_string($value)
                && preg_match('/\A[1-9][0-9]{0,3}\z/D', trim($value)) === 1
            ) {
                $attributes[$name] = trim($value);
            }
        }
        ksort($attributes, SORT_STRING);

        return $attributes;
    }

    private function safeSource(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (
            $value === ''
            || strlen($value) > BlogDocumentValidator::MAX_URL_BYTES
            || preg_match('/[\x00-\x20\x7F]/', $value) === 1
            || str_contains($value, '\\')
            || filter_var($value, FILTER_VALIDATE_URL) === false
        ) {
            return null;
        }
        try {
            $parts = parse_url($value);
        } catch (ValueError) {
            return null;
        }
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !is_string($parts['host'] ?? null)
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('port', $parts)
            || array_key_exists('fragment', $parts)
        ) {
            return null;
        }
        $host = strtolower($parts['host']);
        $path = is_string($parts['path'] ?? null) ? $parts['path'] : '/';
        if (
            $path === ''
            // Provider embed identifiers never need path escapes. Keeping
            // them out also closes single and repeatedly encoded traversal.
            || str_contains($path, '%')
        ) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }
        foreach (self::SOURCES as $source) {
            if (
                $host === $source['host']
                && $this->pathMatches($path, $source['path_prefix'])
            ) {
                return $value;
            }
        }

        return null;
    }

    private function pathMatches(string $path, string $prefix): bool
    {
        return str_ends_with($prefix, '/')
            ? str_starts_with($path, $prefix) && strlen($path) > strlen($prefix)
            : $path === $prefix;
    }

    private function plainAttribute(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== ''
            && strlen($value) <= BlogDocumentValidator::MAX_TITLE_BYTES
            && preg_match('//u', $value) === 1
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
                ? $value : null;
    }
}
