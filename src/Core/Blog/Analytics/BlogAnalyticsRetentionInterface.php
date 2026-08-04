<?php

declare(strict_types=1);

namespace App\Core\Blog\Analytics;

use DateTimeImmutable;

interface BlogAnalyticsRetentionInterface
{
    public function purgeBefore(
        DateTimeImmutable $cutoffExclusive
    ): BlogAnalyticsPurgeResult;
}
