<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use InvalidArgumentException;

/** Complete, typed category and tag maps for one bounded public card batch. */
final class BlogPublicCardTaxonomyBatch
{
    public const MAX_CATEGORIES_PER_CARD = 100;
    public const MAX_TAGS_PER_CARD = 30;

    /**
     * @param array<string, list<BlogPublicCardCategory>> $categoriesBySlug
     * @param array<string, list<BlogPublicCardTag>> $tagsBySlug
     */
    public function __construct(
        private readonly array $categoriesBySlug,
        private readonly array $tagsBySlug
    ) {
        if (array_keys($categoriesBySlug) !== array_keys($tagsBySlug)) {
            throw new InvalidArgumentException(
                'Public Blog taxonomy maps must cover the same cards.'
            );
        }

        foreach ($categoriesBySlug as $slug => $categories) {
            if (
                !is_string($slug)
                || !array_is_list($categories)
                || count($categories) > self::MAX_CATEGORIES_PER_CARD
            ) {
                throw new InvalidArgumentException(
                    'Invalid public Blog category batch.'
                );
            }
            foreach ($categories as $category) {
                if (!$category instanceof BlogPublicCardCategory) {
                    throw new InvalidArgumentException(
                        'Invalid public Blog category batch.'
                    );
                }
            }

            $tags = $tagsBySlug[$slug];
            if (
                !array_is_list($tags)
                || count($tags) > self::MAX_TAGS_PER_CARD
            ) {
                throw new InvalidArgumentException(
                    'Invalid public Blog tag batch.'
                );
            }
            foreach ($tags as $tag) {
                if (!$tag instanceof BlogPublicCardTag) {
                    throw new InvalidArgumentException(
                        'Invalid public Blog tag batch.'
                    );
                }
            }
        }
    }

    /** @return array<string, list<BlogPublicCardCategory>> */
    public function categoriesBySlug(): array
    {
        return $this->categoriesBySlug;
    }

    /** @return array<string, list<BlogPublicCardTag>> */
    public function tagsBySlug(): array
    {
        return $this->tagsBySlug;
    }
}
