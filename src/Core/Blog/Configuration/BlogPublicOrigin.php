<?php

declare(strict_types=1);

namespace App\Core\Blog\Configuration;

use App\Core\WebAdmin\Mail\WebAdminMailConfiguration;

final class BlogPublicOrigin
{
    public const ENV = WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV;

    private function __construct(private readonly string $value)
    {
    }

    /** @param array<string, mixed> $environment */
    public static function fromEnvironment(
        #[\SensitiveParameter] array $environment
    ): self {
        $value = $environment[self::ENV] ?? null;
        if (!is_string($value) || !self::isValid($value)) {
            throw new BlogConfigException(
                $value === null || $value === ''
                    ? 'environment.public_origin_missing'
                    : 'environment.public_origin_invalid',
                self::ENV
            );
        }

        return new self(rtrim($value, '/'));
    }

    public function value(): string
    {
        return $this->value;
    }

    public function absoluteUrl(string $path): string
    {
        if (
            preg_match(
                '#\A/[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?:/[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)*\z#',
                $path
            ) !== 1
        ) {
            throw new BlogConfigException(
                'config.invalid_absolute_path',
                'public_url'
            );
        }

        return $this->value . $path;
    }

    private static function isValid(string $value): bool
    {
        if (
            $value === ''
            || strlen($value) > 2048
            || trim($value) !== $value
            || preg_match('/[\x00-\x20\x7F]/', $value) === 1
            || filter_var($value, FILTER_VALIDATE_URL) === false
        ) {
            return false;
        }
        $parts = parse_url($value);
        if (!is_array($parts)) {
            return false;
        }
        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? '';

        return ($parts['scheme'] ?? null) === 'https'
            && is_string($host)
            && self::isValidHost($host)
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && in_array($path, ['', '/'], true)
            && (!isset($parts['port'])
                || ((int) $parts['port'] >= 1
                    && (int) $parts['port'] <= 65535));
    }

    private static function isValidHost(string $host): bool
    {
        return strlen($host) <= 253
            && preg_match('/[\x00-\x20\x7F]/', $host) !== 1
            && (
                filter_var($host, FILTER_VALIDATE_IP) !== false
                || filter_var(
                    $host,
                    FILTER_VALIDATE_DOMAIN,
                    FILTER_FLAG_HOSTNAME
                ) !== false
            );
    }
}
