<?php

declare(strict_types=1);

use App\Core\WebAdmin\Http\WebAdminShellContext;
use App\Core\WebAdmin\Http\WebAdminShellRenderer;
use PHPUnit\Framework\TestCase;

final class WebAdminShellAssetContractTest extends TestCase
{
    private string $css;
    private string $javascript;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $css = file_get_contents(
            $root . '/modules/webadmin/published/assets/webadmin.css'
        );
        $javascript = file_get_contents(
            $root . '/modules/webadmin/published/assets/webadmin.js'
        );
        self::assertIsString($css);
        self::assertIsString($javascript);
        $this->css = $css;
        $this->javascript = $javascript;
    }

    public function testDrawerControlsOnlyBecomeVisibleAfterJavascriptBinds(): void
    {
        self::assertMatchesRegularExpression(
            '/\.webadmin \.webadminShell-drawerClose \{[^}]*'
                . 'display: none;/s',
            $this->css
        );
        self::assertMatchesRegularExpression(
            '/\.webadmin \.webadminShell-menuToggle,\s*'
                . '\.webadmin \.webadminShell-inspectorToggle \{[^}]*'
                . 'display: none;/s',
            $this->css
        );
        self::assertMatchesRegularExpression(
            "/\[data-webadmin-shell-bound='true'\][^{]*"
                . '\.webadminShell-drawerClose \{[^}]*'
                . 'display: inline-flex;/s',
            $this->css
        );
        self::assertMatchesRegularExpression(
            "/@media \(min-width: 64rem\)[\s\S]*"
                . "\[data-webadmin-shell-bound='true'\][^{]*"
                . '\.webadminShell-drawerClose \{\s*display: none;/s',
            $this->css
        );

        $listener = strpos(
            $this->javascript,
            "menuToggle.addEventListener('click'"
        );
        $bound = strpos(
            $this->javascript,
            "root.dataset.webadminShellBound = 'true';"
        );
        self::assertIsInt($listener);
        self::assertIsInt($bound);
        self::assertGreaterThan($listener, $bound);
    }

    public function testClosedDrawersLeaveTheAccessibilityTreeAndRestoreFocus(): void
    {
        foreach ([
            "sidebar.removeAttribute('aria-hidden')",
            "sidebar.setAttribute('aria-hidden', 'true')",
            "sidebar.removeAttribute('inert')",
            "sidebar.setAttribute('inert', '')",
            'sidebar.contains(document.activeElement)',
            'menuToggle.focus()',
            "inspector.removeAttribute('aria-hidden')",
            "inspector.setAttribute('aria-hidden', 'true')",
            "inspector.removeAttribute('inert')",
            "inspector.setAttribute('inert', '')",
            'inspector.contains(document.activeElement)',
            "event.key !== 'Escape' || isDesktop()",
            "desktop.addEventListener('change', viewportChanged)",
            "desktop.addListener(viewportChanged)",
            "root.addEventListener('webadmin:open-inspector'",
            'event.detail.returnFocus',
            'rememberInspectorReturnFocus(',
            'setInspector(true)',
            'returnFocus.focus()',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }
    }

    public function testServerMarkupKeepsContentAvailableBeforeEnhancement(): void
    {
        $html = (new WebAdminShellRenderer())->render(
            'Editor',
            '<article><h1>Editor</h1></article>',
            new WebAdminShellContext(
                '/admin',
                'csrf',
                '/blog/editor',
                false,
                [],
                '<section><h2>Herramientas</h2></section>'
            )
        );

        foreach ([
            'data-webadmin-shell-toggle',
            'data-webadmin-sidebar-close',
            'data-webadmin-inspector-toggle',
            'data-webadmin-inspector-close',
        ] as $control) {
            self::assertStringContainsString($control, $html);
        }
        self::assertStringNotContainsString(
            'data-webadmin-shell-bound=',
            $html
        );
        self::assertStringNotContainsString('aria-hidden=', $html);
        self::assertStringNotContainsString(' inert', $html);
        self::assertStringContainsString(
            '<aside class="webadminShell-sidebar"',
            $html
        );
        self::assertStringContainsString(
            '<aside class="webadminShell-inspector"',
            $html
        );
    }

    public function testMediaLibraryUsesAFlatResponsivePresentation(): void
    {
        foreach ([
            '.webadmin .webadminMedia {',
            '.webadmin .webadminMedia > section > form {',
            '.webadmin .webadminMedia > section > ul {',
            'repeat(auto-fit, minmax(min(100%, 15rem), 1fr))',
            '.webadmin .webadminMedia > section > ul > li > article {',
            'aspect-ratio: 4 / 3;',
            "nav[aria-label='Paginaci&oacute;n']",
        ] as $contract) {
            self::assertStringContainsString($contract, $this->css);
        }

        self::assertDoesNotMatchRegularExpression(
            '/border-(?:left|inline-start)\s*:/',
            $this->css
        );
        self::assertStringNotContainsString(
            '.webadminMedia::before',
            $this->css
        );
        self::assertStringNotContainsString(
            '.webadminMedia::after',
            $this->css
        );
    }
}
