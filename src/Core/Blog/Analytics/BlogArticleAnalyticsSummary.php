<?php

declare(strict_types=1);

namespace App\Core\Blog\Analytics;

use App\Core\Blog\BlogInput;
use InvalidArgumentException;

final class BlogArticleAnalyticsSummary
{
    private readonly string $localizationPublicId;

    public function __construct(
        string $localizationPublicId,
        private readonly int $pageViews,
        private readonly int $uniqueVisitors,
        private readonly int $returningVisitors,
        private readonly int $averageEngagementMilliseconds,
        private readonly int $landingSessions,
        private readonly int $engagedLandingSessions
    ) {
        $this->localizationPublicId = BlogInput::publicId(
            $localizationPublicId
        );
        if (
            min(
                $pageViews,
                $uniqueVisitors,
                $returningVisitors,
                $averageEngagementMilliseconds,
                $landingSessions,
                $engagedLandingSessions
            ) < 0
            || $returningVisitors > $uniqueVisitors
            || $engagedLandingSessions > $landingSessions
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog analytics summary.'
            );
        }
    }

    public function localizationPublicId(): string
    {
        return $this->localizationPublicId;
    }

    public function pageViews(): int
    {
        return $this->pageViews;
    }

    public function uniqueVisitors(): int
    {
        return $this->uniqueVisitors;
    }

    public function returningVisitors(): int
    {
        return $this->returningVisitors;
    }

    public function averageEngagementMilliseconds(): int
    {
        return $this->averageEngagementMilliseconds;
    }

    public function landingSessions(): int
    {
        return $this->landingSessions;
    }

    public function engagedLandingSessions(): int
    {
        return $this->engagedLandingSessions;
    }

    public function bounceRatePercentage(): float
    {
        if ($this->landingSessions === 0) {
            return 0.0;
        }

        return round(
            (($this->landingSessions - $this->engagedLandingSessions)
                / $this->landingSessions) * 100,
            1
        );
    }
}
