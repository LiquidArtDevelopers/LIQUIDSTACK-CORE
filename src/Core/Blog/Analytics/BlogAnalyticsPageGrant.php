<?php

declare(strict_types=1);

namespace App\Core\Blog\Analytics;

use DateTimeImmutable;

/**
 * Verified, short-lived capability for one rendered public Blog page view.
 */
final class BlogAnalyticsPageGrant
{
    public function __construct(
        private readonly string $localizationPublicId,
        private readonly string $viewPublicId,
        private readonly string $canonicalPath,
        private readonly DateTimeImmutable $issuedAt,
        private readonly DateTimeImmutable $expiresAt
    ) {
    }

    public function localizationPublicId(): string
    {
        return $this->localizationPublicId;
    }

    public function viewPublicId(): string
    {
        return $this->viewPublicId;
    }

    public function canonicalPath(): string
    {
        return $this->canonicalPath;
    }

    public function issuedAt(): DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['page_grant' => '[redacted]'];
    }
}
