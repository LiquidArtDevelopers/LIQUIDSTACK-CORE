<?php

declare(strict_types=1);

namespace App\Core\Environment;

use InvalidArgumentException;
use ValueError;

/** Canonical loopback origins shared by the local PHP and Vite runtimes. */
final class DevelopmentServerOrigin
{
    public const VITE_ORIGIN_ENV = 'LIQUIDSTACK_DEV_VITE_ORIGIN';
    public const DEFAULT_VITE_ORIGIN = 'http://localhost:5173';

    private function __construct(
        private readonly string $httpOrigin,
        private readonly string $webSocketOrigin
    ) {
    }

    /** @param array<string, mixed> $environment */
    public static function viteFromEnvironment(array $environment): self
    {
        $value = $environment[self::VITE_ORIGIN_ENV] ?? null;
        if ($value === null || $value === '') {
            $value = self::DEFAULT_VITE_ORIGIN;
        }

        if (!is_string($value) || !self::hasSafeUrlShape($value)) {
            throw self::invalidOrigin();
        }

        try {
            $parts = parse_url($value);
        } catch (ValueError) {
            throw self::invalidOrigin();
        }

        if (!is_array($parts)) {
            throw self::invalidOrigin();
        }

        $rawHost = $parts['host'] ?? null;
        $port = $parts['port'] ?? null;
        if (
            ($parts['scheme'] ?? null) !== 'http'
            || !is_string($rawHost)
            || !is_int($port)
            || $port < 1
            || $port > 65535
            || self::containsNonOriginComponents($parts)
        ) {
            throw self::invalidOrigin();
        }

        $host = self::canonicalLoopbackHost($rawHost);
        if ($host === null) {
            throw self::invalidOrigin();
        }

        $authority = self::serializeHost($host) . ':' . $port;
        $canonical = 'http://' . $authority;
        if ($value !== $canonical) {
            throw self::invalidOrigin();
        }

        return new self($canonical, 'ws://' . $authority);
    }

    public function httpOrigin(): string
    {
        return $this->httpOrigin;
    }

    public function webSocketOrigin(): string
    {
        return $this->webSocketOrigin;
    }

    private static function hasSafeUrlShape(string $origin): bool
    {
        return $origin !== ''
            && strlen($origin) <= 255
            && trim($origin) === $origin
            && preg_match('/[\x00-\x20\x7F]/', $origin) !== 1
            && filter_var($origin, FILTER_VALIDATE_URL) !== false;
    }

    /** @param array<string, mixed> $parts */
    private static function containsNonOriginComponents(array $parts): bool
    {
        foreach (['user', 'pass', 'path', 'query', 'fragment'] as $part) {
            if (array_key_exists($part, $parts)) {
                return true;
            }
        }

        return false;
    }

    private static function canonicalLoopbackHost(string $rawHost): ?string
    {
        $bracketed = str_starts_with($rawHost, '[')
            && str_ends_with($rawHost, ']');
        if (str_starts_with($rawHost, '[') !== str_ends_with($rawHost, ']')) {
            return null;
        }

        $host = strtolower($bracketed ? substr($rawHost, 1, -1) : $rawHost);
        if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return null;
        }

        if ($host === '::1' && !$bracketed) {
            return null;
        }
        if ($host !== '::1' && $bracketed) {
            return null;
        }

        return $host;
    }

    private static function serializeHost(string $host): string
    {
        return $host === '::1' ? '[::1]' : $host;
    }

    private static function invalidOrigin(): InvalidArgumentException
    {
        return new InvalidArgumentException(
            'Development Vite origin must be an exact loopback HTTP origin with an explicit port.'
        );
    }
}
