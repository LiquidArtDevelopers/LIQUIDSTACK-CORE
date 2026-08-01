<?php

declare(strict_types=1);

namespace App\Core\Environment;

use InvalidArgumentException;
use ValueError;

final class ProjectRuntimeProfile
{
    public const ORIGIN_ENV = 'RAIZ';
    public const DEVELOPMENT_MODE_ENV = 'DEV_MODE';

    private function __construct(
        private readonly string $origin,
        private readonly string $authority,
        private readonly bool $developmentLoopbackHttp
    ) {
    }

    /** @param array<string, mixed> $environment */
    public static function fromEnvironment(
        #[\SensitiveParameter] array $environment
    ): self {
        $origin = $environment[self::ORIGIN_ENV] ?? null;
        if (!is_string($origin) || !self::hasSafeUrlShape($origin)) {
            throw self::invalidProfile();
        }

        try {
            $parts = parse_url($origin);
        } catch (ValueError) {
            throw self::invalidProfile();
        }

        if (!is_array($parts)) {
            throw self::invalidProfile();
        }

        $scheme = $parts['scheme'] ?? null;
        $rawHost = $parts['host'] ?? null;
        if (
            !is_string($scheme)
            || !in_array($scheme, ['http', 'https'], true)
            || !is_string($rawHost)
            || $rawHost === ''
            || self::containsNonOriginComponents($parts)
        ) {
            throw self::invalidProfile();
        }

        $host = self::canonicalHost($rawHost);
        if ($host === null) {
            throw self::invalidProfile();
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && (!is_int($port) || $port < 1 || $port > 65535)) {
            throw self::invalidProfile();
        }

        $authority = self::serializeHost($host)
            . ($port === null ? '' : ':' . $port);
        $canonicalOrigin = $scheme . '://' . $authority;

        // A trailing root slash is a common legacy RAIZ spelling. Normalize
        // only that harmless variant; every other component remains exact so
        // Host comparisons keep one unambiguous authority.
        if (rtrim($origin, '/') !== $canonicalOrigin) {
            throw self::invalidProfile();
        }

        $developmentLoopbackHttp = $scheme === 'http'
            && self::developmentModeEnabled(
                $environment[self::DEVELOPMENT_MODE_ENV] ?? null
            )
            && self::isExactLoopbackHost($host);

        if ($scheme === 'http' && !$developmentLoopbackHttp) {
            throw self::invalidProfile();
        }

        return new self(
            $canonicalOrigin,
            $authority,
            $developmentLoopbackHttp
        );
    }

    public function origin(): string
    {
        return $this->origin;
    }

    public function authority(): string
    {
        return $this->authority;
    }

    public function isDevelopmentLoopbackHttp(): bool
    {
        return $this->developmentLoopbackHttp;
    }

    private static function hasSafeUrlShape(string $origin): bool
    {
        return $origin !== ''
            && strlen($origin) <= 2048
            && trim($origin) === $origin
            && preg_match('/[\x00-\x20\x7F]/', $origin) !== 1
            && filter_var($origin, FILTER_VALIDATE_URL) !== false;
    }

    /** @param array<string, mixed> $parts */
    private static function containsNonOriginComponents(array $parts): bool
    {
        foreach (['user', 'pass', 'query', 'fragment'] as $component) {
            if (array_key_exists($component, $parts)) {
                return true;
            }
        }

        return !in_array($parts['path'] ?? '', ['', '/'], true);
    }

    private static function canonicalHost(string $rawHost): ?string
    {
        $hasOpeningBracket = str_starts_with($rawHost, '[');
        $hasClosingBracket = str_ends_with($rawHost, ']');
        if ($hasOpeningBracket !== $hasClosingBracket) {
            return null;
        }

        $host = $hasOpeningBracket
            ? substr($rawHost, 1, -1)
            : $rawHost;
        if ($host === '') {
            return null;
        }

        $host = strtolower($host);
        $isIpAddress = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $isHostname = filter_var(
            $host,
            FILTER_VALIDATE_DOMAIN,
            FILTER_FLAG_HOSTNAME
        ) !== false;

        if (!$isIpAddress && !$isHostname) {
            return null;
        }

        if (
            $hasOpeningBracket
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false
        ) {
            return null;
        }

        if (!$hasOpeningBracket && str_contains($host, ':')) {
            return null;
        }

        return $host;
    }

    private static function serializeHost(string $host): string
    {
        return str_contains($host, ':') ? '[' . $host . ']' : $host;
    }

    private static function developmentModeEnabled(mixed $value): bool
    {
        if ($value === true) {
            return true;
        }

        return is_string($value)
            && in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    private static function isExactLoopbackHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private static function invalidProfile(): InvalidArgumentException
    {
        return new InvalidArgumentException(
            'Project runtime profile contains an invalid origin.'
        );
    }
}
