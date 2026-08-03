<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Http;

use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Media\MediaService;
use App\Core\WebAdmin\Navigation\WebAdminNavigationCatalog;

final class WebAdminMediaHttpRuntime
{
    private readonly WebAdminNavigationCatalog $navigation;

    public function __construct(
        private readonly WebAdminConfig $config,
        private readonly WebAdminAuthenticationService $authentication,
        private readonly WebAdminAuthorizationService $authorization,
        private readonly MediaService $media,
        ?WebAdminNavigationCatalog $navigation = null
    ) {
        $this->navigation = $navigation ?? new WebAdminNavigationCatalog();
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
    public function navigation(): WebAdminNavigationCatalog
    {
        return $this->navigation;
    }
}
