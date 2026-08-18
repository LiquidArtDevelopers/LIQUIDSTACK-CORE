<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BlogStructuredEditorAssetsContractTest extends TestCase
{
    private string $javascript;
    private string $stylesheet;
    private string $publicStylesheet;
    private string $resourceStylesheet;

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
        $this->resourceStylesheet = $this->read(
            $root . '/modules/blog/resources/project/src/scss/resources/'
                . '_artBlogArticle01.scss'
        );
    }

    public function testJavascriptOwnsTheClosedV1AndV2DocumentContracts(): void
    {
        foreach ([
            "var SCHEMA = 'liquidstack.blog.document'",
            'var VERSION = 2',
            'var LEGACY_VERSION = 1',
            "var TEMPLATE_BASIC = 'article-basic-01'",
            "var TEMPLATE_COVER = 'article-cover-01'",
            "'paragraph'",
            "'heading'",
            "'list'",
            "'callout'",
            "'link'",
            "'image'",
            "'video'",
            "'cta'",
            'function exactKeys(',
            'function plainObject(',
            'function validInline(',
            'function validDocument(',
            'function validLegacyDocument(',
            'function validV2Document(',
            'function safeUrl(',
            'JSON.parse(documentInput.value)',
            'JSON.stringify(context.documentValue)',
            'input[name="document_json"]',
            'bytes(JSON.stringify(documentValue)) <= MAX_JSON_BYTES',
            "form.dataset.blogLayoutEditorReady === 'true'",
            'layoutEditorReady && documentValue.version === LEGACY_VERSION',
            'context.layoutEditorReady',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }
    }

    public function testJavascriptSupportsProgressiveBlockAndNestedEditing(): void
    {
        foreach ([
            '[data-blog-editor]',
            '[data-blog-add-block]',
            'data-blog-action',
            'data-blog-inline-action',
            'data-blog-add-inline',
            'blogEditor__listTextarea',
            'function listItemsAsLines(',
            'function makeBlock(',
            'function renderBlockFields(',
            'function renderInlineEditor(',
            'function renderListEditor(',
            'function renderImageFields(',
            'function renderLinkFields(',
            'function normalizeContracts(',
            'function headingLevelAllowed(',
            'function semanticRange(',
            'function moveSemanticGroup(',
            'function refreshVisualCanvas(',
            'function renderInspector(',
            "[2, 3, 4, 5, 6].map(function (level)",
            "fieldset.setAttribute('aria-labelledby', heading.id)",
            "announce(context, 'Contenido eliminado.', false)",
            "provider: 'youtube'",
            "video_id: 'vKQi3bBA1y8'",
            'context.documentValue.blocks.splice(',
            'moveSemanticGroup(context, blockIndex,',
            'form.addEventListener(\'submit\'',
            'event.preventDefault()',
            "window.fetch(form.action, {",
            "document.addEventListener('invalid'",
            'formFingerprint(form)',
            'function isExpectedEditorRedirect(',
            'function isExpectedCategoryRedirect(',
            'function initCategoryAssignment(',
            'function initCategoryWorkspace(',
            'function loadCategoryCatalog(',
            'function categoryRequest(',
            "'X-LiquidStack-Category-Manager': 'async'",
            "redirect: 'error'",
            'function renderCategoryChoices(',
            'function renderCategoryManager(',
            'function readHeaderCatalog(',
            'function readHeaderSelection(',
            'function renderHeaderControls(',
            'function v2PersistHeaderSelection(',
            'dataset.blogHeaderChoice',
            '[data-blog-category-assignment-form]',
            "'webadmin:open-inspector'",
            "inspector.removeAttribute('inert')",
            '[2, 3, 4, 5, 6].includes(requestedHeadingLevel)',
            'window.crypto.randomUUID',
            'window.crypto.getRandomValues',
            "form.dataset.blogEditorReadonly === 'true'",
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }
    }

    public function testJavascriptExecutesNestedSemanticGroupingAndMoves(): void
    {
        $node = new Process(['node', '--version']);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no estÃ¡ disponible.');
        }

        $root = dirname(__DIR__, 3);
        $process = new Process([
            'node',
            $root . '/tests/Blog/StructuredContent/fixtures/'
                . 'blog-editor-semantic-harness.mjs',
            $root . '/modules/blog/published/assets/blog-editor.js',
        ]);
        $process->mustRun();
        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertTrue($result['valid']);
        self::assertFalse($result['invalidJump']);
        self::assertSame(['start' => 2, 'end' => 7], $result['h4Range']);
        self::assertSame(['start' => 1, 'end' => 9], $result['h3Range']);
        self::assertSame(['start' => 0, 'end' => 11], $result['h2Range']);
        self::assertSame(['start' => 2, 'end' => 7], $result['h4Previous']);
        self::assertTrue($result['moved']);
        self::assertTrue($result['movedValid']);
        self::assertTrue($result['expectedRedirect']);
        self::assertFalse($result['loginRedirect']);
        self::assertTrue($result['expectedCategoryRedirect']);
        self::assertFalse($result['categoryLoginRedirect']);
        self::assertSame([
            '00000000-0000-4000-8000-000000000001',
            '00000000-0000-4000-8000-000000000002',
            '00000000-0000-4000-8000-000000000008',
            '00000000-0000-4000-8000-000000000009',
            '00000000-0000-4000-8000-000000000003',
            '00000000-0000-4000-8000-000000000004',
            '00000000-0000-4000-8000-000000000005',
            '00000000-0000-4000-8000-000000000006',
            '00000000-0000-4000-8000-000000000007',
            '00000000-0000-4000-8000-000000000010',
            '00000000-0000-4000-8000-000000000011',
            '00000000-0000-4000-8000-000000000012',
        ], $result['movedIds']);
    }

    public function testJavascriptRestoresFocusAndReannouncesRepeatedStatus(): void
    {
        foreach ([
            'function focusAfterRender(',
            'editButton.dataset.blogBlockTitle = block.id',
            'item.dataset.blogInlineOwner = ownerId',
            'item.dataset.blogInlineIndex = String(index)',
            'function listItemsFromLines(',
            "listTextarea.className = 'blogEditor__listTextarea'",
            'context.status.textContent = \'\'',
            'context.announcementVersion += 1',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }
        self::assertGreaterThanOrEqual(
            9,
            substr_count($this->javascript, 'focusAfterRender(context,')
        );

        $node = new Process(['node', '--version']);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no está disponible.');
        }

        $asset = dirname(__DIR__, 3)
            . '/modules/blog/published/assets/blog-editor.js';
        $script = <<<'JS'
import fs from 'node:fs';
import vm from 'node:vm';

let source = fs.readFileSync(process.argv[1], 'utf8');
const marker = '}());';
const markerIndex = source.lastIndexOf(marker);
if (markerIndex < 0) throw new Error('Unable to expose editor test hooks.');
source = source.slice(0, markerIndex)
    + 'globalThis.__blogEditorHooks = { announce, focusAfterRender };\n'
    + source.slice(markerIndex);

const scheduled = [];
globalThis.document = {
  readyState: 'loading',
  addEventListener() {},
};
globalThis.window = {
  requestAnimationFrame(callback) {
    scheduled.push(callback);
    return scheduled.length;
  },
};
vm.runInThisContext(source, { filename: process.argv[1] });

const focused = [];
const node = (label, dataset) => ({
  dataset,
  focus() { focused.push(label); },
});
const blockNodes = [
  node('block-a', { blogBlockTitle: 'block-a' }),
  node('block-b', { blogBlockTitle: 'block-b' }),
];
const inlineNodes = [
  node('owner-a:0', {
    blogInlineOwner: 'owner-a',
    blogInlineIndex: '0',
  }),
  node('owner-a:1', {
    blogInlineOwner: 'owner-a',
    blogInlineIndex: '1',
  }),
];
const listNodes = [
  node('list-a', { blogListItemId: 'list-a' }),
];
const fallback = node('fallback', {});
const context = {
  blockList: {
    querySelectorAll(selector) {
      if (selector === '[data-blog-block-title]') return blockNodes;
      if (selector.includes('data-blog-inline-owner')) return inlineNodes;
      if (selector === '[data-blog-list-item-id]') return listNodes;
      return [];
    },
  },
  form: { querySelector() { return fallback; } },
  templateSelect: node('template', {}),
};

const hooks = globalThis.__blogEditorHooks;
hooks.focusAfterRender(context, { kind: 'block', id: 'block-b' });
hooks.focusAfterRender(context, {
  kind: 'inline',
  owner: 'owner-a',
  index: 1,
});
hooks.focusAfterRender(context, { kind: 'list-item', id: 'list-a' });
hooks.focusAfterRender(context, { kind: 'block', id: 'missing' });

const writes = [];
const status = {
  dataset: {},
  _text: 'Bloque eliminado.',
  get textContent() { return this._text; },
  set textContent(value) {
    this._text = value;
    writes.push(value);
  },
};
const announcement = { status, announcementVersion: 0 };
hooks.announce(announcement, 'Bloque eliminado.', false);
scheduled.shift()();
hooks.announce(announcement, 'Bloque eliminado.', false);
scheduled.shift()();

process.stdout.write(JSON.stringify({
  focused,
  writes,
  state: status.dataset.state,
}));
JS;

        $process = new Process([
            'node',
            '--input-type=module',
            '--eval',
            $script,
            $asset,
        ]);
        $process->mustRun();
        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame([
            'block-b',
            'owner-a:1',
            'list-a',
            'fallback',
        ], $result['focused']);
        self::assertSame([
            '',
            'Bloque eliminado.',
            '',
            'Bloque eliminado.',
        ], $result['writes']);
        self::assertSame('ok', $result['state']);
    }

    public function testJavascriptKeepsHtmlSafeAndUsesOnlyTheSeoEndpoint(): void
    {
        foreach ([
            'localStorage',
            'sessionStorage',
            'document.cookie',
            '.outerHTML',
            'insertAdjacentHTML',
            'document.write(',
            'document.writeln(',
            'createContextualFragment(',
            'setHTMLUnsafe(',
            'eval(',
            'new Function',
            'XMLHttpRequest',
            'require(',
            'import(',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $this->javascript);
        }
        self::assertStringContainsString(
            'document.createElement(',
            $this->javascript
        );
        self::assertStringContainsString('.textContent =', $this->javascript);
        self::assertStringContainsString('.replaceChildren()', $this->javascript);
        self::assertStringContainsString(
            'function escapeHtml(value)',
            $this->javascript
        );
        self::assertStringContainsString(
            '+ escapeHtml(raw) + \'</span>\';',
            $this->javascript
        );
        self::assertSame(
            1,
            preg_match_all('/\\.innerHTML\\s*=/', $this->javascript)
        );
        self::assertMatchesRegularExpression(
            "/highlight\\.innerHTML\\s*=\\s*\\(language === 'css'\\s*"
                . "\\? richHighlightCssSource\\(textarea\\.value\\)\\s*"
                . ": richHighlightHtmlSource\\(textarea\\.value\\)\\)"
                . " \\+ '\\\\n';/",
            $this->javascript
        );
        self::assertSame(1, substr_count($this->javascript, 'fetch(endpoint,'));
        self::assertStringContainsString(
            "credentials: 'same-origin'",
            $this->javascript
        );
        self::assertStringContainsString(
            "'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'",
            $this->javascript
        );
        self::assertStringContainsString('new AbortController()', $this->javascript);
        self::assertStringContainsString('window.setTimeout(run, 650)', $this->javascript);
        self::assertStringContainsString(
            'El H1 pertenece al art&iacute;culo',
            (new \App\Core\Blog\StructuredContent\Rendering\BlogStructuredEditorHtmlRenderer())
                ->render(...$this->minimalRendererArguments())
        );
    }

    public function testJavascriptTreatsMarkupShapedCopyAsPlainText(): void
    {
        $node = new Process(['node', '--version']);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no está disponible.');
        }

        $asset = dirname(__DIR__, 3)
            . '/modules/blog/published/assets/blog-editor.js';
        $script = <<<'JS'
import fs from 'node:fs';
import vm from 'node:vm';

let source = fs.readFileSync(process.argv[1], 'utf8');
const marker = '}());';
const markerIndex = source.lastIndexOf(marker);
if (markerIndex < 0) throw new Error('Unable to expose editor test hooks.');
source = source.slice(0, markerIndex)
    + 'globalThis.__blogPlainText = safePlainText;\n'
    + source.slice(markerIndex);
globalThis.document = { readyState: 'loading', addEventListener() {} };
globalThis.window = {};
vm.runInThisContext(source, { filename: process.argv[1] });

process.stdout.write(JSON.stringify({
  comparison: globalThis.__blogPlainText('Tiempo de respuesta <24 h'),
  markup: globalThis.__blogPlainText('<strong>Texto literal</strong>'),
  sql: globalThis.__blogPlainText('SELECT * FROM posts; DROP TABLE posts; --'),
  control: globalThis.__blogPlainText('rechazado' + String.fromCharCode(7)),
}));
JS;
        $process = new Process([
            'node',
            '--input-type=module',
            '--eval',
            $script,
            $asset,
        ]);
        $process->mustRun();

        self::assertSame(
            [
                'comparison' => true,
                'markup' => true,
                'sql' => true,
                'control' => false,
            ],
            json_decode(
                $process->getOutput(),
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        );
    }

    public function testV2RichTextUsesASelectionBasedSafeModal(): void
    {
        foreach ([
            "document.createElement('dialog')",
            "visual.contentEditable = context.readOnly ? 'false' : 'true'",
            'new DOMParser().parseFromString(source, \'text/html\')',
            'function richTransformRange(',
            'function richParseHtml(',
            'function richSerializeHtml(',
            'function richModalDirty(',
            'state.baselineHtml = richSerializeEditorHtml(state)',
            "'underline'",
            "'size-large'",
            "'text-color02'",
            "'background-color03'",
            "state.linkPanel.hidden = true",
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }

        foreach (['execCommand', 'designMode'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $this->javascript);
        }
    }

    public function testV2InspectorKeepsPaletteTypographyAndListOptionsClosed(): void
    {
        $node = new Process(['node', '--version']);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no est\u00e1 disponible.');
        }

        $asset = dirname(__DIR__, 3)
            . '/modules/blog/published/assets/blog-editor.js';
        $script = <<<'JS'
import fs from 'node:fs';
import vm from 'node:vm';

let source = fs.readFileSync(process.argv[1], 'utf8');
const marker = '}());';
const markerIndex = source.lastIndexOf(marker);
if (markerIndex < 0) throw new Error('Unable to expose editor test hooks.');
source = source.slice(0, markerIndex)
    + 'globalThis.__blogRichHooks = {'
    + ' RICH_PALETTE_OPTIONS, PRESENTATION_TEXT_ALIGNS,'
    + ' validPresentation, v2InsertOptions, validEmbedHtml,'
    + ' richEmbedAttributeAllowed, richAdvancedIframeUrl };\n'
    + source.slice(markerIndex);

globalThis.document = {
  readyState: 'loading',
  addEventListener() {},
};
globalThis.window = {};
vm.runInThisContext(source, { filename: process.argv[1] });

const hooks = globalThis.__blogRichHooks;
const listOptions = hooks.v2InsertOptions({
  ownerType: 'section',
  divDepth: 0,
}).filter((option) => option.type === 'list');
const linkOptions = hooks.v2InsertOptions({
  ownerType: 'section',
  divDepth: 0,
}).filter((option) => option.type === 'link');
const embedHtmlPolicy = {
  global_attributes: ['class', 'id', 'title', 'aria-label'],
  special_attributes: {
    a: ['href', 'target', 'rel'],
    img: ['src', 'alt', 'width', 'height', 'loading', 'decoding'],
    iframe: [
      'src', 'title', 'width', 'height', 'loading', 'referrerpolicy',
      'allow', 'allowfullscreen', 'sandbox',
    ],
  },
  iframe_sources: [
    { host: 'www.youtube-nocookie.com', path_prefix: '/embed/' },
  ],
  max_html_bytes: 50000,
};
const embedPolicy = { html: embedHtmlPolicy, css: {} };
const fakeNode = (tag, attributes = {}) => ({
  nodeName: tag.toUpperCase(),
  getAttribute(name) { return attributes[name] ?? null; },
});
const embedAttribute = (tag, name, value, attributes = {}) =>
  hooks.richEmbedAttributeAllowed(
    fakeNode(tag, { ...attributes, [name]: value }),
    { name, value },
    { ids: new Set() },
    embedPolicy
  );

process.stdout.write(JSON.stringify({
  textColors: hooks.RICH_PALETTE_OPTIONS.color.map((option) => option.value),
  backgrounds: hooks.RICH_PALETTE_OPTIONS.background.map(
    (option) => option.value
  ),
  textAlignments: hooks.PRESENTATION_TEXT_ALIGNS,
  oldPresentationValid: hooks.validPresentation({
    width: 'full',
    align: 'start',
  }),
  justifiedPresentationValid: hooks.validPresentation({
    width: '60',
    align: 'center',
    text_align: 'justify',
  }),
  arbitraryAlignmentValid: hooks.validPresentation({
    width: '60',
    align: 'center',
    text_align: 'left',
  }),
  typographyValid: hooks.validPresentation({
    width: '80',
    align: 'center',
    text_align: 'justify',
    font_size: 'large',
    font_weight: 'semibold',
    text_color: 'color02',
  }, 'paragraph'),
  typographyRejectedOnImage: hooks.validPresentation({
    width: '80',
    align: 'center',
    text_align: 'justify',
    font_size: 'large',
    font_weight: 'semibold',
    text_color: 'color02',
  }, 'image'),
  noDomEmbedFailsClosed: hooks.validEmbedHtml(
    '<iframe src="https://www.youtube-nocookie.com/embed/abcdefghijk"></iframe>',
    embedPolicy
  ),
  unsafeEmbed: hooks.validEmbedHtml('<script>alert(1)</script>'),
  iframeSourceValid: hooks.richAdvancedIframeUrl(
    'https://www.youtube-nocookie.com/embed/abcdefghijk',
    embedHtmlPolicy.iframe_sources
  ),
  iframeSourceWithoutId: hooks.richAdvancedIframeUrl(
    'https://www.youtube-nocookie.com/embed/',
    embedHtmlPolicy.iframe_sources
  ),
  iframeSourceWithEncodedPath: hooks.richAdvancedIframeUrl(
    'https://www.youtube-nocookie.com/embed/%2e%2e',
    embedHtmlPolicy.iframe_sources
  ),
  iframeSourceWithPort: hooks.richAdvancedIframeUrl(
    'https://www.youtube-nocookie.com:443/embed/abcdefghijk',
    embedHtmlPolicy.iframe_sources
  ),
  onclickAllowed: embedAttribute('div', 'onclick', 'alert(1)'),
  styleAllowed: embedAttribute('div', 'style', 'color:red'),
  unknownAttributeAllowed: embedAttribute('div', 'data-unknown', 'x'),
  javascriptHrefAllowed: embedAttribute(
    'a',
    'href',
    'javascript:alert(1)'
  ),
  listOptions: listOptions.map((option) => ({
    type: option.type,
    group: option.group,
  })),
  linkOptions: linkOptions.map((option) => option.type),
}));
JS;

        $process = new Process([
            'node',
            '--input-type=module',
            '--eval',
            $script,
            $asset,
        ]);
        $process->mustRun();
        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $basicColors = [
            'red',
            'orange',
            'yellow',
            'green',
            'blue',
            'purple',
            'pink',
            'gray',
        ];
        self::assertSame([
            '',
            'text-color00',
            'text-color01',
            'text-color02',
            'text-color03',
            'text-color04',
            'text-color05',
            ...array_map(
                static fn (string $color): string => 'text-basic-' . $color,
                $basicColors
            ),
        ], $result['textColors']);
        self::assertSame([
            '',
            'background-color00',
            'background-color01',
            'background-color02',
            'background-color03',
            'background-color04',
            'background-color05',
            ...array_map(
                static fn (string $color): string =>
                    'background-basic-' . $color,
                $basicColors
            ),
        ], $result['backgrounds']);
        self::assertSame(
            ['start', 'center', 'end', 'justify'],
            $result['textAlignments']
        );
        self::assertTrue($result['oldPresentationValid']);
        self::assertTrue($result['justifiedPresentationValid']);
        self::assertFalse($result['arbitraryAlignmentValid']);
        self::assertTrue($result['typographyValid']);
        self::assertFalse($result['typographyRejectedOnImage']);
        self::assertFalse($result['noDomEmbedFailsClosed']);
        self::assertFalse($result['unsafeEmbed']);
        self::assertTrue($result['iframeSourceValid']);
        self::assertFalse($result['iframeSourceWithoutId']);
        self::assertFalse($result['iframeSourceWithEncodedPath']);
        self::assertFalse($result['iframeSourceWithPort']);
        self::assertFalse($result['onclickAllowed']);
        self::assertFalse($result['styleAllowed']);
        self::assertFalse($result['unknownAttributeAllowed']);
        self::assertFalse($result['javascriptHrefAllowed']);
        self::assertSame([], $result['listOptions']);
        self::assertSame([], $result['linkOptions']);
    }

    public function testButtonAlignmentAndHtmlSourceOnlyModalStayExplicit(): void
    {
        foreach ([
            "'Alineaci\\u00f3n del bot\\u00f3n'",
            "{ value: 'start', label: 'Izquierda' }",
            "{ value: 'center', label: 'Centro' }",
            "{ value: 'end', label: 'Derecha' }",
            "item.dataset.buttonAlign = block.presentation.text_align || 'start'",
            "var sourceOnly = block.type === 'embed'",
            "state.visualTab.hidden = sourceOnly",
            "state.mode = sourceOnly ? 'source' : 'visual'",
            "RICH_MODAL_BLOCK_TYPES.includes(location.node.type)",
            "child.type = 'cta'",
            "child.variant = 'primary'",
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }

        self::assertStringContainsString(
            ".blogEditor__builderModule--cta[data-button-align='center']",
            $this->stylesheet
        );
        self::assertStringContainsString(
            'justify-content: center;',
            $this->stylesheet
        );
    }

    public function testRichPaletteAndTextAlignmentHaveVisualParity(): void
    {
        foreach (
            ['red', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'gray']
            as $color
        ) {
            $textToken = 'text-basic-' . $color;
            $backgroundToken = 'background-basic-' . $color;

            self::assertStringContainsString(
                "[data-blog-rich-swatch='" . $textToken . "']",
                $this->stylesheet
            );
            self::assertStringContainsString(
                "[data-blog-rich-swatch='" . $backgroundToken . "']",
                $this->stylesheet
            );
            self::assertStringContainsString(
                '.blogDocument__inline--' . $textToken,
                $this->publicStylesheet
            );
            self::assertStringContainsString(
                '.blogDocument__inline--' . $backgroundToken,
                $this->publicStylesheet
            );
            self::assertStringContainsString(
                '&--' . $textToken,
                $this->resourceStylesheet
            );
            self::assertStringContainsString(
                '&--' . $backgroundToken,
                $this->resourceStylesheet
            );
        }

        foreach (['start', 'center', 'end', 'justify'] as $alignment) {
            self::assertStringContainsString(
                "[data-text-align='" . $alignment . "']",
                $this->stylesheet
            );
            self::assertStringContainsString(
                '.blogDocument__module--text-align-' . $alignment,
                $this->publicStylesheet
            );
            self::assertStringContainsString(
                '&--text-align-' . $alignment,
                $this->resourceStylesheet
            );
        }

        foreach ([
            '.webadmin .blogEditor__richPaletteTrigger',
            '.webadmin .blogEditor__richPaletteMenu',
            '.webadmin .blogEditor__richPaletteOption',
            '.webadmin .blogEditor__richSwatch',
            '.webadmin .blogEditor__richListPanel',
            '.webadmin .blogEditor__listTextarea',
        ] as $visualContract) {
            self::assertStringContainsString(
                $visualContract,
                $this->stylesheet
            );
        }
    }

    public function testRichPaletteKeepsItsTouchTargetsAndTooltipsVisible(): void
    {
        self::assertMatchesRegularExpression(
            '/\.webadmin \.blogEditor__richPaletteMenu \{'
                . '(?:(?!\n\}).)*'
                . 'width: min\(21rem, calc\(100vw - 2rem\)\);'
                . '(?:(?!\n\}).)*max-height: 22rem;'
                . '(?:(?!\n\}).)*grid-template-columns: '
                . 'repeat\(auto-fit, minmax\(2\.75rem, 1fr\)\);'
                . '(?:(?!\n\}).)*overflow-x: hidden;'
                . '(?:(?!\n\}).)*overflow-y: auto;'
                . '(?:(?!\n\}).)*\n\}/s',
            $this->stylesheet
        );
        self::assertStringContainsString(
            '@media (max-width: 40rem)',
            $this->stylesheet
        );
        self::assertStringContainsString(
            'min-inline-size: 2.75rem;',
            $this->stylesheet
        );
        self::assertStringContainsString(
            'min-block-size: 2.75rem;',
            $this->stylesheet
        );
    }

    public function testEditorControlsKeepAtLeastFortyFourPixelTargets(): void
    {
        foreach ([
            '.webadmin .blogEditor__richFooter button' => [
                'min-height: 2.75rem;',
            ],
            '.webadmin .blogEditor__richClose' => [
                'width: 2.75rem;',
                'min-width: 2.75rem;',
            ],
            '.webadmin .blogEditor__richField select' => [
                'min-height: 2.75rem;',
            ],
            '.webadmin .blogEditor__richSourceSuggestion' => [
                'min-height: 2.75rem;',
            ],
            '.webadmin .blogEditor__builderColumn '
                . '.blogEditor__builderActions button' => [
                    'min-height: 2.75rem;',
                ],
            '.webadmin .blogEditor__builderActions '
                . '.blogEditor__builderIconButton' => [
                    'inline-size: 2.75rem;',
                    'block-size: 2.75rem;',
                    'min-inline-size: 2.75rem;',
                    'min-block-size: 2.75rem;',
                ],
            '.webadmin .blogEditor__insertMenu button' => [
                'min-height: 2.75rem;',
            ],
            '.webadmin .blogEditor__insertToggle' => [
                'inline-size: 2.75rem;',
                'block-size: 2.75rem;',
                'min-inline-size: 2.75rem;',
                'min-block-size: 2.75rem;',
            ],
            '.webadmin .blogEditor__insertOptions button' => [
                'min-height: 2.75rem;',
            ],
            '.webadmin .blogEditor__customColorPanel input' => [
                'min-height: 2.75rem;',
            ],
            '.webadmin .blogEditor__builderColumn '
                . '.blogEditor__builderActions' => [
                    'position: static;',
                    'inset: auto;',
                    'align-self: flex-end;',
                    'justify-self: end;',
                    'flex-wrap: wrap;',
                ],
        ] as $selector => $declarations) {
            $this->assertCssRuleContains($selector, $declarations);
        }
    }

    public function testStylesAreScopedResponsiveAndDependencyFree(): void
    {
        foreach ([
            '.webadmin .blogAdminPage',
            '.webadmin .blogAdminPage table',
            '.webadmin .blogEditor',
            '.webadmin .blogEditor__metadataGrid',
            '.webadmin .blogEditor__blockToolbar',
            '.webadmin .blogEditor__canvas',
            '.webadmin .blogEditor__postPreview',
            '.webadmin .blogEditor__previewSection',
            '.webadmin .blogEditor__previewArticle',
            '.webadmin .blogEditor__inspectorTabs',
            '.webadmin .blogEditor [data-blog-category-assignment-form]',
            '.webadmin .blogEditor__categoryQuick',
            '.webadmin .blogEditor__categoryDialog',
            '.webadmin .blogEditor__categoryManagerRow',
            '.webadmin .blogEditor [data-blog-header-controls]',
            '.webadmin .blogEditor__headerChoice',
            ".blogEditor__headerChoice[aria-pressed='true']",
            '.webadmin .blogEditor__headerMediaPreview',
            '.webadmin .blogEditor__inlineList',
            '.webadmin .blogEditor__listItems',
            '.webadmin .blogEditor__revision',
            '.webadmin .blogEditor__richDialog',
            '.webadmin .blogEditor__richCanvas',
            '.webadmin .blogEditor__richToolbar',
            '@media (min-width: 48rem)',
            '@media (min-width: 80rem)',
            '@media (prefers-reduced-motion: reduce)',
            'var(--ls-webadmin-accent)',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->stylesheet);
        }
        foreach (['@import', 'javascript:', 'expression(', 'http://', 'https://'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $this->stylesheet);
        }
    }

    public function testBuilderGuidesWidthsAndLongTextModalFollowTheVisualContract(): void
    {
        foreach ([
            '.webadmin .blogEditor__postMain.blogEditor__builderMain',
            '.webadmin .blogEditor__builderContainer::before',
            'repeating-linear-gradient(',
            '--blog-builder-section-gap: 2rem',
            '--blog-builder-section-gap: 3rem',
            '--blog-builder-section-gap: 5rem',
            'align-items: center',
            'justify-content: flex-start',
            'row-gap: 0',
            '.blogEditor__postPreview.blogEditor__builder',
            'overflow: visible',
            '.webadmin .blogEditor__builderModule::before',
            '--ls-blog-builder-module-guide: #9ca5ae',
            'border-radius: 0.75rem',
            'box-shadow: 0 0 1.6rem 0.3rem var(--blog-builder-glow)',
            'min-height: max(2.5rem, var(--blog-builder-section-gap))',
            'block-size: max(2.5rem, var(--blog-builder-section-gap))',
            '.blogEditor__builderModule--heading',
            '.blogEditor__previewHeading',
            ".blogEditor__previewHeading[data-semantic-tag='h2']",
            ".blogEditor__previewHeading[data-semantic-tag='h6']",
            '.blogEditor__builderIconButton',
            '.blogEditor__choiceGroup',
            ".blogEditor__choiceButton[aria-pressed='true']",
            'height: min(88dvh, 58rem)',
            'max-height: calc(100dvh - 2rem)',
            'resize: vertical',
            ".webadmin .blogEditor__richDialog[data-expanded='true']",
            '@media (max-width: 36rem)',
            'grid-template-columns: repeat(2, minmax(0, 1fr))',
            'inset-block-start: auto',
            '@media (max-width: 47.99rem), (pointer: coarse)',
        ] as $visualContract) {
            self::assertStringContainsString(
                $visualContract,
                $this->stylesheet
            );
        }

        foreach ([
            "'toggle-size'",
            "state.dialog.dataset.expanded = expanded ? 'true' : 'false'",
            "state.dialog.style.removeProperty('height')",
            "'Ampliar el editor hasta el alto disponible'",
            "'Restaurar el alto del editor'",
            'function v2IconActionButton(',
            'function v2ConfigButtonGroup(',
            "button.dataset.blogV2Action = 'set-config'",
            "button.setAttribute('aria-pressed'",
            "'h' + block.level",
            'function v2OpenSelectedInspector(context, preferredFocusSelector)',
            'function v2SelectCanvasNode(',
            'function v2HandleCanvasSelection(',
            'function v2HandleCanvasSelectionKeydown(',
            "item.dataset.blogV2Selectable = 'true'",
            "wrapper.dataset.blogV2Selectable = 'true'",
        ] as $javascriptContract) {
            self::assertStringContainsString(
                $javascriptContract,
                $this->javascript
            );
        }

        self::assertStringNotContainsString(
            "action === 'config'",
            $this->javascript
        );
        self::assertStringNotContainsString(
            "clearButton,\n            alignment",
            $this->javascript
        );
        foreach ([
            'function v2InspectorSection(',
            'function v2TypographyOptions(',
            'function v2TextAlignmentOptions(',
            'function v2ListConfig(',
            "'apply-heading-level'",
            "embed: 'HTML'",
        ] as $inspectorContract) {
            self::assertStringContainsString(
                $inspectorContract,
                $this->javascript
            );
        }
    }

    public function testImmersiveSsrPreviewAndMobileFirstCanvasStayProgressive(): void
    {
        foreach ([
            'function v2SaveDraft(context)',
            'function v2PrepareSavedPreview(context, forceDocumentSave)',
            'function v2CreatePreview(context, trigger)',
            'function v2BindImmersivePreview(context)',
            "document.createElement('dialog')",
            "document.createElement('iframe')",
            "frame.title = 'Vista previa SSR del art\\u00edculo'",
            "['desktop', 'tablet', 'mobile']",
            "'Guardar borrador'",
            "'Volver al editor'",
            "'[data-blog-editor-publish-form]'",
            "'a[data-blog-editor-preview]'",
            "'[data-blog-editor-save][form=\"'",
            "'[data-blog-editor-status][data-blog-editor-form=\"'",
            'function v2PublishSaved(context, publishForm)',
            "event.ctrlKey",
            "dialog.addEventListener('cancel'",
            'v2BindImmersivePreview(context)',
        ] as $javascriptContract) {
            self::assertStringContainsString(
                $javascriptContract,
                $this->javascript
            );
        }

        foreach ([
            '.webadmin .blogEditor__immersivePreview',
            '.webadmin .blogEditor__immersivePreviewStage',
            ".blogEditor__immersivePreviewStage[data-device='tablet']",
            ".blogEditor__immersivePreviewStage[data-device='mobile']",
            '.webadmin .blogEditor__immersivePreviewToolbar',
            'width: min(100%, 48rem)',
            'width: min(100%, 24rem)',
            ".blogEditor__builderModule[data-width='80']",
            ".blogEditor__builderModule[data-width='60']",
            ".blogEditor__builderModule[data-width='40']",
            '@container (min-width: 24rem)',
            '> .blogEditor__builderModule--image[data-width=\'full\']',
            '.webadmin .blogEditor__postPreview.blogEditor__builder',
            'overflow: visible',
            '.webadmin .blogEditor__actionBar > form',
            ':has(> .blogEditor__inspector)',
        ] as $stylesheetContract) {
            self::assertStringContainsString(
                $stylesheetContract,
                $this->stylesheet
            );
        }

        self::assertMatchesRegularExpression(
            "/function v2BindPublish\(context\)[\s\S]+?"
                . "if \(typeof window\.fetch !== 'function'\) \{"
                . "[\s\S]+?return;[\s\S]+?event\.preventDefault\(\);/",
            $this->javascript
        );

        self::assertMatchesRegularExpression(
            "/\\.webadmin \\.blogEditor__builderModule"
                . "\\[data-width='80'\\] \\{\\s*width:\\s*100%;/s",
            $this->stylesheet
        );
        self::assertMatchesRegularExpression(
            '/\\.webadmin \\.blogEditor__videoPlaceholder\\s*\\{'
                . '[^}]*width:\\s*100%;'
                . '[^}]*max-width:\\s*100%;'
                . '[^}]*min-width:\\s*0;/s',
            $this->stylesheet
        );
        self::assertMatchesRegularExpression(
            "/@media \\(min-width: 48rem\\).*?"
                . "data-width='80'\\] \\{\\s*width:\\s*90%;.*?"
                . "data-width='60'\\] \\{\\s*width:\\s*80%;.*?"
                . "data-width='40'\\] \\{\\s*width:\\s*60%;/s",
            $this->stylesheet
        );
        self::assertMatchesRegularExpression(
            "/@media \\(min-width: 64rem\\).*?"
                . "data-width='80'\\] \\{\\s*width:\\s*80%;.*?"
                . "data-width='60'\\] \\{\\s*width:\\s*60%;.*?"
                . "data-width='40'\\] \\{\\s*width:\\s*40%;/s",
            $this->stylesheet
        );
    }

    public function testStickyActionBarPaintsAboveCanvasInsertionTargets(): void
    {
        self::assertSame(
            1,
            preg_match(
                '/\\.webadmin \\.blogEditor__save\\s*\\{[^}]*'
                    . 'z-index:\\s*(\\d+)\\s*;/s',
                $this->stylesheet,
                $actionBarMatch
            )
        );
        self::assertSame(
            1,
            preg_match(
                '/\\.webadmin \\.blogEditor__insert\\s*\\{[^}]*'
                    . 'z-index:\\s*(\\d+)\\s*;/s',
                $this->stylesheet,
                $insertMatch
            )
        );
        self::assertGreaterThan(
            (int) $insertMatch[1],
            (int) $actionBarMatch[1],
            'The sticky action bar must receive pointer events above insert rows.'
        );
    }

    public function testEditorUsesAccessibleDialogsDeleteActionsAndInsertState(): void
    {
        foreach ([
            'function createEditorDialog(context)',
            'function confirmEditorAction(context, options, trigger)',
            'function showEditorServerNotice(context, error, message, trigger)',
            "dialog.setAttribute('aria-labelledby'",
            "dialog.setAttribute('aria-describedby'",
            "dialog.addEventListener('cancel'",
            "dialog.addEventListener('close'",
            'cancelButton.hidden = settings.notice === true',
            'function v2CanDelete(location)',
            'function v2TrashIcon()',
            "'blogEditor__builderIconButton--delete'",
            'function v2SetInsertExpanded(toggle, menu, expanded)',
            "isExpanded ? 'true' : 'false'",
            "isExpanded ? 'Cerrar opciones' : 'A\\u00f1adir contenido'",
            'function tooltip(button, label)',
            "visual.setAttribute('aria-hidden', 'true')",
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }

        foreach ([
            '.webadmin .blogEditor__confirmDialog',
            '.webadmin .blogEditor__confirmDialog::backdrop',
            '.webadmin .blogEditor__confirmDialogActions',
            'min-height: 2.75rem;',
            ".blogEditor__insertToggle[aria-expanded='true']::after",
            '.webadmin .blogEditor__tooltip',
            '[data-blog-tooltip]:is(:hover, :focus-visible)',
            '.webadmin .blogEditor__builderActions .blogEditor__tooltip',
            'inset-inline-end: 0;',
            'max-width: min(11.5rem, calc(100vw - 2rem));',
            '.blogEditor__builderIconButton--delete',
            '.webadmin .blogEditor__richSelect > span:not(.blogEditor__tooltip)',
            '.webadmin .blogEditor__richField > span:not(.blogEditor__tooltip)',
            'color: var(--ls-webadmin-surface-strong);',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->stylesheet);
        }

        self::assertStringNotContainsString(
            '.webadmin .blogEditor__richSelect span,',
            $this->stylesheet
        );

        self::assertStringNotContainsString(
            'window.confirm(',
            $this->javascript
        );
        self::assertStringNotContainsString(
            'beforeunload',
            $this->javascript
        );
        self::assertStringNotContainsString(
            'onbeforeunload',
            $this->javascript
        );
        self::assertStringNotContainsString(
            'window.alert(',
            $this->javascript
        );
    }

    public function testAsyncEditorialActionsShareTheLatestLockVersion(): void
    {
        foreach ([
            'function v2SyncEditorialLockVersion(context, lockVersion)',
            "document.querySelectorAll('form')",
            "form.elements.namedItem('post')",
            "form.elements.namedItem('locale')",
            "form.elements.namedItem('lock_version')",
            "'X-LiquidStack-Editor': 'async'",
            "'Accept': 'application/json'",
            "payload.ok !== true",
            "!Number.isInteger(payload.lock_version)",
            'v2SyncEditorialLockVersion(',
            'function v2SyncCategoryWorkspaceVersion(context, workspaceVersion)',
            '!Number.isInteger(payload.category_workspace_version)',
            'v2SyncCategoryWorkspaceVersion(',
            'function v2SyncTagWorkspaceVersion(context, workspaceVersion)',
            '!Number.isInteger(payload.tag_workspace_version)',
            'v2SyncTagWorkspaceVersion(',
            'function categorySelectionFingerprint(form)',
            'state.cleanFingerprint = submittedFingerprint;',
            'state.assignmentPending',
            'function saveTagAssignment(state, commitPendingInput)',
            'function editorialFacetsHaveChanges(context)',
            'waitEditorialFacets(context).then(',
            'saveEditorialFacets(context)',
            'function v2BindPublish(context)',
            'publishForm.addEventListener(\'submit\'',
            'context.publishPending',
            'context.publishPromise',
            'window.fetch(publishForm.action',
            "payload.status !== 'published'",
            'v2BindPublish(context)',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }
        foreach (['color04', 'color05'] as $color) {
            self::assertStringContainsString(
                ".blogEditorPreferences__swatch[data-color='"
                    . $color . "']",
                $this->stylesheet
            );
        }
        self::assertStringContainsString(
            "'color03', 'color04',",
            $this->javascript
        );

        $node = new Process(['node', '--version']);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no est\u00e1 disponible.');
        }

        $asset = dirname(__DIR__, 3)
            . '/modules/blog/published/assets/blog-editor.js';
        $script = <<<'JS'
import fs from 'node:fs';
import vm from 'node:vm';

let source = fs.readFileSync(process.argv[1], 'utf8');
const marker = '}());';
const markerIndex = source.lastIndexOf(marker);
if (markerIndex < 0) throw new Error('Unable to expose editor test hooks.');
source = source.slice(0, markerIndex)
            + 'globalThis.__blogLockHooks = { '
            + 'v2SyncEditorialLockVersion, v2SyncTagWorkspaceVersion };\n'
    + source.slice(markerIndex);

class FakeInput {
  constructor(value) { this.value = value; }
}
class FakeForm {
  constructor(values) {
    this.controls = Object.fromEntries(
      Object.entries(values).map(([name, value]) => [name, new FakeInput(value)])
    );
    this.elements = { namedItem: (name) => this.controls[name] || null };
  }
}

const editor = new FakeForm({
  post: 'post-a', locale: 'es', lock_version: '3',
  tag_workspace_version: '2',
});
const category = new FakeForm({
  post: 'post-a', locale: 'es', lock_version: '3',
});
const publication = new FakeForm({
  post: 'post-a', locale: 'es', lock_version: '3',
  tag_workspace_version: '2',
});
const anotherLocale = new FakeForm({
  post: 'post-a', locale: 'eu', lock_version: '8',
  tag_workspace_version: '8',
});

globalThis.HTMLInputElement = FakeInput;
globalThis.HTMLFormElement = FakeForm;
globalThis.document = {
  readyState: 'loading',
  addEventListener() {},
  querySelectorAll(selector) {
    if (selector !== 'form') return [];
    return [editor, category, publication, anotherLocale];
  },
};
globalThis.window = {};
vm.runInThisContext(source, { filename: process.argv[1] });

const synced = globalThis.__blogLockHooks.v2SyncEditorialLockVersion(
  { form: editor },
  4
);
const tagsSynced = globalThis.__blogLockHooks.v2SyncTagWorkspaceVersion(
  { form: editor },
  0
);
process.stdout.write(JSON.stringify({
  synced,
  tagsSynced,
  editor: editor.controls.lock_version.value,
  category: category.controls.lock_version.value,
  publication: publication.controls.lock_version.value,
  anotherLocale: anotherLocale.controls.lock_version.value,
  editorTags: editor.controls.tag_workspace_version.value,
  publicationTags: publication.controls.tag_workspace_version.value,
  anotherLocaleTags: anotherLocale.controls.tag_workspace_version.value,
}));
JS;

        $process = new Process([
            'node',
            '--input-type=module',
            '--eval',
            $script,
            $asset,
        ]);
        $process->mustRun();
        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertTrue($result['synced']);
        self::assertTrue($result['tagsSynced']);
        self::assertSame('4', $result['editor']);
        self::assertSame('4', $result['category']);
        self::assertSame('4', $result['publication']);
        self::assertSame('8', $result['anotherLocale']);
        self::assertSame('0', $result['editorTags']);
        self::assertSame('0', $result['publicationTags']);
        self::assertSame('8', $result['anotherLocaleTags']);
    }

    public function testAsyncDraftSavePreservesUnsavedWorkAndRecoversCsrf(): void
    {
        foreach ([
            'function validV2DraftDocument(',
            'function editorialFormFingerprint(',
            'function v2SyncCsrfToken(',
            'function v2ReadSaveResponse(',
            "'session_expired'",
            "'csrf_stale'",
            "'lock_conflict'",
            "'invalid_draft'",
            'attempt === 0',
            "submittedBody.set('csrf'",
            'var submittedFingerprint = editorialFormFingerprint(',
            'var unchangedSinceSubmit = editorialFormFingerprint(',
            'context.initialFingerprint = unchangedSinceSubmit',
            'context.documentValue = savedDocument',
            'payload.document_sha256',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }

        self::assertStringNotContainsString(
            'Completa el H2 de cada secci',
            $this->javascript
        );
    }

    public function testAsyncPublishRetriesCsrfAndNeverUsesNativeNavigation(): void
    {
        foreach ([
            'function v2PublishSaved(context, publishForm)',
            'v2PrepareSavedPreview(context).then(',
            'window.fetch(publishForm.action',
            "'X-LiquidStack-Editor': 'async'",
            ').then(v2ReadSaveResponse).catch(function (error)',
            'v2SyncCsrfToken(error.payload.csrf)',
            "submittedBody.set('csrf', error.payload.csrf)",
            "payload.status !== 'published'",
            'context.publishPending = false',
            'context.publishPromise = null',
            'El borrador se conserva',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }
        $publishStart = strpos(
            $this->javascript,
            'function v2PublishSaved(context, publishForm)'
        );
        $publishEnd = strpos(
            $this->javascript,
            'function v2BindPublish(context)',
            $publishStart === false ? 0 : $publishStart
        );
        self::assertNotFalse($publishStart);
        self::assertNotFalse($publishEnd);
        self::assertStringNotContainsString(
            'requestSubmit',
            substr($this->javascript, $publishStart, $publishEnd - $publishStart)
        );

        $node = new Process(['node', '--version']);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no est\u00e1 disponible.');
        }

        $asset = dirname(__DIR__, 3)
            . '/modules/blog/published/assets/blog-editor.js';
        $script = <<<'JS'
import fs from 'node:fs';
import vm from 'node:vm';

let source = fs.readFileSync(process.argv[1], 'utf8');
const marker = '}());';
const markerIndex = source.lastIndexOf(marker);
if (markerIndex < 0) throw new Error('Unable to expose editor test hooks.');
source = source.slice(0, markerIndex)
    + 'globalThis.__blogPublish = v2PublishSaved;\n'
    + source.slice(markerIndex);

class FakeInput {
  constructor(name, value) { this.name = name; this.value = value; }
}
class FakeButton extends FakeInput {
  constructor() {
    super('', '');
    this.type = 'submit';
    this.disabled = false;
  }
}
class FakeForm {
  constructor(action, controls) {
    this.action = action;
    this.controls = controls;
    this.attributes = {};
    const elements = [...controls];
    elements.namedItem = (name) => controls.find(
      (control) => control.name === name
    ) || null;
    this.elements = elements;
  }
  setAttribute(name, value) { this.attributes[name] = value; }
  removeAttribute(name) { delete this.attributes[name]; }
}
class FakeFormData {
  constructor(form) { this.form = form; }
  forEach(callback) {
    this.form.controls.forEach((control) => {
      if (control.name) callback(control.value, control.name);
    });
  }
}

const csrf = new FakeInput('csrf', 'A'.repeat(43));
const post = new FakeInput('post', 'post-a');
const locale = new FakeInput('locale', 'es');
const lock = new FakeInput('lock_version', '4');
const draft = new FakeInput('h1', 'Borrador local intacto');
const editor = new FakeForm('/admin/blog/editor/save', [
  csrf, post, locale, lock, draft,
]);
const publishCsrf = new FakeInput('csrf', csrf.value);
const publish = new FakeForm('/admin/blog/editor/publish', [
  publishCsrf,
  new FakeInput('post', 'post-a'),
  new FakeInput('locale', 'es'),
  new FakeInput('lock_version', '4'),
  new FakeInput('category_workspace_version', '2'),
  new FakeButton(),
]);
const status = { textContent: '', dataset: {} };
const forms = [editor, publish];
let calls = 0;

globalThis.HTMLInputElement = FakeInput;
globalThis.HTMLButtonElement = FakeButton;
globalThis.HTMLFormElement = FakeForm;
globalThis.FormData = FakeFormData;
globalThis.document = {
  readyState: 'loading',
  addEventListener() {},
  querySelectorAll(selector) { return selector === 'form' ? forms : []; },
};
globalThis.window = {
  requestAnimationFrame(callback) { callback(); },
  fetch() {
    calls += 1;
    const payload = calls === 1
      ? { ok: false, error: 'csrf_stale', csrf: 'B'.repeat(43) }
      : { ok: false, error: 'invalid_draft' };
    return Promise.resolve({
      redirected: false,
      ok: false,
      headers: { get() { return 'application/json; charset=utf-8'; } },
      json() { return Promise.resolve(payload); },
    });
  },
};
vm.runInThisContext(source, { filename: process.argv[1] });

const context = {
  form: editor,
  readOnly: true,
  richEditor: null,
  status,
  announcementVersion: 0,
  publishPending: false,
  publishPromise: null,
};
const result = await globalThis.__blogPublish(context, publish);
process.stdout.write(JSON.stringify({
  result,
  calls,
  editorCsrf: csrf.value,
  publishCsrf: publishCsrf.value,
  draft: draft.value,
  pending: context.publishPending,
  promise: context.publishPromise,
  busy: publish.attributes['aria-busy'] || null,
  message: status.textContent,
}));
JS;
        $process = new Process([
            'node',
            '--input-type=module',
            '--eval',
            $script,
            $asset,
        ]);
        $process->mustRun();
        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertFalse($result['result']);
        self::assertSame(2, $result['calls']);
        self::assertSame(str_repeat('B', 43), $result['editorCsrf']);
        self::assertSame(str_repeat('B', 43), $result['publishCsrf']);
        self::assertSame('Borrador local intacto', $result['draft']);
        self::assertFalse($result['pending']);
        self::assertNull($result['promise']);
        self::assertNull($result['busy']);
        self::assertStringContainsString(
            'datos necesarios',
            $result['message']
        );
    }

    public function testCanvasUsesTheHeroLabelWithoutAVisibleLocaleBadge(): void
    {
        self::assertStringContainsString("? 'HERO'", $this->javascript);
        self::assertStringNotContainsString(
            'blogEditor__localeBadge',
            $this->javascript
        );
        self::assertStringNotContainsString(
            "'Idioma ' + context.locale.toUpperCase()",
            $this->javascript
        );
    }

    public function testTechnicalLimitsDriveAccessibleCountersAndTypedFocus(): void
    {
        $script = $this->javascript;
        $stylesheet = $this->stylesheet;

        foreach ([
            'readTechnicalLimits(form)',
            'function characters(value)',
            'function entryTechnicalIssue(context, control)',
            'function firstDocumentTechnicalIssue(context)',
            'function v2ApplyValidationIssue(context, rawIssue)',
            "control.setAttribute('aria-invalid', 'true')",
            "control.focus()",
            "control.select()",
            'richUpdateLimitFeedback(state)',
            'state.context.technicalLimits.block[field].bytes',
            'CUSTOM_TEXT_POLICY = technicalLimits.custom_text_policy',
        ] as $contract) {
            self::assertStringContainsString($contract, $script);
        }
        self::assertStringContainsString(
            ".blogEditor__fieldFeedback[data-state='advisory']",
            $stylesheet
        );
        self::assertStringContainsString(
            ".blogEditor [aria-invalid='true']",
            $stylesheet
        );
        self::assertStringContainsString(
            '.blogEditor__blockFeedback',
            $stylesheet
        );
    }

    public function testHeaderSelectionClientContractIsIndependentAndClosed(): void
    {
        $node = new Process(['node', '--version']);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no está disponible.');
        }

        $asset = dirname(__DIR__, 3)
            . '/modules/blog/published/assets/blog-editor.js';
        $script = <<<'JS'
import fs from 'node:fs';
import vm from 'node:vm';

let source = fs.readFileSync(process.argv[1], 'utf8');
const marker = '}());';
const markerIndex = source.lastIndexOf(marker);
if (markerIndex < 0) throw new Error('Unable to expose editor test hooks.');
source = source.slice(0, markerIndex)
    + 'globalThis.__headerHooks = { validHeaderSelection, templateForHero };\n'
    + source.slice(markerIndex);
globalThis.document = { readyState: 'loading', addEventListener() {} };
globalThis.window = {};
vm.runInThisContext(source, { filename: process.argv[1] });
const hooks = globalThis.__headerHooks;
process.stdout.write(JSON.stringify({
  independent: hooks.validHeaderSelection(
    { hero: 'hero06', h1_module: 'moduleH1Type04' },
    'article-hero06-01'
  ),
  wrongTemplate: hooks.validHeaderSelection(
    { hero: 'hero06', h1_module: 'moduleH1Type04' },
    'article-cover-01'
  ),
  unknownHero: hooks.validHeaderSelection(
    { hero: 'hero99', h1_module: 'moduleH1Type04' },
    'article-cover-01'
  ),
  template: hooks.templateForHero('hero07'),
}));
JS;

        $process = new Process([
            'node', '--input-type=module', '--eval', $script, $asset,
        ]);
        $process->mustRun();
        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertTrue($result['independent']);
        self::assertFalse($result['wrongTemplate']);
        self::assertFalse($result['unknownHero']);
        self::assertSame('article-cover-01', $result['template']);
    }

    public function testHeaderCanvasProjectsEachH1ResourceIndependentlyFromHero(): void
    {
        self::assertStringContainsString(
            "module.setAttribute('data-blog-h1-module', moduleKey)",
            $this->javascript
        );
        foreach ([
            'moduleH1Type01',
            'moduleH1Type03',
            'moduleH1Type04',
        ] as $module) {
            self::assertStringContainsString(
                'blogEditor__postHeading--' . $module,
                $this->stylesheet
            );
            self::assertStringContainsString(
                'blogEditor__postExcerpt--' . $module,
                $this->stylesheet
            );
        }
        foreach (['hero00', 'hero06', 'hero07'] as $hero) {
            self::assertStringContainsString(
                'blogEditor__postHeader--' . $hero,
                $this->stylesheet
            );
        }
        self::assertStringContainsString(
            "intro: 'destacado'",
            $this->javascript
        );
    }

    public function testBlogConsumesTheReusablePickerSelectionWithoutOwningItsTransport(): void
    {
        foreach ([
            'function initMediaDialog(context)',
            'function openMediaDialog(context, publicId, apply)',
            "'[data-webadmin-media-picker][data-webadmin-media-picker-owner=\"'",
            "'liquidstack:webadmin-media-picker:selected'",
            'detail.public_id !== selected.publicId',
            'detail.label !== selected.label',
            'detail.thumbnail_url !== selected.thumbnailUrl',
            'event.preventDefault()',
            'typeof context.mediaApply !== \'function\'',
            'appendMediaOption(context.mediaCatalog, selected)',
            'readMedia(context.mediaDialogSelect).find(',
            'context.media.unshift(selected)',
            "dialog.addEventListener('close'",
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }
        foreach ([
            'X-LiquidStack-Media-Manager',
            'new FormData(upload)',
            'function mediaRequestId()',
            'function validUploadedMedia(',
            'data-blog-media-dialog',
            'data-blog-media-upload',
            'data-blog-media-use',
            'data-blog-media-close',
        ] as $privateMechanic) {
            self::assertStringNotContainsString(
                $privateMechanic,
                $this->javascript
            );
        }
        self::assertStringNotContainsString(
            "mediaSelect.id = 'blog-editor-header-media'",
            $this->javascript
        );
        self::assertStringContainsString(
            "'Posici\\u00f3n vertical de la imagen'",
            $this->javascript
        );
        self::assertStringContainsString(
            "'image-object-position-y'",
            $this->javascript
        );
        foreach ([
            '.webadmin .blogEditor__mediaChooseButton',
            '.webadmin .blogEditor__mediaDialog',
            '.webadmin .blogEditor__mediaDialog::backdrop',
            '.webadmin .blogEditor__mediaDialog progress',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->stylesheet);
        }
    }

    /** @return array<int, mixed> */
    private function minimalRendererArguments(): array
    {
        $document = \App\Core\Blog\StructuredContent\Document\BlogDocument::fromArray([
            'schema' => 'liquidstack.blog.document',
            'version' => 1,
            'template' => 'article-basic-01',
            'blocks' => [],
        ]);
        $now = new \DateTimeImmutable('2026-08-02T10:00:00Z');
        $variant = new \App\Core\Blog\BlogPostVariant(
            '00000000-0000-4000-8000-000000000001',
            '00000000-0000-4000-8000-000000000002',
            'es',
            new \App\Core\Blog\BlogDraft('H1', ''),
            'draft',
            null,
            1,
            '00000000-0000-4000-8000-000000000003',
            '00000000-0000-4000-8000-000000000004',
            $now,
            $now
        );

        return [
            '/admin/blog',
            'csrf-token-safe',
            $variant,
            $document,
            (new \App\Core\Blog\StructuredContent\Document\BlogDocumentCodec())
                ->encode($document),
        ];
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }

    /** @param list<string> $declarations */
    private function assertCssRuleContains(
        string $selector,
        array $declarations
    ): void {
        self::assertSame(
            1,
            preg_match(
                '/' . preg_quote($selector, '/')
                    . '\\s*\\{(?<body>[^}]*)\\}/s',
                $this->stylesheet,
                $match
            ),
            'Missing CSS rule for ' . $selector
        );
        foreach ($declarations as $declaration) {
            self::assertStringContainsString(
                $declaration,
                $match['body'],
                $selector . ' must include ' . $declaration
            );
        }
    }

}
