<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use Throwable;

/**
 * Fail-closed boundary between project views and the public Blog read model.
 *
 * Query construction stays outside the operational guard so invalid caller
 * configuration is never disguised as an unavailable storage backend.
 */
final class BlogPublicCollectionResolver
{
    public function __construct(
        private readonly ?BlogPublicCollectionFeedInterface $feed
    ) {
    }

    public static function current(): self
    {
        try {
            return new self(BlogPublicResourceFeed::current());
        } catch (Throwable) {
            return new self(null);
        }
    }

    /**
     * Resolves the common newest-published collection without exposing the
     * query invariants to every consuming view.
     */
    public function latest(
        string $locale,
        int $limit
    ): BlogPublicCollectionViewModel {
        $query = new BlogPublicResourceQuery(
            locale: $locale,
            categoryScope: BlogPublicResourceQuery::SCOPE_ALL,
            categories: [],
            categoryMode: BlogPublicResourceQuery::MODE_ANY,
            excludeDummy: true,
            items: $limit,
            limit: $limit,
            search: null,
            offset: 0,
            excludeSlug: null,
            order: BlogPublicResourceQuery::ORDER_NEWEST
        );

        return $this->resolve($query);
    }

    public function resolve(
        BlogPublicResourceQuery $query
    ): BlogPublicCollectionViewModel {
        if ($this->feed === null) {
            return BlogPublicCollectionViewModel::unavailable();
        }

        try {
            $items = $this->feed->cardsForResource($query);
            return $items === []
                ? BlogPublicCollectionViewModel::empty()
                : BlogPublicCollectionViewModel::ready($items);
        } catch (Throwable) {
            return BlogPublicCollectionViewModel::unavailable();
        }
    }
}
