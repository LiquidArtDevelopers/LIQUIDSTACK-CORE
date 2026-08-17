<?php

declare(strict_types=1);

use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicIndexConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\PublicFeed\BlogPublicArchivePeriodsQuery;
use App\Core\Blog\PublicFeed\BlogPublicArchiveQuery;
use App\Core\Blog\PublicFeed\BlogPublicCatalogQuery;
use App\Core\Blog\PublicIndex\BlogPublicIndex;
use App\Core\Blog\PublicIndex\BlogPublicIndexBootstrap;
use App\Core\Blog\PublicIndex\BlogPublicIndexBootstrapOptions;
use App\Core\Blog\PublicIndex\BlogPublicIndexDefaultSecurityPolicy;
use App\Core\Blog\PublicIndex\BlogPublicIndexFeedInterface;
use App\Core\Blog\PublicIndex\BlogPublicIndexInput;
use App\Core\Blog\PublicIndex\BlogPublicIndexPreviewSourceInterface;
use App\Core\Blog\PublicIndex\BlogPublicIndexSecurityContext;
use App\Core\Blog\PublicIndex\BlogPublicIndexSecurityPolicyInterface;
use App\Core\Blog\PublicIndex\BlogPublicIndexTextCatalog;
use App\Core\Blog\PublicShell\BlogPublicShellSecurityConfig;
use App\Core\Blog\PublicShell\BlogPublicShellSecurityContext;
use App\Core\Blog\PublicShell\BlogPublicShellSecurityPolicyInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogPublicIndexBootstrapTest extends TestCase
{
    private string $fixtureRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-blog-index-bootstrap-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->fixtureRoot);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testGetPreparesHttpSecurityAndRenderablePage(): void
    {
        $feed = new PublicIndexBootstrapFeedFake($this->cards(13));
        $result = $this->bootstrap($feed)->resolve(
            $this->input('GET', '/es/noticias'),
            $this->copy(),
            new BlogPublicIndexBootstrapOptions(
                null,
                new PublicIndexBootstrapSecurityFake()
            )
        );

        self::assertFalse($result->shouldTerminate());
        self::assertNotNull($result->page());
        self::assertSame(BlogPublicIndex::STATE_READY, $result->page()?->state());
        self::assertSame(200, $result->response()->status());
        self::assertSame('', $result->response()->body());
        self::assertSame(12, $result->page()?->cardCount());
        self::assertSame(
            '/es/noticias/pagina/2#blog-results',
            $result->page()?->nextUrl()
        );
        self::assertSame(
            'public, no-cache, must-revalidate',
            $result->response()->headers()['Cache-Control']
        );
        self::assertSame(
            'X-LiquidStack-Partial',
            $result->response()->headers()['Vary']
        );
        self::assertSame(
            'index, follow',
            $result->response()->headers()['X-Robots-Tag']
        );
        self::assertSame(
            'text/html; charset=utf-8',
            $result->response()->headers()['Content-Type']
        );
        self::assertSame(
            'es',
            $result->response()->headers()['Content-Language']
        );
        self::assertSame(
            PublicIndexBootstrapSecurityFake::NONCE,
            $result->security()->nonce()
        );
        self::assertStringContainsString(
            "'nonce-" . PublicIndexBootstrapSecurityFake::NONCE . "'",
            $result->response()->headers()['Content-Security-Policy']
        );
    }

    public function testHeadRedirect404And503TerminateWithFullHeaders(): void
    {
        $options = new BlogPublicIndexBootstrapOptions(
            null,
            new PublicIndexBootstrapSecurityFake()
        );
        $bootstrap = $this->bootstrap(
            new PublicIndexBootstrapFeedFake($this->cards(13))
        );

        $head = $bootstrap->resolve(
            $this->input('HEAD', '/es/noticias'),
            $this->copy(),
            $options
        );
        self::assertTrue($head->shouldTerminate());
        self::assertTrue($head->page()?->isHeadRequest());
        self::assertSame(200, $head->response()->status());
        self::assertSame('', $head->response()->body());
        self::assertSame(
            'index, follow',
            $head->response()->headers()['X-Robots-Tag']
        );
        self::assertArrayHasKey(
            'Content-Security-Policy',
            $head->response()->headers()
        );

        $redirect = $bootstrap->resolve(
            $this->input(
                'HEAD',
                '/es/noticias?page=2',
                ['page' => '2']
            ),
            $this->copy(),
            $options
        );
        self::assertTrue($redirect->shouldTerminate());
        self::assertNull($redirect->page());
        self::assertSame(301, $redirect->response()->status());
        self::assertSame('', $redirect->response()->body());
        self::assertSame(
            '/es/noticias/pagina/2',
            $redirect->response()->headers()['Location']
        );
        self::assertArrayHasKey(
            'Content-Security-Policy',
            $redirect->response()->headers()
        );

        $notFound = $bootstrap->resolve(
            $this->input(
                'HEAD',
                '/es/noticias/pagina/999999',
                [],
                ['page' => '999999']
            ),
            $this->copy(),
            $options
        );
        self::assertTrue($notFound->shouldTerminate());
        self::assertSame(404, $notFound->response()->status());
        self::assertSame(
            'noindex, follow',
            $notFound->response()->headers()['X-Robots-Tag']
        );

        $unavailable = $this->bootstrap(null)->resolve(
            $this->input('HEAD', '/es/noticias'),
            $this->copy(),
            $options
        );
        self::assertTrue($unavailable->shouldTerminate());
        self::assertSame(503, $unavailable->response()->status());
        self::assertSame(
            '300',
            $unavailable->response()->headers()['Retry-After']
        );
        self::assertSame(
            'noindex, nofollow',
            $unavailable->response()->headers()['X-Robots-Tag']
        );
        self::assertArrayHasKey(
            'Content-Security-Policy',
            $unavailable->response()->headers()
        );
    }

    public function testProjectOptionsInjectPreviewAndSecurityOffView(): void
    {
        $path = $this->fixtureRoot . '/'
            . BlogPublicIndexBootstrapOptions::PROJECT_FILE;
        $this->filesystem->mkdir(dirname($path));
        $this->filesystem->dumpFile($path, <<<'PHP'
<?php

return [
    'preview' => new \PublicIndexBootstrapPreviewFake(),
    'security_sources' => [
        'script' => ['https://assets.example.test'],
        'style' => ['https://assets.example.test'],
        'image' => ['https://assets.example.test'],
        'connect' => ['https://api.example.test'],
    ],
];
PHP);

        $options = BlogPublicIndexBootstrapOptions::fromProject(
            $this->fixtureRoot
        );
        $result = $this->bootstrap(
            new PublicIndexBootstrapFeedFake()
        )->resolve(
            $this->input(
                'GET',
                '/es/noticias?qa_matrix=1',
                ['qa_matrix' => '1']
            ),
            $this->copy(),
            $options
        );

        self::assertInstanceOf(
            PublicIndexBootstrapPreviewFake::class,
            $options->preview()
        );
        self::assertInstanceOf(
            BlogPublicIndexDefaultSecurityPolicy::class,
            $options->securityPolicy()
        );
        self::assertStringContainsString(
            'https://assets.example.test',
            $result->response()->headers()['Content-Security-Policy']
        );
        self::assertStringContainsString(
            'https://api.example.test',
            $result->response()->headers()['Content-Security-Policy']
        );
        self::assertSame(BlogPublicIndex::STATE_READY, $result->page()?->state());
        self::assertSame(1, $result->page()?->cardCount());
        self::assertSame(
            '/admin/blog/editor/preview?post=fixture&locale=es',
            $result->page()?->cards()[0]['url'] ?? null
        );
        self::assertSame(
            'no-store, no-cache, must-revalidate',
            $result->response()->headers()['Cache-Control']
        );
    }

    public function testMissingOptionsUseInjectedEnvironmentPolicy(): void
    {
        $policy = new PublicIndexBootstrapSecurityFake();
        $options = BlogPublicIndexBootstrapOptions::fromProject(
            $this->fixtureRoot,
            $policy
        );

        self::assertNull($options->preview());
        self::assertSame($policy, $options->securityPolicy());
    }

    public function testIndexUsesSharedSecurityAlongsideItsPreviewConfig(): void
    {
        $sharedPath = $this->fixtureRoot . '/'
            . BlogPublicShellSecurityConfig::PROJECT_FILE;
        $indexPath = $this->fixtureRoot . '/'
            . BlogPublicIndexBootstrapOptions::PROJECT_FILE;
        $this->filesystem->mkdir(dirname($sharedPath));
        $this->filesystem->dumpFile($sharedPath, <<<'PHP'
<?php
return [
    'security_sources' => [
        'script' => ['https://webda.eus'],
        'style' => ['https://webda.eus'],
        'image' => ['https://webda.eus'],
        'connect' => ['https://webda.eus'],
    ],
];
PHP);
        $this->filesystem->dumpFile($indexPath, <<<'PHP'
<?php
return ['preview' => new \PublicIndexBootstrapPreviewFake()];
PHP);

        $options = BlogPublicIndexBootstrapOptions::fromProject(
            $this->fixtureRoot
        );
        $csp = $options->securityPolicy()
            ->context()
            ->headers()['Content-Security-Policy'];

        self::assertInstanceOf(
            PublicIndexBootstrapPreviewFake::class,
            $options->preview()
        );
        self::assertStringContainsString('https://webda.eus', $csp);
    }

    public function testSharedAndLegacyIndexSecurityCannotBothConfigure(): void
    {
        $sharedPath = $this->fixtureRoot . '/'
            . BlogPublicShellSecurityConfig::PROJECT_FILE;
        $indexPath = $this->fixtureRoot . '/'
            . BlogPublicIndexBootstrapOptions::PROJECT_FILE;
        $this->filesystem->mkdir(dirname($sharedPath));
        $this->filesystem->dumpFile(
            $sharedPath,
            "<?php return ['security_sources' => []];\n"
        );
        $this->filesystem->dumpFile(
            $indexPath,
            "<?php return ['security_sources' => []];\n"
        );

        $this->expectException(RuntimeException::class);
        BlogPublicIndexBootstrapOptions::fromProject($this->fixtureRoot);
    }

    public function testSharedPolicyRetainsLegacyIndexResultContext(): void
    {
        $sharedPath = $this->fixtureRoot . '/'
            . BlogPublicShellSecurityConfig::PROJECT_FILE;
        $this->filesystem->mkdir(dirname($sharedPath));
        $this->filesystem->dumpFile($sharedPath, <<<'PHP'
<?php
return [
    'security_policy' => new \PublicIndexSharedSecurityFake(),
];
PHP);

        $result = $this->bootstrap(
            new PublicIndexBootstrapFeedFake()
        )->resolve(
            $this->input('GET', '/es/noticias'),
            $this->copy(),
            BlogPublicIndexBootstrapOptions::fromProject($this->fixtureRoot)
        );

        self::assertInstanceOf(
            BlogPublicIndexSecurityContext::class,
            $result->security()
        );
        self::assertSame(
            PublicIndexSharedSecurityFake::NONCE,
            $result->security()->nonce()
        );
    }

    /** @dataProvider invalidOptionsProvider */
    public function testInvalidProjectOptionsFailClosed(string $contents): void
    {
        $path = $this->fixtureRoot . '/'
            . BlogPublicIndexBootstrapOptions::PROJECT_FILE;
        $this->filesystem->mkdir(dirname($path));
        $this->filesystem->dumpFile($path, $contents);

        $this->expectException(RuntimeException::class);
        BlogPublicIndexBootstrapOptions::fromProject($this->fixtureRoot);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOptionsProvider(): iterable
    {
        yield 'list' => ["<?php return ['invalid'];\n"];
        yield 'unknown key' => ["<?php return ['secret' => true];\n"];
        yield 'invalid preview' => [
            "<?php return ['preview' => new stdClass()];\n",
        ];
        yield 'invalid security policy' => [
            "<?php return ['security_policy' => new stdClass()];\n",
        ];
        yield 'security policy and sources conflict' => [
            "<?php return ["
                . "'security_policy' => new \\PublicIndexBootstrapSecurityFake(),"
                . "'security_sources' => []];\n",
        ];
        yield 'unsafe production HTTP source' => [
            "<?php return ['security_sources' => ["
                . "'script' => ['http://evil.example']]];\n",
        ];
        yield 'loopback source cannot be enabled in production' => [
            "<?php return ['security_sources' => ["
                . "'script' => ['http://localhost:5173']]];\n",
        ];
        yield 'HTTPS loopback cannot be enabled in production' => [
            "<?php return ['security_sources' => ["
                . "'script' => ['https://localhost:5173']]];\n",
        ];
        yield 'secure websocket loopback cannot be enabled in production' => [
            "<?php return ['security_sources' => ["
                . "'connect' => ['wss://localhost:5173']]];\n",
        ];
        yield 'output' => ["<?php echo 'leak'; return [];\n"];
    }

    public function testDefaultSecurityPolicyHasProductionAndLoopbackModes(): void
    {
        $production = (new BlogPublicIndexDefaultSecurityPolicy())->context();
        self::assertStringContainsString(
            'upgrade-insecure-requests',
            $production->headers()['Content-Security-Policy']
        );
        self::assertStringNotContainsString(
            'localhost:5173',
            $production->headers()['Content-Security-Policy']
        );

        $development = BlogPublicIndexDefaultSecurityPolicy::fromEnvironment([
            'DEV_MODE' => '1',
            'RAIZ' => 'http://localhost:1309',
        ])->context();
        self::assertStringContainsString(
            'http://localhost:5173',
            $development->headers()['Content-Security-Policy']
        );
        self::assertStringContainsString(
            'ws://localhost:5173',
            $development->headers()['Content-Security-Policy']
        );
        self::assertStringNotContainsString(
            'upgrade-insecure-requests',
            $development->headers()['Content-Security-Policy']
        );
    }

    public function testProjectSourcesExtendDevelopmentPolicyWithoutLosingVite(): void
    {
        $path = $this->fixtureRoot . '/'
            . BlogPublicIndexBootstrapOptions::PROJECT_FILE;
        $this->filesystem->mkdir(dirname($path));
        $this->filesystem->dumpFile($path, <<<'PHP'
<?php

return [
    'security_sources' => [
        'script' => ['https://webda.eus'],
        'style' => ['https://webda.eus'],
        'connect' => ['https://webda.eus'],
    ],
];
PHP);

        $default = BlogPublicIndexDefaultSecurityPolicy::fromEnvironment([
            'DEV_MODE' => '1',
            'RAIZ' => 'http://localhost:1309',
        ]);
        $options = BlogPublicIndexBootstrapOptions::fromProject(
            $this->fixtureRoot,
            $default
        );
        $csp = $options->securityPolicy()
            ->context()
            ->headers()['Content-Security-Policy'];

        self::assertStringContainsString('https://webda.eus', $csp);
        self::assertStringContainsString('http://localhost:5173', $csp);
        self::assertStringContainsString('ws://localhost:5173', $csp);
        self::assertStringNotContainsString(
            'upgrade-insecure-requests',
            $csp
        );
    }

    private function bootstrap(
        ?BlogPublicIndexFeedInterface $feed
    ): BlogPublicIndexBootstrap {
        return new BlogPublicIndexBootstrap(new BlogPublicIndex(
            new BlogConfig(
                publicPaths: [
                    'es' => '/es/noticias',
                    'en' => '/en/news',
                ],
                sitemapPath: '/blog-sitemap.xml',
                tablePrefix: 'test_blog_',
                source: 'test',
                defaultLocale: 'es',
                publicIndex: new BlogPublicIndexConfig([
                    'es' => '/es/noticias/pagina/{page}',
                    'en' => '/en/news/page/{page}',
                ])
            ),
            BlogPublicOrigin::fromEnvironment([
                BlogPublicOrigin::ENV => 'https://example.test',
            ]),
            $feed
        ));
    }

    /**
     * @param array<string|int, mixed> $query
     * @param array<string|int, mixed> $route
     */
    private function input(
        string $method,
        string $uri,
        array $query = [],
        array $route = []
    ): BlogPublicIndexInput {
        return new BlogPublicIndexInput('es', $query, $route, [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
        ]);
    }

    private function copy(): BlogPublicIndexTextCatalog
    {
        return BlogPublicIndexTextCatalog::fromGlobals([
            'title' => (object) ['text' => 'Noticias'],
            'description' => (object) ['content' => 'Actualidad.'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function cards(int $count): array
    {
        $cards = [];
        for ($index = 0; $index < $count; ++$index) {
            $number = $index + 1;
            $cards[] = [
                'locale' => 'es',
                'slug' => 'post-' . $number,
                'url' => '/es/noticias/post-' . $number,
                'h1' => 'Post ' . $number,
                'excerpt' => 'Resumen ' . $number,
                'published_at' => sprintf(
                    '2026-08-%02dT10:00:00+00:00',
                    31 - $index
                ),
                'updated_at' => sprintf(
                    '2026-08-%02dT10:00:00+00:00',
                    31 - $index
                ),
            ];
        }

        return $cards;
    }
}

final class PublicIndexBootstrapSecurityFake implements
    BlogPublicIndexSecurityPolicyInterface
{
    public const NONCE = 'abcdefghijklmnopqrstuvwx';

    public function context(): BlogPublicIndexSecurityContext
    {
        return new BlogPublicIndexSecurityContext(self::NONCE, [
            'Content-Security-Policy' =>
                "default-src 'self'; script-src 'nonce-"
                    . self::NONCE . "'",
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}

final class PublicIndexSharedSecurityFake implements
    BlogPublicShellSecurityPolicyInterface
{
    public const NONCE = 'sharedindexnoncevalue000001';

    public function context(): BlogPublicShellSecurityContext
    {
        return new BlogPublicShellSecurityContext(self::NONCE, [
            'Content-Security-Policy' =>
                "default-src 'self'; script-src 'nonce-"
                    . self::NONCE . "'",
        ]);
    }
}

final class PublicIndexBootstrapPreviewFake implements
    BlogPublicIndexPreviewSourceInterface
{
    public function queryParameter(): string
    {
        return 'qa_matrix';
    }

    public function queryValue(): string
    {
        return '1';
    }

    public function cards(string $locale): array
    {
        return [[
            'locale' => 'es',
            'slug' => 'fixture',
            'url' => '/admin/blog/editor/preview?post=fixture&locale=es',
            'h1' => 'Fixture',
            'excerpt' => 'Preview fixture',
            'published_at' => '2026-08-31T10:00:00+00:00',
            'updated_at' => '2026-08-31T10:00:00+00:00',
        ]];
    }
}

final class PublicIndexBootstrapFeedFake implements
    BlogPublicIndexFeedInterface
{
    /** @param list<array<string, mixed>> $cards */
    public function __construct(private readonly array $cards = [])
    {
    }

    public function filters(string $locale): array
    {
        return [];
    }

    public function cardsForQuery(BlogPublicCatalogQuery $query): array
    {
        return array_slice(
            $this->cards,
            $query->offset(),
            $query->limit()
        );
    }

    public function cardsForArchive(BlogPublicArchiveQuery $query): array
    {
        return [];
    }

    public function archivePeriods(
        BlogPublicArchivePeriodsQuery $query
    ): array {
        return [];
    }
}
