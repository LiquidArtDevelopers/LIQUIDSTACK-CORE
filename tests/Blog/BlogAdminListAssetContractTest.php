<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BlogAdminListAssetContractTest extends TestCase
{
    public function testDesktopListAndFiltersUseTheFullWorkspace(): void
    {
        $css = file_get_contents(
            dirname(__DIR__, 2)
                . '/modules/blog/published/assets/blog-admin.css'
        );
        self::assertIsString($css);

        foreach ([
            '.webadmin .blogAdminPage--index {',
            'width: 100%',
            'max-width: none',
            'grid-template-columns: minmax(0, 1fr)',
            '.webadmin .blogAdminPage__tableViewport {',
            'position: relative',
            'overflow-x: auto',
            '.webadmin .blogAdminPage--categories table {',
            'min-width: 32rem',
            'display: table',
            'overflow: visible',
            '.webadmin .blogAdminPage > .blogAdminPage__analyticsFilter {',
            'max-width: none',
            'display: flex',
            'flex-wrap: wrap',
            '.webadmin .blogAdminPage__analyticsFilter > div:first-child {',
            'flex: 2 1 20rem',
            '.webadmin .blogAdminPage__rowActions {',
            'min-width: 16.75rem',
            'flex-wrap: nowrap',
            '.webadmin .blogAdminPage__rowActions > * {',
            'flex: 0 0 auto',
            '.webadmin .blogAdminPage__locale {',
            '.webadmin .blogAdminPage__robots {',
            'width: max-content',
            'margin: 0 auto',
            'display: grid',
            'grid-template-columns: repeat(2, 1.5rem)',
            'justify-items: center',
            'gap: 0.35rem',
            '.webadmin .blogAdminPage__robots > * {',
            '.webadmin .blogAdminPage__action--disabled {',
        ] as $contract) {
            self::assertStringContainsString($contract, $css);
        }

        self::assertDoesNotMatchRegularExpression(
            '/\.blogAdminPage\s*\{[^}]*overflow-x\s*:\s*auto/s',
            $css
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.blogAdminPage__analyticsFilter\s*\{[^}]*'
                . '(?:border-left|border-right|border-inline)/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.webadmin \.blogAdminPage__action\s*\{[^}]*'
                . 'width:\s*2\.75rem;[^}]*min-width:\s*2\.75rem;[^}]*'
                . 'min-height:\s*2\.75rem;/s',
            $css
        );
        self::assertStringContainsString(
            ".blogEditor__immersivePreviewStatus[data-state='loading']",
            $css
        );
        self::assertStringContainsString(
            ".blogEditor__immersivePreviewStatus[data-state='ready']",
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.webadmin \.blogAdminPage table\s*\{[^}]*'
                . 'min-width:\s*0;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.webadmin \.blogAdminPage--index table\s*\{[^}]*'
                . 'min-width:\s*81rem;/s',
            $css
        );
    }

    public function testSeoScoreAndRobotsUseFlatColoredValues(): void
    {
        $css = file_get_contents(
            dirname(__DIR__, 2)
                . '/modules/blog/published/assets/blog-admin.css'
        );
        self::assertIsString($css);

        foreach ([
            '.webadmin .blogAdminPage__seoScore {',
            'display: block',
            'font-weight: 850',
            'text-align: center',
            '.webadmin .blogAdminPage__seoScore--red {',
            '--ls-blog-editor-basic-text-red',
            '.webadmin .blogAdminPage__seoScore--orange {',
            '--ls-blog-admin-seo-text-orange: #b85c00',
            '.webadmin .blogAdminPage__categoryStack {',
            'margin-block: 0',
            'padding-inline-start: 1.25rem',
            '.webadmin .blogAdminPage__seoScore--green,',
            '--ls-blog-editor-basic-text-green',
            '.webadmin .blogAdminPage__statusIcon--enabled {',
            '.webadmin .blogAdminPage__statusIcon {',
            'width: 1.5rem',
            'height: 1.5rem',
            'place-items: center',
            '@media (forced-colors: active)',
            'color: CanvasText',
        ] as $contract) {
            self::assertStringContainsString($contract, $css);
        }

        self::assertDoesNotMatchRegularExpression(
            '/\.blogAdminPage__seoScore\s*\{[^}]*(?:border|background|padding)/s',
            $css
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.blogAdminPage__statusIcon\s*\{[^}]*(?:border|background)/s',
            $css
        );
        self::assertStringNotContainsString(
            '.webadmin .blogAdminPage__seoScoreLabel {',
            $css
        );
    }

    public function testEditorialStatusLedKeepsTextAndRespectsReducedMotion(): void
    {
        $css = file_get_contents(
            dirname(__DIR__, 2)
                . '/modules/blog/published/assets/blog-admin.css'
        );
        self::assertIsString($css);

        foreach ([
            '@keyframes blogAdminPublishedStatusPulse',
            '.webadmin .blogAdminPage__postStatus {',
            'display: inline-flex',
            'color: var(--ls-webadmin-text)',
            'white-space: nowrap',
            '.webadmin .blogAdminPage__postStatusLed {',
            'width: 0.625rem',
            'height: 0.625rem',
            'flex: 0 0 0.625rem',
            '.webadmin .blogAdminPage__postStatusLed::after {',
            'width: 0.875rem',
            'height: 0.875rem',
            'filter: blur(0.25rem)',
            '--blog-admin-status-color: #adff2f',
            '--blog-admin-status-color: #d99100',
            '--blog-admin-status-color: #be0000',
            'animation: blogAdminPublishedStatusPulse 3s ease-in-out 1',
            '@media (prefers-reduced-motion: reduce)',
            'animation: none',
        ] as $contract) {
            self::assertStringContainsString($contract, $css);
        }

        foreach (['draft', 'deleted'] as $staticStatus) {
            self::assertDoesNotMatchRegularExpression(
                '/\.blogAdminPage__postStatus--' . $staticStatus
                    . '\s+\.blogAdminPage__postStatusLed\s*\{[^}]*'
                    . 'animation\s*:/s',
                $css
            );
        }
    }

    public function testEditorialConfirmationUsesAnAccessibleHmrSafeDialog(): void
    {
        $asset = dirname(__DIR__, 2)
            . '/modules/blog/published/assets/blog-admin-list.js';
        $javascript = file_get_contents($asset);
        self::assertIsString($javascript);

        foreach ([
            "Symbol.for('liquidstack.blog.admin-list')",
            'new AbortController()',
            "'[data-blog-confirm-form]'",
            "form.dataset.blogConfirmAction === 'unpublish'",
            "form.dataset.blogConfirmAction === 'category-delete'",
            "title: 'Eliminar traducci\\u00f3n de la categor\\u00eda'",
            "confirm: 'Eliminar traducci\\u00f3n'",
            "dialog.setAttribute('aria-labelledby'",
            "dialog.setAttribute(\n            'aria-describedby'",
            'confirmationState.dialog.showModal()',
            'approvedConfirmationForms.add(form)',
            'destroyBulkStates(currentResults)',
            'destroyBulkStates()',
            'event.preventDefault()',
            'previous.dispose()',
        ] as $contract) {
            self::assertStringContainsString($contract, $javascript);
        }
        foreach (
            ['window.confirm(', '.innerHTML', 'eval(', 'new Function']
            as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $javascript);
        }

        $node = new Process(['node', '--check', $asset]);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no estÃ¡ disponible.');
        }
        self::assertSame(0, $node->getExitCode(), $node->getErrorOutput());

        $harness = <<<'JS'
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(process.argv[1], 'utf8');
let listeners = [];
const documentRef = {
  addEventListener(type, handler, options = {}) {
    const entry = { type, handler };
    listeners.push(entry);
    options.signal?.addEventListener('abort', () => {
      listeners = listeners.filter((candidate) => candidate !== entry);
    }, { once: true });
  },
  querySelector() { return null; },
  querySelectorAll() { return []; },
};
const windowRef = {};
const sandbox = {
  window: windowRef,
  document: documentRef,
  AbortController,
  Symbol,
};
const run = () => vm.runInNewContext(source, sandbox, {
  filename: process.argv[1],
});

