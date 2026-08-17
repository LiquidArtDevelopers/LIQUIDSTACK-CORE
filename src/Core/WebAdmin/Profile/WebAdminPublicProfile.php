<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Profile;

/** Live, public-safe profile projection. It never contains email or DB IDs. */
final class WebAdminPublicProfile
{
    public function __construct(
        private readonly string $userPublicId,
        private readonly ?string $displayName,
        private readonly string $roleCode,
        private readonly string $roleLabel,
        private readonly WebAdminTimeZone $timeZone,
        private readonly bool $timeZoneConfigured,
        private readonly int $lockVersion
    ) {
    }

    public function userPublicId(): string { return $this->userPublicId; }
    public function displayName(): ?string { return $this->displayName; }
    public function roleCode(): string { return $this->roleCode; }
    public function roleLabel(): string { return $this->roleLabel; }
    public function timeZone(): WebAdminTimeZone { return $this->timeZone; }
    public function timeZoneConfigured(): bool { return $this->timeZoneConfigured; }
    public function lockVersion(): int { return $this->lockVersion; }
}
