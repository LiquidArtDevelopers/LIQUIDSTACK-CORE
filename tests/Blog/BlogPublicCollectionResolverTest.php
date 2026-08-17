<?php

declare(strict_types=1);

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Persistence\BlogPersistenceException;
use App\Core\Blog\Persistence\BlogRepositoryInterface;
use App\Core\Blog\PublishedPostCard;
use App\Core\Blog\PublicFeed\BlogPublicCatalogQuery;
use App\Core\Blog\PublicFeed\BlogPublicCatalogRepositoryInterface;
use App\Core\Blog\PublicFeed\BlogPublicCollectionFeedInterface;
use App\Core\Blog\PublicFeed\BlogPublicCollectionResolver;
use App\Core\Blog\PublicFeed\BlogPublicCollectionViewModel;
use App\Core\Blog\PublicFeed\BlogPublicFeed;
use App\Core\Blog\PublicFeed\BlogPublicResourceFeed;
use App\Core\Blog\PublicFeed\BlogPublicResourceQuery;
use App\Core\Support\Paths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogPublicCollectionResolverTest extends TestCase
{
    public function testLatestUsesTheBoundedNewestPublicInvariant(): void
    {
        $first = $this->card('matrix-despierta', 'Matrix despierta');
        $second = $this->card('segunda', 'Segunda');
        $repository = new class($first, $second) implements
            BlogPublicCatalogRepositoryInterface {
            public ?BlogPublicCatalogQuery $query = null;

            public function __construct(
                private readonly PublishedPostCard $first,
                private readonly PublishedPostCard $second
            ) {
            }

            public function search(BlogPublicCatalogQuery $query): array
            {
                $this->query = $query;

                return [$this->first, $this->second];
            }
        };

        $collection = $this->resolver($repository)->latest('es', 1);

        self::assertTrue($collection->isReady());
        self::assertSame(1, $collection->count());
        self::assertSame('matrix-despierta', $collection->items()[0]['slug']);
        self::assertSame('es', $repository->query?->locale());
        self::assertSame([], $repository->query?->categorySlugs());
        self::assertSame(
            BlogPublicResourceQuery::MODE_ANY,
            $repository->query?->categoryMode()
        );
        self::assertSame(
            [BlogPublicResourceQuery::DUMMY_CATEGORY_SLUG],
            $repository->query?->excludedCategorySlugs()
        );
        self::assertSame(1, $repository->query?->limit());
        self::assertSame(0, $repository->query?->offset());
        self::assertNull($repository->query?->excludeSlug());
        self::assertSame(
            BlogPublicResourceQuery::ORDER_NEWEST,
            $repository->query?->order()
        );
    }

    public function testResolvePreservesAnAdvancedTypedQuery(): void
    {
        $card = $this->card('updated-post', 'Updated post', 'en');
        $repository = new class($card) implements
            BlogPublicCatalogRepositoryInterface {
            public ?BlogPublicCatalogQuery $query = null;

            public function __construct(
                private readonly PublishedPostCard $card
            ) {
            }

            public function search(BlogPublicCatalogQuery $query): array
            {
                $this->query = $query;

                return [$this->card];
            }
        };
        $query = new BlogPublicResourceQuery(
            locale: 'en',
            categoryScope: BlogPublicResourceQuery::SCOPE_SELECTED,
            categories: ['financial-planning'],
            categoryMode: BlogPublicResourceQuery::MODE_ALL,
            excludeDummy: true,
            items: 2,
            limit: 2,
            search: 'wealth planning',
            offset: 7,
            excludeSlug: 'already-visible',
            order: BlogPublicResourceQuery::ORDER_UPDATED
        );

        $collection = $this->resolver($repository)->resolve($query);

        self::assertTrue($collection->isReady());
        self::assertSame(['financial-planning'], $repository->query?->categorySlugs());
        self::assertSame(
            BlogPublicResourceQuery::MODE_ALL,
            $repository->query?->categoryMode()
        );
        self::assertSame('wealth planning', $repository->query?->search());
        self::assertSame(7, $repository->query?->offset());
        self::assertSame('already-visible', $repository->query?->excludeSlug());
        self::assertSame(
            BlogPublicResourceQuery::ORDER_UPDATED,
            $repository->query?->order()
        );
    }

    public function testAHealthyFeedWithNoCardsIsEmptyNotUnavailable(): void
    {
        $repository = new class implements BlogPublicCatalogRepositoryInterface {
            public function search(BlogPublicCatalogQuery $query): array
            {
                return [];
            }
        };

        $collection = $this->resolver($repository)->latest('es', 8);

        self::assertSame(BlogPublicCollectionViewModel::STATE_EMPTY, $collection->state());
        self::assertTrue($collection->isEmpty());
        self::assertFalse($collection->isUnavailable());
        self::assertSame([], $collection->items());
    }

    public function testMissingOrFailingInfrastructureIsUnavailable(): void
    {
        $missing = (new BlogPublicCollectionResolver(null))->latest('es', 8);
        self::assertTrue($missing->isUnavailable());

        $repository = new class implements BlogPublicCatalogRepositoryInterface {
            public function search(BlogPublicCatalogQuery $query): array
            {
                throw new BlogPersistenceException();
            }
        };
        $failing = $this->resolver($repository)->latest('es', 8);

        self::assertTrue($failing->isUnavailable());
        self::assertSame([], $failing->items());
        self::assertSame(0, $failing->count());
    }

    #[DataProvider('malformedPresentationData')]
    public function testMalformedPresentationDataAlsoFailsClosed(
        array $items
    ): void
    {
        $feed = new class($items) implements BlogPublicCollectionFeedInterface {
            public function __construct(private readonly array $items)
            {
            }

            public function cardsForResource(BlogPublicResourceQuery $query): array
            {
                return $this->items;
            }
        };

        $collection = (new BlogPublicCollectionResolver($feed))->latest('es', 1);

        self::assertTrue($collection->isUnavailable());
        self::assertSame([], $collection->items());
    }

    /** @return iterable<string, array{0:array<mixed>}> */
    public static function malformedPresentationData(): iterable
    {
        yield 'scalar item' => [[null]];
        yield 'associative outer collection' => [[
            'post' => ['slug' => 'not-a-list'],
        ]];
    }

    #[DataProvider('invalidLatestInput')]
    public function testLatestValidatesBeforeTheUnavailableFallback(
        string $locale,
        int $limit
    ): void {
        $this->expectException(BlogException::class);
        $this->expectExceptionMessage('Invalid Blog input.');

        (new BlogPublicCollectionResolver(null))->latest($locale, $limit);
    }

    /** @return iterable<string, array{0:string,1:int}> */
    public static function invalidLatestInput(): iterable
    {
        yield 'invalid locale' => ['not a locale', 1];
        yield 'zero items' => ['es', 0];
        yield 'negative items' => ['es', -1];
        yield 'over maximum' => ['es', BlogPublicCatalogQuery::MAX_LIMIT + 1];
    }

    public function testCurrentFailsClosedWhenTheProjectEnvironmentIsMissing(): void
    {
        $filesystem = new Filesystem();
        $fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-blog-public-collections-'
            . bin2hex(random_bytes(8));
        $filesystem->mkdir($fixtureRoot);
        $previousRoot = Paths::projectRoot();
        Paths::setProjectRoot($fixtureRoot);

        try {
            $collection = BlogPublicCollectionResolver::current()->latest('es', 1);
            self::assertTrue($collection->isUnavailable());
        } finally {
            Paths::setProjectRoot($previousRoot);
            $filesystem->remove($fixtureRoot);
        }
    }

    private function resolver(
        BlogPublicCatalogRepositoryInterface $repository
    ): BlogPublicCollectionResolver {
        return new BlogPublicCollectionResolver(new BlogPublicResourceFeed(
            new BlogPublicFeed(
                $this->config(),
                new BlogService($this->createMock(BlogRepositoryInterface::class)),
                catalogRepository: $repository
            )
        ));
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

    private function card(
        string $slug,
        string $title,
        string $locale = 'es'
    ): PublishedPostCard {
        return new PublishedPostCard(
            $locale,
            $slug,
            $title,
            'A reusable public presentation excerpt.',
            new DateTimeImmutable('2030-01-02 12:00:00 UTC'),
            new DateTimeImmutable('2030-01-03 13:00:00 UTC')
        );
    }
}
