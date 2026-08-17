<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTextProjector;
use App\Core\Blog\StructuredContent\Rendering\BlogEditorCategoryOption;
use App\Core\Blog\StructuredContent\Rendering\BlogEditorPreviewSandboxPolicy;
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
            )],
            editorStylesheets: ['/assets/css/blogEditorTheme-A1b2.css']
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
            '<link rel="stylesheet" href="/assets/css/blogEditorTheme-A1b2.css">',
            $html
        );
        self::assertLessThan(
            strpos($html, '/assets/css/blogEditorTheme-A1b2.css'),
            strpos($html, '/assets/modules/blog/blog-admin.css')
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
        self::assertStringNotContainsString('blogEditor__pageHeader', $html);
        self::assertStringContainsString(
            '<h1 id="blog-editor-title" '
                . 'class="webadminShell-visuallyHidden">'
                . 'Editor visual del Blog</h1>',
            $html
        );
        self::assertStringContainsString(
            '<form id="blog-editor-form" class="blogEditor__form" method="post" action="/admin/blog/editor/save" data-blog-editor data-blog-editor-readonly="false" data-blog-layout-editor-ready="false"',
            $html
        );
        self::assertStringContainsString('data-blog-heading-presets="[', $html);
        self::assertStringContainsString(
            'data-blog-heading-defaults="{&quot;h2&quot;:',
            $html
        );
        self::assertStringContainsString(
            'data-blog-technical-limits="{&quot;entry&quot;:',
            $html
        );
        self::assertStringContainsString(
            'data-blog-limit-field="meta_description"',
            $html
        );
        self::assertStringContainsString(
            'data-blog-field-feedback="meta_description"',
            $html
        );
        self::assertStringContainsString(
            'Recomendaci&oacute;n SEO orientativa: 120&ndash;160 caracteres.',
            $html
        );
        foreach (['h1', 'slug', 'seo_title', 'meta_description', 'excerpt'] as $field) {
            self::assertMatchesRegularExpression(
                '/<(?:input|textarea)[^>]+name="' . $field
                    . '"(?![^>]*\smaxlength=)[^>]*>/u',
                $html
            );
        }
        self::assertStringContainsString('data-webadmin-shell', $html);
        self::assertStringContainsString('data-blog-inspector-tab="entry"', $html);
        self::assertStringContainsString('data-blog-inspector-tab="block"', $html);
        self::assertStringContainsString('data-blog-inspector-tab="seo"', $html);
        self::assertStringContainsString('data-blog-block-list', $html);
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
        self::assertStringContainsString(
            'class="blogEditor__entryLocale"><img '
                . 'src="/assets/modules/blog/flags/es.svg"',
            $html
        );
        self::assertStringContainsString('<span>ES</span>', $html);
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
            'paragraph', 'image', 'video', 'embed', 'cta',
        ] as $type) {
            self::assertStringContainsString(
                'data-blog-add-block="' . $type . '"',
                $html
            );
        }
        foreach (['heading', 'list', 'callout', 'link'] as $legacyTextType) {
            self::assertStringNotContainsString(
                'data-blog-add-block="' . $legacyTextType . '"',
                $html
            );
        }
        self::assertStringContainsString(
            'data-blog-add-block="cta">A&ntilde;adir bot&oacute;n</button>',
            $html
        );
        self::assertStringContainsString(
            'data-blog-add-block="embed">A&ntilde;adir HTML</button>',
            $html
        );
        self::assertStringNotContainsString('data-blog-heading-level=', $html);
        self::assertStringContainsString(
            'Texto re&uacute;ne p&aacute;rrafos, encabezados H2-H6, '
                . 'listas, citas y destacados.',
            $html
        );
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
            'data-block-id="' . $this->id(1) . '">Bloque 1: Texto',
            $html
        );
        self::assertStringContainsString(
            'href="/admin/blog/editor/preview?post='
            . $variant->postPublicId() . '&amp;locale=es"',
            $html
        );
        self::assertStringContainsString(
            'class="webadminAction webadminAction--secondary" '
                . 'data-blog-editor-preview '
                . 'href="/admin/blog/editor/preview?',
            $html
        );
        self::assertStringNotContainsString(
            'href="/admin/blog/editor/preview?post='
            . $variant->postPublicId() . '&amp;locale=es" target="_blank"',
            $html
        );
        self::assertStringNotContainsString(
            'class="blogEditor__navigation"',
            $html
        );
        self::assertStringContainsString(
            'href="/admin/blog/editor/revisions?post=',
            $html
        );
        self::assertStringContainsString(
            '<a class="webadminAction webadminAction--secondary" '
                . 'href="/admin/blog/editor/revisions?',
            $html
        );
        self::assertStringNotContainsString('Revisiones recientes', $html);
        self::assertStringNotContainsString(
            'class="blogEditor__revisions"',
            $html
        );
        self::assertStringNotContainsString('>Otro idioma</a>', $html);
        self::assertStringContainsString(
            '<div class="blogEditor__save blogEditor__actionBar '
                . 'webadminActionGroup" '
                . 'role="group" aria-label="Acciones del art&iacute;culo">',
            $html
        );
        self::assertStringContainsString(
            '<button class="webadminAction webadminAction--secondary" '
                . 'type="submit" form="blog-editor-form" '
                . 'data-blog-editor-save>Guardar documento</button>',
            $html
        );
        self::assertStringContainsString(
            'data-blog-editor-status '
                . 'data-blog-editor-form="blog-editor-form"',
            $html
        );
        self::assertStringNotContainsString('>Volver al Blog</a>', $html);
        self::assertStringNotContainsString('/categories/assign', $html);
        self::assertStringNotContainsString('/posts/publish', $html);
        self::assertStringContainsString('>Revisiones</a>', $html);
        self::assertStringNotContainsString('/editor/restore', $html);
        self::assertStringNotContainsString(
            'name="revision" value="' . $revisionId . '"',
            $html
        );
        self::assertDoesNotMatchRegularExpression('/\s(?:style|on[a-z]+)=/i', $html);
        self::assertSame(3, substr_count($html, '<script '));
        self::assertSame(
            3,
            preg_match_all(
                '/<script src="[^"]+" defer><\/script>/',
                $html
            )
        );
        self::assertStringContainsString(
            '/assets/modules/webadmin/webadmin-media-picker.css',
            $html
        );
        self::assertStringContainsString(
            '/assets/modules/webadmin/webadmin-media-picker.js',
            $html
        );
        self::assertStringNotContainsString('<iframe', strtolower($html));
    }

    public function testLayoutEditorCapabilityIsExposedExplicitly(): void
    {
        $document = $this->document();
        $html = (new BlogStructuredEditorHtmlRenderer())->render(
            '/admin/blog',
            'csrf-token-safe',
            $this->variant($document),
            $document,
            (new BlogDocumentCodec())->encode($document),
            layoutEditorReady: true
        );

        self::assertStringContainsString(
            'data-blog-layout-editor-ready="true"',
            $html
        );
    }

    public function testAdvancedPreviewReceivesOneClosedNoncePolicy(): void
    {
        $document = $this->document();
        $nonce = 'abcdefghijklmnopQRSTUVWX';
        $policy = new BlogEditorPreviewSandboxPolicy($nonce);
        $html = (new BlogStructuredEditorHtmlRenderer())->render(
            '/admin/blog',
            'csrf-token-safe',
            $this->variant($document),
            $document,
            (new BlogDocumentCodec())->encode($document),
            layoutEditorReady: true,
            previewSandbox: $policy
        );

        self::assertStringContainsString(
            'data-blog-advanced-preview-style-nonce="' . $nonce . '"',
            $html
        );
        preg_match(
            '/data-blog-advanced-preview-csp="([^"]+)"/',
            $html,
            $policyMatch
        );
        self::assertSame(
            $policy->contentSecurityPolicy(),
            html_entity_decode(
                $policyMatch[1],
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        );
        self::assertStringNotContainsString("'unsafe-inline'", $html);
        self::assertSame(
            2,
            substr_count($policy->contentSecurityPolicy(), "'nonce-$nonce'")
        );
    }

    public function testAdvancedPreviewRejectsAnInvalidStyleNonce(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BlogEditorPreviewSandboxPolicy("bad'nonce");
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
            'data-blog-category-manager-open',
            $draftHtml
        );
        self::assertStringContainsString(
            '<form id="blog-editor-publish-form" method="post" '
                . 'data-blog-editor-publish-form '
                . 'action="/admin/blog/posts/publish">',
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
        self::assertStringContainsString(
            '>Retirar publicaci&oacute;n</button>',
            $publishedHtml
        );
        self::assertStringNotContainsString('/categories/assign', $publishedHtml);

        $privateWorkflowHtml = $renderer->render(
            '/admin/blog',
            'csrf-token-safe',
            $published,
            $document,
            (new BlogDocumentCodec())->encode($document),
            canPublish: true,
            privateDraftPublicationReady: true
        );
        self::assertStringContainsString(
            '<form id="blog-editor-publish-form" method="post" '
                . 'data-blog-editor-publish-form '
                . 'action="/admin/blog/editor/publish">',
            $privateWorkflowHtml
        );
        self::assertStringContainsString(
            '<button class="webadminAction webadminAction--primary" '
                . 'type="submit">Publicar</button>',
            $privateWorkflowHtml
        );
    }

    public function testCategoryAssignmentIsLocalizedTypedAndSeparate(): void
    {
        $document = $this->document();
        $variant = $this->variant($document);
        $assignedId = $this->id(700_001);
        $availableId = $this->id(700_002);
        $html = (new BlogStructuredEditorHtmlRenderer())->render(
            '/admin/blog',
            'csrf-token-safe',
            $variant,
            $document,
            (new BlogDocumentCodec())->encode($document),
            canAssignCategories: true,
            categoryOptions: [
                new BlogEditorCategoryOption(
                    $assignedId,
                    'Noticias & actualidad',
                    true
                ),
                new BlogEditorCategoryOption(
                    $availableId,
                    'Fiscalidad',
                    false
                ),
            ]
        );

        self::assertStringContainsString(
            '<form method="post" action="/admin/blog/categories/assign" '
                . 'data-blog-category-assignment-form '
                . 'data-blog-category-locale="es">',
            $html
        );
        self::assertStringContainsString(
            '<input type="hidden" name="csrf" value="csrf-token-safe">',
            $html
        );
        self::assertStringContainsString(
            '<input type="hidden" name="post" value="'
                . $variant->postPublicId() . '">',
            $html
        );
        self::assertMatchesRegularExpression(
            '/name="categories\[\]" value="' . preg_quote($assignedId, '/')
                . '" checked> Noticias &amp; actualidad/',
            $html
        );
        self::assertMatchesRegularExpression(
            '/name="categories\[\]" value="' . preg_quote($availableId, '/')
                . '"> Fiscalidad/',
            $html
        );
        self::assertStringContainsString(
            'data-blog-category-assignment-status role="status" '
                . 'aria-live="polite"',
            $html
        );
        $adminCss = file_get_contents(
            dirname(__DIR__, 3)
                . '/modules/blog/published/assets/blog-admin.css'
        );
        self::assertIsString($adminCss);
        self::assertStringContainsString(
            '[data-blog-category-manager-status]'
                . "\n    ):empty {\n    display: none;",
            $adminCss
        );
        self::assertStringContainsString(
            'data-blog-category-tools '
                . 'data-blog-category-endpoint="/admin/blog/categories" '
                . 'data-blog-category-locale="es"',
            $html
        );
        self::assertStringContainsString(
            'data-blog-category-quick-form',
            $html
        );
        self::assertStringContainsString(
            'data-blog-category-manager-open',
            $html
        );
        self::assertStringContainsString(
            'data-blog-category-manager aria-labelledby="'
                . 'blog-editor-category-manager-title"',
            $html
        );
        self::assertStringContainsString(
            'data-blog-category-manager-create',
            $html
        );
        self::assertStringNotContainsString('target="_blank"', $html);

        preg_match(
            '/<form method="post" action="\/admin\/blog\/categories\/assign"'
                . '[\s\S]+?<\/form>/',
            $html,
            $categoryForm
        );
        self::assertArrayHasKey(0, $categoryForm);
        self::assertStringNotContainsString(
            'name="locale"',
            $categoryForm[0]
        );
    }

    public function testRobotsControlsAreFormAssociatedAndDummyForcesSafeState(): void
    {
        $document = $this->document();
        $renderer = new BlogStructuredEditorHtmlRenderer();
        $html = $renderer->render(
            '/admin/blog',
            'csrf-token-safe',
            $this->variant($document),
            $document,
            (new BlogDocumentCodec())->encode($document)
        );

        self::assertStringContainsString(
            'data-blog-robots-controls data-blog-dummy-category="false"',
            $html
        );
        foreach (['index', 'follow'] as $directive) {
            self::assertStringContainsString(
                'name="robots_' . $directive . '" value="0" '
                    . 'form="blog-editor-form"',
                $html
            );
            self::assertMatchesRegularExpression(
                '/name="robots_' . $directive
                    . '" type="checkbox" value="1" '
                    . 'form="blog-editor-form" checked/',
                $html
            );
        }

        $dummyHtml = $renderer->render(
            '/admin/blog',
            'csrf-token-safe',
            $this->variant($document),
            $document,
            (new BlogDocumentCodec())->encode($document),
            dummyCategoryAssigned: true
        );
        self::assertStringContainsString(
            'data-blog-robots-controls data-blog-dummy-category="true"',
            $dummyHtml
        );
        self::assertSame(2, substr_count($dummyHtml, ' disabled '));
        self::assertStringNotContainsString('form="blog-editor-form" checked', $dummyHtml);
        self::assertStringContainsString(
            '<strong>noindex,nofollow</strong>',
            $dummyHtml
        );
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
            '/<button class="webadminAction webadminAction--secondary" '
                . 'type="submit" form="blog-editor-form" '
                . 'data-blog-editor-save disabled>Guardar documento<\/button>/',
            $html
        );
        self::assertStringContainsString('>Revisiones</a>', $html);
        self::assertStringNotContainsString('/editor/restore', $html);
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
        self::assertStringContainsString('>Revisiones</a>', $html);
        self::assertStringNotContainsString(
            '<li>No hay revisiones guardadas.</li>',
            $html
        );
    }

    public function testInternalMediaCatalogHasAStableIdAndIsNotSubmitted(): void
    {
        $document = $this->document();
        $html = (new BlogStructuredEditorHtmlRenderer())->render(
            '/admin/blog',
            'csrf-token-safe',
            $this->variant($document),
            $document,
            (new BlogDocumentCodec())->encode($document)
        );

        $matched = preg_match(
            '/<select\b[^>]*data-blog-media-catalog[^>]*>/',
            $html,
            $catalog
        );
        self::assertSame(1, $matched);
        self::assertStringContainsString(
            'id="blog-editor-media-catalog"',
            $catalog[0]
        );
        self::assertStringContainsString(' hidden', $catalog[0]);
        self::assertStringContainsString('aria-hidden="true"', $catalog[0]);
        self::assertStringContainsString('tabindex="-1"', $catalog[0]);
        self::assertStringNotContainsString(' name=', $catalog[0]);
    }

    public function testContextualMediaPickerAllowsViewWithoutGrantingUpload(): void
    {
        $document = $this->document();
        $mediaId = $this->id(900_001);
        $media = new BlogEditorMediaOption(
            $mediaId,
            'Portada Matrix',
            '/admin/media/file?asset=' . $mediaId . '&width=480'
        );
        $renderer = new BlogStructuredEditorHtmlRenderer();
        $withoutUpload = $renderer->render(
            '/admin/blog',
            'csrf-token-safe',
            $this->variant($document),
            $document,
            (new BlogDocumentCodec())->encode($document),
            [$media]
        );
        self::assertStringContainsString(
            'data-webadmin-media-picker-owner="blog-editor-form"',
            $withoutUpload
        );
        self::assertStringContainsString(
            'data-webadmin-media-picker-catalog="/admin/media/catalog"',
            $withoutUpload
        );
        self::assertStringNotContainsString(
            'data-webadmin-media-picker-upload',
            $withoutUpload
        );

        $html = $renderer->render(
            '/admin/blog',
            'csrf-token-safe',
            $this->variant($document),
            $document,
            (new BlogDocumentCodec())->encode($document),
            [$media],
            canUploadMedia: true
        );

        self::assertStringContainsString(
            'data-webadmin-media-picker-owner="blog-editor-form"',
            $html
        );
        self::assertStringContainsString(
            'action="/admin/media/upload"',
            $html
        );
        self::assertStringContainsString(
            'data-webadmin-media-picker-upload',
            $html
        );
        self::assertStringContainsString(
            '<input type="hidden" name="csrf" value="csrf-token-safe">',
            $html
        );
        self::assertStringContainsString(
            'data-webadmin-media-picker-progress hidden',
            $html
        );
        self::assertLessThan(
            strpos($html, 'data-webadmin-media-picker-owner'),
            strpos($html, '</form>')
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
        $category = new BlogEditorCategoryOption(
            $this->id(700_001),
            'Noticias',
            false
        );
        $invalidCollections = [
            [[$media, $media], [], []],
            [['not-an-option'], [], []],
            [[], [$revision, $revision], []],
            [[], ['not-a-summary'], []],
            [[2 => $media], [], []],
            [[], [], [$category, $category]],
            [[], [], ['not-a-category']],
            [[], [], [2 => $category]],
        ];
        foreach ($invalidCollections as [
            $mediaOptions,
            $summaries,
            $categoryOptions,
        ]) {
            $this->assertInvalidPresentation(fn (): string =>
                (new BlogStructuredEditorHtmlRenderer())->render(
                    '/admin/blog',
                    'csrf-token-safe',
                    $variant,
                    $document,
                    $canonical,
                    $mediaOptions,
                    $summaries,
                    categoryOptions: $categoryOptions
                )
            );
        }
    }

    public function testCategoryOptionsRespectTheBoundedCatalogLimit(): void
    {
        $document = $this->document();
        $variant = $this->variant($document);
        $canonical = (new BlogDocumentCodec())->encode($document);
        $categories = [];
        for (
            $index = 1;
            $index <= BlogStructuredEditorHtmlRenderer::MAX_CATEGORY_OPTIONS;
            ++$index
        ) {
            $categories[] = new BlogEditorCategoryOption(
                $this->id(700_000 + $index),
                'Category ' . $index,
                false
            );
        }

        $html = (new BlogStructuredEditorHtmlRenderer())->render(
            '/admin/blog',
            'csrf-token-safe',
            $variant,
            $document,
            $canonical,
            canAssignCategories: true,
            categoryOptions: $categories
        );
        self::assertStringContainsString(
            'value="' . $this->id(700_100) . '"',
            $html
        );

        $categories[] = new BlogEditorCategoryOption(
            $this->id(700_101),
            'One category too many',
            false
        );
        $this->assertInvalidPresentation(fn (): string =>
            (new BlogStructuredEditorHtmlRenderer())->render(
                '/admin/blog',
                'csrf-token-safe',
                $variant,
                $document,
                $canonical,
                canAssignCategories: true,
                categoryOptions: $categories
            )
        );
    }

    public function testMediaCatalogFitsRecentAndMaximumDocumentReferences(): void
    {
        $document = $this->document();
        $variant = $this->variant($document);
        $canonical = (new BlogDocumentCodec())->encode($document);
        $mediaOptions = [];
        for (
            $index = 1;
            $index <= BlogStructuredEditorHtmlRenderer::MAX_MEDIA_OPTIONS;
            ++$index
        ) {
            $mediaOptions[] = new BlogEditorMediaOption(
                $this->id(900_000 + $index),
                'Matrix media ' . $index
            );
        }

        $html = (new BlogStructuredEditorHtmlRenderer())->render(
            '/admin/blog',
            'csrf-token-safe',
            $variant,
            $document,
            $canonical,
            $mediaOptions
        );

        self::assertStringContainsString(
            'value="' . $this->id(900_248) . '"',
            $html
        );
        $mediaOptions[] = new BlogEditorMediaOption(
            $this->id(900_249),
            'One media too many'
        );
        $this->assertInvalidPresentation(fn (): string =>
            (new BlogStructuredEditorHtmlRenderer())->render(
                '/admin/blog',
                'csrf-token-safe',
                $variant,
                $document,
                $canonical,
                $mediaOptions
            )
        );
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
