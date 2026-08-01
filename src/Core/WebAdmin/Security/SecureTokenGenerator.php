<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Security;

final class SecureTokenGenerator
{
    public const RANDOM_BYTES = 32;
    public const ENCODED_LENGTH = 43;
    public const HASH_ALGORITHM = 'sha256';
    public const HASH_LENGTH = 64;

    public function generate(): string
    {
        return self::base64UrlEncode(random_bytes(self::RANDOM_BYTES));
    }

    public function hashForStorage(string $token): string
    {
        if (!$this->hasValidFormat($token)) {
            throw new InvalidSecurityToken();
        }

        return hash(self::HASH_ALGORITHM, $token);
    }

    public function verify(string $token, string $storedHash): bool
    {
        if (
            !$this->hasValidFormat($token)
            || preg_match('/\A[0-9a-f]{64}\z/', $storedHash) !== 1
        ) {
            return false;
        }

        return ConstantTime::equals(
            $storedHash,
            hash(self::HASH_ALGORITHM, $token)
        );
    }

    public function hasValidFormat(string $token): bool
    {
        if (
            strlen($token) !== self::ENCODED_LENGTH
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) !== 1
        ) {
            return false;
        }

        $padding = (4 - (strlen($token) % 4)) % 4;
        $decoded = base64_decode(
            strtr($token, '-_', '+/') . str_repeat('=', $padding),
            true
        );

        return is_string($decoded)
            && strlen($decoded) === self::RANDOM_BYTES
            && self::base64UrlEncode($decoded) === $token;
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
