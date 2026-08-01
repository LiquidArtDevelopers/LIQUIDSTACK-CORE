<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\UserManagement;

use DateTimeImmutable;

class EditorSummary
{
    private readonly OpaquePersonalData $emailCanonical;
    private readonly OpaquePersonalData $displayName;

    public function __construct(
        private readonly string $publicId,
        #[\SensitiveParameter] string $emailCanonical,
        #[\SensitiveParameter] ?string $displayName,
        private readonly string $status,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $updatedAt
    ) {
        $this->emailCanonical = OpaquePersonalData::fromNullable(
            $emailCanonical
        );
        $this->displayName = OpaquePersonalData::fromNullable($displayName);
    }

    public function publicId(): string
    {
        return $this->publicId;
    }

    public function emailCanonical(): string
    {
        $value = $this->emailCanonical->reveal();
        if ($value === null) {
            throw new \LogicException('WebAdmin email is unavailable.');
        }

        return $value;
    }

    public function displayName(): ?string
    {
        return $this->displayName->reveal();
    }

    public function status(): string
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'public_id' => $this->publicId,
            'email' => '[redacted]',
            'display_name' => '[redacted]',
            'status' => $this->status,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}
