<?php

declare(strict_types=1);

namespace App\Core\Blog\Analytics;

use DateTimeImmutable;
use InvalidArgumentException;

final class BlogAnalyticsPurgeResult
{
    public function __construct(
        private readonly DateTimeImmutable $cutoffExclusive,
        private readonly int $deletedSessions,
        private readonly int $deletedViews
    ) {
        if ($deletedSessions < 0 || $deletedViews < 0) {
            throw new InvalidArgumentException('Invalid analytics purge result.');
        }
    }

    public function cutoffExclusive(): DateTimeImmutable
    {
        return $this->cutoffExclusive;
    }

    public function deletedSessions(): int
    {
        return $this->deletedSessions;
    }

    public function deletedViews(): int
    {
        return $this->deletedViews;
    }

    /** @return array{cutoff_exclusive: string, deleted_sessions: int, deleted_views: int} */
    public function toSafeArray(): array
    {
        return [
            'cutoff_exclusive' => $this->cutoffExclusive->format(DATE_ATOM),
            'deleted_sessions' => $this->deletedSessions,
            'deleted_views' => $this->deletedViews,
        ];
    }
}
