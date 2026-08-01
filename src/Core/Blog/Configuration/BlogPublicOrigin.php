<?php

declare(strict_types=1);

namespace App\Core\Blog\Configuration;

use App\Core\Environment\ProjectRuntimeProfile;
use App\Core\WebAdmin\Mail\WebAdminMailConfiguration;
use InvalidArgumentException;

final class BlogPublicOrigin
{
    public const ENV = WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV;
    public const PROJECT_ORIGIN_ENV = ProjectRuntimeProfile::ORIGIN_ENV;
    public const SOURCE_PROJECT = 'raiz';
    public const SOURCE_LEGACY = 'legacy';
    public const SOURCE_LEGACY_COMPATIBILITY =
        'legacy_compatibility_override';

    private function __construct(
        private readonly string $value,
        private readonly string $source
    ) {
    }

    /** @param array<string, mixed> $environment */
    public static function fromEnvironment(
        #[\SensitiveParameter] array $environment
    ): self {
        $legacyValue = $environment[self::ENV] ?? null;
        $legacyOrigin = null;
        if ($legacyValue !== null && $legacyValue !== '') {
            if (!is_string($legacyValue) || !self::isValid($legacyValue)) {
                throw new BlogConfigException(
                    'environment.public_origin_invalid',
                    self::ENV
                );
            }

            $legacyOrigin = rtrim($legacyValue, '/');
        }

        $projectOrigin = null;
        $projectIsDevelopmentLoopback = false;
        if (array_key_exists(self::PROJECT_ORIGIN_ENV, $environment)) {
            try {
                $profile = ProjectRuntimeProfile::fromEnvironment($environment);
                $projectOrigin = $profile->origin();
                $projectIsDevelopmentLoopback =
                    $profile->isDevelopmentLoopbackHttp();
            } catch (InvalidArgumentException) {
                /*
                 * Mantiene compatibilidad con instalaciones anteriores que
                 * ya declaraban el origen WebAdmin explícito. RAIZ pasa a ser
                 * la fuente canónica recomendada, pero una forma legacy válida
                 * no deja el Blog fuera de servicio durante la transición.
                 */
                if ($legacyOrigin === null) {
                    throw new BlogConfigException(
                        'environment.public_origin_invalid',
                        self::PROJECT_ORIGIN_ENV
                    );
                }
            }
        }

        $origin = $projectOrigin ?? $legacyOrigin;
        $source = $projectOrigin !== null
            ? self::SOURCE_PROJECT
            : self::SOURCE_LEGACY;
        if (
            $projectOrigin !== null
            && $legacyOrigin !== null
            && !$projectIsDevelopmentLoopback
            && !hash_equals(
                self::normalizedProductionOrigin($projectOrigin),
                self::normalizedProductionOrigin($legacyOrigin)
            )
        ) {
            /*
             * Hasta esta versión el Blog publicaba exclusivamente con el
             * alias WebAdmin. Mantenerlo temporalmente evita cambiar URLs
             * canónicas durante un update; doctor señala la discrepancia para
             * que el proyecto alinee ambos valores y retire el alias.
            */
            $origin = $legacyOrigin;
            $source = self::SOURCE_LEGACY_COMPATIBILITY;
        }
        if ($origin === null) {
            throw new BlogConfigException(
                'environment.public_origin_missing',
                self::PROJECT_ORIGIN_ENV
            );
        }

        return new self($origin, $source);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function usesLegacyCompatibilityOverride(): bool
    {
        return $this->source === self::SOURCE_LEGACY_COMPATIBILITY;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function usesLegacyOrigin(): bool
    {
        return $this->source !== self::SOURCE_PROJECT;
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

    private static function normalizedProductionOrigin(string $origin): string
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || !is_string($parts['host'] ?? null)) {
            return $origin;
        }

        $host = strtolower((string) $parts['host']);
        if (str_contains($host, ':')) {
            $host = '[' . trim($host, '[]') . ']';
        }
        $port = isset($parts['port']) && (int) $parts['port'] !== 443
            ? ':' . (int) $parts['port']
            : '';

        return 'https://' . $host . $port;
    }
}
