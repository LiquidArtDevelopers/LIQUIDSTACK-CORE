<?php

declare(strict_types=1);

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogException;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogSitemapEntry;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogAnalyticsConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Http\BlogPublicArticleShellContext;
use App\Core\Blog\Http\BlogPublicHtmlRenderer;
use App\Core\Blog\Http\BlogSitemapRenderer;
use App\Core\Blog\PublicShell\BlogPublicShellSecurityContext;
use App\Core\Blog\Seo\BlogRobotsPreferences;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImageCandidate;
use App\Core\WebAdmin\Profile\WebAdminPublicProfile;
use App\Core\WebAdmin\Profile\WebAdminTimeZone;
use PHPUnit\Framework\TestCase;

final class BlogPublicRendererTest extends TestCase
{
    public function testLiveAuthorProfileAndLocalizedDateAreRenderedInHero(): void
    {
        $profile = new WebAdminPublicProfile(
            '33333333-3333-4333-8333-333333333333',
            '<Igor & equipo>',
            'site_admin',
            'Administrador',
            WebAdminTimeZone::utc(),
            false,
            0
        );
        $html = (new BlogPublicHtmlRenderer())->render(
            $this->variant('Matrix body'),
            'https://example.test/news/matrix',
            authorProfile: $profile
        );

        self::assertStringContainsString(
            'class="blogArticleHero-signature"',
            $html
        );
        self::assertStringContainsString('&lt;Igor &amp; equipo&gt;', $html);
        self::assertStringContainsString('Administrador', $html);
        self::assertStringContainsString('UTC</time>', $html);
        self::assertStringNotContainsString('<Igor & equipo>', $html);
    }

