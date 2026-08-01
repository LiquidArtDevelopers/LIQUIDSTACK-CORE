<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Authentication;

use DateTimeImmutable;

final class AuthenticatedSession
{
    public function __construct(
        private readonly string $sessionPublicId,
        private readonly int $userId,
        private readonly string $userPublicId,
        private readonly string $emailCanonical,
        private readonly ?string $displayName,
        private readonly int $authVersion,
        private readonly DateTimeImmutable $idleExpiresAt,
        private readonly DateTimeImmutable $absoluteExpiresAt
    ) {
    }

    public function sessionPublicId(): string
    {
        return $this->sessionPublicId;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function userPublicId(): string
    {
        return $this->userPublicId;
    }

    public function emailCanonical(): string
    {
        return $this->emailCanonical;
    }

    public function displayName(): ?string
    {
        return $this->displayName;
    }

    public function authVersion(): int
    {
        return $this->authVersion;
    }

    public function idleExpiresAt(): DateTimeImmutable
    {
        return $this->idleExpiresAt;
    }

    public function absoluteExpiresAt(): DateTimeImmutable
    {
        return $this->absoluteExpiresAt;
    }
}
