<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Authentication;

use App\Core\WebAdmin\Security\OpaqueSecret;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One-time delivery envelope for the HTTP adapter.
 *
 * Raw session and CSRF values exist only in memory and are intentionally not
 * serializable, stringable or visible through debug output. The adapter may
 * read them explicitly to build the
 * host-only cookie and form field.
 */
final class SessionSecrets
{
    public const PREAUTHENTICATED = 'preauth';
    public const AUTHENTICATED = 'authenticated';

    private readonly OpaqueSecret $sessionToken;
    private readonly OpaqueSecret $csrfToken;

    public function __construct(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        private readonly string $sessionType,
        private readonly DateTimeImmutable $absoluteExpiresAt
    ) {
        if (!in_array($sessionType, [
            self::PREAUTHENTICATED,
            self::AUTHENTICATED,
        ], true)) {
            throw new InvalidArgumentException('Invalid WebAdmin session type.');
        }

        $this->sessionToken = OpaqueSecret::fromString($sessionToken);
        $this->csrfToken = OpaqueSecret::fromString($csrfToken);
    }

    public function sessionToken(): string
    {
        return $this->sessionToken->reveal();
    }

    public function csrfToken(): string
    {
        return $this->csrfToken->reveal();
    }

    public function sessionType(): string
    {
        return $this->sessionType;
    }

    public function absoluteExpiresAt(): DateTimeImmutable
    {
        return $this->absoluteExpiresAt;
    }

    public function isAuthenticated(): bool
    {
        return $this->sessionType === self::AUTHENTICATED;
    }

    /** @return array{session_token: string, csrf_token: string, session_type: string, absolute_expires_at: string} */
    public function __debugInfo(): array
    {
        return [
            'session_token' => '[redacted]',
            'csrf_token' => '[redacted]',
            'session_type' => $this->sessionType,
            'absolute_expires_at' => $this->absoluteExpiresAt->format(DATE_ATOM),
        ];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new InvalidArgumentException(
            'WebAdmin session secrets cannot be serialized.'
        );
    }
}
