<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogService;
use App\Core\Blog\Categories\BlogCategoryException;
use App\Core\Blog\Categories\BlogCategoryPublicProjectionService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\PublishedPostCard;
use Throwable;

/**
 * Presentation boundary for project-owned Blog indexes and resources.
 *
 * Resources consume the returned public arrays; they never receive PDO or
 * query the module database themselves.
 */
final class BlogPublicFeed
{
    public function __construct(
        private readonly BlogConfig $config,
        private readonly BlogService $service,
        private readonly ?BlogCategoryPublicProjectionService
            $categoryProjection = null,
        private readonly ?BlogPublicCatalogRepositoryInterface
            $catalogRepository = null,
        private readonly ?BlogPublicDiscoveryRepositoryInterface
            $discoveryRepository = null,
        private readonly ?BlogPublicCardMediaRepositoryInterface
            $mediaRepository = null
    ) {
    }

    /**
     * @return list<array{
     *     locale: string,
     *     slug: string,
     *     url: string,
     *     h1: string,
     *     excerpt: string,
     *     published_at: string,
     *     updated_at: string
     * }>
     */
    public function cards(
        string $locale,
        int $limit = BlogService::DEFAULT_PUBLIC_LIST_LIMIT,
        int $offset = 0
    ): array {
        $basePath = $this->config->publicPath($locale);
        if ($basePath === null) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return array_map(
            static function (PublishedPostCard $card) use ($basePath): array {
                return [
                    'locale' => $card->locale(),
                    'slug' => $card->slug(),
                    'url' => $basePath . '/' . $card->slug(),
                    'h1' => $card->h1(),
                    'excerpt' => $card->excerpt(),
                    'published_at' => $card->publishedAt()->format(DATE_ATOM),
                    'updated_at' => $card->updatedAt()->format(DATE_ATOM),
                ];
            },
            $this->service->listPublishedCards($locale, $limit, $offset)
        );
    }

    /**
     * @return list<array{locale: string, slug: string, name: string, count: int}>
     */
    public function filtersForLocale(string $locale): array
    {
        return $this->requiredCategoryProjection()->filtersForLocale($locale);
    }

    /**
     * @return list<array{
     *     locale: string,
     *     slug: string,
     *     url: string,
     *     h1: string,
     *     excerpt: string,
     *     published_at: string,
     *     updated_at: string
     * }>
     */
    public function postsForFilter(
        string $locale,
        string $categorySlug,
        int $limit = BlogService::DEFAULT_PUBLIC_LIST_LIMIT,
        int $offset = 0
    ): array {
        return $this->requiredCategoryProjection()->postsForFilter(
            $locale,
            $categorySlug,
            $limit,
            $offset
        );
    }

    /**
     * @return list<array{
     *     locale: string,
     *     slug: string,
     *     url: string,
     *     h1: string,
     *     excerpt: string,
     *     published_at: string,
     *     updated_at: string,
     *     categories: list<array{locale:string,slug:string,name:string}>,
     *     thumbnail?: array{src:string,srcset:string,alt:string,width:int,height:int}
     * }>
     */
    public function cardsForQuery(BlogPublicCatalogQuery $query): array
    {
        $basePath = $this->config->publicPath($query->locale());
        if ($basePath === null) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        $repository = $this->catalogRepository
            ?? throw new BlogException(BlogException::STORAGE_UNAVAILABLE);

        return $this->projectCards(
            $query->locale(),
            $basePath,
            $repository->search($query),
            $repository instanceof BlogPublicCardCategoryRepositoryInterface
                ? $repository
                : null
        );
    }

    /**
     * Returns published posts that share the greatest number of localized
     * categories with the published source article.
     *
     * @return list<array{
     *     locale: string,
     *     slug: string,
     *     url: string,
     *     h1: string,
     *     excerpt: string,
     *     published_at: string,
     *     updated_at: string,
     *     categories: list<array{locale:string,slug:string,name:string}>,
     *     thumbnail?: array{src:string,srcset:string,alt:string,width:int,height:int}
     * }>
     */
    public function cardsForRelated(BlogPublicRelatedQuery $query): array
    {
        return $this->discoveryCards(
            $query->locale(),
            fn (BlogPublicDiscoveryRepositoryInterface $repository): array =>
                $repository->relatedPosts($query)
        );
    }

