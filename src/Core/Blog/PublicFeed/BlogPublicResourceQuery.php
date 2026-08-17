<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\BlogException;
use App\Core\Blog\Categories\BlogReservedCategoryPolicy;

/**
 * Typed, bounded configuration shared by dynamic public Blog resources.
 *
 * Category scope is explicit: `all` never interprets a category slug as a
 * sentinel, while `selected` requires at least one real localized slug.
 */
final class BlogPublicResourceQuery
{
    public const SCOPE_ALL = 'all';
    public const SCOPE_SELECTED = 'selected';
    public const MODE_ANY = BlogPublicCatalogQuery::MODE_ANY;
    public const MODE_ALL = BlogPublicCatalogQuery::MODE_ALL;
    public const ORDER_NEWEST = BlogPublicCatalogQuery::ORDER_NEWEST;
    public const ORDER_OLDEST = BlogPublicCatalogQuery::ORDER_OLDEST;
    public const ORDER_UPDATED = BlogPublicCatalogQuery::ORDER_UPDATED;
    public const DUMMY_CATEGORY_SLUG = BlogReservedCategoryPolicy::DUMMY_SLUG;
    public const DEFAULT_ITEMS = 12;

    private readonly BlogPublicCatalogQuery $catalogQuery;

    /** @param list<string> $categories */
    public function __construct(
        string $locale,
        private readonly string $categoryScope = self::SCOPE_ALL,
        array $categories = [],
        private readonly string $categoryMode = self::MODE_ANY,
        bool $excludeDummy = true,
        private readonly int $items = self::DEFAULT_ITEMS,
        int $limit = self::DEFAULT_ITEMS,
        ?string $search = null,
        int $offset = 0,
        ?string $excludeSlug = null,
        string $order = self::ORDER_NEWEST
    ) {
        if (
            !in_array(
                $this->categoryScope,
                [self::SCOPE_ALL, self::SCOPE_SELECTED],
                true
            )
            || !in_array(
                $this->categoryMode,
                [self::MODE_ANY, self::MODE_ALL],
                true
            )
            || $this->items < 0
            || $this->items > $limit
            || !$excludeDummy
        ) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        if (
            ($this->categoryScope === self::SCOPE_ALL && $categories !== [])
            || (
                $this->categoryScope === self::SCOPE_SELECTED
                && $categories === []
            )
        ) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        $this->catalogQuery = new BlogPublicCatalogQuery(
            $locale,
            $search,
            $categories,
            $this->categoryMode,
            $limit,
            $offset,
            $excludeSlug,
            [self::DUMMY_CATEGORY_SLUG],
            $order
        );
    }

    public function locale(): string
    {
        return $this->catalogQuery->locale();
    }

    public function categoryScope(): string
    {
        return $this->categoryScope;
    }

    /** @return list<string> */
    public function categories(): array
    {
        return $this->catalogQuery->categorySlugs();
    }

    public function categoryMode(): string
    {
        return $this->categoryMode;
    }

    public function excludeDummy(): bool
    {
        return true;
    }

    public function items(): int
    {
        return $this->items;
    }

    public function limit(): int
    {
        return $this->catalogQuery->limit();
    }

    public function offset(): int
    {
        return $this->catalogQuery->offset();
    }

    public function order(): string
    {
        return $this->catalogQuery->order();
    }

    public function catalogQuery(): BlogPublicCatalogQuery
    {
        return $this->catalogQuery;
    }
}
