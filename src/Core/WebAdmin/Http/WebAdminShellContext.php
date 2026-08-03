<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;
use InvalidArgumentException;

/** Immutable presentation context for one authenticated WebAdmin document. */
final class WebAdminShellContext
{
    /** @var list<WebAdminNavigationItem> */
    private readonly array $moduleNavigation;

    private readonly WebAdminPageAssets $assets;

    /**
     * `trustedInspectorHtml` must already be rendered and context-escaped by
     * the owning private feature. It is never built from arbitrary request
     * input by this shell.
     *
     * @param list<WebAdminNavigationItem> $moduleNavigation
     */
    public function __construct(
        private readonly string $basePath,
        #[\SensitiveParameter]
        private readonly ?string $logoutCsrf,
        private readonly string $activePath = '',
        private readonly bool $showUsersLink = false,
        array $moduleNavigation = [],
        private readonly ?string $trustedInspectorHtml = null,
        ?WebAdminPageAssets $assets = null
    ) {
        if (
            $activePath !== ''
            && preg_match(
                '#\A/[a-z0-9][a-z0-9_-]*(?:/[a-z0-9][a-z0-9_-]*)*\z#',
                $activePath
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid WebAdmin shell active path.'
            );
        }
        if ($logoutCsrf !== null && $logoutCsrf === '') {
            throw new InvalidArgumentException(
                'Invalid WebAdmin shell logout token.'
            );
        }
        if (!array_is_list($moduleNavigation)) {
            throw new InvalidArgumentException(
                'WebAdmin shell navigation must be a list.'
            );
        }
        foreach ($moduleNavigation as $item) {
            if (!$item instanceof WebAdminNavigationItem) {
                throw new InvalidArgumentException(
                    'Invalid WebAdmin shell navigation item.'
                );
            }
        }
        if ($trustedInspectorHtml === '') {
            throw new InvalidArgumentException(
                'Invalid WebAdmin shell inspector content.'
            );
        }

        $this->moduleNavigation = $moduleNavigation;
        $this->assets = $assets ?? new WebAdminPageAssets();
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function logoutCsrf(): ?string
    {
        return $this->logoutCsrf;
    }

    public function activePath(): string
    {
        return $this->activePath;
    }

    public function showUsersLink(): bool
    {
        return $this->showUsersLink;
    }

    /** @return list<WebAdminNavigationItem> */
    public function moduleNavigation(): array
    {
        return $this->moduleNavigation;
    }

    public function inspectorHtml(): ?string
    {
        return $this->trustedInspectorHtml;
    }

    public function assets(): WebAdminPageAssets
    {
        return $this->assets;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'base_path' => $this->basePath,
            'active_path' => $this->activePath,
            'show_users_link' => $this->showUsersLink,
            'navigation_items' => count($this->moduleNavigation),
            'has_logout' => $this->logoutCsrf !== null,
            'has_inspector' => $this->trustedInspectorHtml !== null,
            'stylesheets' => $this->assets->stylesheets(),
            'scripts' => $this->assets->scripts(),
        ];
    }
}
