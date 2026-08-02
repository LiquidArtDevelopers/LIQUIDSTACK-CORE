<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Http;

use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Media\MediaService;

final class WebAdminMediaHttpRuntime
{
    public function __construct(
        private readonly WebAdminConfig $config,
        private readonly WebAdminAuthenticationService $authentication,
        private readonly WebAdminAuthorizationService $authorization,
        private readonly MediaService $media
    ) {
    }

    public function config(): WebAdminConfig { return $this->config; }
    public function authentication(): WebAdminAuthenticationService
    {
        return $this->authentication;
    }
    public function authorization(): WebAdminAuthorizationService
    {
        return $this->authorization;
    }
    public function media(): MediaService { return $this->media; }
}
