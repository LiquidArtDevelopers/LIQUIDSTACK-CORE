<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

/** Shared semantic layout for authenticated WebAdmin feature screens. */
final class WebAdminShellRenderer
{
    public function __construct(
        private readonly WebAdminPageDocumentRenderer $documents =
            new WebAdminPageDocumentRenderer()
    ) {
    }

    public function render(
        string $title,
        string $mainHtml,
        WebAdminShellContext $context
    ): string {
        $basePath = $context->basePath();
        $navigation = $this->navigation($context);
        $logout = $this->logout($context);
        $inspector = $context->inspectorHtml();
        $layoutClass = 'webadminShell-layout'
            . ($inspector !== null
                ? ' webadminShell-layout--withInspector'
                : '');
        $inspectorHtml = $inspector === null
            ? ''
            : '<aside class="webadminShell-inspector" id="webadmin-inspector" '
                . 'aria-label="Herramientas de la p&aacute;gina" '
                . 'data-webadmin-shell-inspector>'
                . '<button class="webadminShell-drawerClose" type="button" '
                . 'data-webadmin-inspector-close>Cerrar herramientas</button>'
                . $inspector . '</aside>';
        $inspectorToggle = $inspector === null
            ? ''
            : '<button class="webadminShell-inspectorToggle" type="button" '
                . 'aria-controls="webadmin-inspector" aria-expanded="true" '
                . 'data-webadmin-inspector-toggle>Herramientas</button>';

        $body = '<a class="webadminShell-skipLink" href="#webadmin-main">'
            . 'Saltar al contenido</a>'
            . '<div class="webadminShell" data-webadmin-shell>'
            . '<aside class="webadminShell-sidebar" id="webadmin-navigation">'
            . '<button class="webadminShell-drawerClose" type="button" '
            . 'data-webadmin-sidebar-close>Cerrar men&uacute;</button>'
            . '<a class="webadminShell-brand" href="'
            . $this->path($basePath, '') . '">LiquidStack</a>'
            . $navigation . '</aside>'
            . '<div class="webadminShell-workspace">'
            . '<header class="webadminShell-topbar">'
            . '<button class="webadminShell-menuToggle" type="button" '
            . 'aria-controls="webadmin-navigation" aria-expanded="true" '
            . 'data-webadmin-shell-toggle>Men&uacute;</button>'
            . '<p class="webadminShell-pageTitle">'
            . $this->escape($title, false) . '</p>'
            . $inspectorToggle . $logout . '</header>'
            . '<div class="' . $layoutClass . '">'
            . '<main class="webadminShell-main" id="webadmin-main">'
            . $mainHtml . '</main>'
            . $inspectorHtml . '</div></div></div>';

        return $this->documents->render(
            $title,
            $body,
            $context->assets()
        );
    }

    private function navigation(WebAdminShellContext $context): string
    {
        $activeSuffix = $this->activeNavigationSuffix($context);
        $items = $this->navigationLink(
            $context,
            '',
            'Resumen',
            $activeSuffix
        );
        if ($context->showUsersLink()) {
            $items .= $this->navigationLink(
                $context,
                '/users',
                'Editores',
                $activeSuffix
            );
        }
        foreach ($context->moduleNavigation() as $item) {
            // Built-in authentication and user-management routes are never
            // surfaced through a module-owned capability.
            if ($this->isReservedSuffix($item->suffix())) {
                continue;
            }
            $items .= $this->navigationLink(
                $context,
                $item->suffix(),
                $item->label(),
                $activeSuffix
            );
        }

        return '<nav class="webadminShell-nav" '
            . 'aria-label="Administraci&oacute;n"><ul '
            . 'class="webadminShell-navList">' . $items . '</ul></nav>';
    }

    private function navigationLink(
        WebAdminShellContext $context,
        string $suffix,
        string $label,
        ?string $activeSuffix
    ): string {
        $current = $activeSuffix === $suffix
            ? ' aria-current="page"'
            : '';

        return '<li><a class="webadminShell-navLink" href="'
            . $this->path($context->basePath(), $suffix) . '"'
            . $current . '>' . $this->escape($label, false) . '</a></li>';
    }

    private function logout(WebAdminShellContext $context): string
    {
        $csrf = $context->logoutCsrf();
        if ($csrf === null) {
            return '';
        }

        return '<form class="webadminShell-logout" method="post" action="'
            . $this->path($context->basePath(), '/logout') . '">'
            . '<input type="hidden" name="csrf" value="'
            . $this->escape($csrf) . '">'
            . '<button type="submit">Cerrar sesi&oacute;n</button></form>';
    }

    private function activeNavigationSuffix(
        WebAdminShellContext $context
    ): ?string
    {
        $activePath = $context->activePath();
        if ($activePath === '') {
            return '';
        }

        $candidates = $context->showUsersLink() ? ['/users'] : [];
        foreach ($context->moduleNavigation() as $item) {
            if (!$this->isReservedSuffix($item->suffix())) {
                $candidates[] = $item->suffix();
            }
        }

        $best = null;
        foreach ($candidates as $candidate) {
            if (
                $activePath !== $candidate
                && !str_starts_with($activePath, $candidate . '/')
            ) {
                continue;
            }
            if ($best === null || strlen($candidate) > strlen($best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function isReservedSuffix(string $suffix): bool
    {
        foreach ([
            '/login',
            '/logout',
            '/password',
            '/activate',
            '/action-unavailable',
            '/users',
        ] as $reserved) {
            if ($suffix === $reserved || str_starts_with(
                $suffix,
                $reserved . '/'
            )) {
                return true;
            }
        }

        return false;
    }

    private function path(string $basePath, string $suffix): string
    {
        $path = rtrim($basePath, '/') . $suffix;

        return $this->escape($path === '' ? '/' : $path);
    }

    private function escape(string $value, bool $doubleEncode = true): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
            $doubleEncode
        );
    }
}
