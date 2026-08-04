<?php

declare(strict_types=1);

namespace App\Core\Blog\Analytics;

use DateTimeImmutable;

interface BlogAnalyticsReportInterface
{
    /**
     * @param list<string> $localizationPublicIds
     * @return array<string, BlogArticleAnalyticsSummary>
     */
    public function summariesForLocalizations(
        array $localizationPublicIds,
        DateTimeImmutable $fromInclusive,
        DateTimeImmutable $toExclusive
    ): array;
}
