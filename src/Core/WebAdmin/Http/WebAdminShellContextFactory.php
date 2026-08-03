<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Navigation\WebAdminNavigationCatalog;

/**
 * Builds a shell context only from navigation the authenticated actor may see.
 *
 * Authentication and the broad `webadmin.access` gate remain the caller's
 * responsibility. This factory deliberately performs only capability-based
 * presentation filtering and never replaces endpoint authorization.
 */
final class WebAdminShellContextFactory
{
    private const USERS_VIEW = 'webadmin.users.view';

    public function __construct(
        private readonly string $basePath,
        private readonly WebAdminAuthorizationService $authorization,
        private readonly WebAdminNavigationCatalog $navigation
    ) {
    }

    public function create(
        #[\SensitiveParameter]
        string $sessionToken,
        #[\SensitiveParameter]
        string $csrfToken,
        string $activePath,
        ?string $trustedInspectorHtml = null,
        ?WebAdminPageAssets $assets = null
    ): WebAdminShellContext {
        $visible = [];
        foreach ($this->navigation->items() as $item) {
            if ($this->authorization->hasCapability(
                $sessionToken,
                $item->requiredCapability()
            )) {
                $visible[] = $item;
            }
        }

        return new WebAdminShellContext(
            basePath: $this->basePath,
            logoutCsrf: $csrfToken,
            activePath: $activePath,
            showUsersLink: $this->authorization->hasCapability(
                $sessionToken,
                self::USERS_VIEW
            ),
            moduleNavigation: $visible,
            trustedInspectorHtml: $trustedInspectorHtml,
            assets: $assets
        );
    }
}
