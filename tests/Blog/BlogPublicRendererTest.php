<?php

declare(strict_types=1);

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogSitemapEntry;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Http\BlogPublicHtmlRenderer;
use App\Core\Blog\Http\BlogSitemapRenderer;
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
    }

    public function testSitemapUsesOnlyConfiguredCanonicalOriginAndSorts(): void
    {
        $origin = BlogPublicOrigin::fromEnvironment([
            BlogPublicOrigin::ENV => 'https://canonical.example.test',
            'HTTP_HOST' => 'attacker.invalid',
        ]);
        $config = new BlogConfig(
            ['es' => '/noticias', 'en' => '/en/news'],
            '/blog-sitemap.xml',
            'ls_blog_',
            'fixture'
        );
        $xml = (new BlogSitemapRenderer())->render([
            new BlogSitemapEntry(
                'en',
                'zion',
                new DateTimeImmutable('2026-01-01T00:00:00Z'),
                new DateTimeImmutable('2026-01-03T12:30:00Z')
            ),
            new BlogSitemapEntry(
                'es',
                'matrix',
                new DateTimeImmutable('2026-01-01T00:00:00Z'),
                new DateTimeImmutable('2026-01-02T09:15:00Z')
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
                'Matrix title',
                'Matrix description',
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
