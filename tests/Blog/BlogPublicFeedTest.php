<?php

declare(strict_types=1);

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogService;
use App\Core\Blog\Categories\BlogCategoryException;
use App\Core\Blog\Categories\BlogCategoryPublicProjectionService;
use App\Core\Blog\Categories\Persistence\BlogCategoryRepositoryInterface;
use App\Core\Blog\Categories\PublishedCategoryFilter;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Http\BlogPublicHttpRuntime;
use App\Core\Blog\Http\BlogPublicHttpRuntimeFactoryInterface;
use App\Core\Blog\Persistence\BlogRepositoryInterface;
use App\Core\Blog\Persistence\BlogPersistenceException;
use App\Core\Blog\PublishedPostCard;
use App\Core\Blog\PublicDelivery\BlogPublicMediaRoute;
use App\Core\Blog\PublicFeed\BlogPublicArchivePeriod;
use App\Core\Blog\PublicFeed\BlogPublicArchivePeriodsQuery;
use App\Core\Blog\PublicFeed\BlogPublicArchiveQuery;
use App\Core\Blog\PublicFeed\BlogPublicCardCategory;
use App\Core\Blog\PublicFeed\BlogPublicCardCategoryQuery;
use App\Core\Blog\PublicFeed\BlogPublicCardCategoryRepositoryInterface;
use App\Core\Blog\PublicFeed\BlogPublicCardTag;
use App\Core\Blog\PublicFeed\BlogPublicCardTaxonomyBatch;
use App\Core\Blog\PublicFeed\BlogPublicCardTaxonomyQuery;
use App\Core\Blog\PublicFeed\BlogPublicCardTaxonomyRepositoryInterface;
use App\Core\Blog\PublicFeed\BlogPublicCardMediaQuery;
use App\Core\Blog\PublicFeed\BlogPublicCardMediaRepositoryInterface;
use App\Core\Blog\PublicFeed\BlogPublicCardThumbnail;
use App\Core\Blog\PublicFeed\BlogPublicCardThumbnailCandidate;
use App\Core\Blog\PublicFeed\BlogPublicCatalogQuery;
use App\Core\Blog\PublicFeed\BlogPublicCatalogRepositoryInterface;
use App\Core\Blog\PublicFeed\BlogPublicDiscoveryRepositoryInterface;
use App\Core\Blog\PublicFeed\BlogPublicFeed;
use App\Core\Blog\PublicFeed\BlogPublicFeedFactory;
use App\Core\Blog\PublicFeed\BlogPublicRelatedQuery;
use App\Core\Blog\PublicFeed\BlogPublicResourceFeed;
use App\Core\Blog\PublicFeed\BlogPublicResourceQuery;
use App\Core\Modules\ModuleRuntimeContext;
use PHPUnit\Framework\TestCase;

