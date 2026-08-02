<?php

declare(strict_types=1);

namespace App\Core\Blog\Categories;

use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Categories\Persistence\BlogCategoryRepositoryInterface;
use App\Core\Blog\PublishedPostCard;
use Throwable;

/**
 * Read-only public boundary. Resources receive arrays from this service and
 * never receive PDO, table names or persistence identifiers.
 */
final class BlogCategoryPublicProjectionService
{
    public function __construct(
        private readonly BlogConfig $config,
        private readonly BlogCategoryRepositoryInterface $repository
    ) {
    }

    /** @return list<array{locale: string, slug: string, name: string, count: int}> */
    public function filtersForLocale(string $locale): array
    {
        $locale = BlogCategoryInput::locale($locale);
        try {
            return array_map(
                static fn (PublishedCategoryFilter $filter): array =>
                    $filter->toResourceData(),
                $this->repository->publicFilters($locale)
            );
        } catch (BlogCategoryException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogCategoryException(
                BlogCategoryException::STORAGE_UNAVAILABLE
            );
        }
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
        int $limit = 12,
        int $offset = 0
    ): array {
        $locale = BlogCategoryInput::locale($locale);
        $categorySlug = BlogCategoryInput::slug($categorySlug);
        $limit = BlogCategoryInput::listLimit($limit);
        $offset = BlogCategoryInput::listOffset($offset);
        $basePath = $this->config->publicPath($locale);
        if ($basePath === null) {
            throw new BlogCategoryException(
                BlogCategoryException::INVALID_INPUT
            );
        }
        try {
            return array_map(
                static fn (PublishedPostCard $card): array => [
                    'locale' => $card->locale(),
                    'slug' => $card->slug(),
                    'url' => $basePath . '/' . $card->slug(),
                    'h1' => $card->h1(),
                    'excerpt' => $card->excerpt(),
                    'published_at' => $card->publishedAt()->format(DATE_ATOM),
                    'updated_at' => $card->updatedAt()->format(DATE_ATOM),
                ],
                $this->repository->publicPostCards(
                    $locale,
                    $categorySlug,
                    $limit,
                    $offset
                )
            );
        } catch (BlogCategoryException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogCategoryException(
                BlogCategoryException::STORAGE_UNAVAILABLE
            );
        }
    }
}
