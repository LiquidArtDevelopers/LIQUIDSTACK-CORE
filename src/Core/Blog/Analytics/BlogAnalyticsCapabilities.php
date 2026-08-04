<?php

declare(strict_types=1);

namespace App\Core\Blog\Analytics;

/** Shared authorization contract for private Blog analytics. */
final class BlogAnalyticsCapabilities
{
    public const VIEW = 'blog.analytics.view';
    public const VIEW_LABEL = 'blog.capabilities.analytics_view';

    private function __construct()
    {
    }
}
