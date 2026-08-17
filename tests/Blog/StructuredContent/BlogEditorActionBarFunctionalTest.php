<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BlogEditorActionBarFunctionalTest extends TestCase
{
    public function testSaveDirtyBaselineAndPreviewLifecycle(): void
    {
        $node = new Process(['node', '--version']);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no est&aacute; disponible.');
        }

        $root = dirname(__DIR__, 3);
        $process = new Process([
            'node',
            $root . '/tests/Blog/StructuredContent/fixtures/'
                . 'blog-editor-actionbar-harness.mjs',
            $root . '/modules/blog/published/assets/blog-editor.js',
        ]);
        $process->mustRun();
        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame([
            'textFlowTechnicalProjectionStaysValid' => true,
            'semanticTechnicalLimitsMatchDomain' => true,
            'genericFormPayloadRemainsUnfiltered' => true,
            'editorPayloadIgnoresLayoutControls' => true,
            'editorFingerprintIgnoresLayoutControls' => true,
            'savePayloadIgnoresLayoutControls' => true,
            'externalSaveButtonUsesAssociatedForm' => true,
            'cleanSubmitStaysInEditor' => true,
            'dirtySubmitStaysInEditor' => true,
            'failedSubmitStaysInEditor' => true,
            'thrownValidationCannotEscapeSubmit' => true,
            'noFetchSubmitKeepsSsrFallback' => true,
            'successBaselineClean' => true,
            'previewUsesSavedSnapshot' => true,
            'dirtyPreviewOpensBeforeSaveAndWaitsFor200' => true,
            'validPreviewRequiresMarker' => true,
            'preview503CannotBecomeReady' => true,
            'loginRedirectCannotBecomeReady' => true,
            'missingMarkerCannotBecomeReady' => true,
            'crossOriginPreviewCannotBecomeReady' => true,
            'backendUnavailableUsesClientAllowlist' => true,
            'failedPreviewStaysRecoverableInDialog' => true,
            'pendingPreviewBlocksDoubleActivation' => true,
            'inFlightEditRemainsDirty' => true,
            'failedSaveRemainsDirty' => true,
            'cleanNavigationUsesNativePath' => true,
            'dirtyNavigationSavesFirst' => true,
            'pendingNavigationAwaitsSave' => true,
            'failedNavigationStaysInEditor' => true,
            'keyboardNavigationSavesFirst' => true,
            'noFetchNavigationOffersCustomChoice' => true,
            'discardNavigationLeavesWithoutSaving' => true,
            'cleanLogoutKeepsNativePost' => true,
            'dirtyLogoutCanSaveThenPostOnce' => true,
            'failedLogoutStaysInEditor' => true,
            'dirtyLogoutCanStay' => true,
            'dirtyLogoutCanDiscardThenPostOnce' => true,
            'unrelatedPostFormsStayNative' => true,
            'excludedLinksStayNative' => true,
        ], $result);
    }

    public function testEditorPayloadAllowlistAlsoProtectsSeoWithoutFilteringOtherForms(): void
    {
        $asset = file_get_contents(
            dirname(__DIR__, 3)
                . '/modules/blog/published/assets/blog-editor.js'
        );

        self::assertIsString($asset);
        self::assertMatchesRegularExpression(
            '/function initSeoAnalysis\(context\).*?'
                . 'var body = editorFormBody\(context\.form\);/s',
            $asset
        );
        self::assertStringContainsString(
            'var submittedBody = editorFormBody(context.form);',
            $asset
        );
        self::assertMatchesRegularExpression(
            '/function formFingerprint\(form\)\s*\{\s*'
                . 'return editorFormBody\(form\)\.toString\(\);/s',
            $asset
        );
        self::assertMatchesRegularExpression(
            '/function editorialFormFingerprint\(form\)\s*\{\s*'
                . 'var body = editorFormBody\(form\);/s',
            $asset
        );
        self::assertStringContainsString(
            'body: editorFormBody(form).toString(),',
            $asset
        );
        self::assertMatchesRegularExpression(
            '/function categoryFormBody\(form\)\s*\{\s*'
                . 'var body = formBody\(form\);/s',
            $asset
        );
        self::assertStringContainsString(
            'var submittedBody = formBody(publishForm);',
            $asset
        );
    }

    public function testEditorUsesAccessibleNavigationDialogNotNativePrompts(): void
    {
        $asset = file_get_contents(
            dirname(__DIR__, 3)
                . '/modules/blog/published/assets/blog-editor.js'
        );

        self::assertIsString($asset);
        self::assertStringNotContainsString('beforeunload', $asset);
        self::assertStringNotContainsString('onbeforeunload', $asset);
        self::assertStringNotContainsString('window.confirm', $asset);
        self::assertStringContainsString(
            "dialog.setAttribute('aria-modal', 'true')",
            $asset
        );
        self::assertStringContainsString('Guardar y salir', $asset);
        self::assertStringContainsString('Salir sin guardar', $asset);
        self::assertStringContainsString('Seguir editando', $asset);
    }

    public function testPreviewErrorsAreExplicitlyDangerColored(): void
    {
        $stylesheet = file_get_contents(
            dirname(__DIR__, 3)
                . '/modules/blog/published/assets/blog-admin.css'
        );

        self::assertIsString($stylesheet);
        self::assertStringContainsString(
            ".blogEditor__immersivePreviewStatus[data-state='error']",
            $stylesheet
        );
        self::assertStringContainsString(
            "[data-blog-editor-status][data-state='error']",
            $stylesheet
        );
        self::assertGreaterThanOrEqual(
            2,
            substr_count($stylesheet, 'color: var(--ls-webadmin-danger)')
        );
        self::assertMatchesRegularExpression(
            '/\.blogEditor__immersivePreviewDevices button,\s*'
                . '\.webadmin \.blogEditor__immersivePreviewActions button\s*'
                . '\{[^}]*min-height:\s*2\.75rem;/s',
            $stylesheet
        );
    }
}
