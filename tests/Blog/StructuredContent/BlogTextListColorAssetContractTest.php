<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use PHPUnit\Framework\TestCase;

final class BlogTextListColorAssetContractTest extends TestCase
{
    private string $javascript;
    private string $stylesheet;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $this->javascript = (string) file_get_contents(
            $root . '/modules/blog/published/assets/blog-editor.js'
        );
        $this->stylesheet = (string) file_get_contents(
            $root . '/modules/blog/published/assets/blog-admin.css'
        );
    }

    public function testTextFlowKeepsSemanticSafeVisualAndHtmlRoundTrips(): void
    {
        foreach ([
            "paragraph: 'Texto'",
            'function richParseTextFlowRoot(',
            'function richParseTextFlowHtml(',
            'function richSerializeTextFlowHtml(',
            'function richInsertFlowParagraph(',
            "event.inputType === 'insertParagraph'",
            "['ul', 'ol'].includes(tag)",
            "item.nodeName.toLowerCase() !== 'li'",
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }
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
        foreach ([
            '.outerHTML',
            'insertAdjacentHTML',
            'document.write(',
            'document.writeln(',
            'createContextualFragment(',
            'setHTMLUnsafe(',
        ] as $unsafeSink) {
            self::assertStringNotContainsString(
                $unsafeSink,
                $this->javascript
            );
        }
    }

    public function testLegacyListUsesTheRichProjectionAndKeepsItsInspector(): void
    {
        foreach ([
            "state.legacyListMode = block.type === 'list'",
            '? richLegacyListFlow(block)',
            'function richLegacyListEditMode(',
            'function richPristineFingerprint(',
            "return 'noop';",
            "? 'list'",
            ": 'paragraph';",
            "var LEGACY_TEXT_BLOCK_TYPES = ['heading', 'list', 'callout', 'quote'];",
            'var INSERTABLE_BLOCK_TYPES = BLOCK_TYPES.filter(function (type) {',
            "node.id,\n                'list-type'",
            "node.id,\n                'list-marker'",
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }
    }

    public function testOneColorSelectorOwnsTokensAndIntegratedRgba(): void
    {
        foreach ([
            'function v2ColorControl(',
            "'toggle-custom-color'",
            "'apply-custom-color'",
            "alpha.type = 'number'",
            'blogEditor__customColorPanel',
            'blogEditor__colorSelector',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }
        self::assertGreaterThanOrEqual(
            4,
            substr_count($this->javascript, 'v2ColorControl(')
        );
        self::assertStringNotContainsString(
            "alpha.type = 'range'",
            $this->javascript
        );
        self::assertStringContainsString(
            '.webadmin .blogEditor__customColorPanel[hidden]',
            $this->stylesheet
        );
    }
}
