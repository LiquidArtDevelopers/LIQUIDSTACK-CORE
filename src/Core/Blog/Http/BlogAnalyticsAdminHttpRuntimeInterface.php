<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\Analytics\BlogAnalyticsReportInterface;

/** Optional admin capability exposed only when analytics schema is ready. */
interface BlogAnalyticsAdminHttpRuntimeInterface
{
    public function analyticsReport(): BlogAnalyticsReportInterface;
}
