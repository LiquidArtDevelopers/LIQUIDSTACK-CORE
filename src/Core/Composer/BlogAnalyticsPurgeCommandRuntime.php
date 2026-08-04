<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Blog\Analytics\BlogAnalyticsPurgeResult;
use App\Core\Blog\Analytics\BlogAnalyticsRetentionInterface;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\SystemClock;
use DateInterval;
use Throwable;

final class BlogAnalyticsPurgeCommandRuntime implements
    BlogAnalyticsPurgeCommandRuntimeInterface
{
    public function __construct(
        private readonly BlogAnalyticsRetentionInterface $retention,
        private readonly int $retentionDays,
        private readonly ClockInterface $clock = new SystemClock()
    ) {
    }

    public function purge(): BlogAnalyticsPurgeResult
    {
        try {
            return $this->retention->purgeBefore(
                $this->clock->now()->sub(
                    new DateInterval('P' . $this->retentionDays . 'D')
                )
            );
        } catch (Throwable) {
            throw new BlogAnalyticsPurgeCommandRuntimeException(
                'blog.analytics.purge.failed'
            );
        }
    }
}
