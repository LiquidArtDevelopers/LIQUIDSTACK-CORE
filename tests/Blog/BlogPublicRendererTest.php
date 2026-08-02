<?php

declare(strict_types=1);

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogSitemapEntry;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Http\BlogPublicHtmlRenderer;
use App\Core\Blog\Http\BlogSitemapRenderer;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImageCandidate;
use PHPUnit\Framework\TestCase;

final class BlogPublicRendererTest extends TestCase
{
    public function testPublishedPlainTextIsEscapedAndSplitIntoParagraphs(): void
    {
        $variant = $this->variant(
            "First line\ncontinued\n\nSecond & final"
        );
        $html = (new BlogPublicHtmlRenderer())->render(
            $variant,
            'https://example.test/news/matrix'
        );

        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('<h1>Matrix &amp; systems</h1>', $html);
        self::assertStringContainsString(
            '<p>First line' . "\n" . 'continued</p>',
            $html
        );
        self::assertStringContainsString('<p>Second &amp; final</p>', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertSame(1, substr_count($html, '<h1>'));
        self::assertStringContainsString(
            '<meta name="robots" content="index,follow">',
            $html
        );
        self::assertStringContainsString(
            '<meta property="og:type" content="article">',
            $html
        );
        self::assertStringContainsString(
            '<meta property="og:title" content="Matrix &amp; title">',
            $html
        );
        self::assertStringContainsString(
            '<meta property="og:description" content="Matrix &quot;description&quot;">',
            $html
        );
        self::assertStringContainsString(
            '<meta property="og:url" content="https://example.test/news/matrix">',
            $html
        );
        self::assertStringContainsString(
            '<meta name="twitter:card" content="summary">',
            $html
        );
        self::assertStringContainsString(
            '<link rel="alternate" hreflang="en" href="https://example.test/news/matrix">',
            $html
        );
        self::assertStringContainsString(
            '<link rel="alternate" hreflang="x-default" href="https://example.test/news/matrix">',
            $html
        );
        self::assertStringNotContainsString('property="og:image"', $html);
        self::assertStringNotContainsString('name="twitter:image"', $html);
    }

    public function testSitemapUsesOnlyConfiguredCanonicalOriginAndSorts(): void
    {
        $origin = BlogPublicOrigin::fromEnvironment([
            BlogPublicOrigin::ENV => 'https://canonical.example.test',
            'HTTP_HOST' => 'attacker.invalid',
        ]);
        $config = new BlogConfig(
            ['en' => '/en/news', 'es' => '/noticias'],
            '/blog-sitemap.xml',
            'ls_blog_',
            'fixture',
            'shared',
            'es'
        );
        $xml = (new BlogSitemapRenderer())->render([
            new BlogSitemapEntry(
                'en',
                'zion',
                new DateTimeImmutable('2026-01-01T00:00:00Z'),
                new DateTimeImmutable('2026-01-03T12:30:00Z'),
                '11111111-1111-4111-8111-111111111111'
            ),
            new BlogSitemapEntry(
                'es',
                'matrix',
                new DateTimeImmutable('2026-01-01T00:00:00Z'),
                new DateTimeImmutable('2026-01-02T09:15:00Z'),
                '11111111-1111-4111-8111-111111111111'
            ),
        ], $config, $origin);

        self::assertStringContainsString(
            '<loc>https://canonical.example.test/en/news/zion</loc>',
            $xml
        );
        self::assertStringContainsString(
            '<loc>https://canonical.example.test/noticias/matrix</loc>',
            $xml
        );
        self::assertStringNotContainsString('attacker.invalid', $xml);
        self::assertLessThan(
            strpos($xml, '/noticias/matrix'),
            strpos($xml, '/en/news/zion')
        );
        self::assertStringContainsString(
            '<lastmod>2026-01-03T12:30:00Z</lastmod>',
            $xml
        );
        self::assertStringContainsString(
            'xmlns:xhtml="http://www.w3.org/1999/xhtml"',
            $xml
        );
        self::assertSame(2, substr_count(
            $xml,
            'hreflang="es" href="https://canonical.example.test/noticias/matrix"'
        ));
        self::assertSame(2, substr_count(
            $xml,
            'hreflang="en" href="https://canonical.example.test/en/news/zion"'
        ));
        self::assertSame(2, substr_count(
            $xml,
            'hreflang="x-default" href="https://canonical.example.test/noticias/matrix"'
        ));
    }

    public function testStructuredCoverProvidesAbsoluteSocialImageAndAlternates(): void
    {
        $asset = '44444444-4444-4444-8444-444444444444';
        $document = BlogDocument::fromArray([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::VERSION,
            'template' => 'article-cover-01',
            'blocks' => [[
                'id' => '55555555-5555-4555-8555-555555555555',
                'type' => 'image',
                'media_asset_public_id' => $asset,
                'alt' => 'Matrix cover',
                'title' => null,
                'caption' => null,
                'decorative' => false,
                'display' => 'cover',
            ]],
        ]);
        $resolver = new class ($asset) implements BlogImageResolverInterface {
            public function __construct(private readonly string $asset)
            {
            }

            public function resolve(string $mediaAssetPublicId): ?BlogResolvedImage
            {
                if ($mediaAssetPublicId !== $this->asset) {
                    return null;
                }

                return new BlogResolvedImage(
                    $this->asset,
                    [
                        new BlogResolvedImageCandidate('/media/480.avif', 480),
                        new BlogResolvedImageCandidate('/media/960.avif', 960),
                    ],
                    960,
                    640
                );
            }
        };
        $origin = BlogPublicOrigin::fromEnvironment([
            BlogPublicOrigin::ENV => 'https://example.test',
        ]);

        $html = (new BlogPublicHtmlRenderer())->renderStructuredFromOrigin(
            $this->variant('Matrix body'),
            $origin,
            '/en/news/matrix',
            $document,
            $resolver,
            [
                'es' => '/noticias/matrix',
                'en' => '/en/news/matrix',
            ],
            '/noticias/matrix'
        );

        self::assertStringContainsString(
            '<meta property="og:image" content="https://example.test/media/960.avif">',
            $html
        );
        self::assertStringContainsString(
            '<meta name="twitter:card" content="summary_large_image">',
            $html
        );
        self::assertStringContainsString(
            '<meta name="twitter:image" content="https://example.test/media/960.avif">',
            $html
        );
        self::assertStringContainsString(
            'hreflang="es" href="https://example.test/noticias/matrix"',
            $html
        );
        self::assertStringContainsString(
            'hreflang="x-default" href="https://example.test/noticias/matrix"',
            $html
        );
    }

    public function testTypedLoopbackOriginCanRenderInDevelopment(): void
    {
        $origin = BlogPublicOrigin::fromEnvironment([
            BlogPublicOrigin::PROJECT_ORIGIN_ENV =>
                'http://localhost:1309',
            'DEV_MODE' => '1',
        ]);
        $html = (new BlogPublicHtmlRenderer())->renderFromOrigin(
            $this->variant('Matrix body'),
            $origin,
            '/en/news/matrix'
        );

        self::assertStringContainsString(
            '<link rel="canonical" href="http://localhost:1309/en/news/matrix">',
            $html
        );
        self::assertStringContainsString(
            '<meta property="og:url" content="http://localhost:1309/en/news/matrix">',
            $html
        );
        self::assertStringContainsString(
            'hreflang="x-default" href="http://localhost:1309/en/news/matrix"',
            $html
        );
    }

    private function variant(string $body): BlogPostVariant
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

        return new BlogPostVariant(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            'en',
            new BlogDraft(
                'Matrix & systems',
                $body,
                'matrix',
                'Matrix & title',
                'Matrix "description"',
                'Matrix excerpt'
            ),
            BlogPostVariant::PUBLISHED,
            $now,
            1,
            '33333333-3333-4333-8333-333333333333',
            '33333333-3333-4333-8333-333333333333',
            $now,
            $now
        );
    }
}
