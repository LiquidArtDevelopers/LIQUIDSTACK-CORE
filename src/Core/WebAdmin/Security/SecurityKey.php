<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Security;

use LogicException;

/**
 * Process-local keyed hashing primitive for low-entropy identifiers.
 *
 * Unlike session and CSRF tokens, email addresses and IP addresses can be
 * guessed. Their persisted rate-limit subjects therefore require HMAC, not a
 * plain digest. The raw key deliberately has no accessor or string cast.
 */
final class SecurityKey
{
    public const MINIMUM_BYTES = 32;
    public const MAXIMUM_BYTES = 1024;
    public const BASE64_URL_LENGTH = 43;

    private readonly OpaqueSecret $rawBytes;

    private function __construct(#[\SensitiveParameter] string $rawBytes)
    {
        $this->rawBytes = OpaqueSecret::fromString($rawBytes);
    }

    public static function fromRawBytes(
        #[\SensitiveParameter] string $rawBytes
    ): self
    {
        $length = strlen($rawBytes);
        if ($length < self::MINIMUM_BYTES || $length > self::MAXIMUM_BYTES) {
            throw new InvalidSecurityKey();
        }

        return new self($rawBytes);
    }

    public static function fromBase64Url(
        #[\SensitiveParameter] string $encoded
    ): self
    {
        if (
            strlen($encoded) !== self::BASE64_URL_LENGTH
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $encoded) !== 1
        ) {
            throw new InvalidSecurityKey();
        }

        $decoded = base64_decode(
            strtr($encoded, '-_', '+/') . '=',
            true
        );
        if (
            !is_string($decoded)
            || strlen($decoded) !== self::MINIMUM_BYTES
            || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=')
                !== $encoded
        ) {
            throw new InvalidSecurityKey();
        }

        return new self($decoded);
    }

    public function subjectHash(string $purpose, string $subject): string
    {
        return bin2hex($this->derive($purpose, $subject));
    }

    /**
     * Derives a purpose-bound, canonical 256-bit browser token. The key stays
     * process-local and neither it nor the derived value is persisted.
     */
    public function deriveToken(string $purpose, string $subject): string
    {
        return rtrim(strtr(
            base64_encode($this->derive($purpose, $subject)),
            '+/',
            '-_'
        ), '=');
    }

    private function derive(string $purpose, string $subject): string
    {
        if (
            preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $purpose) !== 1
        ) {
            throw new InvalidSecurityKey();
        }

        return hash_hmac(
            'sha256',
            $purpose . "\0" . $subject,
            $this->rawBytes->reveal(),
            true
        );
    }

    /** @return array{key: string} */
    public function __debugInfo(): array
    {
        return ['key' => '[redacted]'];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new LogicException('WebAdmin security keys cannot be serialized.');
    }
}
