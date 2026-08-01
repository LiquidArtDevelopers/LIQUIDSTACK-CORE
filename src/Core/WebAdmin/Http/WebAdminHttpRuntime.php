<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\CredentialAction\CredentialActionService;
use App\Core\WebAdmin\Navigation\WebAdminNavigationCatalog;
use App\Core\WebAdmin\UserManagement\UserManagementService;

final class WebAdminHttpRuntime
{
    private readonly WebAdminNavigationCatalog $navigation;

    public function __construct(
        private readonly WebAdminConfig $config,
        private readonly WebAdminAuthenticationService $authentication,
        private readonly WebAdminAuthorizationService $authorization,
        private readonly ?CredentialActionService $credentialActions = null,
        private readonly ?UserManagementService $userManagement = null,
        ?WebAdminNavigationCatalog $navigation = null
    ) {
        $this->navigation = $navigation ?? new WebAdminNavigationCatalog();
    }

    public function config(): WebAdminConfig
    {
        return $this->config;
    }

    public function authentication(): WebAdminAuthenticationService
    {
        return $this->authentication;
    }

    public function authorization(): WebAdminAuthorizationService
    {
        return $this->authorization;
    }

    public function credentialActions(): CredentialActionService
    {
        if (!$this->credentialActions instanceof CredentialActionService) {
            throw new WebAdminHttpRuntimeException(
                'webadmin.credential_actions_unavailable'
            );
        }

        return $this->credentialActions;
    }

    public function userManagement(): UserManagementService
    {
        if (!$this->userManagement instanceof UserManagementService) {
            throw new WebAdminHttpRuntimeException(
                'webadmin.user_management_unavailable'
            );
        }

        return $this->userManagement;
    }

    public function navigation(): WebAdminNavigationCatalog
    {
        return $this->navigation;
    }
}
