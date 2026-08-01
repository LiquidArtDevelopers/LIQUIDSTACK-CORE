<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;

final class BlogPublicHttpRuntime
{
    public function __construct(
        private readonly BlogConfig $config,
        private readonly BlogPublicOrigin $origin,
        private readonly BlogService $service
    ) {
    }

    public function config(): BlogConfig
    {
        return $this->config;
    }

    public function origin(): BlogPublicOrigin
    {
        return $this->origin;
    }

    public function service(): BlogService
    {
        return $this->service;
    }
}
