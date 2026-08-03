<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTextProjector;
use App\Core\Blog\StructuredContent\Rendering\BlogEditorMediaOption;
use App\Core\Blog\StructuredContent\Rendering\BlogEditorRevisionSummary;
use App\Core\Blog\StructuredContent\Rendering\BlogStructuredEditorHtmlRenderer;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BlogStructuredEditorHtmlRendererTest extends TestCase
{
    public function testDraftEditorRendersEscapedAccessibleControlledForm(): void
    {
        $document = $this->document();
        $canonical = (new BlogDocumentCodec())->encode($document);
        $variant = $this->variant($document);
        $mediaId = $this->id(900_001);
        $revisionId = $this->id(800_001);
        $html = (new BlogStructuredEditorHtmlRenderer())->render(
            '/admin/blog',
            'csrf-token-safe_123',
            $variant,
            $document,
            $canonical,
            [new BlogEditorMediaOption($mediaId, 'Matrix & "portada"')],
            [new BlogEditorRevisionSummary(
                $revisionId,
                3,
                6,
                new DateTimeImmutable('2026-08-02T12:30:00+02:00')
            )]
        );

        self::assertStringStartsWith('<!doctype html><html lang="es">', $html);
        self::assertStringContainsString(
            '<link rel="stylesheet" href="/assets/modules/webadmin/webadmin.css">',
            $html
        );
        self::assertStringContainsString(
            '<link rel="stylesheet" href="/assets/modules/blog/blog-admin.css">',
            $html
        );
        self::assertStringContainsString(
            '<script src="/assets/modules/webadmin/webadmin.js" defer></script>',
            $html
        );
        self::assertStringContainsString(
            '<script src="/assets/modules/blog/blog-editor.js" defer></script>',
            $html
        );
        self::assertSame(1, substr_count($html, '<h1'));
        self::assertStringContainsString(
            '<form class="blogEditor__form" method="post" action="/admin/blog/editor/save" data-blog-editor data-blog-editor-readonly="false">',
            $html
        );
        self::assertStringContainsString(
            'data-blog-seo-endpoint="/admin/blog/editor/seo-analysis"',
            $html
        );
        self::assertStringContainsString(
            'id="blog-editor-seo-panel-title"',
            $html
        );
        self::assertSame(1, substr_count($html, 'id="blog-editor-seo-title"'));
        preg_match_all('/\sid="([^"]+)"/', $html, $idMatches);
        self::assertSame(
            count($idMatches[1]),
            count(array_unique($idMatches[1])),
            'El editor no puede renderizar IDs HTML duplicados.'
        );
        foreach (['csrf', 'post', 'locale', 'lock_version', 'document_json'] as $name) {
            self::assertStringContainsString(
                'type="hidden" name="' . $name . '"',
                $html
            );
        }
        self::assertStringContainsString(
            'name="post" value="' . $variant->postPublicId() . '"',
            $html
        );
        self::assertStringContainsString('name="locale" value="es"', $html);
        self::assertStringContainsString('name="lock_version" value="7"', $html);
        self::assertStringContainsString(
            'name="document_json" value="{&quot;schema&quot;:&quot;liquidstack.blog.document&quot;',
            $html
        );
        foreach (['h1', 'slug', 'seo_title', 'meta_description', 'excerpt'] as $name) {
            self::assertStringContainsString('name="' . $name . '"', $html);
        }
        self::assertStringNotContainsString('name="body_text"', $html);
        self::assertStringContainsString(
            'El H1 pertenece al art&iacute;culo y nunca forma parte de sus bloques.',
            $html
        );
        self::assertStringContainsString(
            'value="H1 &amp; &quot;Matrix&quot;"',
            $html
        );
        self::assertStringContainsString(
            '<option value="article-basic-01" selected>',
            $html
        );
        self::assertStringContainsString('data-blog-template-select', $html);
        self::assertStringNotContainsString(
            'data-blog-template-select name=',
            $html
        );
        foreach ([
            'paragraph', 'heading', 'list', 'callout',
            'link', 'image', 'video', 'cta',
        ] as $type) {
            self::assertStringContainsString(
                'data-blog-add-block="' . $type . '"',
                $html
            );
        }
        self::assertStringContainsString(
            '>A&ntilde;adir v&iacute;deo de YouTube</button>',
            $html
        );
        self::assertStringNotContainsString(
            '>A&ntilde;adir v&iacute;deo de youtube</button>',
            $html
        );
        self::assertStringContainsString(
            '<option value="' . $mediaId
            . '">Matrix &amp; &quot;portada&quot;</option>',
            $html
        );
        self::assertStringContainsString(
            'data-block-id="' . $this->id(1) . '">Bloque 1: P&aacute;rrafo',
            $html
        );
        self::assertStringContainsString(
            'href="/admin/blog/editor/preview?post='
            . $variant->postPublicId() . '&amp;locale=es" target="_blank" '
            . 'rel="noopener noreferrer"',
            $html
        );
        self::assertStringContainsString(
            'href="/admin/blog/editor/revisions?post='
            . $variant->postPublicId() . '&amp;locale=es"',
            $html
        );
        self::assertStringContainsString(
            'href="/admin/blog/posts/new?post=' . $variant->postPublicId()
                . '">A&ntilde;adir otro idioma</a>',
            $html
        );
        self::assertStringNotContainsString('/categories/assign', $html);
        self::assertStringNotContainsString('/posts/publish', $html);
        self::assertStringContainsString(
            '<form method="post" action="/admin/blog/editor/restore">',
            $html
        );
        self::assertStringContainsString(
            'name="revision" value="' . $revisionId . '"',
            $html
        );
        self::assertStringContainsString(
            '<time datetime="2026-08-02T10:30:00+00:00">2026-08-02 10:30 UTC</time>',
            $html
        );
        self::assertDoesNotMatchRegularExpression('/\s(?:style|on[a-z]+)=/i', $html);
        self::assertSame(2, substr_count($html, '<script '));
        self::assertSame(
            2,
            preg_match_all(
                '/<script src="[^"]+" defer><\/script>/',
                $html
            )
        );
        self::assertStringNotContainsString('<iframe', strtolower($html));
    }

    public function testWorkflowControlsRespectPresentationCapabilities(): void
    {
        $document = $this->document();
        $renderer = new BlogStructuredEditorHtmlRenderer();
        $draft = $this->variant($document);
        $draftHtml = $renderer->render(
            '/admin/blog',
            'csrf-token-safe',
            $draft,
            $document,
            (new BlogDocumentCodec())->encode($document),
            canPublish: true,
            canAssignCategories: true
        );

        self::assertStringContainsString(
            'href="/admin/blog/categories/assign?post='
                . $draft->postPublicId() . '&amp;locale=es"',
            $draftHtml
        );
        self::assertStringContainsString(
            '<form method="post" action="/admin/blog/posts/publish">',
            $draftHtml
        );
        self::assertStringContainsString('>Publicar</button>', $draftHtml);
        self::assertStringContainsString(
            'name="lock_version" value="7"',
            $draftHtml
        );

        $published = $this->variant(
            $document,
            BlogPostVariant::PUBLISHED
        );
        $publishedHtml = $renderer->render(
            '/admin/blog',
            'csrf-token-safe',
            $published,
            $document,
            (new BlogDocumentCodec())->encode($document),
            canPublish: true
        );
        self::assertStringContainsString(
            '<form method="post" action="/admin/blog/posts/unpublish">',
            $publishedHtml
        );
        self::assertStringContainsString('>Retirar</button>', $publishedHtml);
        self::assertStringNotContainsString('/categories/assign', $publishedHtml);
    }

    public function testPublishedVariantKeepsPresentationReadOnly(): void
    {
        $document = $this->document();
        $canonical = (new BlogDocumentCodec())->encode($document);
        $variant = $this->variant($document, BlogPostVariant::PUBLISHED);
        $html = (new BlogStructuredEditorHtmlRenderer())->render(
            '/admin/blog/',
            'csrf-token-safe',
            $variant,
            $document,
            $canonical,
            [],
            [new BlogEditorRevisionSummary(
                $this->id(800_001),
                1,
                7,
                new DateTimeImmutable('2026-08-02T10:00:00Z')
            )]
        );

        self::assertStringContainsString(
            'data-blog-editor-readonly="true"',
            $html
        );
        self::assertStringContainsString(
            'Retira la variante antes de modificarla',
            $html
        );
        self::assertStringContainsString(
            'id="blog-editor-h1" name="h1" type="text"',
            $html
        );
        self::assertStringContainsString('required readonly>', $html);
        self::assertStringContainsString(
            'data-blog-template-select disabled',
            $html
        );
        self::assertMatchesRegularExpression(
            '/<button type="submit" disabled>Guardar documento<\/button>/',
            $html
        );
        self::assertMatchesRegularExpression(
            '/action="\/admin\/blog\/editor\/restore"[\s\S]+?'
            . '<button type="submit" disabled>Restaurar esta revisi&oacute;n<\/button>/',
            $html
        );
    }

    public function testEmptyCatalogDisablesOnlyNewImageButton(): void
    {
        $document = $this->document();
        $html = (new BlogStructuredEditorHtmlRenderer())->render(
            '/admin/blog',
            'csrf-token-safe',
            $this->variant($document),
            $document,
            (new BlogDocumentCodec())->encode($document)
        );

        self::assertStringContainsString(
            'data-blog-add-block="image" disabled',
            $html
        );
        self::assertStringContainsString(
            'data-blog-add-block="paragraph">',
            $html
        );
        self::assertStringContainsString(
            '<li>No hay revisiones guardadas.</li>',
            $html
        );
    }

    public function testCanonicalJsonAndDocumentMustMatch(): void
    {
        $document = $this->document();
        $this->assertInvalidPresentation(fn (): string =>
            (new BlogStructuredEditorHtmlRenderer())->render(
                '/admin/blog',
                'csrf-token-safe',
                $this->variant($document),
                $document,
                '{"schema":"tampered"}'
            )
        );
    }

    public function testDocumentProjectionMustMatchVariantBodySemantically(): void
    {
        $document = $this->document();
        $variant = $this->variant($document, BlogPostVariant::DRAFT, 'Other body');

        $this->assertInvalidPresentation(fn (): string =>
            (new BlogStructuredEditorHtmlRenderer())->render(
                '/admin/blog',
                'csrf-token-safe',
                $variant,
                $document,
                (new BlogDocumentCodec())->encode($document)
            )
        );
    }

    public function testLegacyWhitespaceNormalizationAllowsSafeFirstRender(): void
    {
        $document = BlogDocument::fromArray([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::VERSION,
            'template' => 'article-basic-01',
            'blocks' => [[
                'id' => $this->id(1),
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'One', 'marks' => []],
                    ['type' => 'break'],
                    ['type' => 'text', 'text' => 'Two words', 'marks' => []],
                ],
            ], [
                'id' => $this->id(2),
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text', 'text' => 'Three', 'marks' => [],
                ]],
            ]],
        ]);
        $variant = $this->variant(
            $document,
            BlogPostVariant::DRAFT,
            " One\r\nTwo\twords\r\n\r\n\r\n Three \r\n"
        );

        $html = (new BlogStructuredEditorHtmlRenderer())->render(
            '/admin/blog',
            'csrf-token-safe',
            $variant,
            $document,
            (new BlogDocumentCodec())->encode($document)
        );
        self::assertStringContainsString('data-blog-editor', $html);
    }

    public function testExactStructuredProjectionKeepsConsecutiveBreakNodes(): void
    {
        $document = BlogDocument::fromArray([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::VERSION,
            'template' => 'article-basic-01',
            'blocks' => [[
                'id' => $this->id(1),
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'One', 'marks' => []],
                    ['type' => 'break'],
                    ['type' => 'break'],
                    ['type' => 'break'],
                    ['type' => 'text', 'text' => 'Two', 'marks' => []],
                ],
            ]],
        ]);

        $html = (new BlogStructuredEditorHtmlRenderer())->render(
            '/admin/blog',
            'csrf-token-safe',
            $this->variant($document),
            $document,
            (new BlogDocumentCodec())->encode($document)
        );
        self::assertStringContainsString('data-blog-editor', $html);
    }

    public function testUnsafeBasePathAndCsrfFailBeforeRendering(): void
    {
        $document = $this->document();
        $variant = $this->variant($document);
        $canonical = (new BlogDocumentCodec())->encode($document);

        foreach ([
            '//evil.test/admin',
            '/admin/../blog',
            '/admin/%252e%252e/blog',
            '/admin/blog?redirect=evil',
            '/admin\\blog',
        ] as $basePath) {
            $this->assertInvalidPresentation(fn (): string =>
                (new BlogStructuredEditorHtmlRenderer())->render(
                    $basePath,
                    'csrf-token-safe',
                    $variant,
                    $document,
                    $canonical
                )
            );
        }

        foreach (['', "csrf\nleak", str_repeat('x', 513)] as $csrf) {
            $this->assertInvalidPresentation(fn (): string =>
                (new BlogStructuredEditorHtmlRenderer())->render(
                    '/admin/blog',
                    $csrf,
                    $variant,
                    $document,
                    $canonical
                )
            );
        }
    }

    public function testMediaAndRevisionValueObjectsAreStrict(): void
    {
        self::assertSame(
            'Safe image',
            (new BlogEditorMediaOption($this->id(900_001), 'Safe image'))
                ->label()
        );
        foreach ([
            ['bad-id', 'Safe image'],
            [$this->id(900_001), ''],
            [$this->id(900_001), ' Leading'],
            [$this->id(900_001), '<img>'],
        ] as [$publicId, $label]) {
            $this->assertInvalidPresentation(
                static fn (): BlogEditorMediaOption =>
                    new BlogEditorMediaOption($publicId, $label)
            );
        }

        $summary = new BlogEditorRevisionSummary(
            $this->id(800_001),
            2,
            5,
            new DateTimeImmutable('2026-08-02T12:00:00+02:00')
        );
        self::assertSame(2, $summary->revisionNumber());
        self::assertSame(5, $summary->variantLockVersion());
        self::assertSame(
            '2026-08-02T10:00:00+00:00',
            $summary->createdAt()->format(DATE_ATOM)
        );
        foreach ([
            ['bad-id', 1, 1],
            [$this->id(800_001), 0, 1],
            [$this->id(800_001), 1, 0],
        ] as [$revisionId, $number, $lock]) {
            $this->assertInvalidPresentation(
                static fn (): BlogEditorRevisionSummary =>
                    new BlogEditorRevisionSummary(
                        $revisionId,
                        $number,
                        $lock,
                        new DateTimeImmutable('2026-08-02T10:00:00Z')
                    )
            );
        }
    }

    public function testOptionCollectionsMustBeListsOfUniqueTypedValues(): void
    {
        $document = $this->document();
        $variant = $this->variant($document);
        $canonical = (new BlogDocumentCodec())->encode($document);
        $media = new BlogEditorMediaOption($this->id(900_001), 'Image');
        $revision = new BlogEditorRevisionSummary(
            $this->id(800_001),
            1,
            1,
            new DateTimeImmutable('2026-08-02T10:00:00Z')
        );
        $invalidCollections = [
            [[$media, $media], []],
            [['not-an-option'], []],
            [[], [$revision, $revision]],
            [[], ['not-a-summary']],
            [[2 => $media], []],
        ];
        foreach ($invalidCollections as [$mediaOptions, $summaries]) {
            $this->assertInvalidPresentation(fn (): string =>
                (new BlogStructuredEditorHtmlRenderer())->render(
                    '/admin/blog',
                    'csrf-token-safe',
                    $variant,
                    $document,
                    $canonical,
                    $mediaOptions,
                    $summaries
                )
            );
        }
    }

    private function document(): BlogDocument
    {
        return BlogDocument::fromArray([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::VERSION,
            'template' => 'article-basic-01',
            'blocks' => [[
                'id' => $this->id(1),
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'Matrix body & "choice"',
                    'marks' => [],
                ]],
            ], [
                'id' => $this->id(2),
                'type' => 'heading',
                'level' => 2,
                'content' => [[
                    'type' => 'text',
                    'text' => 'The red pill',
                    'marks' => [],
                ]],
            ]],
        ]);
    }

    private function variant(
        BlogDocument $document,
        string $status = BlogPostVariant::DRAFT,
        ?string $bodyText = null
    ): BlogPostVariant {
        $draft = new BlogDraft(
            'H1 & "Matrix"',
            $bodyText ?? (new BlogDocumentTextProjector())->project($document),
            'matrix-choice',
            'SEO & "Matrix"',
            'Description & "safe"',
            'Excerpt & "safe"'
        );
        $now = new DateTimeImmutable('2026-08-02T10:00:00Z');

        return new BlogPostVariant(
            $this->id(100_001),
            $this->id(200_001),
            'es',
            $draft,
            $status,
            $status === BlogPostVariant::PUBLISHED ? $now : null,
            7,
            $this->id(300_001),
            $this->id(300_002),
            $now,
            $now
        );
    }

    private function id(int $number): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $number);
    }

    /** @param callable(): mixed $operation */
    private function assertInvalidPresentation(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected invalid Blog editor presentation.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringStartsWith('Invalid Blog editor', $exception->getMessage());
        }
    }
}
