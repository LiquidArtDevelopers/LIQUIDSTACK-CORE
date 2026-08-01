<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\UserManagement;

use DateTimeImmutable;

final class EditorDetail extends EditorSummary
{
    /**
     * @param list<string> $directCapabilities
     * @param list<string> $effectiveCapabilities
     */
    public function __construct(
        string $publicId,
        #[\SensitiveParameter] string $emailCanonical,
        #[\SensitiveParameter] ?string $displayName,
        string $status,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        private readonly ?DateTimeImmutable $invitedAt,
        private readonly ?DateTimeImmutable $activatedAt,
        private readonly ?DateTimeImmutable $suspendedAt,
        private readonly ?DateTimeImmutable $lastLoginAt,
        private readonly array $directCapabilities,
        private readonly array $effectiveCapabilities
    ) {
        parent::__construct(
            $publicId,
            $emailCanonical,
            $displayName,
            $status,
            $createdAt,
            $updatedAt
        );
    }

    public function invitedAt(): ?DateTimeImmutable
    {
        return $this->invitedAt;
    }

    public function activatedAt(): ?DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function suspendedAt(): ?DateTimeImmutable
    {
        return $this->suspendedAt;
    }

    public function lastLoginAt(): ?DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    /** @return list<string> */
    public function directCapabilities(): array
    {
        return $this->directCapabilities;
    }

    /** @return list<string> */
    public function effectiveCapabilities(): array
    {
        return $this->effectiveCapabilities;
    }
}
