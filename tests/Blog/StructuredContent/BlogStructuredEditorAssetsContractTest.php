<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use PHPUnit\Framework\TestCase;

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
            'function hasPreviousH2(',
            "block.level = value === '3' ? 3 : 2",
            "provider: 'youtube'",
            "video_id: 'vKQi3bBA1y8'",
            'context.documentValue.blocks.splice(',
            'move(context.documentValue.blocks,',
            'form.addEventListener(\'submit\'',
            'event.preventDefault()',
            'window.crypto.randomUUID',
            'window.crypto.getRandomValues',
            "form.dataset.blogEditorReadonly === 'true'",
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }
    }

    public function testJavascriptDoesNotIntroduceFreeHtmlPersistenceOrDependencies(): void
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
            'fetch(',
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
            'El H1 pertenece al art&iacute;culo',
            (new \App\Core\Blog\StructuredContent\Rendering\BlogStructuredEditorHtmlRenderer())
                ->render(...$this->minimalRendererArguments())
        );
    }

    public function testStylesAreScopedResponsiveAndDependencyFree(): void
    {
        foreach ([
            '.blogAdmin .blogEditor',
            '.blogAdmin .blogEditor__metadataGrid',
            '.blogAdmin .blogEditor__blockToolbar',
            '.blogAdmin .blogEditor__blockList',
            '.blogAdmin .blogEditor__inlineList',
            '.blogAdmin .blogEditor__listItems',
            '.blogAdmin .blogEditor__revision',
            '@media (min-width: 48rem)',
            '@media (min-width: 64rem)',
            '@media (prefers-reduced-motion: reduce)',
            'var(--ls-webadmin-border)',
            'var(--ls-webadmin-accent)',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->stylesheet);
        }
        foreach (['@import', 'javascript:', 'expression(', 'http://', 'https://'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $this->stylesheet);
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
}
