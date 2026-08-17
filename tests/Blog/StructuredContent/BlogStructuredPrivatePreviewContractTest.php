<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\Http\BlogStructuredEditorHttpResponseFactory;
use App\Core\Blog\Preview\BlogPreviewAssetContext;
use App\Core\Blog\Preview\BlogPreviewAssetSet;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Document\BlogSafeIframePolicy;
use App\Core\Blog\StructuredContent\Rendering\BlogDocumentHtmlRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;
use App\Core\Blog\StructuredContent\Rendering\BlogStructuredPrivateHtmlRenderer;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BlogStructuredPrivatePreviewContractTest extends TestCase
{
    public function testPreviewIsPureLocalizedSmoothDocument(): void
    {
        $documents = new BlogDocumentHtmlRenderer(
            new class implements BlogImageResolverInterface {
                public function resolve(string $mediaAssetPublicId): ?BlogResolvedImage
                {
                    return null;
                }
            }
        );
        $renderer = new BlogStructuredPrivateHtmlRenderer($documents);
        $variant = new BlogPostVariant(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            'eu',
            new BlogDraft('Matrix euskaraz', ''),
            BlogPostVariant::DRAFT,
            null,
            1,
            '33333333-3333-4333-8333-333333333333',
            '33333333-3333-4333-8333-333333333333',
            new DateTimeImmutable('2030-01-01 10:00:00 UTC'),
            new DateTimeImmutable('2030-01-01 10:00:00 UTC')
        );
        $document = BlogDocument::fromArray([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => [],
        ]);
        $assets = new BlogPreviewAssetSet(
            new BlogPreviewAssetContext(dirname(__DIR__, 3), false),
            ['/assets/css/blog-article.abc123.css'],
            ['/assets/js/blog-article.abc123.js'],
            ['/assets/modules/blog/blog-public.js']
        );

        $nonce = 'abcdefghijklmnopQRSTUVWX';
        $html = $renderer->preview(
            '/admin/blog',
            $variant,
            $document,
            null,
            $assets,
            $nonce
        );

        self::assertStringStartsWith(
            '<!doctype html><html lang="eu" '
                . 'data-blog-preview-ready="true">',
            $html
        );
        self::assertSame(1, substr_count(
            $html,
            'data-blog-preview-ready="true"'
        ));
        self::assertStringContainsString(
            '<div id="smooth-wrapper"><div id="smooth-content">',
            $html
        );
        self::assertStringContainsString(
            '<main class="blogArticleMain blog-article artBlogArticle01 '
                . 'artBlogArticle01--basic">',
            $html
        );
        self::assertStringContainsString(
            '<body class="blog-article-page blogPreviewDocument">',
            $html
        );
        self::assertStringNotContainsString('blogPreviewNotice', $html);
        self::assertStringNotContainsString('Volver al editor', $html);
        self::assertStringNotContainsString('Idioma EU', $html);
        self::assertStringContainsString(
            '<link rel="stylesheet" href="/assets/css/blog-article.abc123.css">',
            $html
        );
        self::assertStringContainsString(
            '<meta property="csp-nonce" nonce="' . $nonce . '">',
            $html
        );
        self::assertStringContainsString(
            '<script type="module" src="/assets/js/blog-article.abc123.js"></script>',
            $html
        );
        self::assertStringNotContainsString(
            '/assets/modules/blog/blog-admin.css',
            $html
        );

        $revisions = $renderer->revisions(
            '/admin/blog',
            $variant,
            []
        );
        self::assertStringNotContainsString(
            'data-blog-preview-ready',
            $revisions
        );
    }

    public function testPreviewResponseUsesTheVariantContentLanguage(): void
    {
        $factory = new BlogStructuredEditorHttpResponseFactory(
            new WebAdminConfig(
                '/admin',
                'ls_webadmin_',
                'LS_WEBADMIN_SID',
                300,
                3600,
                'test'
            )
        );

        $assets = BlogPreviewAssetSet::standalone(
            new BlogPreviewAssetContext(dirname(__DIR__, 3), false)
        );
        $response = $factory->previewHtml(
            200,
            '<p>Gordeta</p>',
            'eu',
            $assets
        );

        self::assertSame('eu', $response->headers()['Content-Language']);
        self::assertSame('SAMEORIGIN', $response->headers()['X-Frame-Options']);
        self::assertStringContainsString(
            "frame-ancestors 'self'",
            $response->headers()['Content-Security-Policy']
        );
        foreach (BlogSafeIframePolicy::cspSources() as $source) {
            self::assertStringContainsString(
                $source,
                $response->headers()['Content-Security-Policy']
            );
        }
    }
}
