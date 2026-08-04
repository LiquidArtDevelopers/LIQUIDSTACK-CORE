<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BlogStructuredEditorAssetsContractTest extends TestCase
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

    public function testJavascriptOwnsOnlyTheClosedDocumentV1Contract(): void
    {
        foreach ([
            "var SCHEMA = 'liquidstack.blog.document'",
            'var VERSION = 1',
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
            'function validInline(',
            'function validDocument(',
            'function safeUrl(',
            'JSON.parse(documentInput.value)',
            'JSON.stringify(context.documentValue)',
            'input[name="document_json"]',
            'bytes(JSON.stringify(documentValue)) <= MAX_JSON_BYTES',
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
            'data-blog-list-action',
            'data-blog-add-inline',
            'data-blog-add-list-item',
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
            "window.addEventListener('beforeunload'",
            "document.addEventListener('invalid'",
            'formFingerprint(form)',
            'function isExpectedEditorRedirect(',
            'function isExpectedCategoryRedirect(',
            'function initCategoryAssignment(',
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
            'item.dataset.blogListItemId = itemValue.id',
            'context.status.textContent = \'\'',
            'context.announcementVersion += 1',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }
        self::assertGreaterThanOrEqual(
            12,
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
            '.innerHTML',
            '.outerHTML',
            'insertAdjacentHTML',
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
            '.webadmin .blogEditor__inlineList',
            '.webadmin .blogEditor__listItems',
            '.webadmin .blogEditor__revision',
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

    public function testLocaleBadgeUsesAHighContrastTokenOnTheDarkHeader(): void
    {
        self::assertMatchesRegularExpression(
            '/\.webadmin \.blogEditor__postHeader '
                . '\.blogEditor__localeBadge \{[^}]*'
                . 'color:\s*var\(--ls-webadmin-surface-strong\);/s',
            $this->stylesheet
        );

        $webadminStylesheet = $this->read(
            dirname(__DIR__, 3)
                . '/modules/webadmin/published/assets/webadmin.css'
        );
        $foreground = $this->hexToken(
            $webadminStylesheet,
            '--ls-webadmin-surface-strong'
        );
        $background = $this->hexToken(
            $webadminStylesheet,
            '--ls-webadmin-background-soft'
        );

        self::assertGreaterThanOrEqual(
            4.5,
            $this->contrastRatio($foreground, $background)
        );
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

    private function hexToken(string $stylesheet, string $token): string
    {
        $matched = preg_match(
            '/' . preg_quote($token, '/') . ':\s*(#[0-9a-f]{6})/i',
            $stylesheet,
            $matches
        );
        self::assertSame(1, $matched);

        return strtolower($matches[1]);
    }

    private function contrastRatio(string $foreground, string $background): float
    {
        $lighter = max(
            $this->relativeLuminance($foreground),
            $this->relativeLuminance($background)
        );
        $darker = min(
            $this->relativeLuminance($foreground),
            $this->relativeLuminance($background)
        );

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        $channels = [
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2)),
        ];
        $linear = array_map(static function (int $channel): float {
            $normalized = $channel / 255;

            return $normalized <= 0.04045
                ? $normalized / 12.92
                : (($normalized + 0.055) / 1.055) ** 2.4;
        }, $channels);

        return (0.2126 * $linear[0])
            + (0.7152 * $linear[1])
            + (0.0722 * $linear[2]);
    }
}
