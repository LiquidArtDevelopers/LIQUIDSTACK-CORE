<?php

declare(strict_types=1);

namespace App\Core\Blog\Analytics;

use App\Core\Blog\BlogInput;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\SystemClock;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class BlogAnalyticsPageGrantCodec
{
    public const MAX_TOKEN_BYTES = 1024;
    public const TTL_SECONDS = 86_400;
    public const CLOCK_SKEW_SECONDS = 60;

    private const PURPOSE = 'blog.analytics.page-grant.v1';
    private const VERSION = 1;

    public function __construct(
        private readonly SecurityKey $securityKey,
        private readonly BlogPublicOrigin $origin,
        private readonly ClockInterface $clock = new SystemClock()
    ) {
    }

    public function issue(
        string $localizationPublicId,
        string $canonicalPath
    ): string {
        $localizationPublicId = BlogInput::publicId($localizationPublicId);
        $this->assertPath($canonicalPath);
        $issuedAt = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->getTimestamp();
        $payload = [
            'v' => self::VERSION,
            'view_id' => $this->newPublicId(),
            'localization_id' => $localizationPublicId,
            'path' => $canonicalPath,
            'origin' => $this->origin->value(),
            'iat' => $issuedAt,
            'exp' => $issuedAt + self::TTL_SECONDS,
        ];
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $encoded = self::encode($json);
        $token = $encoded . '.' . $this->securityKey->deriveToken(
            self::PURPOSE,
            $encoded
        );
        if (strlen($token) > self::MAX_TOKEN_BYTES) {
            throw new BlogAnalyticsPersistenceException();
        }

        return $token;
    }

    public function verify(
        #[\SensitiveParameter] string $token
    ): ?BlogAnalyticsPageGrant {
        try {
            if (
                $token === ''
                || strlen($token) > self::MAX_TOKEN_BYTES
                || preg_match(
                    '/\A([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]{43})\z/D',
                    $token,
                    $matches
                ) !== 1
            ) {
                return null;
            }
            $encoded = $matches[1];
            $signature = $matches[2];
            $expected = $this->securityKey->deriveToken(
                self::PURPOSE,
                $encoded
            );
            if (!hash_equals($expected, $signature)) {
                return null;
            }
            $json = self::decode($encoded);
            if ($json === null) {
                return null;
            }
            $payload = json_decode(
                $json,
                true,
                8,
                JSON_THROW_ON_ERROR
            );
            if (
                !is_array($payload)
                || array_is_list($payload)
                || array_keys($payload) !== [
                    'v',
                    'view_id',
                    'localization_id',
                    'path',
                    'origin',
                    'iat',
                    'exp',
                ]
                || json_encode(
                    $payload,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ) !== $json
                || $payload['v'] !== self::VERSION
                || !is_string($payload['view_id'])
                || !is_string($payload['localization_id'])
                || !is_string($payload['path'])
                || !is_string($payload['origin'])
                || !is_int($payload['iat'])
                || !is_int($payload['exp'])
                || $payload['origin'] !== $this->origin->value()
                || $payload['exp'] - $payload['iat'] !== self::TTL_SECONDS
            ) {
                return null;
            }
            $viewPublicId = BlogInput::generatedPublicId($payload['view_id']);
            $localizationPublicId = BlogInput::publicId(
                $payload['localization_id']
            );
            $this->assertPath($payload['path']);
            $now = $this->clock->now()->getTimestamp();
            if (
                $payload['iat'] > $now + self::CLOCK_SKEW_SECONDS
                || $payload['exp'] <= $now
            ) {
                return null;
            }

            return new BlogAnalyticsPageGrant(
                $localizationPublicId,
                $viewPublicId,
                $payload['path'],
                self::date($payload['iat']),
                self::date($payload['exp'])
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function assertPath(string $path): void
    {
        if (
            $path === ''
            || strlen($path) > BlogAnalyticsRequestPolicy::MAX_PATH_BYTES
            || !str_starts_with($path, '/')
            || str_contains($path, '?')
            || str_contains($path, '#')
            || preg_match('/[\x00-\x20\x7F]/', $path) === 1
        ) {
            throw new BlogAnalyticsPersistenceException();
        }
    }

    private function newPublicId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): ?string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(
            strtr($value, '-_', '+/') . str_repeat('=', $padding),
            true
        );
        if (!is_string($decoded) || self::encode($decoded) !== $value) {
            return null;
        }

        return $decoded;
    }

    private static function date(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(
            new DateTimeZone('UTC')
        );
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'security_key' => '[redacted]',
            'origin' => '[redacted]',
        ];
    }
}
