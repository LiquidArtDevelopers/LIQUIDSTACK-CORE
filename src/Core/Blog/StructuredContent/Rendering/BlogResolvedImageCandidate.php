<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

/** One validated AVIF candidate for an image srcset. */
final class BlogResolvedImageCandidate
{
    public const MAX_WIDTH = 2_560;
    public const MAX_URL_BYTES = 2_048;

    public function __construct(
        private readonly string $url,
        private readonly int $width
    ) {
        if (
            $width < 1
            || $width > self::MAX_WIDTH
            || !$this->isSafeUrl($url)
        ) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_IMAGE_PRESENTATION
            );
        }
    }

    public function url(): string
    {
        return $this->url;
    }

    public function width(): int
    {
        return $this->width;
    }

    private function isSafeUrl(string $url): bool
    {
        if (
            $url === ''
            || trim($url) !== $url
            || strlen($url) > self::MAX_URL_BYTES
            || preg_match('//u', $url) !== 1
            || preg_match('/\p{Cc}/u', $url) === 1
            || preg_match('/\s/u', $url) === 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $url) === 1
            || str_contains($url, '\\')
            || str_contains($url, '#')
            || str_contains($url, ',')
            || preg_match('/[<>"\']/', $url) === 1
        ) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//')
                && $this->hasSafePath($url);
        }

        if (!str_starts_with($url, 'https://')) {
            return false;
        }

        $parts = parse_url($url);
        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !is_string($parts['host'] ?? null)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return false;
        }

        return $this->hasSafePath($url);
    }

    private function hasSafePath(string $url): bool
    {
        $parts = parse_url($url);
        $path = is_array($parts) ? ($parts['path'] ?? null) : null;
        if (
            !is_string($path)
            || !str_starts_with($path, '/')
            || str_contains($path, '//')
            || preg_match('/%(?:2f|5c)/i', $path) === 1
        ) {
            return false;
        }

        $decoded = $path;
        $stable = false;
        for ($pass = 0; $pass < 8; ++$pass) {
            if (
                preg_match('/%(?:2f|5c)/i', $decoded) === 1
                || preg_match('/%(?![0-9A-Fa-f]{2})/', $decoded) === 1
            ) {
                return false;
            }

            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                $stable = true;
                break;
            }
            $decoded = $next;
        }
        if (
            !$stable
            || preg_match('//u', $decoded) !== 1
            || preg_match('/\p{Cc}/u', $decoded) === 1
            || str_contains($decoded, '\\')
            || str_contains($decoded, '//')
        ) {
            return false;
        }

        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
