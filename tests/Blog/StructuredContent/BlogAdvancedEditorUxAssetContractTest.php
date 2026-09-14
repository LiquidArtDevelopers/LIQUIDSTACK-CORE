<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BlogAdvancedEditorUxAssetContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $result;
    private string $javascript;
    private string $stylesheet;
    private string $publicStylesheet;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $this->javascript = $this->read(
            $root . '/modules/blog/published/assets/blog-editor.js'
        );
        $this->stylesheet = $this->read(
            $root . '/modules/blog/published/assets/blog-admin.css'
        );
        $this->publicStylesheet = $this->read(
            $root . '/modules/blog/published/assets/blog-public.css'
        );
        $process = new Process([
            'node',
            $root . '/tests/Blog/StructuredContent/fixtures/'
                . 'blog-editor-advanced-ux-harness.mjs',
            $root . '/modules/blog/published/assets/blog-editor.js',
        ]);
        $process->mustRun();
        $this->result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    public function testCodeEditingPairsIndentationAndAltGraphAreBehavioral(): void
    {
        $pairs = $this->result['pairs'];

        self::assertSame('    ', $this->result['indent']);
        self::assertSame('p{}', $pairs['openBrace']['value']);
        self::assertSame(2, $pairs['openBrace']['start']);
        self::assertSame("p{\n    \n}", $pairs['pairedEnter']['value']);
        self::assertSame(7, $pairs['pairedEnter']['start']);
        self::assertSame('p{}', $pairs['overtypedBrace']['value']);
        self::assertSame(3, $pairs['overtypedBrace']['start']);
        self::assertSame('p', $pairs['deletedBracePair']['value']);
        self::assertSame('(alpha)', $pairs['wrappedSelection']['value']);
        self::assertSame(1, $pairs['wrappedSelection']['start']);
        self::assertSame(6, $pairs['wrappedSelection']['end']);
        self::assertSame('""', $pairs['pairedQuote']['value']);
        self::assertSame(2, $pairs['overtypedQuote']['start']);
        self::assertSame('<p></p>', $pairs['htmlClosed']['value']);
        self::assertSame("<p>\n    \n</p>", $pairs['htmlEntered']['value']);
        self::assertSame(
            '<p title="a > b"></p>',
            $pairs['quotedAttributeHtmlClosed']['value']
        );
        self::assertSame(
            "<p title=\"a > b\">\n    \n</p>",
            $pairs['quotedAttributeHtmlEntered']['value']
        );
        self::assertNull($pairs['openQuotedAttributeHtmlClosed']);
        self::assertSame(
            '<p title="a > b"><strong></strong>',
            $pairs['nestedQuotedAttributeHtmlClosed']['value']
        );
        self::assertSame("    one\n    two", $pairs['tabbed']['value']);
        self::assertSame("one\ntwo", $pairs['untabbed']['value']);

        self::assertTrue($this->result['modifiers']['altGraph']);
        self::assertFalse($this->result['modifiers']['altGraphCommand']);
        self::assertTrue($this->result['modifiers']['fallback']);
        self::assertFalse($this->result['modifiers']['fallbackCommand']);
        self::assertTrue($this->result['modifiers']['command']);

        self::assertSame('p{}', $this->result['textarea']['value']);
        self::assertSame([[
            'replacement' => '{}',
            'start' => 1,
            'end' => 1,
            'mode' => 'end',
        ]], $this->result['textarea']['setRangeTextCalls']);
        self::assertSame(2, $this->result['textarea']['selectionStart']);
        self::assertGreaterThanOrEqual(
            2,
            substr_count(
                $this->javascript,
                'event.isComposing || event.keyCode === 229'
            )
        );
    }

    public function testProgrammaticCodeEditsHaveBoundedUndoAndRedoUnits(): void
    {
        $history = $this->result['history'];
        foreach ($history['scenarios'] as $scenario) {
            self::assertTrue($scenario['after']['touched']);
            self::assertTrue($scenario['undoHandled']);
            self::assertFalse($scenario['undo']['touched']);
            self::assertTrue($scenario['redoHandled']);
            self::assertTrue($scenario['redo']['touched']);
            self::assertSame(
                $scenario['after']['value'],
                $scenario['redo']['value']
            );
            self::assertSame(
                $scenario['after']['start'],
                $scenario['redo']['start']
            );
        }
        self::assertSame('p', $history['scenarios']['htmlPair']['undo']['value']);
        self::assertSame('p', $history['scenarios']['cssPair']['undo']['value']);
        self::assertSame(
            'p{}',
            $history['scenarios']['pairedEnter']['undo']['value']
        );
        self::assertSame(
            '<p',
            $history['scenarios']['htmlAutoClose']['undo']['value']
        );
        self::assertSame('undo', $history['directions']['undo']);
        self::assertSame('redo', $history['directions']['redoShift']);
        self::assertSame('redo', $history['directions']['redoY']);
        self::assertNull($history['directions']['native']);
        self::assertNull($history['directions']['ime']);
        self::assertSame(
            ['p{x}', 'p{}', 'p'],
            array_column($history['unifiedUndo'], 'value')
        );
        self::assertSame(
            ['p{}', 'p{x}', 'p{}'],
            array_column($history['unifiedRedo'], 'value')
        );
        self::assertSame([
            'units' => 1,
            'undo' => true,
            'afterUndo' => '',
            'redo' => true,
            'afterRedo' => 'abc',
        ], $history['contiguousTyping']);
        self::assertSame([
            'units' => 0,
            'value' => 'p{}',
            'start' => 3,
        ], $history['overtype']);
        self::assertSame([3, 2, 1], array_column(
            $history['unifiedUndo'],
            'start'
        ));
        self::assertNotContains(
            false,
            array_column($history['unifiedUndo'], 'handled')
        );
        self::assertNotContains(
            false,
            array_column($history['unifiedRedo'], 'handled')
        );
        self::assertSame(
            ['value' => 'c', 'redo' => 0, 'undo' => 1],
            $history['branch']
        );
        self::assertTrue($history['nativeKinds']['typed']['recorded']);
        self::assertTrue($history['nativeKinds']['pasted']['recorded']);
        self::assertSame(
            ['insertText', 'insertFromPaste'],
            $history['nativeKinds']['inputTypes']
        );
        self::assertSame([
            'recorded' => true,
            'undo' => true,
            'value' => '',
            'units' => 1,
        ], $history['composition']);
        self::assertSame([
            'undoDirection' => 'undo',
            'undoPrevented' => true,
            'redoDirection' => 'redo',
            'redoPrevented' => true,
            'value' => 'p{}',
        ], $history['beforeInputHistory']);
        self::assertSame(100, $history['boundedUndo']);

        foreach ([
            'function richCodeHistoryBeforeInput(',
            'function richCodeHistoryCommitInput(',
            'function richCodeHistoryCommitComposition(',
            "source.addEventListener('beforeinput'",
            "cssSource.addEventListener('beforeinput'",
            "source.addEventListener('compositionend'",
            "cssSource.addEventListener('compositionend'",
        ] as $historyContract) {
            self::assertStringContainsString(
                $historyContract,
                $this->javascript
            );
        }
    }

    public function testPrettyHtmlPreservesInlineSpacingAndMarksInBothOrders(): void
    {
        self::assertSame(
            '<div class="lead">' . "\n"
                . '    <p>Alpha <span id="beta">beta</span></p>' . "\n"
                . '    <blockquote>Gamma <span>delta</span></blockquote>' . "\n"
                . '</div>',
            $this->result['formatted']
        );
        self::assertTrue($this->result['formattedStable']);
        self::assertSame(
            '<div>Alpha <span>beta</span> '
                . '<blockquote>Gamma <span>delta</span></blockquote> tail</div>',
            $this->result['mixedFormatted']
        );
        self::assertSame(
            '<div>Alpha <span>beta</span> '
                . '<blockquote>Gamma <span>delta</span></blockquote> tail!</div>',
            $this->result['mixedAfterMinimalEdit']
        );

        $expected = '<span data-ls-text-color="color02">'
            . '<strong>independiente</strong></span>';
        self::assertSame($expected, $this->result['marks']['colorThenBold']);
        self::assertSame($expected, $this->result['marks']['boldThenColor']);
        self::assertSame(
            0,
            substr_count($this->result['marks']['stableFlow'], '<p></p>')
        );
        self::assertSame(
            3,
            substr_count($this->result['marks']['stableFlow'], '<p>')
        );
    }

    public function testVisualListDoubleEnterExitsWithoutLosingAdvancedMarkup(): void
    {
        $listEnter = $this->result['advanced']['listEnter'];
        $unorderedId = '81000000-0000-4000-8000-000000000001';
        $orderedId = '81000000-0000-4000-8000-000000000002';
        $unorderedItem = '<li data-content-list-item-id="'
            . $unorderedId . '" class="item" lang="es">'
            . '<span data-content-format-text-color="color04">'
            . '<strong>Uno</strong></span></li>';
        $orderedItem = '<li data-content-list-item-id="'
            . $orderedId . '"><em>Primero</em></li>';

        self::assertSame(
            '<ul class="miLista" aria-label="Prueba" '
                . 'data-content-list-marker="square">' . "\n"
                . '    ' . $unorderedItem . "\n"
                . '    <li class="item" lang="es"></li>' . "\n"
                . '</ul>',
            $listEnter['unordered']['splitSource']
        );
        self::assertSame(
            '<ul class="miLista" aria-label="Prueba" '
                . 'data-content-list-marker="square">' . "\n"
                . '    ' . $unorderedItem . "\n"
                . '</ul>' . "\n" . '<br>',
            $listEnter['unordered']['exitSource']
        );
        self::assertSame(
            '<ol class="steps" start="3">' . "\n"
                . '    ' . $orderedItem . "\n"
                . '    <li></li>' . "\n"
                . '</ol>',
            $listEnter['ordered']['splitSource']
        );
        self::assertSame(
            '<ol class="steps" start="3">' . "\n"
                . '    ' . $orderedItem . "\n"
                . '</ol>' . "\n" . '<br>',
            $listEnter['ordered']['exitSource']
        );
        self::assertSame(
            '<ul class="miLista" aria-label="Prueba" '
                . 'data-content-list-marker="square">' . "\n"
                . '    ' . $unorderedItem . "\n"
                . '</ul>',
            $listEnter['unordered']['discardedSource']
        );
        self::assertSame(
            '<ol class="steps" start="3">' . "\n"
                . '    ' . $orderedItem . "\n"
                . '</ol>',
            $listEnter['ordered']['discardedSource']
        );
        self::assertSame(
            ['strong', 'text-color04'],
            $listEnter['unordered']['originalMarks']
        );
        self::assertSame(['em'], $listEnter['ordered']['originalMarks']);
        self::assertSame(
            '& .miLista { color: red; }',
            $listEnter['unordered']['css']
        );
        self::assertSame(
            '& .miLista { color: red; }',
            $listEnter['ordered']['css']
        );
        self::assertSame('<br>', $listEnter['singleEmptyExit']);
        self::assertSame('<br>', $listEnter['singleBreakExit']);
        self::assertSame(
            ['direct' => true, 'nested' => false],
            $this->result['advanced']['listEnterGuard']
        );
        self::assertSame(
            1,
            substr_count($listEnter['unordered']['splitSource'], $unorderedId)
        );
        self::assertSame(
            1,
            substr_count($listEnter['ordered']['splitSource'], $orderedId)
        );
        self::assertStringContainsString(
            'currentList.lastElementChild === current',
            $this->javascript
        );
        self::assertStringContainsString(
            "splitItem.removeAttribute('data-content-list-item-id')",
            $this->javascript
        );

        $interaction = $this->result['advanced']['listExitInteraction'];
        self::assertSame(
            '<ul data-content-list-marker="disc">' . "\n"
                . '    <li>Uno</li>' . "\n"
                . '</ul>' . "\n" . '<p>X</p>',
            $interaction['typed']['serialized']
        );
        self::assertSame(
            '<ul data-content-list-marker="disc">' . "\n"
                . '    <li>Uno</li>' . "\n"
                . '</ul>' . "\n" . '<br>',
            $interaction['abandoned']['serialized']
        );
        self::assertSame([
            'generated' => true,
            'mismatched' => false,
            'extra' => false,
            'priority' => false,
            'missingMarker' => false,
        ], $this->result['advanced']['listStylePolicy']);
    }

    public function testVisualEnterCreatesPersistentRootBreaksWithoutEmptyParagraphs(): void
    {
        $interaction = $this->result['advanced']['blockEnterInteraction'];

        self::assertSame(
            '<br>' . "\n" . '<br>' . "\n"
                . '<p>Título nuevo</p>' . "\n"
                . '<p>Texto existente</p>',
            $interaction['leadingTyped']['serialized']
        );
        self::assertSame(
            '<br>' . "\n" . '<br>' . "\n" . '<br>' . "\n"
                . '<p>Texto existente</p>',
            $interaction['leadingAbandoned']['serialized']
        );
        self::assertSame(
            '<h3>Encabezado</h3>' . "\n" . '<br>' . "\n"
                . '<p>Siguiente</p>',
            $interaction['typed']['serialized']
        );
        self::assertSame(
            '<h3>Encabezado</h3>' . "\n" . '<br>' . "\n" . '<br>',
            $interaction['abandoned']['serialized']
        );
        self::assertTrue(
            $this->result['advanced']['rootBreakContract']['protectsLeadingHeading']
        );
        self::assertSame(
            '<p class="miClase">nuevo párrafo</p>',
            $this->result['advanced']['rootBreakContract']['advancedDeleted']
        );
        self::assertSame([
            'headingEnter' => true,
            'inserted' => true,
            'textBoundary' => true,
            'breaksBeforeType' => 2,
            'fillerBeforeType' => true,
            'caretAfterBreak' => true,
            'pendingBeforeSecondLine' => true,
            'pending' => false,
            'fillerCount' => 0,
            'paragraph' => '<p>Primera linea<br>Segunda linea</p>',
            'serialized' => '<h3>Encabezado</h3>' . "\n"
                . '<p>Primera linea<br>Segunda linea</p>',
        ], $interaction['ctrlEnter']);
        self::assertMatchesRegularExpression(
            '/function richSetMode\(state, mode\).*?'
                . "if \(state\.mode === 'visual'\) \{\\s+"
                . 'richDiscardEmptyCaretExit\(state\);/s',
            $this->javascript
        );
        self::assertMatchesRegularExpression(
            '/function richApplyModal\(state\).*?'
                . "if \(state\.mode === 'visual'\) \{\\s+"
                . 'richDiscardEmptyCaretExit\(state\);/s',
            $this->javascript
        );
    }

    public function testAdvancedCssStylesOnlyTheEditableVisualCanvas(): void
    {
        self::assertSame([
            'stylesheet' => '[data-blog-rich-css-scope="rich-visual-7"]'
                . '[data-advanced="editable"].blogEditor__richCanvas{'
                . '& .miClase { color: red; }}',
            'unsafeRejected' => true,
            'invalidScopeRejected' => true,
            'projectedClass' => 'miClase',
            'trustedAttributeStripped' => true,
            'changedAttributeRejected' => true,
            'unknownAttributeRejected' => true,
            'emptyParagraphBreaksAccepted' => true,
            'splitProjectedClasses' => ['miClase', 'miClase'],
        ], $this->result['advanced']['advancedVisualStyle']);
        foreach ([
            'blogEditor__advancedShadowPreview',
            'advancedPreviewSlot',
            'Vista previa con CSS',
        ] as $removedShadowContract) {
            self::assertStringNotContainsString(
                $removedShadowContract,
                $this->javascript
            );
        }
        self::assertStringContainsString(
            "state.advancedVisualStyle.setAttribute(\n                'nonce',",
            $this->javascript
        );
        self::assertMatchesRegularExpression(
            '/dialog\.addEventListener\(\'close\'.*?'
                . 'richClearAdvancedVisualStyle\(state\);/s',
            $this->javascript
        );
        self::assertMatchesRegularExpression(
            '/function richRenderAdvancedDraft\(state\).*?'
                . 'richAdvancedPreviewFrame\(/s',
            $this->javascript
        );
        self::assertMatchesRegularExpression(
            '/function richRenderDraft\(state, restoreSelection, options\)'
                . '.*?richRefreshAdvancedVisualStyle\(state\);/s',
            $this->javascript
        );
        self::assertMatchesRegularExpression(
            '/function richCommitAdvancedVisualFlow\(state\)\s*\{'
                . '(?:(?!richRefreshAdvancedVisualStyle).)*?\n    \}/s',
            $this->javascript
        );
        self::assertMatchesRegularExpression(
            '/richCommitAdvancedVisualFlow\(state\);\s*'
                . 'if \(\s*state\.advancedMode\s*'
                . '&& state\.advancedVisualEditable\s*'
                . '&& state\.advancedVisualStructureLocked\s*\) \{\s*'
                . 'richAdvancedVisualProjectAttributes\(state\);/s',
            $this->javascript
        );
    }

    public function testToolbarPaintIsCoalescedWithoutRecapturingSelection(): void
    {
        self::assertStringContainsString(
            'function richSetDomProperty(node, property, value)',
            $this->javascript
        );
        self::assertMatchesRegularExpression(
            '/function richCaptureSelection\(state\).*?'
                . 'state\.toolbarSelectedFlowIndexes = selectedFlowIndexes\.slice\(\);'
                . '.*?richScheduleToolbarRefresh\(state\);/s',
            $this->javascript
        );
        self::assertMatchesRegularExpression(
            '/function richScheduleToolbarRefresh\(state\).*?'
                . 'window\.requestAnimationFrame\(function \(\).*?'
                . 'state\.toolbarSelectedFlowIndexes\.slice\(\)/s',
            $this->javascript
        );
        self::assertMatchesRegularExpression(
            '/function richRefreshToolbar\(state, selectedFlowIndexesSnapshot\)'
                . '.*?usesCapturedFlowIndexes.*?selectedFlowIndexesSnapshot\.slice\(\)'
                . '.*?: richFlowSelectedIndexes\(state\)/s',
            $this->javascript
        );
        self::assertMatchesRegularExpression(
            '/function richCloseModal\(state\).*?'
                . 'richCancelToolbarRefresh\(state\);\s*state\.active = false;/s',
            $this->javascript
        );
        self::assertMatchesRegularExpression(
            '/function richOpenLinkPanel\(state\).*?'
                . 'richSyncVisual\(state\).*?'
                . 'state\.selectionRevision = state\.documentRevision;/s',
            $this->javascript
        );
    }

    public function testHeadingPolicyAndAdvancedEditingStayFailClosedAndNoopSafe(): void
    {
        $expectedPolicy = [
            'allowed_levels' => [2, 3, 4, 5, 6],
            'defaults' => ['section' => 2, 'article' => 3, 'div' => 3],
        ];
        self::assertSame($expectedPolicy, $this->result['policy']);
        self::assertSame($expectedPolicy, $this->result['fallbackPolicy']);

        $advanced = $this->result['advanced'];
        self::assertSame(
            '<p>uno <strong>dos</strong></p><p>tres</p>',
            $advanced['noopSource']
        );
        self::assertSame(
            '<p>uno <strong>dos</strong></p>' . "\n" . '<p>tres</p>',
            $advanced['visualSource']
        );
        self::assertSame(
            ['source' => false, 'css' => false, 'visual' => false],
            $advanced['touchState']
        );
        self::assertTrue($advanced['simpleShape']);
        self::assertFalse($advanced['contentShape']);
        self::assertTrue($advanced['simpleVisualEditable']);
        self::assertFalse($advanced['complexVisualEditable']);
        self::assertFalse($advanced['unsafeCssVisualEditable']);
        self::assertTrue($advanced['safeCss']);
        self::assertFalse($advanced['unsafeCss']);
        self::assertSame([
            'visualEditable' => true,
            'standardConvertible' => false,
            'structureLocked' => true,
            'noopSource' => '<p>uno</p>'
                . '<p class="miClase">nuevo párrafo</p>',
            'formattedSource' => '<p>uno</p>' . "\n"
                . '<p class="miClase">'
                . '<span data-content-format-text-color="color04">'
                . '<strong>nuevo párrafo</strong></span></p>',
            'validSource' => true,
            'roundTripMarks' => ['strong', 'text-color04'],
            'css' => '& .miClase { color: red; }',
        ], $advanced['classedVisual']);
        self::assertSame([
            'split' => '<p id="lead" class="miClase" '
                . 'data-content-theme="warm">ab</p>' . "\n"
                . '<p class="miClase" data-content-theme="warm">cd</p>',
            'lineBreak' => '<p id="lead" class="miClase" '
                . 'data-content-theme="warm">ab<br>cd</p>',
            'callout' => '<aside id="lead" class="miClase" '
                . 'data-content-theme="warm" '
                . 'data-content-callout="true" role="note">abcd</aside>',
            'emptyParagraphs' => 0,
        ], $advanced['attributedVisual']);
        self::assertTrue($advanced['advancedSectionHeading']);
        self::assertSame(
            ['valid' => true, 'invalid' => false],
            $advanced['advancedLeadingHeadingApply']
        );
        self::assertSame([
            'headings' => true,
            'lists' => true,
            'nestedLists' => true,
            'quote' => true,
            'callout' => true,
            'customAfterBase' => true,
            'marksAfterCustom' => true,
        ], $advanced['unifiedPreviewCss']);
        foreach ([
            'data-content-format-size',
            'data-content-format-text-color',
            'data-content-format-background-color',
            'data-content-format-text-rgba',
            'data-content-format-background-rgba',
            'function richAdvancedPreviewPalette(form)',
            'function richAdvancedPreviewMarkCss(palette)',
        ] as $advancedMarkContract) {
            self::assertStringContainsString(
                $advancedMarkContract,
                $this->javascript
            );
        }
        self::assertStringContainsString(
            "'format-size': ['M11 4v3h3v13h4V7h4V4z', "
                . "'M2 10v2h2v8h3v-8h2v-2z']",
            $this->javascript
        );
        self::assertTrue($advanced['emptyCssOnly']);
        self::assertFalse($advanced['nonEmptyAdvanced']);
        foreach (['safeCommit', 'unsafeCommit'] as $commit) {
            self::assertSame(
                '<p>uno <strong>dos</strong></p><p>tres</p>',
                $advanced[$commit]['html']
            );
            self::assertTrue($advanced[$commit]['advanced']);
            self::assertFalse($advanced[$commit]['sourceTouched']);
        }
        self::assertTrue($advanced['safeCommit']['visualEditable']);
        self::assertFalse($advanced['unsafeCommit']['visualEditable']);
        foreach (['convertedViaHtml', 'convertedViaVisual'] as $conversion) {
            self::assertFalse($advanced[$conversion]['afterCss']['advanced']);
            self::assertFalse(
                $advanced[$conversion]['afterCss']['wasAdvanced']
            );
            self::assertSame('', $advanced[$conversion]['afterCss']['css']);
            self::assertSame(
                '<p>Hello</p>',
                $advanced[$conversion]['afterCss']['serialized']
            );
            self::assertSame(
                '<p>Hello</p>',
                $advanced[$conversion]['afterHtml']
            );
            self::assertFalse($advanced[$conversion]['advanced']);
            self::assertFalse($advanced[$conversion]['wasAdvanced']);
            self::assertSame(
                'Hello',
                $advanced[$conversion]['flow'][0]['content'][0]['text']
            );
        }
        self::assertSame(
            $advanced['convertedViaHtml'],
            $advanced['convertedWhitespaceCss']
        );
        self::assertSame([
            'empty' => true,
            'whitespace' => true,
            'valid' => true,
            'invalid' => false,
        ], $advanced['cssValidation']);
        self::assertSame([
            'id', 'type', 'content', 'presentation',
        ], $advanced['clearedAdvancedResult']['keys']);
        self::assertFalse($advanced['clearedAdvancedResult']['advanced']);
        self::assertSame(
            'Hello',
            $advanced['clearedAdvancedResult']['text']
        );
        self::assertSame([
            'width' => 'full',
            'align' => 'start',
            'text_align' => 'start',
            'size' => 'm',
            'font_weight' => 'default',
            'text_color' => 'default',
        ], $advanced['clearedAdvancedResult']['presentation']);
        self::assertStringContainsString(
            'richApplyTextFlowDraft(location, state.flowDraft);',
            $this->javascript
        );
        self::assertSame([
            'dirty' => true,
            'reverted' => false,
            'html' => '<p>A</p><p>B</p>',
        ], $advanced['visualNet']);
        self::assertSame([
            'seeded' => true,
            'touched' => true,
            'html' => '<p>A</p>',
            'css' => 'p { color: red; }',
        ], $advanced['cssOnlyVisual']);
        self::assertSame([
            [
                'name' => 'list',
                'seeded' => false,
                'raw' => '<ul><li></li></ul>',
                'serialized' => '<ul>' . "\n"
                    . '    <li></li>' . "\n" . '</ul>',
                'flow' => [[
                    'type' => 'list',
                    'ordered' => false,
                    'items' => [['content' => []]],
                ]],
            ],
            [
                'name' => 'paragraphs',
                'seeded' => false,
                'raw' => '<p></p><p></p>',
                'serialized' => '<br>' . "\n" . '<br>',
                'flow' => [
                    ['type' => 'break'],
                    ['type' => 'break'],
                ],
            ],
        ], $advanced['existingEmptyStructures']);

        foreach ([
            "state.visual.contentEditable = 'false'",
            "frame.setAttribute('sandbox', '')",
            'richCommitAdvancedVisualFlow(state)',
            'richResetAdvancedTouchState(state)',
            'function richAdvancedCssVisualSafe(',
            'function richSourceSupportsAdvancedVisualFlow(',
            'function richParseAdvancedVisualFlowHtml(',
            'function richSerializeAdvancedVisualFlowHtml(',
            'advancedVisualStructureLocked',
            'function richTextBlockEmpty(',
            'function richSeedAdvancedVisualText(',
            'function richPlaceEmptyFlowCaret(',
            'function richReconcileAdvancedVisualTouch(',
            'function richAdvancedVisualCanSplitSelection(',
            'state.wasAdvanced = false',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }

        foreach ([
            'blogEditor__advancedNotice',
            'advancedNoticeText',
            'Edici\\u00f3n visual segura:',
        ] as $removedNoticeContract) {
            self::assertStringNotContainsString(
                $removedNoticeContract,
                $this->javascript
            );
        }
        foreach ([
            'var sourceTab = richToolbarButton(',
            'var cssTab = richToolbarButton(',
            "'mode-source'",
            "'mode-css'",
            "sourcePanel.setAttribute('role', 'tabpanel')",
            "cssPanel.setAttribute('role', 'tabpanel')",
        ] as $remainingModeContract) {
            self::assertStringContainsString(
                $remainingModeContract,
                $this->javascript
            );
        }
    }

    public function testPaletteIsSwatchOnlyAccessibleAndResponsive(): void
    {
        foreach (['color', 'background'] as $group) {
            $options = $this->result['palette'][$group];
            self::assertCount(15, $options);
            self::assertSame('', $options[0]['value']);
            self::assertCount(6, array_slice($options, 1, 6));
            self::assertCount(8, array_slice($options, 7));
        }
        self::assertSame([
            'text-color00',
            'text-color01',
            'text-color02',
            'text-color03',
            'text-color04',
            'text-color05',
        ], array_column(
            array_slice($this->result['palette']['color'], 1, 6),
            'value'
        ));
        self::assertSame([
            'insideClosed' => false,
            'insideOpen' => true,
            'outsideClosed' => true,
            'menuHidden' => true,
            'expanded' => 'false',
            'customHidden' => true,
        ], $this->result['paletteClose']);
        self::assertSame([
            'insideClosed' => false,
            'insideOpen' => true,
            'headerClosed' => true,
            'headerTriggerFocus' => 1,
            'helpClosed' => true,
            'helpTriggerFocus' => 1,
            'outsideClosed' => true,
            'outsideTriggerFocus' => 0,
            'menuHidden' => true,
            'expanded' => 'false',
        ], $this->result['palettePointer']);
        self::assertSame([
            'hidden' => true,
            'expanded' => 'false',
            'triggerFocusCalls' => 1,
        ], $this->result['paletteTriggerClose']);

        foreach ([
            "trigger.setAttribute('aria-haspopup', 'dialog')",
            "menu.setAttribute('role', 'dialog')",
            'optionIndex === 1 || optionIndex === 7',
            "option.setAttribute('aria-pressed', 'false')",
            'definition.palette.menu.append(custom, definition.control.root)',
            "menu && event.key === 'Escape'",
            'richToolbarIconButton(',
            'richSvgIcon(',
            "'format-size'",
            'blogEditor__richAlignmentLabel webadmin-srOnly',
            'richClosePalettesOnFocusLeave(',
            "visual.addEventListener('pointerdown'",
            "visual.addEventListener('focus'",
            "dialog.addEventListener('pointerdown'",
            'richClosePalettesOnPointerDown(',
            'function richPointerTargetCanReceiveFocus(',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }
        foreach ([
            'width: min(21rem, calc(100vw - 2rem))',
            'max-height: 22rem',
            'grid-template-columns: repeat(auto-fit, minmax(2.75rem, 1fr))',
            'overflow-x: hidden',
            'overflow-y: auto',
            "[data-blog-rich-palette-kind='reset']",
            'grid-column: 1 / -1',
            'inline-size: 2.75rem',
            'min-block-size: 2.75rem',
            'border-radius: 0.25rem',
            '.webadmin .blogEditor__richSelect--icon select',
            '.webadmin .blogEditor__richToolbar[hidden]',
            '.blogEditor__richSelect--icon:has(select:disabled)',
            '.blogEditor__richSelect--icon' . "\n"
                . '    select:disabled',
            'opacity: 0',
            ".blogEditor__richRgbaControl input[type='color']",
            ".blogEditor__richRgbaControl input[type='number']",
            '.blogEditor__richRgbaControl button',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->stylesheet);
        }
        foreach ([
            'grid-template-columns: repeat(6, 2.75rem)',
            'grid-template-columns: repeat(4, 2.75rem)',
        ] as $rigidPaletteContract) {
            self::assertStringNotContainsString(
                $rigidPaletteContract,
                $this->stylesheet
            );
        }
        foreach ([
            '/\.blogEditor__richRgbaControl input\[type=\'color\'\]\s*'
                . '\{[^}]*block-size:\s*2\.75rem/s',
            '/\.blogEditor__richRgbaControl input\[type=\'number\'\]\s*'
                . '\{[^}]*min-height:\s*2\.75rem/s',
            '/\.blogEditor__richRgbaControl button\s*'
                . '\{[^}]*min-block-size:\s*2\.75rem/s',
        ] as $targetSizeContract) {
            self::assertMatchesRegularExpression(
                $targetSizeContract,
                $this->stylesheet
            );
        }
    }

    public function testListStylesUseStableNativeToolbarControls(): void
    {
        foreach ([
            "'Tipo de lista con viñetas'",
            "'Tipo de lista numerada'",
            'delete listStyle.select.dataset.blogRichRequiresSelection',
            "'[data-blog-rich-list-style]'",
            "['quote', 'callout'].includes(",
            'richSyncFlowMetadataElement(state, index)',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }
        foreach ([
            'listMetadataGroup',
            'Lista numerada seleccionada',
            'Lista con viñetas seleccionada',
        ] as $removedPanelContract) {
            self::assertStringNotContainsString(
                $removedPanelContract,
                $this->javascript
            );
        }
        foreach ([
            ".blogEditor__richListStyle[data-active='true']",
            '.blogEditor__richListStyle::after',
            'pointer-events: none;',
        ] as $stylesheetContract) {
            self::assertStringContainsString(
                $stylesheetContract,
                $this->stylesheet
            );
        }
    }

    public function testSourceAndSectionLayoutCannotCreateHorizontalOverflow(): void
    {
        self::assertSame([
            ['number' => 1, 'height' => 72],
            ['number' => 2, 'height' => 24],
        ], $this->result['gutterModel']);
        self::assertSame([
            'width' => '208px',
            'heights' => [72, 24],
            'rows' => [
                'una línea que envuelve varias veces',
                'corta',
            ],
        ], $this->result['gutterMeasure']);
        self::assertSame([
            'count' => 1000,
            'last' => ['number' => 1000, 'height' => 24],
        ], $this->result['gutterThousand']);
        foreach ([
            "source.wrap = 'soft'",
            "cssSource.wrap = 'soft'",
            'control.setRangeText(',
            'state.sourceTouched = richCodeTouched(',
            'function richCodeHasCommandModifier(',
            'function richCodeHistoryStep(',
            'function richSourceMeasureWrappedLines(',
            'function richSyncMeasuredSourceGutter(',
            'function richScheduleSourceGutter(',
            'new window.ResizeObserver(',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }
        foreach ([
            'white-space: pre-wrap',
            'overflow-x: hidden',
            'overflow-wrap: anywhere',
            'scrollbar-gutter: stable',
            '.blogEditor__richSourceMeasure',
            '.blogEditor__richSourceMeasureLine',
            '.blogEditor__richSourceGutterLine',
            'minmax(calc(4ch + 1.75rem), max-content)',
            'grid-template-rows: auto auto minmax(0, 1fr) auto auto auto',
            'overscroll-behavior: contain',
            'scroll-padding-block: 1rem',
            '@media (max-width: 47.99rem), (pointer: coarse)',
            '--blog-builder-section-inset: clamp(',
            'padding-inline: var(--blog-builder-section-inset)',
            '.webadmin .blogEditor__richLinkPanel {',
            'grid-template-columns: minmax(0, 1fr)',
            'minmax(min(100%, 8rem), 1fr)',
            'overflow-wrap: anywhere',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->stylesheet);
        }
        self::assertStringNotContainsString(
            'calc(-1 * var(--blog-builder-section-inset))',
            $this->stylesheet
        );
        foreach ([
            '--blog-rich-source-bg: #1f1f1f',
            '--blog-rich-source-panel: #181818',
            '--blog-rich-source-border: #3c3c3c',
            '--blog-rich-source-text: #d4d4d4',
            '--blog-rich-source-muted: #858585',
            '--blog-rich-source-accent: #4daafc',
        ] as $darkModernToken) {
            self::assertStringContainsString(
                $darkModernToken,
                $this->stylesheet
            );
        }
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }
}
