<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\CredentialAction;

use App\Core\WebAdmin\Security\OpaqueSecret;
use DateTimeImmutable;
use InvalidArgumentException;

final class CredentialActionCsrfToken
{
    private readonly OpaqueSecret $csrfToken;

    public function __construct(
        #[\SensitiveParameter] string $csrfToken,
        private readonly string $purpose,
        private readonly DateTimeImmutable $expiresAt
    ) {
        if (!CredentialActionService::isSupportedPurpose($purpose)) {
            throw new InvalidArgumentException(
                'Invalid WebAdmin credential-action purpose.'
            );
        }
        $this->csrfToken = OpaqueSecret::fromString($csrfToken);
    }

    public function csrfToken(): string
    {
        return $this->csrfToken->reveal();
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /** @return array{csrf_token: string, purpose: string, expires_at: string} */
    public function __debugInfo(): array
    {
        return [
            'csrf_token' => '[redacted]',
            'purpose' => $this->purpose,
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
        ];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new InvalidArgumentException(
            'WebAdmin credential-action CSRF tokens cannot be serialized.'
        );
    }
}