    /**
     * @return list<array{
     *     locale: string,
     *     slug: string,
     *     url: string,
     *     h1: string,
     *     excerpt: string,
     *     published_at: string,
     *     updated_at: string,
     *     categories: list<array{locale:string,slug:string,name:string}>,
     *     thumbnail?: array{src:string,srcset:string,alt:string,width:int,height:int}
     * }>
     */
    public function cardsForArchive(BlogPublicArchiveQuery $query): array
    {
        return $this->discoveryCards(
            $query->locale(),
            fn (BlogPublicDiscoveryRepositoryInterface $repository): array =>
                $repository->archivePosts($query)
        );
    }

    /**
     * @return list<array{locale: string, year: int, month: int, count: int}>
     */
    public function archivePeriods(
        BlogPublicArchivePeriodsQuery $query
    ): array {
        if ($this->config->publicPath($query->locale()) === null) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return array_map(
            static fn (BlogPublicArchivePeriod $period): array =>
                $period->toResourceData(),
            $this->requiredDiscoveryRepository()->archivePeriods($query)
        );
    }

    /**
     * @param callable(BlogPublicDiscoveryRepositoryInterface):list<PublishedPostCard>
     *     $load
     * @return list<array{
     *     locale: string,
     *     slug: string,
     *     url: string,
     *     h1: string,
     *     excerpt: string,
     *     published_at: string,
     *     updated_at: string,
     *     categories: list<array{locale:string,slug:string,name:string}>,
     *     thumbnail?: array{src:string,srcset:string,alt:string,width:int,height:int}
     * }>
     */
    private function discoveryCards(string $locale, callable $load): array
    {
        $basePath = $this->config->publicPath($locale);
        if ($basePath === null) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        $repository = $this->requiredDiscoveryRepository();

        return $this->projectCards(
            $locale,
            $basePath,
            $load($repository),
            $repository instanceof BlogPublicCardCategoryRepositoryInterface
                ? $repository
                : null
        );
    }

    /**
     * @param list<PublishedPostCard> $cards
     * @return list<array<string, mixed>>
     */
    private function projectCards(
        string $locale,
        string $basePath,
        array $cards,
        ?BlogPublicCardCategoryRepositoryInterface $categoryRepository
    ): array {
        $slugs = array_map(
            static fn (PublishedPostCard $card): string => $card->slug(),
            $cards
        );
        $categoriesBySlug = $categoryRepository === null || $cards === []
            ? []
            : $categoryRepository->categoriesForCards(
                new BlogPublicCardCategoryQuery(
                    $locale,
                    $slugs
                )
            );
        $thumbnailsBySlug = [];
        if ($this->mediaRepository !== null && $cards !== []) {
            try {
                $thumbnailsBySlug = $this->mediaRepository
                    ->thumbnailsForCards(
                        new BlogPublicCardMediaQuery($locale, $slugs)
                    );
            } catch (Throwable) {
                // Optional media never makes an otherwise valid feed fail.
                $thumbnailsBySlug = [];
            }
        }

        return array_map(
            static function (PublishedPostCard $card) use (
                $basePath,
                $categoriesBySlug,
                $thumbnailsBySlug
            ): array {
                $categories = $categoriesBySlug[$card->slug()] ?? [];
                $item = [
                    'locale' => $card->locale(),
                    'slug' => $card->slug(),
                    'url' => $basePath . '/' . $card->slug(),
                    'h1' => $card->h1(),
                    'excerpt' => $card->excerpt(),
                    'published_at' => $card->publishedAt()->format(DATE_ATOM),
                    'updated_at' => $card->updatedAt()->format(DATE_ATOM),
                    'categories' => array_map(
                        static fn (BlogPublicCardCategory $category): array =>
                            $category->toResourceData(),
                        $categories
                    ),
                ];
                $thumbnail = $thumbnailsBySlug[$card->slug()] ?? null;
                if ($thumbnail instanceof BlogPublicCardThumbnail) {
                    $item['thumbnail'] = $thumbnail->toResourceData();
                }

                return $item;
            },
            $cards
        );
    }

    private function requiredDiscoveryRepository(
    ): BlogPublicDiscoveryRepositoryInterface {
        return $this->discoveryRepository
            ?? throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
    }

    private function requiredCategoryProjection(): BlogCategoryPublicProjectionService
    {
        return $this->categoryProjection
            ?? throw new BlogCategoryException(
                BlogCategoryException::STORAGE_UNAVAILABLE
            );
    }
}