run();
run();
const listenerCount = listeners.filter(
  (entry) => entry.type === 'submit'
).length;

process.stdout.write(JSON.stringify({
  listenerCount,
}));
JS;
        $runtime = new Process([
            'node',
            '--input-type=module',
            '--eval',
            $harness,
            $asset,
        ]);
        $runtime->run();
        self::assertTrue($runtime->isSuccessful(), $runtime->getErrorOutput());
        $result = json_decode(
            $runtime->getOutput(),
            true,
            8,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(1, $result['listenerCount']);
    }

    public function testCatalogEnhancementAbortsStaleResponsesAndOwnsHistory(): void
    {
        $root = dirname(__DIR__, 2);
        $asset = $root . '/modules/blog/published/assets/blog-admin-list.js';
        $javascript = file_get_contents($asset);
        self::assertIsString($javascript);

        foreach ([
            "'[data-blog-admin-filter-form]'",
            "'[data-blog-admin-results]'",
            "'[data-blog-admin-pagination] a[href]'",
            "'[data-blog-admin-sort][href]'",
            "'per_page', 'sort', 'dir'",
            'catalogRequestController?.abort()',
            'generation !== catalogGeneration',
            'window.fetch(destination.href',
            "credentials: 'same-origin'",
            'new window.DOMParser().parseFromString(',
            'currentResults.replaceWith(importedResults)',
            "window.history.pushState({}, '', destination.href)",
            "window.history.replaceState({}, '', destination.href)",
            "window.addEventListener('popstate'",
            'const catalogStatus = (message, state)',
            'status.dataset.state = state',
            "catalogStatus('', 'neutral')",
            "catalogStatus('Actualizando art\\u00edculos\\u2026', 'busy')",
            "'success'",
            "'error'",
            "}, 350);",
        ] as $contract) {
            self::assertStringContainsString($contract, $javascript);
        }

        $css = file_get_contents(
            $root . '/modules/blog/published/assets/blog-admin.css'
        );
        self::assertIsString($css);
        foreach ([
            "[data-blog-admin-filter-status][data-state='busy']",
            'color: var(--ls-webadmin-muted)',
            "[data-blog-admin-filter-status][data-state='error']",
            'color: var(--ls-webadmin-danger)',
        ] as $contract) {
            self::assertStringContainsString($contract, $css);
        }

        $harness = __DIR__
            . '/fixtures/blog-admin-list-reactive-harness.mjs';
        $runtime = new Process(['node', $harness, $asset]);
        $runtime->run();
        if (!$runtime->isSuccessful()) {
            self::fail($runtime->getErrorOutput() . $runtime->getOutput());
        }
        $result = json_decode(
            $runtime->getOutput(),
            true,
            8,
            JSON_THROW_ON_ERROR
        );

        self::assertTrue($result['firstAborted']);
        self::assertSame(['push', 'replace'], $result['historyTypes']);
        self::assertStringContainsString(
            'q=second',
            $result['historyUrls'][0]
        );
        self::assertStringContainsString(
            'q=third',
            $result['historyUrls'][1]
        );
        self::assertSame(['second', 'third', 'third'], $result['replacements']);
        self::assertSame([], $result['assigned']);
        foreach (['neutral', 'busy', 'success', 'error'] as $state) {
            self::assertContains($state, $result['statusStates']);
        }
        self::assertSame('error', $result['statusState']);
        self::assertSame(
            'No se pudo actualizar el listado.',
            $result['statusText']
        );
        self::assertFalse($result['statusHidden']);
    }

    public function testLanguageCopyEnhancesSsrFallbackAsAccessibleDialog(): void
    {
        $javascript = file_get_contents(
            dirname(__DIR__, 2)
                . '/modules/blog/published/assets/blog-admin-list.js'
        );
        self::assertIsString($javascript);

        foreach ([
            "'[data-blog-language-flow]'",
            "'[data-blog-language-panel]'",
            "input[name=\"destination_locale\"]:checked",
            "typeof HTMLDialogElement !== 'function'",
            "document.createElement('dialog')",
            "trigger.setAttribute('aria-haspopup', 'dialog')",
            'summary.childNodes',
            '(node) => node.cloneNode(true)',
            "trigger.setAttribute('aria-label', triggerLabel)",
            "dialog.setAttribute('aria-labelledby', heading.id)",
            "dialog.setAttribute('aria-describedby', dialogDescribedBy)",
            'dialog.showModal()',
            "dialog.addEventListener('cancel'",
            "dialog.addEventListener('close'",
            'trigger.focus()',
            'clearLiveSearchTimer()',
            'cancelCatalogRequest()',
            "form.addEventListener('submit'",
            "'[data-blog-language-operation]'",
            'fallbackOperationId: operation.value',
            'selected.dataset.blogLanguageOperationId',
            "state.submit.setAttribute('aria-busy', 'true')",
            "state.dialog.setAttribute('tabindex', '-1')",
            'state.dialog.focus({ preventScroll: true })',
            "destination.name = 'destination_locale'",
            "destination.dataset.blogLanguageSubmissionDestination = 'true'",
            "state.outcome.textContent = 'Creando el borrador privado\\u2026'",
            'state.submit.disabled = true',
            'state.close.disabled = true',
            "radio.setAttribute('aria-disabled', 'true')",
            "window.addEventListener('pageshow'",
            'details.append(state.panel)',
            'destroyLanguageFlows(currentResults)',
            'initializeLanguageFlows(importedResults)',
        ] as $contract) {
            self::assertStringContainsString($contract, $javascript);
        }

        $harness = __DIR__
            . '/fixtures/blog-admin-list-language-idempotency-harness.mjs';
        $runtime = new Process(['node', $harness, dirname(__DIR__, 2)
            . '/modules/blog/published/assets/blog-admin-list.js']);
        $runtime->run();
        if (!$runtime->isSuccessful()) {
            self::fail($runtime->getErrorOutput() . $runtime->getOutput());
        }
        $result = json_decode(
            $runtime->getOutput(),
            true,
            8,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('option-operation-es', $result['initial']);
        self::assertSame('option-operation-es', $result['sameAfterPageshow']);
        self::assertSame('option-operation-en', $result['differentAfterBack']);
        self::assertSame('option-operation-en', $result['differentAfterPageshow']);
        self::assertSame(2, $result['submittedDestinations']);

        $css = file_get_contents(
            dirname(__DIR__, 2)
                . '/modules/blog/published/assets/blog-admin.css'
        );
        self::assertIsString($css);
        foreach ([
            '.webadmin .blogAdminPage__languageDialog {',
            'width: min(calc(100% - 2rem), 44rem)',
            '.webadmin .blogAdminPage__languageDialog::backdrop {',
            'grid-template-columns: 1.25rem minmax(0, 1fr)',
            ".blogAdminPage__languageOption > input[type='radio'] {",
            'min-height: 1.25rem',
            '.blogAdminPage__languageOption:focus-within {',
            '.blogAdminPage__languageOption:has(input:checked) {',
            '.blogAdminPage__languageDialog .blogAdminPage__languageActions {',
            'justify-content: flex-end',
        ] as $contract) {
            self::assertStringContainsString($contract, $css);
        }
    }

    public function testNoJsLanguageFallbackAndRevisionRestoreKeepTheirLayout(): void
    {
        $css = file_get_contents(
            dirname(__DIR__, 2)
                . '/modules/blog/published/assets/blog-admin.css'
        );

        self::assertMatchesRegularExpression(
            '/\.webadmin \.blogAdminPage__languageFlow\[open\]\s*\{[^}]*'
                . 'position:\s*fixed;[^}]*inset:\s*0;[^}]*'
                . 'overflow:\s*auto;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.webadmin\s+\.blogAdminPage__languageFlow\[open\]\s*'
                . '>\s*\.blogAdminPage__languagePanel\s*\{[^}]*'
                . 'width:\s*min\(100%,\s*44rem\);[^}]*'
                . 'max-height:\s*calc\(100dvh\s*-\s*2rem\);[^}]*'
                . 'overflow:\s*auto;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.webadmin \.blogAdminPage__languageFlow\[open\]\s*'
                . '>\s*summary\s*\{[^}]*position:\s*fixed;[^}]*'
                . 'inset-block-start:\s*clamp\([^;]+;[^}]*'
                . 'inset-inline-end:\s*clamp\([^;]+;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.webadmin \.blogAdminPage__revisionRestore\s*\{[^}]*'
                . 'width:\s*min\(100%,\s*42rem\);[^}]*'
                . 'padding:\s*clamp\(1rem,\s*2\.5vw,\s*1\.5rem\);[^}]*'
                . 'border:\s*0\.0625rem solid var\(--ls-webadmin-border\);/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.webadmin \.blogAdminPage__revisionRestore\s*>\s*summary'
                . '\s*\{[^}]*cursor:\s*pointer;[^}]*font-weight:\s*800;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.webadmin \.blogAdminPage__revisionRestore\s*>\s*form'
                . '\s*\{[^}]*margin:\s*0;/s',
            $css
        );
    }

    public function testRevisionMediaStaysInsideTheAdminContentColumn(): void
    {
        $css = file_get_contents(
            dirname(__DIR__, 2)
                . '/modules/blog/published/assets/blog-admin.css'
        );
        self::assertIsString($css);

        $revisionScope = "section[aria-labelledby='blog-revision-content-title']";
        self::assertStringContainsString($revisionScope, $css);
        self::assertMatchesRegularExpression(
            '/' . preg_quote($revisionScope, '/') . '\s*\{[^}]*'
                . 'min-width:\s*0;[^}]*max-width:\s*100%;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/' . preg_quote($revisionScope, '/') . '\s*'
                . ':is\(figure,\s*picture,\s*img\)\s*\{[^}]*'
                . 'max-width:\s*100%;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/' . preg_quote($revisionScope, '/') . '\s*figure\s*\{[^}]*'
                . 'width:\s*100%;[^}]*margin-inline:\s*0;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/' . preg_quote($revisionScope, '/') . '\s*'
                . ':is\(picture,\s*img\)\s*\{[^}]*width:\s*100%;[^}]*'
                . 'display:\s*block;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/' . preg_quote($revisionScope, '/') . '\s*img\s*\{[^}]*'
                . 'height:\s*auto;/s',
            $css
        );
    }

    public function testPrivatePreviewReusesTheExternalImmersiveShell(): void
    {
        $javascript = file_get_contents(
            dirname(__DIR__, 2)
                . '/modules/blog/published/assets/blog-admin-list.js'
        );
        self::assertIsString($javascript);

        foreach ([
            "'[data-blog-private-preview]'",
            "document.createElement('dialog')",
            "document.createElement('iframe')",
            "['desktop', 'tablet', 'mobile']",
            "'blogEditor__immersivePreviewStage'",
            "'blogEditor__immersivePreviewToolbar'",
            "'Volver a gesti\\u00f3n'",
            'dialog.append(stage, toolbar)',
            'destination.origin !== window.location.origin',
            "destination.pathname.endsWith('/editor/preview')",
            "frame.referrerPolicy = 'no-referrer'",
            'state.frame.contentWindow.location.href',
            'state.frame.contentDocument',
            ".blogPreviewReady === 'true'",
            "'link[rel~=\"stylesheet\"]'",
            'stylesheets.every((stylesheet) => stylesheet.sheet !== null)',
            "state.status.dataset.state = 'ready'",
            "state.status.dataset.state = 'error'",
            "state.status.dataset.state = 'loading'",
            "state.status.dataset.state !== 'ready'",
            'button.disabled = true',
            'setPreviewDeviceAvailability(state, true)',
            "state.frame.src = 'about:blank'",
            'const previewLoadTimeoutMs = 12000',
            'state.loadGeneration !== generation',
            'state.url !== expectedUrl',
            'actual.href !== expectedUrl',
            'clearPreviewLoadTimeout(state)',
            'resetPreviewLoad(previewState)',
            'completa y con estilos.',
        ] as $contract) {
            self::assertStringContainsString($contract, $javascript);
        }

        foreach ([
            'Guardar borrador',
            'Publicar',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $javascript);
        }

        $css = file_get_contents(
            dirname(__DIR__, 2)
                . '/modules/blog/published/assets/blog-admin.css'
        );
        self::assertIsString($css);
        self::assertMatchesRegularExpression(
            '/\.webadmin \.blogEditor__immersivePreviewToolbar\s*\{[^}]*'
                . 'max-height:\s*min\(60dvh,\s*24rem\);[^}]*'
                . 'overflow-y:\s*auto;[^}]*'
                . 'overscroll-behavior:\s*contain;/s',
            $css
        );

        $harness = __DIR__
            . '/fixtures/blog-admin-list-preview-timeout-harness.mjs';
        $runtime = new Process(['node', $harness, dirname(__DIR__, 2)
            . '/modules/blog/published/assets/blog-admin-list.js']);
        $runtime->run();
        if (!$runtime->isSuccessful()) {
            self::fail($runtime->getErrorOutput() . $runtime->getOutput());
        }
        $result = json_decode(
            $runtime->getOutput(),
            true,
            8,
            JSON_THROW_ON_ERROR
        );
        self::assertTrue($result['initialFocusIsClose']);
        self::assertSame(12000, $result['timeoutDelay']);
        self::assertSame([
            'timerCount' => 0,
            'state' => 'ready',
            'text' => 'Vista de escritorio.',
            'devicesEnabled' => true,
        ], $result['validLoad']);
        self::assertSame('loading', $result['afterStale']['state']);
        self::assertSame(1, $result['afterStale']['timerCount']);
        self::assertStringContainsString(
            'post=second',
            $result['afterStale']['src']
        );
        self::assertFalse($result['afterStale']['hidden']);
        self::assertSame([
            'timerCount' => 1,
            'state' => 'loading',
            'src' => 'https://example.test/admin/blog/editor/preview?post=second&locale=en',
            'hidden' => false,
            'devicesDisabled' => true,
        ], $result['afterStaleLoad']);
        self::assertSame([
            'timerCount' => 0,
            'state' => 'error',
            'src' => 'about:blank',
            'hidden' => true,
            'devicesDisabled' => true,
        ], $result['afterCurrentTimeout']);
        self::assertSame(1, $result['disposeTimerCount']);
        self::assertSame(0, $result['timersAfterDispose']);
        self::assertTrue($result['dialogRemoved']);
    }
}
