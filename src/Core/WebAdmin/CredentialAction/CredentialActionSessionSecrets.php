<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\CredentialAction;

use App\Core\WebAdmin\Security\OpaqueSecret;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One-time browser delivery envelope for a credential-action session.
 *
 * It is intentionally a different type from login SessionSecrets so an HTTP
 * adapter cannot accidentally reuse the login cookie or CSRF context.
 */
final class CredentialActionSessionSecrets
{
    public const CSRF_PURPOSE = 'csrf.credential_action';

    private readonly OpaqueSecret $sessionToken;
    private readonly OpaqueSecret $csrfToken;

    public function __construct(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        private readonly string $purpose,
        private readonly DateTimeImmutable $absoluteExpiresAt
    ) {
        if (!CredentialActionService::isSupportedPurpose($purpose)) {
            throw new InvalidArgumentException(
                'Invalid WebAdmin credential-action purpose.'
            );
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

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function absoluteExpiresAt(): DateTimeImmutable
    {
        return $this->absoluteExpiresAt;
    }

    /** @return array{session_token: string, csrf_token: string, purpose: string, absolute_expires_at: string} */
    public function __debugInfo(): array
    {
        return [
            'session_token' => '[redacted]',
            'csrf_token' => '[redacted]',
            'purpose' => $this->purpose,
            'absolute_expires_at' => $this->absoluteExpiresAt->format(DATE_ATOM),
        ];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new InvalidArgumentException(
            'WebAdmin credential-action secrets cannot be serialized.'
        );
    }
}
