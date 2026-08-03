<?php

declare(strict_types=1);

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Http\BlogPublicHttpController;
use App\Core\Blog\Http\BlogPublicHttpRuntime;
use App\Core\Blog\Http\BlogPublicHttpRuntimeException;
use App\Core\Blog\Http\BlogPublicHtmlRenderer;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Http\Request;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use PHPUnit\Framework\TestCase;

final class BlogPublicHttpControllerTest extends TestCase
{
    private PDO $pdo;
    private BlogService $service;
    private BlogPublicHttpRuntime $runtime;
    private BlogPublicHttpController $controller;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(
            PDO::ATTR_DEFAULT_FETCH_MODE,
            PDO::FETCH_ASSOC
        );
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $scope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $migration = null;
        foreach (BlogMigrationProvider::migrations() as $candidate) {
            if ($candidate->id() === '0001_blog_posts') {
                $migration = $candidate;
                break;
            }
        }
        self::assertInstanceOf(MigrationDefinition::class, $migration);
        foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
            $this->pdo->exec($sql);
        }

        $uuidGenerator = new class implements UuidGeneratorInterface {
            /** @var list<string> */
            private array $values = [
                '11111111-1111-4111-8111-111111111111',
                '22222222-2222-4222-8222-222222222222',
                '44444444-4444-4444-8444-444444444444',
                '55555555-5555-4555-8555-555555555555',
            ];

            public function generateV4(): string
            {
                return array_shift($this->values)
                    ?? throw new RuntimeException('UUID fixture exhausted.');
            }
        };
        $this->service = new BlogService(
            new PdoBlogRepository($this->pdo, $scope),
            $uuidGenerator
        );
        $config = new BlogConfig(
            [
                'es' => '/noticias',
                'en' => '/en/news',
                'eu' => '/eu/albisteak',
            ],
            '/blog-sitemap.xml',
            'ls_blog_',
            'fixture'
        );
        $this->runtime = new BlogPublicHttpRuntime(
            $config,
            BlogPublicOrigin::fromEnvironment([
                BlogPublicOrigin::ENV => 'https://example.test',
            ]),
            $this->service
        );
        $this->controller = new BlogPublicHttpController($this->runtime);
    }

    public function testUnknownAndDraftVariantsFallThrough(): void
    {
        self::assertNull($this->controller->article('es', 'unknown'));
        $this->service->createPost(
            $this->actorGate(),
            'es',
            $this->draft()
        );

        self::assertNull($this->controller->article('es', 'matrix'));
    }

    public function testPublishedArticleAndSitemapAreDbBacked(): void
    {
        $created = $this->service->createPost(
            $this->actorGate(),
            'es',
            $this->draft()
        );
        $published = $this->service->publish(
            $this->actorGate(),
            $created->postPublicId(),
            'es',
            $created->lockVersion()
        );

        $article = $this->controller->article('es', 'matrix');
        self::assertNotNull($article);
        self::assertSame(200, $article->status());
        self::assertSame(
            'text/html; charset=utf-8',
            $article->headers()['Content-Type']
        );
        self::assertStringContainsString(
            '<link rel="canonical" href="https://example.test/noticias/matrix">',
            $article->body()
        );
        self::assertStringContainsString(
            '<h1>Matrix &amp; sistemas</h1>',
            $article->body()
        );
        self::assertStringContainsString(
            '<script src="/assets/modules/blog/blog-public.js" defer></script>',
            $article->body()
        );
        self::assertStringContainsString(
            "default-src 'none'",
            $article->headers()['Content-Security-Policy']
        );
        self::assertStringContainsString(
            "style-src 'self'",
            $article->headers()['Content-Security-Policy']
        );
        self::assertStringContainsString(
            "script-src 'self'",
            $article->headers()['Content-Security-Policy']
        );
        self::assertStringContainsString(
            'frame-src https://www.youtube-nocookie.com',
            $article->headers()['Content-Security-Policy']
        );

        $sitemap = $this->controller->sitemap();
        self::assertSame(200, $sitemap->status());
        self::assertSame(
            'application/xml; charset=utf-8',
            $sitemap->headers()['Content-Type']
        );
        self::assertStringContainsString(
            '<loc>https://example.test/noticias/matrix</loc>',
            $sitemap->body()
        );

        $this->service->unpublish(
            $this->actorGate(),
            $published->postPublicId(),
            'es',
            $published->lockVersion()
        );
        self::assertNull($this->controller->article('es', 'matrix'));
        self::assertStringNotContainsString(
            '/noticias/matrix',
            $this->controller->sitemap()->body()
        );
    }

    public function testSitemapUsesAStrongConditionalEtagAndSafeHeaders(): void
    {
        $created = $this->service->createPost(
            $this->actorGate(),
            'es',
            $this->draft()
        );
        $this->service->publish(
            $this->actorGate(),
            $created->postPublicId(),
            'es',
            $created->lockVersion()
        );

        $fresh = $this->controller->sitemap($this->sitemapRequest('GET'));
        self::assertSame(200, $fresh->status());
        self::assertMatchesRegularExpression(
            '/\A"[0-9a-f]{64}"\z/',
            $fresh->headers()['ETag']
        );
        self::assertSame(
            (string) strlen($fresh->body()),
            $fresh->headers()['Content-Length']
        );
        self::assertSame(
            'public, no-cache, must-revalidate',
            $fresh->headers()['Cache-Control']
        );
        self::assertSame('DENY', $fresh->headers()['X-Frame-Options']);
        self::assertStringContainsString(
            "frame-ancestors 'none'",
            $fresh->headers()['Content-Security-Policy']
        );

        $etag = $fresh->headers()['ETag'];
        $notModified = $this->controller->sitemap(
            $this->sitemapRequest('GET', '"different", W/' . $etag)
        );
        self::assertSame(304, $notModified->status());
        self::assertSame('', $notModified->body());
        self::assertSame($etag, $notModified->headers()['ETag']);
        self::assertArrayNotHasKey(
            'Content-Length',
            $notModified->headers()
        );
        self::assertArrayNotHasKey('Content-Type', $notModified->headers());

        $wildcard = $this->controller->sitemap(
            $this->sitemapRequest('HEAD', '*')
        );
        self::assertSame(304, $wildcard->status());
        self::assertSame('', $wildcard->body());

        $nonMatching = $this->controller->sitemap(
            $this->sitemapRequest('GET', 'W/ "malformed"')
        );
        self::assertSame(200, $nonMatching->status());
        self::assertSame($etag, $nonMatching->headers()['ETag']);
    }

    public function testProjectShellOwnsCspWhileDefensiveHeadersRemain(): void
    {
        $created = $this->service->createPost(
            $this->actorGate(),
            'es',
            $this->draft()
        );
        $this->service->publish(
            $this->actorGate(),
            $created->postPublicId(),
            'es',
            $created->lockVersion()
        );
        $view = tempnam(sys_get_temp_dir(), 'liquidstack-blog-shell-');
        self::assertIsString($view);
        file_put_contents($view, <<<'PHP'
<?php
echo '<!doctype html><html lang="'
    . htmlspecialchars($blogArticle->locale(), ENT_QUOTES, 'UTF-8')
    . '"><body><h1>'
    . htmlspecialchars($blogArticle->h1(), ENT_QUOTES, 'UTF-8')
    . '</h1>' . $blogArticle->bodyHtml();
foreach ($blogArticle->alternateUrls() as $locale => $url) {
    echo '<a data-alternate="' . htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')
        . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></a>';
}
foreach ($blogArticle->languageNavigationUrls() as $locale => $url) {
    echo '<a data-language="' . htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')
        . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></a>';
}
echo '</body></html>';
PHP);

        try {
            $controller = new BlogPublicHttpController(
                $this->runtime,
                new BlogPublicHtmlRenderer($view)
            );
            $article = $controller->article('es', 'matrix');
            self::assertNotNull($article);
            self::assertSame(200, $article->status());
            self::assertArrayNotHasKey(
                'Content-Security-Policy',
                $article->headers()
            );
            self::assertSame(
                'DENY',
                $article->headers()['X-Frame-Options']
            );
            self::assertSame(
                'nosniff',
                $article->headers()['X-Content-Type-Options']
            );
            self::assertSame(
                'camera=(), microphone=(), geolocation=()',
                $article->headers()['Permissions-Policy']
            );
            self::assertStringContainsString(
                '<h1>Matrix &amp; sistemas</h1>',
                $article->body()
            );
            self::assertStringContainsString(
                'data-alternate="es" href="https://example.test/noticias/matrix"',
                $article->body()
            );
            self::assertStringNotContainsString(
                'data-alternate="en"',
                $article->body()
            );
            self::assertStringContainsString(
                'data-language="en" href="https://example.test/en/news"',
                $article->body()
            );
            self::assertStringContainsString(
                'data-language="eu" href="https://example.test/eu/albisteak"',
                $article->body()
            );
        } finally {
            @unlink($view);
        }
    }

    public function testBrokenProjectShellBecomesGenericRuntimeFailure(): void
    {
        $created = $this->service->createPost(
            $this->actorGate(),
            'es',
            $this->draft()
        );
        $this->service->publish(
            $this->actorGate(),
            $created->postPublicId(),
            'es',
            $created->lockVersion()
        );
        $view = tempnam(sys_get_temp_dir(), 'liquidstack-blog-shell-');
        self::assertIsString($view);
        file_put_contents(
            $view,
            "<?php throw new RuntimeException('private detail');\n"
        );

        try {
            $controller = new BlogPublicHttpController(
                $this->runtime,
                new BlogPublicHtmlRenderer($view)
            );
            $this->expectException(BlogPublicHttpRuntimeException::class);
            $controller->article('es', 'matrix');
        } finally {
            @unlink($view);
        }
    }

    public function testPublishedLocaleEquivalentsDriveArticleAndSitemapSeo(): void
    {
        $spanish = $this->service->createPost(
            $this->actorGate(),
            'es',
            $this->draft()
        );
        $english = $this->service->addLocalization(
            $this->actorGate(),
            $spanish->postPublicId(),
            'en',
            $this->localizedDraft(
                'matrix-en',
                'Matrix in English',
                'Matrix | English news'
            )
        );
        $this->service->addLocalization(
            $this->actorGate(),
            $spanish->postPublicId(),
            'eu',
            $this->localizedDraft(
                'matrix-eu',
                'Matrix euskaraz',
                'Matrix | Albisteak'
            )
        );
        $this->service->publish(
            $this->actorGate(),
            $spanish->postPublicId(),
            'es',
            $spanish->lockVersion()
        );
        $englishPublished = $this->service->publish(
            $this->actorGate(),
            $spanish->postPublicId(),
            'en',
            $english->lockVersion()
        );

        $article = $this->controller->article('en', 'matrix-en');
        self::assertNotNull($article);
        self::assertStringContainsString(
            '<title>Matrix | English news</title>',
            $article->body()
        );
        self::assertStringContainsString(
            '<meta name="description" content="Description for Matrix in English.">',
            $article->body()
        );
        self::assertStringContainsString(
            '<meta name="robots" content="index,follow">',
            $article->body()
        );
        self::assertStringContainsString(
            'hreflang="es" href="https://example.test/noticias/matrix"',
            $article->body()
        );
        self::assertStringContainsString(
            'hreflang="en" href="https://example.test/en/news/matrix-en"',
            $article->body()
        );
        self::assertStringContainsString(
            'hreflang="x-default" href="https://example.test/noticias/matrix"',
            $article->body()
        );
        self::assertStringNotContainsString('matrix-eu', $article->body());

        $sitemap = $this->controller->sitemap()->body();
        self::assertStringContainsString(
            'xmlns:xhtml="http://www.w3.org/1999/xhtml"',
            $sitemap
        );
        self::assertSame(2, substr_count(
            $sitemap,
            'hreflang="es" href="https://example.test/noticias/matrix"'
        ));
        self::assertSame(2, substr_count(
            $sitemap,
            'hreflang="en" href="https://example.test/en/news/matrix-en"'
        ));
        self::assertSame(2, substr_count(
            $sitemap,
            'hreflang="x-default" href="https://example.test/noticias/matrix"'
        ));
        self::assertStringNotContainsString('matrix-eu', $sitemap);

        $this->service->unpublish(
            $this->actorGate(),
            $spanish->postPublicId(),
            'en',
            $englishPublished->lockVersion()
        );
        self::assertNull($this->controller->article('en', 'matrix-en'));
        $spanishArticle = $this->controller->article('es', 'matrix');
        self::assertNotNull($spanishArticle);
        self::assertStringNotContainsString(
            'hreflang="en"',
            $spanishArticle->body()
        );
        self::assertStringNotContainsString(
            '/en/news/matrix-en',
            $this->controller->sitemap()->body()
        );
    }

    public function testPublishedArticleUsesTypedLocalOriginInDevelopment(): void
    {
        $created = $this->service->createPost(
            $this->actorGate(),
            'es',
            $this->draft()
        );
        $this->service->publish(
            $this->actorGate(),
            $created->postPublicId(),
            'es',
            $created->lockVersion()
        );
        $controller = new BlogPublicHttpController(
            new BlogPublicHttpRuntime(
                new BlogConfig(
                    ['es' => '/noticias'],
                    '/blog-sitemap.xml',
                    'ls_blog_',
                    'fixture'
                ),
                BlogPublicOrigin::fromEnvironment([
                    BlogPublicOrigin::PROJECT_ORIGIN_ENV =>
                        'http://localhost:1309',
                    'DEV_MODE' => '1',
                ]),
                $this->service
            )
        );

        $article = $controller->article('es', 'matrix');
        self::assertNotNull($article);
        self::assertSame(200, $article->status());
        self::assertStringContainsString(
            'href="http://localhost:1309/noticias/matrix"',
            $article->body()
        );
    }

    public function testXDefaultFallsBackToFirstPublishedLocale(): void
    {
        $english = $this->service->createPost(
            $this->actorGate(),
            'en',
            $this->localizedDraft(
                'matrix-en',
                'Matrix in English',
                'Matrix | English news'
            )
        );
        $this->service->publish(
            $this->actorGate(),
            $english->postPublicId(),
            'en',
            $english->lockVersion()
        );

        $article = $this->controller->article('en', 'matrix-en');
        self::assertNotNull($article);
        self::assertStringContainsString(
            'hreflang="x-default" href="https://example.test/en/news/matrix-en"',
            $article->body()
        );
        self::assertStringContainsString(
            'hreflang="x-default" href="https://example.test/en/news/matrix-en"',
            $this->controller->sitemap()->body()
        );
    }

    private function sitemapRequest(
        string $method,
        ?string $ifNoneMatch = null
    ): Request {
        $headers = $ifNoneMatch === null
            ? []
            : ['If-None-Match' => $ifNoneMatch];

        return Request::fromInput([
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => '/blog-sitemap.xml',
            'HTTPS' => 'on',
        ], headers: $headers);
    }

    private function draft(): BlogDraft
    {
        return new BlogDraft(
            'Matrix & sistemas',
            "Primer párrafo.\n\nSegundo párrafo.",
            'matrix',
            'Matrix | Noticias',
            'Descripción de Matrix.',
            'Extracto de Matrix.'
        );
    }

    private function localizedDraft(
        string $slug,
        string $h1,
        string $seoTitle
    ): BlogDraft {
        return new BlogDraft(
            $h1,
            'First paragraph.' . "\n\n" . 'Second paragraph.',
            $slug,
            $seoTitle,
            'Description for ' . $h1 . '.',
            'Excerpt for ' . $h1 . '.'
        );
    }

    /** @return Closure(PDO): string */
    private function actorGate(): Closure
    {
        return static fn (PDO $pdo): string =>
            '33333333-3333-4333-8333-333333333333';
    }
}