    public function testPersistedRobotsPreferencesReachStandaloneAndProjectSsr(): void
    {
        $variant = $this->variant(
            'Robots body.',
            BlogRobotsPreferences::noIndexNoFollow()
        );
        $standalone = (new BlogPublicHtmlRenderer())->render(
            $variant,
            'https://example.test/news/robots'
        );
        self::assertStringContainsString(
            '<meta name="robots" content="noindex,nofollow">',
            $standalone
        );

        $view = tempnam(sys_get_temp_dir(), 'liquidstack-blog-robots-');
        self::assertIsString($view);
        file_put_contents($view, <<<'PHP'
<?php echo $blogArticle->robotsDirective();
PHP);
        try {
            self::assertSame(
                'noindex,nofollow',
                (new BlogPublicHtmlRenderer($view))->render(
                    $variant,
                    'https://example.test/news/robots'
                )
            );
        } finally {
            @unlink($view);
        }
    }

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
        self::assertStringContainsString(
            '<h1 class="moduleH1Type04-title">'
                . 'Matrix &amp; systems</h1>',
            $html
        );
        self::assertStringContainsString(
            '<p>First line' . "\n" . 'continued</p>',
            $html
        );
        self::assertStringContainsString('<p>Second &amp; final</p>', $html);
        self::assertStringContainsString(
            '<script src="/assets/modules/blog/blog-public.js" defer></script>',
            $html
        );
        self::assertSame(1, substr_count($html, '<h1'));
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
        self::assertStringContainsString(
            '<link rel="stylesheet" href="/assets/modules/blog/blog-public.css">',
            $html
        );
    }

    public function testStandaloneTaxonomiesAreNeutralAndPrecedeArticleBody(): void
    {
        $renderer = new BlogPublicHtmlRenderer();
        $empty = $renderer->render(
            $this->variant('Matrix body'),
            'https://example.test/news/matrix'
        );
        self::assertStringNotContainsString(
            'blogPublicArticleTaxonomies',
            $empty
        );
        $emptyXpath = $this->xpath($empty);
        self::assertCount(1, $emptyXpath->query('/html/body/main'));
        self::assertCount(0, $emptyXpath->query('/html/body/main/article'));
        self::assertCount(
            1,
            $emptyXpath->query('/html/body/main/p[text()="Matrix body"]')
        );

        foreach ([
            'categories' => [
                'categories' => [[
                    'locale' => 'en',
                    'slug' => 'strategy',
                    'name' => 'Strategy',
                ]],
            ],
            'tags' => [
                'tags' => [[
                    'locale' => 'en',
                    'slug' => 'artificial-intelligence',
                    'name' => 'AI <script>alert(1)</script>',
                ]],
            ],
            'both' => [
                'categories' => [
                    [
                        'locale' => 'en',
                        'slug' => 'z-strategy',
                        'name' => 'Strategy',
                    ],
                    [
                        'locale' => 'en',
                        'slug' => 'a-advisory',
                        'name' => 'Advisory',
                    ],
                ],
                'tags' => [
                    [
                        'locale' => 'en',
                        'slug' => 'z-artificial-intelligence',
                        'name' => 'Artificial intelligence',
                    ],
                    [
                        'locale' => 'en',
                        'slug' => 'a-automation',
                        'name' => 'Automation',
                    ],
                ],
            ],
        ] as $state => $taxonomies) {
            $html = $renderer->render(
                $this->variant('Matrix body'),
                'https://example.test/news/matrix',
                taxonomies: $taxonomies
            );
            $xpath = $this->xpath($html);
            self::assertCount(1, $xpath->query(
                '//main/div[contains(@class, '
                    . '"blogPublicArticleTaxonomies")]'
            ), $state);
            self::assertCount(0, $xpath->query(
                '//div[contains(@class, '
                    . '"blogPublicArticleTaxonomies")]//a'
            ), $state);
            self::assertGreaterThanOrEqual(1, $xpath->query(
                '//div[contains(@class, '
                    . '"blogPublicArticleTaxonomies")]//li/span[@dir="auto"]'
            )->count(), $state);
            self::assertCount(1, $xpath->query(
                '//main/div[contains(@class, '
                    . '"blogPublicArticleTaxonomies")]'
                    . '/following-sibling::p[text()="Matrix body"]'
            ), $state);
            self::assertStringNotContainsString('<script>alert(1)</script>', $html);
            if ($state === 'both') {
                self::assertSame('Advisory', $xpath->evaluate(
                    'string((//div[contains(@class, '
                        . '"blogPublicArticleTaxonomies__group--categories")]'
                        . '//li/span)[1])'
                ));
                self::assertSame('Automation', $xpath->evaluate(
                    'string((//div[contains(@class, '
                        . '"blogPublicArticleTaxonomies__group--tags")]'
                        . '//li/span)[1])'
                ));
            }
        }
    }

    public function testProjectViewReceivesTypedArticleAndShellProjections(): void
    {
        $view = tempnam(sys_get_temp_dir(), 'liquidstack-blog-view-');
        self::assertIsString($view);
        file_put_contents($view, <<<'PHP'
<?php
if (!$blogArticle instanceof \App\Core\Blog\Http\BlogPublicArticleViewModel) {
    throw new \RuntimeException('Unexpected model.');
}
if (!$blogArticleShell instanceof \App\Core\Blog\Http\BlogPublicArticleShellContext) {
    throw new \RuntimeException('Unexpected shell context.');
}
if ($blogArticleShell->security()->nonce() !== $blogArticleShell->nonce()) {
    throw new \RuntimeException('Incoherent shell context.');
}
$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
echo '<!doctype html><html lang="' . $escape($blogArticle->locale()) . '">';
echo '<head><title>' . $escape($blogArticle->seoTitle()) . '</title></head>';
echo '<body data-template="' . $escape($blogArticle->template()) . '"';
echo ' data-published="' . $blogArticle->publishedAt()->format(DATE_ATOM) . '"';
echo ' data-updated="' . $blogArticle->updatedAt()->format(DATE_ATOM) . '">';
echo '<h1>' . $escape($blogArticle->h1()) . '</h1>';
echo '<p>' . $escape($blogArticle->excerpt()) . '</p>';
echo '<a rel="canonical" href="' . $escape($blogArticle->canonicalUrl()) . '">';
echo $escape($blogArticle->xDefaultUrl()) . '</a>';
foreach ($blogArticle->alternateUrls() as $locale => $url) {
    echo '<a hreflang="' . $escape($locale) . '" href="' . $escape($url) . '"></a>';
}
foreach ($blogArticle->languageNavigationUrls() as $locale => $url) {
    echo '<a data-language="' . $escape($locale) . '" href="' . $escape($url) . '"></a>';
}
echo '<div data-cover="' . ($blogArticle->coverImageUrl() ?? '') . '">';
echo $blogArticle->bodyHtml() . '</div>';
echo '<script nonce="' . $escape($blogArticleShell->nonce()) . '" src="'
    . $escape($blogArticleShell->publicRuntimeUrl()) . '" defer></script>';
echo '</body></html>';
PHP);

        try {
            $renderer = new BlogPublicHtmlRenderer($view);
            self::assertTrue($renderer->usesProjectArticleView());
            $nonce = 'renderercontextnonce000001';
            $shellContext = new BlogPublicArticleShellContext(
                new BlogPublicShellSecurityContext($nonce, [
                    'Content-Security-Policy' =>
                        "default-src 'self'; script-src 'nonce-{$nonce}'",
                ])
            );
            $html = $renderer->render(
                $this->variant("First & \"quoted\"\n\nSecond & final"),
                'https://example.test/en/news/matrix',
                [
                    'es' => 'https://example.test/noticias/matrix',
                    'en' => 'https://example.test/en/news/matrix',
                ],
                'https://example.test/noticias/matrix',
                [
                    'es' => 'https://example.test/noticias/matrix',
                    'en' => 'https://example.test/en/news/matrix',
                    'eu' => 'https://example.test/eu/albisteak',
                ],
                shellContext: $shellContext
            );

            self::assertStringContainsString(
                '<title>Matrix &amp; title</title>',
                $html
            );
            self::assertStringContainsString(
                '<h1>Matrix &amp; systems</h1>',
                $html
            );
            self::assertStringContainsString(
                '<p>First &amp; &quot;quoted&quot;</p>',
                $html
            );
            self::assertStringContainsString(
                '<p>Second &amp; final</p>',
                $html
            );
            self::assertStringContainsString(
                'data-template="article-basic-01"',
                $html
            );
            self::assertStringContainsString(
                'data-published="2026-01-01T00:00:00+00:00"',
                $html
            );
            self::assertStringContainsString(
                'hreflang="es" href="https://example.test/noticias/matrix"',
                $html
            );
            self::assertStringNotContainsString('hreflang="eu"', $html);
            self::assertStringContainsString(
                'data-language="eu" href="https://example.test/eu/albisteak"',
                $html
            );
            self::assertStringContainsString(
                '<script nonce="' . $nonce . '" src="'
                    . BlogPublicArticleShellContext::PUBLIC_RUNTIME_URL
                    . '" defer></script>',
                $html
            );
            self::assertStringNotContainsString(
                BlogPublicHtmlRenderer::STANDALONE_STYLESHEET,
                $html
            );
        } finally {
            @unlink($view);
        }
    }

    public function testProjectViewExceptionAndEmptyOutputFailWithoutLeaks(): void
    {
        foreach ([
            "<?php\n",
            "<?php echo 'partial'; throw new RuntimeException('private');\n",
        ] as $contents) {
            $view = tempnam(sys_get_temp_dir(), 'liquidstack-blog-view-');
            self::assertIsString($view);
            file_put_contents($view, $contents);

            ob_start();
            try {
                (new BlogPublicHtmlRenderer($view))->render(
                    $this->variant('Matrix body'),
                    'https://example.test/en/news/matrix'
                );
                self::fail('An invalid project view must fail closed.');
            } catch (BlogException $exception) {
                self::assertSame(
                    BlogException::INVALID_STATE,
                    $exception->issueCode()
                );
            } finally {
                $leaked = ob_get_clean();
                @unlink($view);
            }

            self::assertSame('', $leaked);
        }
    }

    public function testProjectViewCanBindScopedCustomCssToShellNonce(): void
    {
        $document = BlogDocument::fromArray([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => 'article-basic-01',
            'blocks' => [[
                'id' => '40000000-0000-4000-8000-000000000001',
                'type' => 'section',
                'children' => [[
                    'id' => '40000000-0000-4000-8000-000000000002',
                    'type' => 'heading',
                    'level' => 2,
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Custom section',
                        'marks' => [],
                    ]],
                    'presentation' => [
                        'width' => 'full',
                        'align' => 'start',
                        'text_align' => 'start',
                    ],
                ], [
                    'id' => '40000000-0000-4000-8000-000000000003',
                    'type' => 'paragraph',
                    'html' => '<p class="lead">Custom article</p>',
                    'css' => 'color:#123;.lead{font-weight:700;}',
                    'presentation' => [
                        'width' => 'full',
                        'align' => 'start',
                        'text_align' => 'start',
                    ],
                ]],
            ]],
        ]);
        $resolver = new class implements BlogImageResolverInterface {
            public function resolve(string $mediaAssetPublicId): ?BlogResolvedImage
            {
                return null;
            }
        };
        $nonce = 'customcssshellnonce000001';
        $shellContext = new BlogPublicArticleShellContext(
            new BlogPublicShellSecurityContext($nonce, [
                'Content-Security-Policy' =>
                    "default-src 'self'; script-src 'nonce-{$nonce}'; "
                        . "style-src 'self' 'unsafe-inline'",
            ])
        );
        $view = tempnam(sys_get_temp_dir(), 'liquidstack-blog-css-shell-');
        self::assertIsString($view);
        file_put_contents($view, <<<'PHP'
<?php
$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
echo '<style nonce="' . $escape($blogArticleShell->nonce()) . '">'
    . $blogArticle->customCss() . '</style>';
echo '<script nonce="' . $escape($blogArticleShell->nonce()) . '" src="'
    . $escape($blogArticleShell->publicRuntimeUrl()) . '" defer></script>';
PHP);

        try {
            $html = (new BlogPublicHtmlRenderer($view))->renderStructured(
                $this->variant('Matrix body'),
                'https://example.test/en/news/matrix',
                $document,
                $resolver,
                styleNonce: $nonce,
                shellContext: $shellContext
            );
        } finally {
            @unlink($view);
        }

        self::assertStringContainsString(
            '<style nonce="' . $nonce . '">',
            $html
        );
        self::assertStringContainsString(
            '[data-ls-blog-custom="40000000-0000-4000-8000-000000000003"]',
            $html
        );
        self::assertStringContainsString(
            '<script nonce="' . $nonce . '" src="'
                . BlogPublicArticleShellContext::PUBLIC_RUNTIME_URL
                . '" defer></script>',
            $html
        );
        self::assertStringContainsString(
            "'nonce-{$nonce}'",
            $shellContext->security()->headers()['Content-Security-Policy']
        );
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

    public function testSitemapFailsClosedBeforeExceedingItsByteLimit(): void
    {
        $renderer = new BlogSitemapRenderer(maxDocumentBytes: 128);
        $entry = new BlogSitemapEntry(
            'es',
            'matrix',
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
            new DateTimeImmutable('2026-01-02T09:15:00Z'),
            '11111111-1111-4111-8111-111111111111'
        );

        try {
            $renderer->render(
                [$entry],
                new BlogConfig(
                    ['es' => '/noticias'],
                    '/blog-sitemap.xml',
                    'ls_blog_',
                    'fixture'
                ),
                BlogPublicOrigin::fromEnvironment([
                    BlogPublicOrigin::ENV =>
                        'https://canonical.example.test',
                ])
            );
            self::fail('An oversized sitemap must fail closed.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::SITEMAP_OVERFLOW,
                $exception->issueCode()
            );
        }

        self::assertSame(
            50 * 1024 * 1024,
            BlogSitemapRenderer::MAX_DOCUMENT_BYTES
        );
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
        $xpath = $this->xpath($html);
        self::assertCount(
            1,
            $xpath->query(
                '/html/body/header['
                    . 'contains(concat(" ", normalize-space(@class), " "), '
                    . '" blogArticleHero ")'
                    . ']/div['
                    . 'contains(concat(" ", normalize-space(@class), " "), '
                    . '" blogArticleHero-media ")'
                    . ']/figure['
                    . 'contains(concat(" ", normalize-space(@class), " "), '
                    . '" blogDocument__image--cover ")'
                    . ']'
            )
        );
        self::assertCount(
            0,
            $xpath->query(
                '/html/body/main//figure['
                    . 'contains(concat(" ", normalize-space(@class), " "), '
                    . '" blogDocument__image--cover ")'
                    . ']'
            )
        );
        self::assertCount(
            1,
            $xpath->query(
                '/html/body//figure['
                    . 'contains(concat(" ", normalize-space(@class), " "), '
                    . '" blogDocument__image--cover ")'
                    . ']'
            )
        );

        $view = tempnam(sys_get_temp_dir(), 'liquidstack-blog-view-');
        self::assertIsString($view);
        file_put_contents($view, <<<'PHP'
<?php
echo '<article data-template="'
    . htmlspecialchars($blogArticle->template(), ENT_QUOTES, 'UTF-8')
    . '" data-cover="'
    . htmlspecialchars((string) $blogArticle->coverImageUrl(), ENT_QUOTES, 'UTF-8')
    . '">' . $blogArticle->bodyHtml() . '</article>';
PHP);
        try {
            $projectHtml = (new BlogPublicHtmlRenderer($view))
                ->renderStructuredFromOrigin(
                    $this->variant('Matrix body'),
                    $origin,
                    '/en/news/matrix',
                    $document,
                    $resolver,
                    ['en' => '/en/news/matrix'],
                    '/en/news/matrix'
                );
            self::assertStringContainsString(
                'data-template="article-cover-01"',
                $projectHtml
            );
            self::assertStringContainsString(
                'data-cover="https://example.test/media/960.avif"',
                $projectHtml
            );
            self::assertStringContainsString(
                'class="blogDocument blogDocument--cover '
                    . 'blogDocument--background-white"',
                $projectHtml
            );
            self::assertSame(
                1,
                substr_count(
                    $projectHtml,
                    'blogDocument__image--cover'
                )
            );
        } finally {
            @unlink($view);
        }
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

    public function testAnalyticsMarkerIsExplicitAndOptInOnly(): void
    {
        $renderer = new BlogPublicHtmlRenderer();
        $disabled = $renderer->render(
            $this->variant('Matrix body'),
            'https://example.test/news/matrix'
        );
        $enabled = $renderer->render(
            $this->variant('Matrix body'),
            'https://example.test/news/matrix',
            analytics: new BlogAnalyticsConfig(true, 120, 2400),
            analyticsPageGrant: 'eyJ2IjoxfQ.' . str_repeat('a', 43)
        );

        self::assertStringNotContainsString(
            'data-blog-analytics-enabled',
            $disabled
        );
        self::assertStringContainsString(
            'data-blog-analytics-enabled="true"',
            $enabled
        );
        self::assertStringContainsString(
            'data-blog-analytics-retention-days="120"',
            $enabled
        );
        self::assertStringContainsString(
            'data-blog-analytics-session-timeout="2400"',
            $enabled
        );
        self::assertStringContainsString(
            'data-blog-analytics-page-grant="eyJ2IjoxfQ.',
            $enabled
        );
        self::assertStringNotContainsString(
            '<script src="/assets/modules/blog/blog-analytics.js"',
            $enabled
        );

        $nonce = 'analyticsshellnonce000001';
        $shellContext = new BlogPublicArticleShellContext(
            new BlogPublicShellSecurityContext($nonce, [
                'Content-Security-Policy' =>
                    "default-src 'self'; script-src 'nonce-{$nonce}'",
            ])
        );
        $view = tempnam(sys_get_temp_dir(), 'liquidstack-blog-analytics-');
        self::assertIsString($view);
        file_put_contents($view, <<<'PHP'
<?php
echo '<html';
if ($blogArticle->analyticsEnabled()) {
    echo ' data-blog-analytics-enabled="true"'
        . ' data-blog-analytics-retention-days="'
        . $blogArticle->analyticsRetentionDays() . '"'
        . ' data-blog-analytics-session-timeout="'
        . $blogArticle->analyticsSessionTimeoutSeconds() . '"'
        . ' data-blog-analytics-page-grant="'
        . htmlspecialchars(
            (string) $blogArticle->analyticsPageGrant(),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        ) . '"';
}
echo '><script nonce="'
    . htmlspecialchars(
        $blogArticleShell->nonce(),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    ) . '" src="'
    . htmlspecialchars(
        $blogArticleShell->publicRuntimeUrl(),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    ) . '" defer></script></html>';
PHP);
        try {
            $projectRenderer = new BlogPublicHtmlRenderer($view);
            $projectDisabled = $projectRenderer->render(
                $this->variant('Matrix body'),
                'https://example.test/news/matrix',
                shellContext: $shellContext
            );
            $projectEnabled = $projectRenderer->render(
                $this->variant('Matrix body'),
                'https://example.test/news/matrix',
                analytics: new BlogAnalyticsConfig(true, 120, 2400),
                analyticsPageGrant: 'eyJ2IjoxfQ.' . str_repeat('a', 43),
                shellContext: $shellContext
            );
        } finally {
            @unlink($view);
        }
        self::assertStringNotContainsString(
            'data-blog-analytics-enabled',
            $projectDisabled
        );
        foreach ([
            'data-blog-analytics-enabled="true"',
            'data-blog-analytics-retention-days="120"',
            'data-blog-analytics-session-timeout="2400"',
            'data-blog-analytics-page-grant="eyJ2IjoxfQ.',
            '<script nonce="' . $nonce . '" src="'
                . BlogPublicArticleShellContext::PUBLIC_RUNTIME_URL
                . '" defer>',
        ] as $marker) {
            self::assertStringContainsString($marker, $projectEnabled);
        }
    }

    private function variant(
        string $body,
        ?BlogRobotsPreferences $robotsPreferences = null
    ): BlogPostVariant
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
                'Matrix excerpt',
                $robotsPreferences
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

    private function xpath(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML($html, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        self::assertTrue($loaded);

        return new DOMXPath($document);
    }
}