final class BlogPublicFeedTest extends TestCase
{
    public function testItReturnsOnlyPublishedPresentationDataAndLocalizedUrls(): void
    {
        $repository = $this->createMock(BlogRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('listPublishedCards')
            ->with('es', 8, 4)
            ->willReturn([$this->card()]);
        $feed = new BlogPublicFeed(
            $this->config(),
            new BlogService($repository)
        );

        $items = $feed->cards('es', 8, 4);

        self::assertSame([[
            'locale' => 'es',
            'slug' => 'matrix-despierta',
            'url' => '/es/noticias/matrix-despierta',
            'h1' => 'Matrix despierta',
            'excerpt' => 'Neo descubre que el mundo no es lo que parece.',
            'published_at' => '2030-01-02T12:00:00+00:00',
            'updated_at' => '2030-01-03T13:00:00+00:00',
        ]], $items);
        self::assertArrayNotHasKey('body_text', $items[0]);
        self::assertArrayNotHasKey('post_public_id', $items[0]);
    }

    public function testItFailsClosedForALocaleWithoutAPublicBasePath(): void
    {
        $repository = $this->createMock(BlogRepositoryInterface::class);
        $repository->expects(self::never())->method('listPublishedCards');
        $feed = new BlogPublicFeed(
            $this->config(),
            new BlogService($repository)
        );

        $this->expectException(BlogException::class);
        $feed->cards('eu');
    }

    public function testFactoryBuildsTheFeedThroughTheExistingRuntimeBoundary(): void
    {
        $repository = $this->createMock(BlogRepositoryInterface::class);
        $repository->method('listPublishedCards')->willReturn([]);
        $categoryRepository = $this->createMock(
            BlogCategoryRepositoryInterface::class
        );
        $categoryRepository->expects(self::once())
            ->method('publicFilters')
            ->with('es')
            ->willReturn([new PublishedCategoryFilter(
                '11111111-1111-4111-8111-111111111111',
                'es',
                'matrix',
                'Matrix',
                1
            )]);
        $categoryRepository->expects(self::once())
            ->method('publicPostCards')
            ->with('es', 'matrix', 4, 2)
            ->willReturn([$this->card()]);
        $runtime = new BlogPublicHttpRuntime(
            $this->config(),
            BlogPublicOrigin::fromEnvironment([
                'DEV_MODE' => '1',
                'RAIZ' => 'http://localhost:1309',
            ]),
            new BlogService($repository),
            categoryProjection: new BlogCategoryPublicProjectionService(
                $this->config(),
                $categoryRepository
            )
        );
        $runtimeFactory = $this->createMock(
            BlogPublicHttpRuntimeFactoryInterface::class
        );
        $runtimeFactory->expects(self::once())
            ->method('create')
            ->with(self::callback(
                static fn (ModuleRuntimeContext $context): bool =>
                    $context->projectRoot() === 'C:\\project'
                    && $context->environment()['DEV_MODE'] === '1'
                    && $context->environmentIsUsable()
            ))
            ->willReturn($runtime);

        $feed = (new BlogPublicFeedFactory($runtimeFactory))->create(
            'C:\\project',
            ['DEV_MODE' => '1']
        );

        self::assertSame($runtime->publicFeed(), $feed);
        self::assertSame([], $feed->cards('es'));
        self::assertSame('matrix', $feed->filtersForLocale('es')[0]['slug']);
        self::assertSame(
            '/es/noticias/matrix-despierta',
            $feed->postsForFilter('es', 'matrix', 4, 2)[0]['url']
        );
    }

    public function testUnifiedFeedFailsClosedWhenCategoryProjectionIsUnavailable(): void
    {
        $feed = new BlogPublicFeed(
            $this->config(),
            new BlogService($this->createMock(BlogRepositoryInterface::class))
        );

        try {
            $feed->filtersForLocale('es');
            self::fail('The missing category projection must fail closed.');
        } catch (BlogCategoryException $exception) {
            self::assertSame(
                BlogCategoryException::STORAGE_UNAVAILABLE,
                $exception->issueCode()
            );
        }
    }

    public function testCardsForQueryUsesTheBoundedCatalogReadModel(): void
    {
        $query = new BlogPublicCatalogQuery(
            'es',
            'matrix',
            ['noticias', 'cine'],
            BlogPublicCatalogQuery::MODE_ALL,
            9,
            3,
            'matrix-anterior'
        );
        $catalog = $this->createMock(
            BlogPublicCatalogRepositoryInterface::class
        );
        $catalog->expects(self::once())
            ->method('search')
            ->with(self::identicalTo($query))
            ->willReturn([$this->card()]);
        $legacyRepository = $this->createMock(
            BlogRepositoryInterface::class
        );
        $legacyRepository->expects(self::never())
            ->method('listPublishedCards');
        $feed = new BlogPublicFeed(
            $this->config(),
            new BlogService($legacyRepository),
            catalogRepository: $catalog
        );

        self::assertSame([[
            'locale' => 'es',
            'slug' => 'matrix-despierta',
            'url' => '/es/noticias/matrix-despierta',
            'h1' => 'Matrix despierta',
            'excerpt' => 'Neo descubre que el mundo no es lo que parece.',
            'published_at' => '2030-01-02T12:00:00+00:00',
            'updated_at' => '2030-01-03T13:00:00+00:00',
            'categories' => [],
            'tags' => [],
        ]], $feed->cardsForQuery($query));
    }

    public function testCardsForQueryFailsClosedWithoutCatalogRepository(): void
    {
        $feed = new BlogPublicFeed(
            $this->config(),
            new BlogService($this->createMock(BlogRepositoryInterface::class))
        );

        try {
            $feed->cardsForQuery(new BlogPublicCatalogQuery('es'));
            self::fail('A missing catalog repository must fail closed.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::STORAGE_UNAVAILABLE,
                $exception->issueCode()
            );
        }
    }

    public function testResourceFeedSupportsTypedAndLegacyCategoryContracts(): void
    {
        $card = $this->card();
        $catalog = new class($card) implements
            BlogPublicCatalogRepositoryInterface {
            /** @var list<BlogPublicCatalogQuery> */
            public array $queries = [];

            public function __construct(
                private readonly PublishedPostCard $card
            ) {
            }

            public function search(BlogPublicCatalogQuery $query): array
            {
                $this->queries[] = $query;

                return [$this->card, $this->card];
            }
        };
        $resourceFeed = new BlogPublicResourceFeed(new BlogPublicFeed(
            $this->config(),
            new BlogService($this->createMock(BlogRepositoryInterface::class)),
            catalogRepository: $catalog
        ));

        $typed = new BlogPublicResourceQuery(
            locale: 'es',
            categoryScope: BlogPublicResourceQuery::SCOPE_ALL,
            excludeDummy: true,
            items: 1,
            limit: 5
        );
        self::assertCount(1, $resourceFeed->cards($typed));
        self::assertSame(['dummy'], $catalog->queries[0]->excludedCategorySlugs());

        self::assertCount(2, $resourceFeed->cards([
            'locale' => 'es',
            'categories' => ['noticias'],
            'category_mode' => 'all',
            'limit' => 2,
        ]));
        self::assertSame(['noticias'], $catalog->queries[1]->categorySlugs());
        self::assertSame('all', $catalog->queries[1]->categoryMode());

        self::assertCount(1, $resourceFeed->cards([
            'locale' => 'es',
            'categoryScope' => 'all',
            'excludeDummy' => true,
            'items' => 1,
            'limit' => 2,
        ]));
        self::assertSame(['dummy'], $catalog->queries[2]->excludedCategorySlugs());
    }

    public function testCardsReceiveIdFreeCategoriesThroughOneBatchCapability(): void
    {
        $card = $this->card();
        $catalog = new class($card) implements
            BlogPublicCatalogRepositoryInterface,
            BlogPublicCardCategoryRepositoryInterface {
            public ?BlogPublicCardCategoryQuery $categoryQuery = null;

            public function __construct(
                private readonly PublishedPostCard $card
            ) {
            }

            public function search(BlogPublicCatalogQuery $query): array
            {
                return [$this->card];
            }

            public function categoriesForCards(
                BlogPublicCardCategoryQuery $query
            ): array {
                $this->categoryQuery = $query;

                return [
                    'matrix-despierta' => [
                        new BlogPublicCardCategory(
                            'es',
                            'noticias',
                            'Noticias'
                        ),
                    ],
                ];
            }
        };
        $feed = new BlogPublicFeed(
            $this->config(),
            new BlogService($this->createMock(BlogRepositoryInterface::class)),
            catalogRepository: $catalog
        );

        $cards = $feed->cardsForQuery(new BlogPublicCatalogQuery('es'));

        self::assertSame(
            ['matrix-despierta'],
            $catalog->categoryQuery?->cardSlugs()
        );
        self::assertSame([[
            'locale' => 'es',
            'slug' => 'noticias',
            'name' => 'Noticias',
        ]], $cards[0]['categories']);
        self::assertSame([], $cards[0]['tags']);
        self::assertArrayNotHasKey('category_public_id', $cards[0]);
    }

    public function testCardsAndArticleUseOneIdFreeTaxonomyBatch(): void
    {
        $card = $this->card();
        $catalog = new class($card) implements
            BlogPublicCatalogRepositoryInterface,
            BlogPublicCardTaxonomyRepositoryInterface {
            public int $taxonomyCalls = 0;
            public ?BlogPublicCardTaxonomyQuery $taxonomyQuery = null;

            public function __construct(
                private readonly PublishedPostCard $card
            ) {
            }

            public function search(BlogPublicCatalogQuery $query): array
            {
                return [$this->card];
            }

            public function categoriesForCards(
                BlogPublicCardCategoryQuery $query
            ): array {
                throw new RuntimeException('Legacy batch must not run.');
            }

            public function taxonomiesForCards(
                BlogPublicCardTaxonomyQuery $query
            ): BlogPublicCardTaxonomyBatch {
                ++$this->taxonomyCalls;
                $this->taxonomyQuery = $query;

                return new BlogPublicCardTaxonomyBatch(
                    ['matrix-despierta' => [new BlogPublicCardCategory(
                        'es',
                        'noticias',
                        'Noticias'
                    )]],
                    ['matrix-despierta' => [new BlogPublicCardTag(
                        'es',
                        'inteligencia-artificial',
                        'Inteligencia artificial'
                    )]]
                );
            }
        };
        $feed = new BlogPublicFeed(
            $this->config(),
            new BlogService($this->createMock(BlogRepositoryInterface::class)),
            catalogRepository: $catalog
        );

        $cards = $feed->cardsForQuery(new BlogPublicCatalogQuery('es'));

        self::assertSame(1, $catalog->taxonomyCalls);
        self::assertSame(
            ['matrix-despierta'],
            $catalog->taxonomyQuery?->cardSlugs()
        );
        self::assertSame([[
            'locale' => 'es',
            'slug' => 'inteligencia-artificial',
            'name' => 'Inteligencia artificial',
        ]], $cards[0]['tags']);
        self::assertArrayNotHasKey('id', $cards[0]['tags'][0]);
        self::assertArrayNotHasKey('public_id', $cards[0]['tags'][0]);

        self::assertSame([
            'categories' => [[
                'locale' => 'es',
                'slug' => 'noticias',
                'name' => 'Noticias',
            ]],
            'tags' => [[
                'locale' => 'es',
                'slug' => 'inteligencia-artificial',
                'name' => 'Inteligencia artificial',
            ]],
        ], $feed->taxonomiesForArticle('es', 'matrix-despierta'));
        self::assertSame(2, $catalog->taxonomyCalls);
    }

    public function testCardsReceiveAnIdFreeResponsiveThumbnailAdditively(): void
    {
        $card = $this->card();
        $catalog = $this->createMock(
            BlogPublicCatalogRepositoryInterface::class
        );
        $catalog->method('search')->willReturn([$card]);
        $asset = '91919191-9191-4191-8191-919191919191';
        $media = new class($asset) implements
            BlogPublicCardMediaRepositoryInterface {
            public ?BlogPublicCardMediaQuery $query = null;

            public function __construct(private readonly string $asset)
            {
            }

            public function thumbnailsForCards(
                BlogPublicCardMediaQuery $query
            ): array {
                $this->query = $query;

                return ['matrix-despierta' => new BlogPublicCardThumbnail(
                    'Neo despierta',
                    [new BlogPublicCardThumbnailCandidate(
                        BlogPublicMediaRoute::path($this->asset, 480),
                        480,
                        320
                    )]
                )];
            }
        };
        $feed = new BlogPublicFeed(
            $this->config(),
            new BlogService($this->createMock(BlogRepositoryInterface::class)),
            catalogRepository: $catalog,
            mediaRepository: $media
        );

        $cards = $feed->cardsForQuery(new BlogPublicCatalogQuery('es'));

        self::assertSame(['matrix-despierta'], $media->query?->cardSlugs());
        self::assertSame([
            'src' => BlogPublicMediaRoute::path($asset, 480),
            'srcset' => BlogPublicMediaRoute::path($asset, 480) . ' 480w',
            'alt' => 'Neo despierta',
            'width' => 480,
            'height' => 320,
        ], $cards[0]['thumbnail']);
        self::assertArrayNotHasKey('media_asset_public_id', $cards[0]);
    }

    public function testOptionalMediaFailureNeverDropsOtherwiseValidCards(): void
    {
        $catalog = $this->createMock(
            BlogPublicCatalogRepositoryInterface::class
        );
        $catalog->method('search')->willReturn([$this->card()]);
        $media = new class implements BlogPublicCardMediaRepositoryInterface {
            public function thumbnailsForCards(
                BlogPublicCardMediaQuery $query
            ): array {
                throw new BlogPersistenceException();
            }
        };
        $feed = new BlogPublicFeed(
            $this->config(),
            new BlogService($this->createMock(BlogRepositoryInterface::class)),
            catalogRepository: $catalog,
            mediaRepository: $media
        );

        $cards = $feed->cardsForQuery(new BlogPublicCatalogQuery('es'));

        self::assertCount(1, $cards);
        self::assertSame('matrix-despierta', $cards[0]['slug']);
        self::assertArrayNotHasKey('thumbnail', $cards[0]);
    }

    public function testResourceBatchUsesLookaheadAndProjectsNavigationState(): void
    {
        $card = $this->card();
        $catalog = new class($card) implements
            BlogPublicCatalogRepositoryInterface {
            public ?BlogPublicCatalogQuery $query = null;

            public function __construct(
                private readonly PublishedPostCard $card
            ) {
            }

            public function search(BlogPublicCatalogQuery $query): array
            {
                $this->query = $query;

                return [$this->card, $this->card];
            }
        };
        $resourceFeed = new BlogPublicResourceFeed(new BlogPublicFeed(
            $this->config(),
            new BlogService($this->createMock(BlogRepositoryInterface::class)),
            catalogRepository: $catalog
        ));

        $batch = $resourceFeed->batch([
            'locale' => 'es',
            'items' => 1,
            'offset' => 4,
            'order' => BlogPublicResourceQuery::ORDER_UPDATED,
        ], '/es/noticias?offset=5');

        self::assertCount(1, $batch->items());
        self::assertTrue($batch->hasNext());
        self::assertSame(5, $batch->nextOffset());
        self::assertSame('/es/noticias?offset=5', $batch->nextUrl());
        self::assertSame(
            BlogPublicCatalogQuery::ORDER_UPDATED,
            $catalog->query?->order()
        );
        self::assertSame(2, $catalog->query?->limit());
        self::assertSame([
            'items' => $batch->items(),
            'has_next' => true,
            'next_offset' => 5,
            'next_url' => '/es/noticias?offset=5',
        ], $batch->toResourceData());

        try {
            $resourceFeed->batch([
                'locale' => 'es',
                'items' => 2,
                'limit' => 2,
            ]);
            self::fail('A batch without a lookahead row was accepted.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::INVALID_INPUT,
                $exception->issueCode()
            );
        }

        try {
            $resourceFeed->batch([
                'locale' => 'es',
                'items' => 1,
            ], 'https://example.test/page/2');
            self::fail('An off-site next URL was accepted.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::INVALID_INPUT,
                $exception->issueCode()
            );
        }
    }

    public function testDiscoveryMethodsProjectOnlyReusablePresentationData(): void
    {
        $related = new BlogPublicRelatedQuery('es', 'matrix-anterior', 3);
        $archive = new BlogPublicArchiveQuery('es', 2030, 1, 6, 2);
        $periods = new BlogPublicArchivePeriodsQuery('es', 24);
        $repository = $this->createMock(
            BlogPublicDiscoveryRepositoryInterface::class
        );
        $repository->expects(self::once())
            ->method('relatedPosts')
            ->with(self::identicalTo($related))
            ->willReturn([$this->card()]);
        $repository->expects(self::once())
            ->method('archivePosts')
            ->with(self::identicalTo($archive))
            ->willReturn([$this->card()]);
        $repository->expects(self::once())
            ->method('archivePeriods')
            ->with(self::identicalTo($periods))
            ->willReturn([new BlogPublicArchivePeriod('es', 2030, 1, 2)]);
        $feed = new BlogPublicFeed(
            $this->config(),
            new BlogService($this->createMock(BlogRepositoryInterface::class)),
            discoveryRepository: $repository
        );

        foreach (
            [$feed->cardsForRelated($related), $feed->cardsForArchive($archive)]
            as $cards
        ) {
            self::assertSame('/es/noticias/matrix-despierta', $cards[0]['url']);
            self::assertArrayNotHasKey('body_text', $cards[0]);
            self::assertArrayNotHasKey('post_public_id', $cards[0]);
        }
        self::assertSame([[
            'locale' => 'es',
            'year' => 2030,
            'month' => 1,
            'count' => 2,
        ]], $feed->archivePeriods($periods));
    }

    public function testFactoryReusesTheCatalogAdapterForDiscovery(): void
    {
        $card = $this->card();
        $repository = new class($card) implements
            BlogPublicCatalogRepositoryInterface,
            BlogPublicDiscoveryRepositoryInterface {
            public function __construct(
                private readonly PublishedPostCard $card
            ) {
            }

            public function search(BlogPublicCatalogQuery $query): array
            {
                return [];
            }

            public function relatedPosts(BlogPublicRelatedQuery $query): array
            {
                return [$this->card];
            }

            public function archivePosts(BlogPublicArchiveQuery $query): array
            {
                return [$this->card];
            }

            public function archivePeriods(
                BlogPublicArchivePeriodsQuery $query
            ): array {
                return [new BlogPublicArchivePeriod('es', 2030, 1, 1)];
            }
        };
        $runtime = new BlogPublicHttpRuntime(
            $this->config(),
            BlogPublicOrigin::fromEnvironment([
                'DEV_MODE' => '1',
                'RAIZ' => 'http://localhost:1309',
            ]),
            new BlogService($this->createMock(BlogRepositoryInterface::class)),
            catalogRepository: $repository
        );
        $runtimeFactory = $this->createMock(
            BlogPublicHttpRuntimeFactoryInterface::class
        );
        $runtimeFactory->expects(self::once())
            ->method('create')
            ->willReturn($runtime);

        $feed = (new BlogPublicFeedFactory($runtimeFactory))->create(
            'C:\\project',
            ['DEV_MODE' => '1']
        );

        self::assertSame(
            'matrix-despierta',
            $feed->cardsForRelated(
                new BlogPublicRelatedQuery('es', 'matrix-anterior')
            )[0]['slug']
        );
        self::assertSame(2030, $feed->archivePeriods(
            new BlogPublicArchivePeriodsQuery('es')
        )[0]['year']);
    }

    public function testDiscoveryFailsClosedWithoutTheDiscoveryAdapter(): void
    {
        $feed = new BlogPublicFeed(
            $this->config(),
            new BlogService($this->createMock(BlogRepositoryInterface::class))
        );

        foreach ([
            static fn (): array => $feed->cardsForRelated(
                new BlogPublicRelatedQuery('es', 'matrix')
            ),
            static fn (): array => $feed->cardsForArchive(
                new BlogPublicArchiveQuery('es', 2030)
            ),
            static fn (): array => $feed->archivePeriods(
                new BlogPublicArchivePeriodsQuery('es')
            ),
        ] as $operation) {
            try {
                $operation();
                self::fail('The missing discovery adapter must fail closed.');
            } catch (BlogException $exception) {
                self::assertSame(
                    BlogException::STORAGE_UNAVAILABLE,
                    $exception->issueCode()
                );
            }
        }
    }

    private function config(): BlogConfig
    {
        return new BlogConfig(
            ['es' => '/es/noticias', 'en' => '/en/news'],
            '/blog-sitemap.xml',
            'ls_blog_',
            'test'
        );
    }

    private function card(): PublishedPostCard
    {
        return new PublishedPostCard(
            'es',
            'matrix-despierta',
            'Matrix despierta',
            'Neo descubre que el mundo no es lo que parece.',
            new DateTimeImmutable('2030-01-02 12:00:00 UTC'),
            new DateTimeImmutable('2030-01-03 13:00:00 UTC')
        );
    }
}
