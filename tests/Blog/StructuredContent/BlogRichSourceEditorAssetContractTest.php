<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BlogRichSourceEditorAssetContractTest extends TestCase
{
    private string $javascript;
    private string $stylesheet;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $this->javascript = $this->read(
            $root . '/modules/blog/published/assets/blog-editor.js'
        );
        $this->stylesheet = $this->read(
            $root . '/modules/blog/published/assets/blog-admin.css'
        );
    }

    public function testSourceModeIsScopedAccessibleAndDependencyFree(): void
    {
        foreach ([
            'var RICH_SOURCE_TAG_DEFINITIONS = [',
            'function richSourceDefinitions(',
            'function richSourceSuggestions(',
            'function richCssSuggestions(',
            'function richCssSuggestionEdit(',
            'function richCssSetSuggestionIndex(',
            'function richCssApplySuggestion(',
            'function richSourceIndentEdit(',
            'function richSourceEnterEdit(',
            'function richSourceAutoCloseEdit(',
            'function richSourcePairEdit(',
            'function richSourcePairDeleteEdit(',
            'function richSourceExitPairEdit(',
            'function richCodeEnterEdit(',
            'function richCodeAltGraphInput(',
            'function richSuggestionKeyboardAction(',
            'function richSuggestionCode(',
            'function richApplyTextareaEdit(',
            "source.wrap = 'soft'",
            "cssSource.wrap = 'soft'",
            'event.isComposing || event.keyCode === 229',
            'control.setRangeText(',
            'state.sourceTouched = richCodeTouched(',
            "source.setAttribute('aria-autocomplete', 'list')",
            "cssSource.setAttribute('aria-autocomplete', 'list')",
            "sourceSuggestionList.setAttribute('role', 'listbox')",
            "option.setAttribute('role', 'option')",
            "event.key === 'Tab'",
            "event.key === 'Enter'",
            "event.key === 'Escape'",
            "['next', 'previous'].includes(suggestionAction)",
            "['next', 'previous'].includes(cssSuggestionAction)",
            "event.key === '>'",
            "event.key === ' '",
            "cssPosition.id = 'blog-rich-css-position-' + context.instance",
            'cssPosition.id,',
            "definition.kind === 'property'",
            'richCssRefreshSuggestions(state, true)',
            'richParseHtml(state.source.value,',
            'richParseTextFlowHtml(state.source.value)',
            'richAttributesAllowed(node, linkAttributes)',
            '!safeUrl(href)',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }
        self::assertSame(
            2,
            substr_count(
                $this->javascript,
                "event.shiftKey,\n"
                    . "                event.ctrlKey,\n"
                    . "                event.metaKey,\n"
                    . '                event.altKey'
            )
        );

        foreach ([
            '.webadmin .blogEditor__richSourceEditor',
            '.webadmin .blogEditor__richSourceGutter',
            '.webadmin .blogEditor__richSourceStage',
            '.webadmin .blogEditor__richSourceSuggestions',
            '.webadmin .blogEditor__richSourceSuggestion',
            '.webadmin .blogEditor__richSourceSuggestionMatch',
            'background: rgba(56, 139, 253, 0.42)',
            '.webadmin .blogEditor__richSourceMeta',
            '--blog-rich-source-bg: #1f1f1f',
            'color-scheme: dark',
            'tab-size: 4',
            'scrollbar-gutter: stable',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->stylesheet);
        }

        self::assertDoesNotMatchRegularExpression(
            '/(?:CodeMirror|Monaco|cdnjs|unpkg|jsdelivr)/i',
            $this->javascript . $this->stylesheet
        );
        self::assertStringNotContainsString(
            "source.wrap = 'off'",
            $this->javascript
        );
        self::assertStringContainsString(
            "var RICH_TEXT_BLOCK_TYPES = [\n"
                . "        'paragraph', 'heading', 'list', 'callout', 'quote'",
            $this->javascript
        );
        self::assertStringNotContainsString(
            "'embed', 'paragraph'",
            $this->javascript
        );
    }

    public function testSourceEditingHelpersStayInsideTheRealRichAllowlist(): void
    {
        $asset = dirname(__DIR__, 3)
            . '/modules/blog/published/assets/blog-editor.js';
        $script = <<<'JS'
import fs from 'node:fs';
import vm from 'node:vm';

const asset = process.argv[process.argv.length - 1];
let source = fs.readFileSync(asset, 'utf8');
const marker = '}());';
const markerIndex = source.lastIndexOf(marker);
if (markerIndex < 0) throw new Error('Unable to expose source editor hooks.');
source = source.slice(0, markerIndex)
    + 'globalThis.__richSourceHooks = {'
    + ' RICH_SOURCE_TAG_DEFINITIONS, RICH_TEXT_BLOCK_TYPES,'
    + ' richSourceDefinitions, richSourceSuggestions,'
    + ' richSourceSuggestionEdit, richSourceIndentEdit,'
    + ' richSourceEnterEdit, richSourceAutoCloseEdit, richSourceExitPairEdit,'
    + ' richCodeEnterEdit, richSuggestionKeyboardAction, richSuggestionCode,'
    + ' richCssSuggestions, richCssSuggestionEdit, safeUrl };\n'
    + source.slice(markerIndex);

function fakeNode(tagName, text = '') {
  let ownText = String(text);
  return {
    tagName: String(tagName).toUpperCase(),
    className: '',
    childNodes: [],
    append(...children) { this.childNodes.push(...children); },
    set textContent(value) {
      ownText = String(value);
      this.childNodes = [];
    },
    get textContent() {
      return this.childNodes.length > 0
        ? this.childNodes.map((child) => child.textContent).join('')
        : ownText;
    },
  };
}
globalThis.document = {
  readyState: 'loading',
  addEventListener() {},
  createElement(tagName) { return fakeNode(tagName); },
  createTextNode(text) { return { nodeType: 3, textContent: String(text) }; },
};
globalThis.window = {};
vm.runInThisContext(source, { filename: asset });

const hooks = globalThis.__richSourceHooks;
const tags = (definitions) => definitions.map((definition) => definition.tag);
const definitionTags = tags(hooks.RICH_SOURCE_TAG_DEFINITIONS);
const snippets = hooks.RICH_SOURCE_TAG_DEFINITIONS.map(
  (definition) => definition.snippet
);
const flowRoot = tags(hooks.richSourceDefinitions(true, true, '', 0));
const listBody = tags(hooks.richSourceDefinitions(true, true, '<ul>\n  ', 7));
const paragraphBody = tags(
  hooks.richSourceDefinitions(true, true, '<p>', 3)
);
const linkBody = tags(
  hooks.richSourceDefinitions(true, true, '<p><a href="/">', 15)
);
const headingBody = tags(hooks.richSourceDefinitions(false, false, '', 0));
const suggestions = hooks.richSourceSuggestions(
  '<st', 3, false, true, false
);
const emptyHtmlSuggestions = hooks.richSourceSuggestions(
  '<', 1, true, true, false, [2, 3, 4, 5, 6], true
);
const forcedHtmlSuggestions = hooks.richSourceSuggestions(
  '<', 1, true, true, true, [2, 3, 4, 5, 6], true
);
const unmatchedHtmlSuggestions = hooks.richSourceSuggestions(
  '<zz', 3, true, true, false, [2, 3, 4, 5, 6], true
);
const bareParagraphSuggestions = hooks.richSourceSuggestions(
  'p', 1, true, true, false, [2, 3, 4, 5, 6], false
);
const bareParagraphEdit = hooks.richSourceSuggestionEdit(
  'p',
  bareParagraphSuggestions.start,
  1,
  bareParagraphSuggestions.items[0]
);
const cssPolicy = {
  html: {},
  css: {
    properties: ['color', 'font-weight'],
    root_properties: ['color', 'font-weight'],
    tags: ['p'], at_rules: ['media', 'supports'],
    simple_pseudos: [], pseudo_elements: [],
    attribute_selectors: [], reserved_class_prefixes: [],
    max_depth: 6, max_rules: 128, max_declarations: 256,
    max_selector_bytes: 512, max_value_bytes: 2048,
    max_css_bytes: 30000, max_rendered_css_bytes: 600000,
    max_numeric_tokens_per_value: 64, max_root_numeric_total: 4096,
  },
};
const cssProperties = hooks.richCssSuggestions(
  'co', 2, false, cssPolicy
);
const cssPropertyEdit = hooks.richCssSuggestionEdit(
  'co', cssProperties.start, 2, cssProperties.items[0]
);
const cssValues = hooks.richCssSuggestions(
  'color: r', 8, false, cssPolicy
);
const cssValueEdit = hooks.richCssSuggestionEdit(
  'color: r', cssValues.start, 8, cssValues.items[0]
);
const cssEmptyBlock = '.miClase{}';
const cssEmptyBlockCaret = cssEmptyBlock.indexOf('}');
const cssEmptyAutomatic = hooks.richCssSuggestions(
  cssEmptyBlock, cssEmptyBlockCaret, false, cssPolicy
);
const cssEmptyForced = hooks.richCssSuggestions(
  cssEmptyBlock, cssEmptyBlockCaret, true, cssPolicy
);
const cssEmptyValueCaret = cssPropertyEdit.value.indexOf(';');
const cssEmptyValueAutomatic = hooks.richCssSuggestions(
  cssPropertyEdit.value, cssEmptyValueCaret, false, cssPolicy
);
const cssEmptyValueForced = hooks.richCssSuggestions(
  cssPropertyEdit.value, cssEmptyValueCaret, true, cssPolicy
);
const cssEntered = hooks.richCodeEnterEdit(
  cssEmptyBlock, cssEmptyBlockCaret, cssEmptyBlockCaret
);
const cssMultilineStart = hooks.richCodeEnterEdit('p{}', 2, 2);
const cssMultilinePrefix = {
  value: cssMultilineStart.value.slice(0, cssMultilineStart.start)
    + 'co' + cssMultilineStart.value.slice(cssMultilineStart.end),
  caret: cssMultilineStart.start + 2,
};
const cssMultilineProperties = hooks.richCssSuggestions(
  cssMultilinePrefix.value, cssMultilinePrefix.caret, false, cssPolicy
);
const cssMultilinePropertyEdit = hooks.richCssSuggestionEdit(
  cssMultilinePrefix.value,
  cssMultilineProperties.start,
  cssMultilinePrefix.caret,
  cssMultilineProperties.items[0]
);
const cssMultilineForcedValues = hooks.richCssSuggestions(
  cssMultilinePropertyEdit.value,
  cssMultilinePropertyEdit.start,
  true,
  cssPolicy
);
const cssMultilineValuePrefix = {
  value: cssMultilinePropertyEdit.value.slice(0, cssMultilinePropertyEdit.start)
    + 'r' + cssMultilinePropertyEdit.value.slice(cssMultilinePropertyEdit.end),
  caret: cssMultilinePropertyEdit.start + 1,
};
const cssMultilineValues = hooks.richCssSuggestions(
  cssMultilineValuePrefix.value,
  cssMultilineValuePrefix.caret,
  false,
  cssPolicy
);
const cssMultilineValueEdit = hooks.richCssSuggestionEdit(
  cssMultilineValuePrefix.value,
  cssMultilineValues.start,
  cssMultilineValuePrefix.caret,
  cssMultilineValues.items[0]
);
const cssCrLfPrefix = 'p{\r\n    co\r\n}';
const cssCrLfCaret = cssCrLfPrefix.indexOf('\r\n}');
const cssCrLfProperties = hooks.richCssSuggestions(
  cssCrLfPrefix, cssCrLfCaret, false, cssPolicy
);
const cssCrLfPropertyEdit = hooks.richCssSuggestionEdit(
  cssCrLfPrefix,
  cssCrLfProperties.start,
  cssCrLfCaret,
  cssCrLfProperties.items[0]
);
const cssCrLfValues = hooks.richCssSuggestions(
  cssCrLfPropertyEdit.value,
  cssCrLfPropertyEdit.start,
  true,
  cssPolicy
);
const suggestionEdit = hooks.richSourceSuggestionEdit(
  '<st', suggestions.start, 3, suggestions.items[0]
);
const rogueSuggestion = hooks.richSourceSuggestionEdit(
  '', 0, 0, { tag: 'p', snippet: '<script>|</script>' }
);
const indented = hooks.richSourceIndentEdit('one\n  two', 0, 9, false);
const insertedTab = hooks.richSourceIndentEdit('<p></p>', 3, 3, false);
const outdented = hooks.richSourceIndentEdit(
  indented.value,
  indented.start,
  indented.end,
  true
);
const entered = hooks.richSourceEnterEdit(
  '<ul></ul>', 4, 4, true, true
);
const paragraphEntered = hooks.richSourceEnterEdit(
  '<p></p>', 3, 3, true, true, [], true
);
const siblingSource = '<main>\n\n    <p></p>\n</main>';
const siblingCaret = siblingSource.indexOf('</p>');
const siblingInserted = hooks.richSourceExitPairEdit(
  siblingSource, siblingCaret, siblingCaret
);
const existingSiblingSource = '<main>\n\n    <p></p>\n    \n</main>';
const existingSiblingCaret = existingSiblingSource.indexOf('</p>');
const siblingReused = hooks.richSourceExitPairEdit(
  existingSiblingSource, existingSiblingCaret, existingSiblingCaret
);
const siblingWithWhitespace = (whitespace, newline = '\n') => {
  const value = '<main>' + newline + newline + '    <p></p>' + newline
    + whitespace + newline + '</main>';
  const caret = value.indexOf('</p>');
  return hooks.richSourceExitPairEdit(value, caret, caret);
};
const siblingNormalized = {
  zero: siblingWithWhitespace(''),
  two: siblingWithWhitespace('  '),
  eight: siblingWithWhitespace('        '),
  crlfTwo: siblingWithWhitespace('  ', '\r\n'),
};
const nonEmptyPairExit = hooks.richSourceExitPairEdit(
  '<p>x</p>', 4, 4
);
const autoStrong = hooks.richSourceAutoCloseEdit(
  '<strong', 7, 7, false, true
);
const autoScript = hooks.richSourceAutoCloseEdit(
  '<script', 7, 7, true, true
);
const highlightResult = (value, query) => {
  const code = hooks.richSuggestionCode(value, query);
  return {
    text: code.textContent,
    matches: code.childNodes
      .filter((child) => child.tagName === 'MARK')
      .map((child) => child.textContent),
  };
};
const keyboardForEditor = () => ({
  down: hooks.richSuggestionKeyboardAction('ArrowDown', true, false),
  up: hooks.richSuggestionKeyboardAction('ArrowUp', true, false),
  enter: hooks.richSuggestionKeyboardAction('Enter', true, false),
  shiftEnter: hooks.richSuggestionKeyboardAction('Enter', true, true),
  ctrlEnter: hooks.richSuggestionKeyboardAction(
    'Enter', true, false, true, false, false
  ),
  metaEnter: hooks.richSuggestionKeyboardAction(
    'Enter', true, false, false, true, false
  ),
  altEnter: hooks.richSuggestionKeyboardAction(
    'Enter', true, false, false, false, true
  ),
  tab: hooks.richSuggestionKeyboardAction('Tab', true, false),
  shiftTab: hooks.richSuggestionKeyboardAction('Tab', true, true),
  closed: hooks.richSuggestionKeyboardAction('Enter', false, false),
});
const keyboard = {
  html: keyboardForEditor(),
  css: keyboardForEditor(),
};

process.stdout.write(JSON.stringify({
  definitionTags,
  snippets,
  flowRoot,
  listBody,
  paragraphBody,
  linkBody,
  headingBody,
  suggestionTags: tags(suggestions.items),
  emptyHtmlSuggestionCount: emptyHtmlSuggestions.items.length,
  forcedHtmlSuggestionTags: tags(forcedHtmlSuggestions.items),
  unmatchedHtmlSuggestionCount: unmatchedHtmlSuggestions.items.length,
  suggestionEdit,
  bareParagraphEdit,
  css: {
    properties: cssProperties.items.map((item) => item.value),
    propertyEdit: cssPropertyEdit,
    values: cssValues.items.map((item) => item.value),
    valueEdit: cssValueEdit,
    emptyAutomaticCount: cssEmptyAutomatic.items.length,
    emptyForced: cssEmptyForced.items.map((item) => item.value),
    emptyValueAutomaticCount: cssEmptyValueAutomatic.items.length,
    emptyValueForced: cssEmptyValueForced.items.map((item) => item.value),
    entered: cssEntered,
    multiline: {
      start: cssMultilineStart,
      prefixedValue: cssMultilinePrefix.value,
      properties: cssMultilineProperties.items.map((item) => item.value),
      propertyEdit: cssMultilinePropertyEdit,
      forcedValues: cssMultilineForcedValues.items.map((item) => item.value),
      valuePrefixedValue: cssMultilineValuePrefix.value,
      values: cssMultilineValues.items.map((item) => item.value),
      valueEdit: cssMultilineValueEdit,
      crlfProperties: cssCrLfProperties.items.map((item) => item.value),
      crlfPropertyEdit: cssCrLfPropertyEdit,
      crlfValues: cssCrLfValues.items.map((item) => item.value),
    },
  },
  rogueSuggestion,
  indented,
  insertedTab,
  outdented,
  entered,
  paragraphEntered,
  siblingInserted,
  siblingReused,
  siblingNormalized,
  nonEmptyPairExit,
  autoStrong,
  autoScript,
  highlights: {
    html: highlightResult('<strong>', 'st'),
    css: highlightResult('color', 'co'),
    forced: highlightResult('color', ''),
  },
  keyboard,
  richTypes: hooks.RICH_TEXT_BLOCK_TYPES,
  urls: {
    https: hooks.safeUrl('https://example.test/path'),
    relative: hooks.safeUrl('/ruta-segura'),
    javascript: hooks.safeUrl('javascript:alert(1)'),
    data: hooks.safeUrl('data:text/html,unsafe'),
  },
}));
JS;
        $process = new Process([
            'node',
            '--input-type=module',
            '-',
            $asset,
        ]);
        $process->setInput($script);
        $process->mustRun();
        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(
            [
                'p', 'br', 'ul', 'ol', 'li',
                'h2', 'h3', 'h4', 'h5', 'h6',
                'blockquote', 'aside',
                'a', 'strong', 'em', 'u', 'span',
            ],
            $result['definitionTags']
        );
        self::assertDoesNotMatchRegularExpression(
            '/(?:script|on[a-z]+\s*=|style\s*=|javascript:|data:)/i',
            implode("\n", $result['snippets'])
        );
        self::assertSame(
            ['p', 'ul', 'ol', 'blockquote', 'aside'],
            $result['flowRoot']
        );
        self::assertSame(['li'], $result['listBody']);
        self::assertSame(
            ['br', 'a', 'strong', 'em', 'u', 'span'],
            $result['paragraphBody']
        );
        self::assertSame(
            ['strong', 'em', 'u', 'span'],
            $result['linkBody']
        );
        self::assertSame(
            ['a', 'strong', 'em', 'u', 'span'],
            $result['headingBody']
        );
        self::assertSame(['strong'], $result['suggestionTags']);
        self::assertSame(0, $result['emptyHtmlSuggestionCount']);
        self::assertContains('p', $result['forcedHtmlSuggestionTags']);
        self::assertSame(0, $result['unmatchedHtmlSuggestionCount']);
        self::assertSame('<strong></strong>', $result['suggestionEdit']['value']);
        self::assertSame(8, $result['suggestionEdit']['start']);
        self::assertSame(
            ['value' => '<p></p>', 'start' => 3, 'end' => 3],
            $result['bareParagraphEdit']
        );
        self::assertSame(['color'], $result['css']['properties']);
        self::assertSame(
            ['value' => 'color: ;', 'start' => 7, 'end' => 7],
            $result['css']['propertyEdit']
        );
        self::assertContains('red', $result['css']['values']);
        self::assertSame(
            ['value' => 'color: red', 'start' => 10, 'end' => 10],
            $result['css']['valueEdit']
        );
        self::assertSame(0, $result['css']['emptyAutomaticCount']);
        self::assertSame(
            ['color', 'font-weight'],
            $result['css']['emptyForced']
        );
        self::assertSame(0, $result['css']['emptyValueAutomaticCount']);
        self::assertContains('red', $result['css']['emptyValueForced']);
        self::assertSame(
            [
                'value' => ".miClase{\n    \n}",
                'start' => 14,
                'end' => 14,
            ],
            $result['css']['entered']
        );
        self::assertSame(
            ['value' => "p{\n    \n}", 'start' => 7, 'end' => 7],
            $result['css']['multiline']['start']
        );
        self::assertSame(
            "p{\n    co\n}",
            $result['css']['multiline']['prefixedValue']
        );
        self::assertSame(
            ['color'],
            $result['css']['multiline']['properties']
        );
        self::assertSame(
            [
                'value' => "p{\n    color: ;\n}",
                'start' => 14,
                'end' => 14,
            ],
            $result['css']['multiline']['propertyEdit']
        );
        self::assertContains(
            'red',
            $result['css']['multiline']['forcedValues']
        );
        self::assertSame(
            "p{\n    color: r;\n}",
            $result['css']['multiline']['valuePrefixedValue']
        );
        self::assertContains('red', $result['css']['multiline']['values']);
        self::assertSame(
            [
                'value' => "p{\n    color: red;\n}",
                'start' => 17,
                'end' => 17,
            ],
            $result['css']['multiline']['valueEdit']
        );
        self::assertSame(
            ['color'],
            $result['css']['multiline']['crlfProperties']
        );
        self::assertSame(
            [
                'value' => "p{\r\n    color: ;\r\n}",
                'start' => 15,
                'end' => 15,
            ],
            $result['css']['multiline']['crlfPropertyEdit']
        );
        self::assertContains('red', $result['css']['multiline']['crlfValues']);
        self::assertNull($result['rogueSuggestion']);
        self::assertSame('    one' . "\n" . '      two', $result['indented']['value']);
        self::assertSame('<p>    </p>', $result['insertedTab']['value']);
        self::assertSame(7, $result['insertedTab']['start']);
        self::assertSame('one' . "\n" . '  two', $result['outdented']['value']);
        self::assertSame('<ul>' . "\n    \n" . '</ul>', $result['entered']['value']);
        self::assertSame(
            ['value' => "<p>\n    \n</p>", 'start' => 8, 'end' => 8],
            $result['paragraphEntered']
        );
        self::assertSame(
            [
                'value' => "<main>\n\n    <p></p>\n    \n</main>",
                'start' => 24,
                'end' => 24,
            ],
            $result['siblingInserted']
        );
        self::assertSame(
            [
                'value' => "<main>\n\n    <p></p>\n    \n</main>",
                'start' => 24,
                'end' => 24,
            ],
            $result['siblingReused']
        );
        $normalizedSibling = [
            'value' => "<main>\n\n    <p></p>\n    \n</main>",
            'start' => 24,
            'end' => 24,
        ];
        self::assertSame(
            $normalizedSibling,
            $result['siblingNormalized']['zero']
        );
        self::assertSame(
            $normalizedSibling,
            $result['siblingNormalized']['two']
        );
        self::assertSame(
            $normalizedSibling,
            $result['siblingNormalized']['eight']
        );
        self::assertSame(
            [
                'value' => "<main>\r\n\r\n    <p></p>\r\n    \r\n</main>",
                'start' => 27,
                'end' => 27,
            ],
            $result['siblingNormalized']['crlfTwo']
        );
        self::assertNull($result['nonEmptyPairExit']);
        self::assertSame('<strong></strong>', $result['autoStrong']['value']);
        self::assertNull($result['autoScript']);
        self::assertSame(
            ['paragraph', 'heading', 'list', 'callout', 'quote'],
            $result['richTypes']
        );
        self::assertSame(
            ['text' => '<strong>', 'matches' => ['st']],
            $result['highlights']['html']
        );
        self::assertSame(
            ['text' => 'color', 'matches' => ['co']],
            $result['highlights']['css']
        );
        self::assertSame(
            ['text' => 'color', 'matches' => []],
            $result['highlights']['forced']
        );
        $keyboardContract = [
            'down' => 'next',
            'up' => 'previous',
            'enter' => 'accept',
            'shiftEnter' => '',
            'ctrlEnter' => '',
            'metaEnter' => '',
            'altEnter' => '',
            'tab' => 'accept',
            'shiftTab' => '',
            'closed' => '',
        ];
        self::assertSame($keyboardContract, $result['keyboard']['html']);
        self::assertSame($keyboardContract, $result['keyboard']['css']);
        self::assertSame([
            'https' => true,
            'relative' => true,
            'javascript' => false,
            'data' => false,
        ], $result['urls']);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }
}
