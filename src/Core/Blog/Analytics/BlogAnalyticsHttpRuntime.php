<?php

declare(strict_types=1);

namespace App\Core\Blog\Analytics;

use App\Core\Blog\Configuration\BlogAnalyticsConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\WebAdmin\Configuration\WebAdminConfig;

final class BlogAnalyticsHttpRuntime
{
    public function __construct(
        private readonly BlogAnalyticsConfig $config,
        private readonly BlogPublicOrigin $origin,
        private readonly BlogAnalyticsCollector $collector,
        private readonly BlogAnalyticsPageGrantCodec $pageGrantCodec,
        private readonly string $webAdminCookieName =
            WebAdminConfig::DEFAULT_COOKIE_NAME
    ) {
    }

    public function config(): BlogAnalyticsConfig
    {
        return $this->config;
    }

    public function origin(): BlogPublicOrigin
    {
        return $this->origin;
    }

    public function collector(): BlogAnalyticsCollector
    {
        return $this->collector;
    }

    public function pageGrantCodec(): BlogAnalyticsPageGrantCodec
    {
        return $this->pageGrantCodec;
    }

    public function webAdminCookieName(): string
    {
        return $this->webAdminCookieName;
    }
}
