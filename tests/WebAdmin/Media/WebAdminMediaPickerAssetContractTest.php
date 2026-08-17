<?php

declare(strict_types=1);

namespace Tests\WebAdmin\Media;

use App\Core\WebAdmin\Media\Http\WebAdminMediaPickerHtmlRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class WebAdminMediaPickerAssetContractTest extends TestCase
{
    public function testPickerAssetIsSafeAbortableAndKeepsSelectionAcrossPages(): void
    {
        $root = dirname(__DIR__, 3);
        $scriptPath = $root . '/modules/webadmin/published/assets/'
            . 'webadmin-media-picker.js';
        $stylesheetPath = $root . '/modules/webadmin/published/assets/'
            . 'webadmin-media-picker.css';
        self::assertFileExists($scriptPath);
        self::assertFileExists($stylesheetPath);
        $script = (string) file_get_contents($scriptPath);
        $stylesheet = (string) file_get_contents($stylesheetPath);

        foreach ([
            "'X-LiquidStack-Media-Picker': 'async'",
            "'Accept': 'application/json'",
            "credentials: 'same-origin'",
            'new AbortController()',
            'state.request.abort()',
            'setSelection(item)',
            'renderActive()',
            "select.dispatchEvent(new Event('change', { bubbles: true }))",
            'new MutationObserver(',
            'previous.destroy()',
            'results.replaceChildren()',
            "dialog.setAttribute('data-webadmin-media-picker-enhanced', 'true')",
            'Date.parse(item.created_at)',
            "'Anchos: ' + item.variants.map(",
            'new Intl.DateTimeFormat(',
            "var selectionEvent = 'liquidstack:webadmin-media-picker:selected'",
            'new CustomEvent(',
            'bubbles: true, cancelable: true, detail: detail',
            'confirmButton.disabled = false',
            'function restoreConfirmedSelection()',
            'function closeDialog(cancelSelection)',
            "dialog.addEventListener('cancel'",
            "'[data-webadmin-media-picker-upload]'",
            "'X-LiquidStack-Media-Manager': 'async'",
            'body: new FormData(upload)',
            'idempotency.value = requestId()',
            'state.uploadPending',
            'state.uploadRequest.abort()',
            'confirmSelection();',
        ] as $contract) {
            self::assertStringContainsString($contract, $script);
        }
        foreach ([
            '.innerHTML',
            '.outerHTML',
            'document.write',
            'localStorage',
            'sessionStorage',
            'document.cookie',
            'data-blog-media-use',
            'data-blog-media-close',
            'data-blog-media-upload',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $script);
        }
        foreach ([
            '.webadminMediaPicker',
            'var(--ls-webadmin-accent)',
            '[aria-pressed="true"]',
            '[data-webadmin-media-picker-enhanced="true"]',
            'max-height: calc(100dvh - 2rem)',
        ] as $contract) {
            self::assertStringContainsString($contract, $stylesheet);
        }
        self::assertSame(
            '/assets/modules/webadmin/webadmin-media-picker.css',
            WebAdminMediaPickerHtmlRenderer::STYLESHEET_PATH
        );
        self::assertSame(
            '/assets/modules/webadmin/webadmin-media-picker.js',
            WebAdminMediaPickerHtmlRenderer::SCRIPT_PATH
        );
    }

    public function testPickerJavascriptParses(): void
    {
        $process = new Process([
            'node',
            '--check',
            dirname(__DIR__, 3)
                . '/modules/webadmin/published/assets/'
                . 'webadmin-media-picker.js',
        ]);
        $process->run();
        if ($process->getExitCode() === 9009) {
            self::markTestSkipped('Node.js no est&aacute; disponible.');
        }

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    public function testPickerOwnsSelectionCancellationAndContextualUpload(): void
    {
        $root = dirname(__DIR__, 3);
        $process = new Process([
            'node',
            $root . '/tests/WebAdmin/Media/fixtures/'
                . 'webadmin-media-picker-harness.mjs',
            $root . '/modules/webadmin/published/assets/'
                . 'webadmin-media-picker.js',
        ]);
        $process->run();
        if ($process->getExitCode() === 9009) {
            self::markTestSkipped('Node.js no est&aacute; disponible.');
        }
        self::assertTrue(
            $process->isSuccessful(),
            $process->getErrorOutput() . $process->getOutput()
        );
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        foreach ([
            'enhanced',
            'enabledFromEmpty',
            'firstConfirmed',
            'tentativeSecond',
            'cancelPreservedDraft',
            'uploadConfirmed',
        ] as $outcome) {
            self::assertTrue($result[$outcome], $outcome);
        }
        self::assertSame(
            ['label', 'public_id', 'thumbnail_url'],
            $result['selectionShape']
        );
    }
}
