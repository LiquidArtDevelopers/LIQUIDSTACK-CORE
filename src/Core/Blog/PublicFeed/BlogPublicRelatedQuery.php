<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogInput;

/** Immutable, bounded input for category-based related public posts. */
final class BlogPublicRelatedQuery
{
    public const DEFAULT_LIMIT = 3;
    public const MAX_LIMIT = 12;

    private readonly string $locale;
    private readonly string $sourceSlug;

    public function __construct(
        string $locale,
        string $sourceSlug,
        private readonly int $limit = self::DEFAULT_LIMIT
    ) {
        $this->locale = BlogInput::locale($locale);
        $this->sourceSlug = BlogInput::slug($sourceSlug)
            ?? throw new BlogException(BlogException::INVALID_INPUT);
        if ($this->limit < 1 || $this->limit > self::MAX_LIMIT) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function sourceSlug(): string
    {
        return $this->sourceSlug;
    }

    public function limit(): int
    {
        return $this->limit;
    }
}
