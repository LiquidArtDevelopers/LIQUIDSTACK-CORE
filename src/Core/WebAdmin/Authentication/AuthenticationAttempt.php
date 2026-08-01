<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Authentication;

use LogicException;

final class AuthenticationAttempt
{
    public const GENERIC_FAILURE = 'webadmin.authentication.failed';

    private function __construct(
        private readonly bool $successful,
        private readonly SessionSecrets $nextSession,
        private readonly ?AuthenticatedSession $authenticatedSession
    ) {
    }

    public static function succeeded(
        SessionSecrets $nextSession,
        AuthenticatedSession $authenticatedSession
    ): self {
        return new self(true, $nextSession, $authenticatedSession);
    }

    public static function failed(SessionSecrets $nextSession): self
    {
        return new self(false, $nextSession, null);
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function nextSession(): SessionSecrets
    {
        return $this->nextSession;
    }

    public function authenticatedSession(): AuthenticatedSession
    {
        if ($this->authenticatedSession === null) {
            throw new LogicException('Authentication was not successful.');
        }

        return $this->authenticatedSession;
    }

    public function publicErrorCode(): ?string
    {
        return $this->successful ? null : self::GENERIC_FAILURE;
    }
}
