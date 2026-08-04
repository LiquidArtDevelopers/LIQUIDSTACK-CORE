<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\Analytics\BlogAnalyticsReportInterface;

/** Runtime variant created only after the optional analytics gate passes. */
final class BlogAnalyticsAdminHttpRuntime extends BlogAdminHttpRuntime implements
    BlogAnalyticsAdminHttpRuntimeInterface
{
    public function analyticsReport(): BlogAnalyticsReportInterface
    {
        return $this->configuredAnalyticsReport()
            ?? throw new BlogAdminHttpRuntimeException(
                'blog.analytics_unavailable'
            );
    }
}
