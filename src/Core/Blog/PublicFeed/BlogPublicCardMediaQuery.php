<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogInput;

/** Typed, bounded input for one public card-media projection. */
final class BlogPublicCardMediaQuery
{
    public const MAX_CARDS = BlogPublicCatalogQuery::MAX_LIMIT;

    private readonly string $locale;
    /** @var list<string> */
    private readonly array $cardSlugs;

    /** @param list<string> $cardSlugs */
    public function __construct(string $locale, array $cardSlugs)
    {
        $this->locale = BlogInput::locale($locale);
        $normalized = [];
        foreach ($cardSlugs as $slug) {
            if (!is_string($slug)) {
                throw new BlogException(BlogException::INVALID_INPUT);
            }
            $value = BlogInput::slug($slug)
                ?? throw new BlogException(BlogException::INVALID_INPUT);
            $normalized[$value] = $value;
            if (count($normalized) > self::MAX_CARDS) {
                throw new BlogException(BlogException::INVALID_INPUT);
            }
        }
        $this->cardSlugs = array_values($normalized);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @return list<string> */
    public function cardSlugs(): array
    {
        return $this->cardSlugs;
    }
}
