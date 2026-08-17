<?php

declare(strict_types=1);

use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicIndexConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Http\BlogPublicHttpRuntimeFactoryInterface;
use App\Core\Blog\PublicFeed\BlogPublicArchivePeriodsQuery;
use App\Core\Blog\PublicFeed\BlogPublicArchiveQuery;
use App\Core\Blog\PublicFeed\BlogPublicCatalogQuery;
use App\Core\Blog\PublicIndex\BlogPublicIndex;
use App\Core\Blog\PublicIndex\BlogPublicIndexFeedInterface;
use App\Core\Blog\PublicIndex\BlogPublicIndexInput;
use App\Core\Blog\PublicIndex\BlogPublicIndexPreviewSourceInterface;
use App\Core\Blog\PublicIndex\BlogPublicIndexTextCatalog;
use App\Core\Modules\ModuleRuntimeContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogPublicIndexTest extends TestCase
{
    public function testCardCtaCopyIsLocalizedAndHasASafeFallback(): void
    {
        $localizedCopy = BlogPublicIndexTextCatalog::fromGlobals([
            'blog_index_card_cta' => (object) ['text' => 'Leer más'],
        ]);
        $page = $this->service(new PublicIndexFeedFake())->resolve(
            $this->input('es', '/es/noticias'),
            $localizedCopy
        )->page();

        self::assertNotNull($page);
        self::assertSame('Leer más', $page->cardCtaLabel());
        self::assertSame(
            'Read more',
            BlogPublicIndexTextCatalog::fromGlobals([])->cardCtaLabel()
        );
    }

    public function testCleanCatalogUsesLookaheadAndProjectsSeo(): void
    {
        $feed = new PublicIndexFeedFake(
            queryCards: $this->cards(13),
            periods: [[
                'locale' => 'es',
                'year' => 2026,
                'month' => 8,
                'count' => 13,
            ]]
        );
        $service = $this->service($feed);

        $first = $service->resolve(
            $this->input('es', '/es/noticias'),
            $this->copy()
        )->page();

        self::assertNotNull($first);
        self::assertSame(BlogPublicIndex::STATE_READY, $first->state());
        self::assertSame(200, $first->statusCode());
        self::assertSame(12, $first->cardCount());
        self::assertSame(
            '/es/noticias/pagina/2#blog-results',
            $first->nextUrl()
        );
        self::assertNull($first->previousUrl());
        self::assertSame([
            ['page' => 1, 'current' => true],
            [
                'page' => 2,
                'url' => '/es/noticias/pagina/2#blog-results',
            ],
        ], $first->paginationPages());
        self::assertSame(
            'https://example.test/es/noticias',
            $first->canonicalUrl()
        );
        self::assertSame('index, follow', $first->robotsDirective());
        self::assertSame(
            'public, no-cache, must-revalidate',
            $first->headers()['Cache-Control']
        );
        self::assertSame(
            'X-LiquidStack-Partial',
            $first->headers()['Vary']
        );
        self::assertSame([
            'es' => 'https://example.test/es/noticias',
            'en' => 'https://example.test/en/news',
        ], $first->pageMeta()['alternates']);
        self::assertSame(
            'https://example.test/es/noticias',
            $first->pageMeta()['x_default']
        );
        self::assertSame(13, $feed->queryCalls[0]->limit());
        self::assertSame(0, $feed->queryCalls[0]->offset());

        $second = $service->resolve(
            $this->input(
                'es',
                '/es/noticias/pagina/2',
                [],
                ['page' => '2']
            ),
            $this->copy()
        )->page();

        self::assertNotNull($second);
        self::assertSame(BlogPublicIndex::STATE_READY, $second->state());
        self::assertSame(1, $second->cardCount());
        self::assertSame(
            '/es/noticias#blog-results',
            $second->previousUrl()
        );
        self::assertNull($second->nextUrl());
        self::assertSame(
            'https://example.test/es/noticias/pagina/2',
            $second->canonicalUrl()
        );
        self::assertSame('Noticias — Página 2', $second->title());
        self::assertSame([], $second->pageMeta()['alternates']);
        self::assertSame(12, $feed->queryCalls[1]->offset());
    }

    public function testLegacyQueryAndExplicitPageOneRedirectPermanently(): void
    {
        $service = $this->service(new PublicIndexFeedFake());

        $legacy = $service->resolve(
            $this->input(
                'es',
                '/es/noticias?page=2&q=matrix',
                ['page' => '2', 'q' => 'matrix']
            ),
            $this->copy()
        );

        self::assertSame(301, $legacy->statusCode());
        self::assertNull($legacy->page());
        self::assertSame(
            '/es/noticias/pagina/2?q=matrix',
            $legacy->headers()['Location']
        );
        self::assertSame('noindex, follow', $legacy->headers()['X-Robots-Tag']);
        self::assertSame(
            'X-LiquidStack-Partial',
            $legacy->headers()['Vary']
        );

        $explicitOne = $service->resolve(
            $this->input(
                'es',
                '/es/noticias/pagina/1',
                [],
                ['page' => '1']
            ),
            $this->copy()
        );

        self::assertSame(301, $explicitOne->statusCode());
        self::assertSame(
            '/es/noticias',
            $explicitOne->headers()['Location']
        );
    }

    public function testFilterUrlsPreserveStateAndUnknownCategoryIs404(): void
    {
        $feed = new PublicIndexFeedFake(
            queryCards: $this->cards(13),
            filters: [[
                'locale' => 'es',
                'slug' => 'actualidad',
                'name' => 'Actualidad',
                'count' => 13,
            ]]
        );
        $service = $this->service($feed);
        $query = [
            'q' => 'criterio financiero',
            'category' => ['actualidad'],
            'category_mode' => 'all',
            'order' => 'oldest',
        ];

        $page = $service->resolve(
            $this->input(
                'es',
                '/es/noticias?' . http_build_query($query),
                $query
            ),
            $this->copy()
        )->page();

        self::assertNotNull($page);
        self::assertSame(BlogPublicIndex::STATE_READY, $page->state());
        self::assertSame('noindex, follow', $page->robotsDirective());
        self::assertSame(
            '/es/noticias/pagina/2?'
                . 'q=criterio%20financiero&category%5B0%5D=actualidad'
                . '&category_mode=all&order=oldest#blog-results',
            $page->nextUrl()
        );
        self::assertSame(
            ['actualidad'],
            $feed->queryCalls[0]->categorySlugs()
        );
        self::assertSame('all', $feed->queryCalls[0]->categoryMode());

        $unknown = $service->resolve(
            $this->input(
                'es',
                '/es/noticias?category=missing',
                ['category' => 'missing']
            ),
            $this->copy()
        )->page();

        self::assertNotNull($unknown);
        self::assertSame(
            BlogPublicIndex::STATE_NOT_FOUND,
            $unknown->state()
        );
        self::assertSame(404, $unknown->statusCode());
        self::assertSame([], $unknown->cards());
    }

    public function testArchiveIsLocalizedAndConflictsFailClosed(): void
    {
        $feed = new PublicIndexFeedFake(
            archiveCards: $this->cards(13, 'archive'),
            periods: [[
                'locale' => 'es',
                'year' => 2026,
                'month' => 8,
                'count' => 13,
            ]]
        );
        $service = $this->service($feed);

        $page = $service->resolve(
            $this->input(
                'es',
                '/es/noticias?year=2026&month=8',
                ['year' => '2026', 'month' => '8']
            ),
            $this->copy()
        )->page();

        self::assertNotNull($page);
        self::assertSame(BlogPublicIndex::STATE_READY, $page->state());
        self::assertSame([[
            'url' => '/es/noticias?year=2026&month=8',
            'label' => 'agosto de 2026',
            'count' => 13,
            'active' => true,
        ]], $page->archivePeriods());
        self::assertSame(
            '/es/noticias/pagina/2?year=2026&month=8#blog-results',
            $page->nextUrl()
        );
        self::assertSame(2026, $feed->archiveCalls[0]->year());
        self::assertSame(8, $feed->archiveCalls[0]->month());

        $conflict = $service->resolve(
            $this->input(
                'es',
                '/es/noticias?year=2026&q=matrix',
                ['year' => '2026', 'q' => 'matrix']
            ),
            $this->copy()
        )->page();

        self::assertNotNull($conflict);
        self::assertSame(
            BlogPublicIndex::STATE_NOT_FOUND,
            $conflict->state()
        );
        self::assertSame(404, $conflict->statusCode());
    }

    public function testEmptyNoResultsNotFoundAndUnavailableHaveHttpPolicy(): void
    {
        $emptyService = $this->service(new PublicIndexFeedFake());
        $empty = $emptyService->resolve(
            $this->input('es', '/es/noticias'),
            $this->copy()
        )->page();
        self::assertNotNull($empty);
        self::assertSame(BlogPublicIndex::STATE_EMPTY, $empty->state());
        self::assertSame(200, $empty->statusCode());
        self::assertSame('noindex, follow', $empty->robotsDirective());

        $noResults = $emptyService->resolve(
            $this->input(
                'es',
                '/es/noticias?q=matrix',
                ['q' => 'matrix']
            ),
            $this->copy()
        )->page();
        self::assertNotNull($noResults);
        self::assertSame(
            BlogPublicIndex::STATE_NO_RESULTS,
            $noResults->state()
        );
        self::assertSame(200, $noResults->statusCode());

        $missingPage = $emptyService->resolve(
            $this->input(
                'es',
                '/es/noticias/pagina/2',
                [],
                ['page' => '2']
            ),
            $this->copy()
        )->page();
        self::assertNotNull($missingPage);
        self::assertSame(
            BlogPublicIndex::STATE_NOT_FOUND,
            $missingPage->state()
        );
        self::assertSame(404, $missingPage->statusCode());

        $failingFeed = new PublicIndexFeedFake();
        $failingFeed->fail = true;
        $unavailable = $this->service($failingFeed)->resolve(
            $this->input('es', '/es/noticias'),
            $this->copy()
        )->page();
        self::assertNotNull($unavailable);
        self::assertSame(
            BlogPublicIndex::STATE_UNAVAILABLE,
            $unavailable->state()
        );
        self::assertSame(503, $unavailable->statusCode());
        self::assertSame(
            'no-store, no-cache, must-revalidate',
            $unavailable->headers()['Cache-Control']
        );
        self::assertSame('300', $unavailable->headers()['Retry-After']);
        self::assertSame('noindex, nofollow', $unavailable->robotsDirective());
    }

    public function testPreviewSourceIsMergedBeforePaginationAndNoStored(): void
    {
        $feed = new PublicIndexFeedFake(
            queryCards: $this->cards(6, 'public')
        );
        $preview = new PublicIndexPreviewSourceFake(
            $this->cards(10, 'preview', 20)
        );
        $service = $this->service($feed);

        $first = $service->resolve(
            $this->input(
                'es',
                '/es/noticias?qa_matrix=1',
                ['qa_matrix' => '1']
            ),
            $this->copy(),
            $preview
        )->page();

        self::assertNotNull($first);
        self::assertSame(BlogPublicIndex::STATE_READY, $first->state());
        self::assertSame(12, $first->cardCount());
        self::assertSame(
            '/es/noticias/pagina/2?qa_matrix=1#blog-results',
            $first->nextUrl()
        );
        self::assertSame(
            'no-store, no-cache, must-revalidate',
            $first->headers()['Cache-Control']
        );
        self::assertSame('noindex, nofollow', $first->robotsDirective());

        $second = $service->resolve(
            $this->input(
                'es',
                '/es/noticias/pagina/2?qa_matrix=1',
                ['qa_matrix' => '1'],
                ['page' => '2']
            ),
            $this->copy(),
            $preview
        )->page();

        self::assertNotNull($second);
        self::assertSame(BlogPublicIndex::STATE_READY, $second->state());
        self::assertSame(4, $second->cardCount());
        self::assertNull($second->nextUrl());
        self::assertSame(50, $feed->queryCalls[0]->limit());
        self::assertSame(50, $feed->queryCalls[1]->limit());

        $conflict = $service->resolve(
            $this->input(
                'es',
                '/es/noticias?qa_matrix=1&q=matrix',
                ['qa_matrix' => '1', 'q' => 'matrix']
            ),
            $this->copy(),
            $preview
        )->page();
        self::assertNotNull($conflict);
        self::assertSame(
            BlogPublicIndex::STATE_NOT_FOUND,
            $conflict->state()
        );
        self::assertSame('noindex, nofollow', $conflict->robotsDirective());
    }

    public function testPreviewAcceptsSafeSameOriginQueryUrls(): void
    {
        $card = $this->cards(1, 'preview')[0];
        $card['url'] = '/admin/blog/editor/preview?'
            . 'post=11111111-1111-4111-8111-111111111111&locale=es';

        $page = $this->service(new PublicIndexFeedFake())->resolve(
            $this->input(
                'es',
                '/es/noticias?qa_matrix=1',
                ['qa_matrix' => '1']
            ),
            $this->copy(),
            new PublicIndexPreviewSourceFake([$card])
        )->page();

        self::assertNotNull($page);
        self::assertSame(BlogPublicIndex::STATE_READY, $page->state());
        self::assertSame([$card], $page->cards());
        self::assertSame('noindex, nofollow', $page->robotsDirective());
    }

    /** @dataProvider unsafePreviewUrlProvider */
    public function testPreviewRejectsUnsafeOrNonCanonicalUrls(
        string $url
    ): void {
        $card = $this->cards(1, 'preview')[0];
        $card['url'] = $url;

        $page = $this->service(new PublicIndexFeedFake())->resolve(
            $this->input(
                'es',
                '/es/noticias?qa_matrix=1',
                ['qa_matrix' => '1']
            ),
            $this->copy(),
            new PublicIndexPreviewSourceFake([$card])
        )->page();

        self::assertNotNull($page);
        self::assertSame(BlogPublicIndex::STATE_NOT_FOUND, $page->state());
        self::assertSame(404, $page->statusCode());
        self::assertSame([], $page->cards());
        self::assertSame('noindex, nofollow', $page->robotsDirective());
    }

    /** @return iterable<string, array{string}> */
    public static function unsafePreviewUrlProvider(): iterable
    {
        yield 'absolute cross origin' => [
            'https://evil.example/admin/blog/editor/preview?post=1',
        ];
        yield 'scheme relative cross origin' => [
            '//evil.example/admin/blog/editor/preview?post=1',
        ];
        yield 'fragment' => [
            '/admin/blog/editor/preview?post=1#login',
        ];
        yield 'path traversal' => [
            '/admin/blog/%2e%2e/editor/preview?post=1',
        ];
        yield 'double encoded path traversal' => [
            '/admin/blog/%252e%252e/editor/preview?post=1',
        ];
        yield 'encoded path separator' => [
            '/admin%2fblog/editor/preview?post=1',
        ];
        yield 'encoded backslash' => [
            '/admin/blog/editor/preview?post=one%5ctwo',
        ];
        yield 'encoded control' => [
            '/admin/blog/editor/preview?post=1%0d%0aLocation%3aevil',
        ];
        yield 'double encoded control' => [
            '/admin/blog/editor/preview?post=1%250d%250aLocation%253aevil',
        ];
        yield 'malformed percent escape' => [
            '/admin/blog/editor/preview?post=%ZZ',
        ];
        yield 'empty query' => [
            '/admin/blog/editor/preview?',
        ];
    }

    public function testInputUsesOnlyExplicitServerAndPartialHeader(): void
    {
        $previousServer = $_SERVER;
        $previousGet = $_GET;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/unrelated';
        $_GET = ['page' => '999'];

        try {
            $input = $this->input(
                'es',
                '/es/noticias',
                [],
                [],
                ['HTTP_X_LIQUIDSTACK_PARTIAL' => 'blog-results']
            );
            $page = $this->service(new PublicIndexFeedFake())->resolve(
                $input,
                $this->copy()
            )->page();
        } finally {
            $_SERVER = $previousServer;
            $_GET = $previousGet;
        }

        self::assertNotNull($page);
        self::assertTrue($page->isPartialRequest());
        self::assertSame(BlogPublicIndex::STATE_EMPTY, $page->state());
    }

    public function testMalformedRoutingAndPreviewValuesFailClosed(): void
    {
        $feed = new PublicIndexFeedFake(queryCards: $this->cards(2));
        $service = $this->service($feed);

        $routeAndQueryPage = $service->resolve(
            $this->input(
                'es',
                '/es/noticias/pagina/2?page=2',
                ['page' => '2'],
                ['page' => '2']
            ),
            $this->copy()
        )->page();
        self::assertNotNull($routeAndQueryPage);
        self::assertSame(
            BlogPublicIndex::STATE_NOT_FOUND,
            $routeAndQueryPage->state()
        );
        self::assertSame(404, $routeAndQueryPage->statusCode());

        $post = $service->resolve(
            $this->input(
                'es',
                '/es/noticias',
                [],
                [],
                ['REQUEST_METHOD' => 'POST']
            ),
            $this->copy()
        )->page();
        self::assertNotNull($post);
        self::assertSame(BlogPublicIndex::STATE_NOT_FOUND, $post->state());

        $invalidPreview = $service->resolve(
            $this->input(
                'es',
                '/es/noticias?qa_matrix=unexpected',
                ['qa_matrix' => 'unexpected']
            ),
            $this->copy(),
            new PublicIndexPreviewSourceFake($this->cards(1, 'preview'))
        )->page();
        self::assertNotNull($invalidPreview);
        self::assertSame(
            BlogPublicIndex::STATE_NOT_FOUND,
            $invalidPreview->state()
        );
        self::assertSame(
            'no-store, no-cache, must-revalidate',
            $invalidPreview->headers()['Cache-Control']
        );
        self::assertSame(
            'noindex, nofollow',
            $invalidPreview->robotsDirective()
        );
        self::assertSame([], $feed->queryCalls);
    }

    public function testRuntimeFactoryFailureStillResolvesA503Page(): void
    {
        $filesystem = new Filesystem();
        $root = sys_get_temp_dir()
            . '/liquidstack-blog-public-index-'
            . bin2hex(random_bytes(8));
        $filesystem->mkdir($root . '/App/config');
        $filesystem->dumpFile(
            $root . '/App/config/langs.php',
            "<?php\n\nreturn ['es', 'en'];\n"
        );
        $factory = $this->createMock(
            BlogPublicHttpRuntimeFactoryInterface::class
        );
        $factory->expects(self::once())
            ->method('create')
            ->with(self::callback(
                static fn (ModuleRuntimeContext $context): bool =>
                    !$context->environmentIsUsable()
            ))
            ->willThrowException(new RuntimeException('Database is down.'));

        try {
            $service = BlogPublicIndex::fromContext(
                new ModuleRuntimeContext($root, [
                    BlogPublicOrigin::ENV => 'https://example.test',
                ], false),
                $factory
            );
            $page = $service->resolve(
                $this->input('es', '/blog'),
                $this->copy()
            )->page();
        } finally {
            $filesystem->remove($root);
        }

        self::assertNotNull($page);
        self::assertSame(
            BlogPublicIndex::STATE_UNAVAILABLE,
            $page->state()
        );
        self::assertSame(503, $page->statusCode());
        self::assertSame('300', $page->headers()['Retry-After']);
        self::assertSame(
            'https://example.test/blog',
            $page->canonicalUrl()
        );
    }

    public function testHeadMatchesGetAndExposesBodySuppressionSignal(): void
    {
        $feed = new PublicIndexFeedFake(queryCards: $this->cards(13));
        $service = $this->service($feed);
        $get = $service->resolve(
            $this->input('es', '/es/noticias'),
            $this->copy()
        )->page();
        $head = $service->resolve(
            $this->input(
                'es',
                '/es/noticias',
                [],
                [],
                [
                    'REQUEST_METHOD' => 'HEAD',
                    'HTTP_X_LIQUIDSTACK_PARTIAL' => 'blog-results',
                ]
            ),
            $this->copy()
        )->page();

        self::assertNotNull($get);
        self::assertNotNull($head);
        self::assertFalse($get->isHeadRequest());
        self::assertTrue($head->isHeadRequest());
        self::assertFalse($head->isPartialRequest());
        self::assertSame($get->state(), $head->state());
        self::assertSame($get->statusCode(), $head->statusCode());
        self::assertSame($get->headers(), $head->headers());
        self::assertSame($get->cards(), $head->cards());
        self::assertSame($get->pageMeta(), $head->pageMeta());
        self::assertSame(
            'index, follow',
            $head->headers()['X-Robots-Tag']
        );

        $second = $service->resolve(
            $this->input(
                'es',
                '/es/noticias/pagina/2',
                [],
                ['page' => '2'],
                ['REQUEST_METHOD' => 'HEAD']
            ),
            $this->copy()
        )->page();
        self::assertNotNull($second);
        self::assertTrue($second->isHeadRequest());
        self::assertSame(200, $second->statusCode());
        self::assertSame(
            'https://example.test/es/noticias/pagina/2',
            $second->canonicalUrl()
        );
        self::assertSame(
            'index, follow',
            $second->headers()['X-Robots-Tag']
        );

        $redirect = $service->resolve(
            $this->input(
                'es',
                '/es/noticias?page=2',
                ['page' => '2'],
                [],
                ['REQUEST_METHOD' => 'HEAD']
            ),
            $this->copy()
        );
        self::assertSame(301, $redirect->statusCode());
        self::assertSame('', $redirect->redirectResponse()?->body());
        self::assertSame(
            '/es/noticias/pagina/2',
            $redirect->headers()['Location']
        );
        self::assertSame(
            'noindex, follow',
            $redirect->headers()['X-Robots-Tag']
        );

        $notFound = $service->resolve(
            $this->input(
                'es',
                '/es/noticias/pagina/999999',
                [],
                ['page' => '999999'],
                ['REQUEST_METHOD' => 'HEAD']
            ),
            $this->copy()
        )->page();
        self::assertNotNull($notFound);
        self::assertTrue($notFound->isHeadRequest());
        self::assertSame(404, $notFound->statusCode());
        self::assertSame(
            'noindex, follow',
            $notFound->headers()['X-Robots-Tag']
        );

        $unavailable = $this->service(null)->resolve(
            $this->input(
                'es',
                '/es/noticias',
                [],
                [],
                ['REQUEST_METHOD' => 'HEAD']
            ),
            $this->copy()
        )->page();
        self::assertNotNull($unavailable);
        self::assertTrue($unavailable->isHeadRequest());
        self::assertSame(503, $unavailable->statusCode());
        self::assertSame('300', $unavailable->headers()['Retry-After']);
        self::assertSame(
            'noindex, nofollow',
            $unavailable->headers()['X-Robots-Tag']
        );
    }

    private function service(
        ?BlogPublicIndexFeedInterface $feed
    ): BlogPublicIndex {
        return new BlogPublicIndex(
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
        );
    }

    /**
     * @param array<string|int, mixed> $query
     * @param array<string|int, mixed> $routeParameters
     * @param array<string, mixed> $extraServer
     */
    private function input(
        string $locale,
        string $uri,
        array $query = [],
        array $routeParameters = [],
        array $extraServer = []
    ): BlogPublicIndexInput {
        return new BlogPublicIndexInput(
            $locale,
            $query,
            $routeParameters,
            array_replace([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => $uri,
            ], $extraServer)
        );
    }

    private function copy(): BlogPublicIndexTextCatalog
    {
        return BlogPublicIndexTextCatalog::fromGlobals([
            'title' => (object) ['text' => 'Noticias'],
            'description' => (object) ['content' => 'Actualidad financiera.'],
            'blog_index_page' => (object) ['text' => 'Página'],
            'blog_index_month_08' => (object) ['text' => 'agosto'],
            'blog_index_archive_period_format' =>
                (object) ['text' => '{month} de {year}'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function cards(
        int $count,
        string $prefix = 'post',
        int $dayOffset = 0
    ): array {
        $cards = [];
        for ($index = 0; $index < $count; ++$index) {
            $number = $index + 1;
            $cards[] = [
                'locale' => 'es',
                'slug' => $prefix . '-' . $number,
                'url' => '/es/noticias/' . $prefix . '-' . $number,
                'h1' => ucfirst($prefix) . ' ' . $number,
                'excerpt' => 'Resumen ' . $number,
                'published_at' => sprintf(
                    '2026-08-%02d 10:00:00.000000',
                    31 - $dayOffset - $index
                ),
                'updated_at' => sprintf(
                    '2026-08-%02d 10:00:00.000000',
                    31 - $dayOffset - $index
                ),
            ];
        }

        return $cards;
    }
}

final class PublicIndexFeedFake implements BlogPublicIndexFeedInterface
{
    /** @var list<BlogPublicCatalogQuery> */
    public array $queryCalls = [];
    /** @var list<BlogPublicArchiveQuery> */
    public array $archiveCalls = [];
    public bool $fail = false;

    /**
     * @param list<array<string, mixed>> $queryCards
     * @param list<array<string, mixed>> $archiveCards
     * @param list<array<string, mixed>> $filters
     * @param list<array{locale:string,year:int,month:int,count:int}> $periods
     */
    public function __construct(
        private readonly array $queryCards = [],
        private readonly array $archiveCards = [],
        private readonly array $filters = [],
        private readonly array $periods = []
    ) {
    }

    public function filters(string $locale): array
    {
        $this->throwIfRequested();

        return $this->filters;
    }

    public function cardsForQuery(BlogPublicCatalogQuery $query): array
    {
        $this->throwIfRequested();
        $this->queryCalls[] = $query;

        return array_slice(
            $this->queryCards,
            $query->offset(),
            $query->limit()
        );
    }

    public function cardsForArchive(BlogPublicArchiveQuery $query): array
    {
        $this->throwIfRequested();
        $this->archiveCalls[] = $query;

        return array_slice(
            $this->archiveCards,
            $query->offset(),
            $query->limit()
        );
    }

    public function archivePeriods(
        BlogPublicArchivePeriodsQuery $query
    ): array {
        $this->throwIfRequested();

        return $this->periods;
    }

    private function throwIfRequested(): void
    {
        if ($this->fail) {
            throw new RuntimeException('Public index feed unavailable.');
        }
    }
}

final class PublicIndexPreviewSourceFake implements
    BlogPublicIndexPreviewSourceInterface
{
    /** @param list<array<string, mixed>> $cards */
    public function __construct(private readonly array $cards)
    {
    }

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
        return $this->cards;
    }
}
