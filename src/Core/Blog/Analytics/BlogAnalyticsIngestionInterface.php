<?php

declare(strict_types=1);

namespace App\Core\Blog\Analytics;

use DateTimeImmutable;

interface BlogAnalyticsIngestionInterface
{
    public function recordView(
        string $localizationPublicId,
        string $viewPublicId,
        string $visitorHash,
        string $sessionHash,
        DateTimeImmutable $occurredAt
    ): bool;

    public function recordEngagement(
        string $viewPublicId,
        string $sessionHash,
        int $sequence,
        int $engagementMilliseconds,
        DateTimeImmutable $occurredAt
    ): bool;
}
