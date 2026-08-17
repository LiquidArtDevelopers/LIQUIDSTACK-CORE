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

    public function testDrawerTogglesRemainVisibleAsAccessibleEdgeTabs(): void
    {
        foreach ([
            "button.dataset.webadminToggleState = open ? 'open' : 'closed'",
            "updateToggle(menuToggle, open, 'sidebar')",
            "updateToggle(inspectorToggle, open, 'inspector')",
            "button.setAttribute('aria-label', text)",
            "label.textContent = text",
            "open ? '‹' : '›'",
            "open ? '›' : '‹'",
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }

        foreach ([
            '--ls-webadmin-sidebar-edge:',
            '--ls-webadmin-inspector-edge:',
            ".webadminShell[data-webadmin-sidebar-open='true']",
            ".webadminShell[data-webadmin-inspector-open='true']",
            'inset-inline-start: var(--ls-webadmin-sidebar-edge);',
            'inset-inline-end: var(--ls-webadmin-inspector-edge);',
            '.webadmin .webadminShell-toggleIcon {',
            '.webadmin .webadminShell-visuallyHidden {',
            '@media (max-width: 20rem)',
            'min-width: 100%;',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->css);
        }
    }

    public function testShellNavigationRemainsInOneColumnOnDesktop(): void
    {
        self::assertMatchesRegularExpression(
            '/\.webadmin \.webadminShell-navList \{[^}]*'
                . 'grid-template-columns:\s*minmax\(0,\s*1fr\);/s',
            $this->css
        );
        self::assertMatchesRegularExpression(
            '/@media \(min-width: 48rem\)[\s\S]*?'
                . '\.webadmin nav ul \{[^}]*'
                . 'grid-template-columns:\s*repeat\(2,\s*minmax\(0,\s*1fr\)\);/s',
            $this->css
        );
    }

    public function testLogoutStaysAtTheInlineEndOfTheTopbar(): void
    {
        self::assertMatchesRegularExpression(
            '/\.webadmin \.webadminShell-logout \{[^}]*'
                . 'margin-block:\s*0;[^}]*'
                . 'margin-inline-start:\s*auto;/s',
            $this->css
        );
    }

    public function testSharedActionsHaveAccessibleReusableVariants(): void
    {
        foreach ([
            '.webadmin .webadminAction {',
            'min-height: 2.75rem;',
            'display: inline-flex;',
            'touch-action: manipulation;',
            '.webadmin .webadminAction--primary {',
            '.webadmin .webadminAction--secondary {',
            '.webadmin .webadminAction--danger {',
            '.webadmin .webadminAction--compact {',
            ".webadmin .webadminAction[aria-disabled='true'] {",
            '.webadmin .webadminActionGroup {',
            'flex-wrap: wrap;',
            'gap: 0.75rem;',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->css);
        }

        self::assertMatchesRegularExpression(
            '/\.webadmin \.webadminAction:focus-visible \{[^}]*'
                . 'outline:\s*0\.2rem solid var\(--ls-webadmin-focus\);/s',
            $this->css
        );
        self::assertMatchesRegularExpression(
            '/\.webadmin button\.webadminAction:disabled,\s*'
                . "\\.webadmin \\.webadminAction\\[aria-disabled='true'\\] "
                . '\{[^}]*cursor:\s*not-allowed;/s',
            $this->css
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.webadminAction[^\{]*\{[^}]*(?:border-left|border-right|'
                . 'border-inline-(?:start|end))\s*:/s',
            $this->css
        );
    }

    public function testNativeCheckboxesKeepAReusableTwentyPixelBoxModel(): void
    {
        self::assertMatchesRegularExpression(
            '/\.webadmin input\[type="checkbox"\] \{[^}]*'
                . 'width:\s*1\.25rem;[^}]*'
                . 'height:\s*1\.25rem;[^}]*'
                . 'min-height:\s*1\.25rem;[^}]*'
                . 'margin:\s*0;[^}]*'
                . 'padding:\s*0;[^}]*'
                . 'flex:\s*0 0 1\.25rem;/s',
            $this->css
        );
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
        self::assertDoesNotMatchRegularExpression(
            '/<aside[^>]*(?:aria-hidden=|\sinert(?:\s|>|=))/',
            $html
        );
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
            '.webadmin .webadminMedia [data-webadmin-media-catalog] {',
            'repeat(auto-fit, minmax(min(100%, 15rem), 1fr))',
            '.webadmin .webadminMedia [data-webadmin-media-catalog] > li > article {',
            'aspect-ratio: 4 / 3;',
            '.webadmin .webadminMedia__usage--used {',
            '.webadmin .webadminMedia__usage--unused {',
            '.webadmin .webadminMedia__usage--unknown {',
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

    public function testMediaUploadEnhancementKeepsItsBusyAndFileStatesVisible(): void
    {
        foreach ([
            'bindMediaUploadForm',
            "form.dataset.webadminMediaSubmitting === 'true'",
            'event.preventDefault()',
            'submit.disabled = submitting',
            "setWebAdminLoader(loader, submitting)",
            "fileName.dataset.webadminMediaFileSelected = selected",
            "window.addEventListener('pageshow'",
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }

        foreach ([
            'input[data-webadmin-media-file]::file-selector-button',
            '.webadmin .webadminMedia__fileName {',
            "data-webadmin-media-file-selected='true'",
            '[data-webadmin-media-submit]:disabled',
            '.webadmin .webadminLoader__cube {',
            '@keyframes webadminLoaderCubeRotate',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->css);
        }
    }

    public function testMediaQuarantineEnhancesTheNativeFormWithoutReload(): void
    {
        foreach ([
            'function bindMediaDelete(root)',
            "form.matches('[data-webadmin-media-delete-form]')",
            'dialog.showModal()',
            "['csrf', 256]",
            "['asset', 64]",
            "['asset_version', 128]",
            "['idempotency_key', 64]",
            "['page', 6]",
            "form.querySelectorAll('[name]')",
            'new window.URLSearchParams()',
            'encodedDeletePayload(pendingForm)',
            'action.origin !== window.location.origin',
            'window.fetch(action.toString(), {',
            "credentials: 'same-origin'",
            "'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'",
            "'X-LiquidStack-Media-Manager': 'async'",
            "Accept: 'application/json'",
            "'[data-webadmin-media-catalog-region]'",
            'currentRegion.replaceWith(nextRegion)',
            'webadminMediaDeleteBound',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }
        self::assertStringNotContainsString(
            'window.location.reload',
            $this->javascript
        );
        $deleteStart = strpos($this->javascript, 'function bindMediaDelete');
        $deleteEnd = strpos($this->javascript, 'function bindAdminShell');
        self::assertIsInt($deleteStart);
        self::assertIsInt($deleteEnd);
        $deleteScript = substr(
            $this->javascript,
            $deleteStart,
            $deleteEnd - $deleteStart
        );
        self::assertStringNotContainsString('FormData', $deleteScript);
        self::assertStringNotContainsString('multipart/form-data', $deleteScript);
        foreach ([
            '[data-webadmin-media-delete-form]',
            '[data-webadmin-media-delete-open]',
            '.webadmin .webadminMedia__deleteDialog {',
            '.webadmin .webadminMedia__deleteDialog::backdrop {',
            'data-webadmin-media-delete-confirm',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->css);
        }
        self::assertMatchesRegularExpression(
            '/\.webadmin \.webadminMedia__deleteDialogActions\s*\[\s*'
                . 'data-webadmin-media-delete-confirm\s*\]\s*\{[^}]*'
                . 'color:\s*#fff;[^}]*'
                . 'background:\s*var\(--ls-webadmin-danger\);/s',
            $this->css
        );
    }

    public function testProfileTimeZoneIsOnlyProposedWithoutBrowserPersistence(): void
    {
        foreach ([
            'function bindProfileTimeZone(input)',
            "input.value.trim() !== ''",
            'Intl.DateTimeFormat()',
            '.resolvedOptions().timeZone',
            'input.value = proposal',
            'Revísala antes de guardar.',
            "document.querySelectorAll('[data-webadmin-profile-time-zone]')",
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }
        $start = strpos($this->javascript, 'function bindProfileTimeZone');
        $end = strpos($this->javascript, 'function init()', $start ?: 0);
        self::assertIsInt($start);
        self::assertIsInt($end);
        $profileScript = substr($this->javascript, $start, $end - $start);
        foreach (['localStorage', 'sessionStorage', 'document.cookie', 'fetch(']
            as $forbidden) {
            self::assertStringNotContainsString($forbidden, $profileScript);
        }
    }
}
