<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Authentication;

use App\Core\WebAdmin\Security\OpaqueSecret;
use DateTimeImmutable;
use InvalidArgumentException;

/** A stable, session-bound CSRF token plus the refreshed session deadlines. */
final class SessionCsrfToken
{
    private readonly OpaqueSecret $csrfToken;

    public function __construct(
        #[\SensitiveParameter] string $csrfToken,
        private readonly DateTimeImmutable $idleExpiresAt,
        private readonly DateTimeImmutable $absoluteExpiresAt
    ) {
        $this->csrfToken = OpaqueSecret::fromString($csrfToken);
    }

    public function csrfToken(): string
    {
        return $this->csrfToken->reveal();
    }

    public function idleExpiresAt(): DateTimeImmutable
    {
        return $this->idleExpiresAt;
    }

    public function absoluteExpiresAt(): DateTimeImmutable
    {
        return $this->absoluteExpiresAt;
    }

    /** @return array{csrf_token: string, idle_expires_at: string, absolute_expires_at: string} */
    public function __debugInfo(): array
    {
        return [
            'csrf_token' => '[redacted]',
            'idle_expires_at' => $this->idleExpiresAt->format(DATE_ATOM),
            'absolute_expires_at' => $this->absoluteExpiresAt->format(DATE_ATOM),
        ];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new InvalidArgumentException(
            'WebAdmin CSRF secrets cannot be serialized.'
        );
    }
}
