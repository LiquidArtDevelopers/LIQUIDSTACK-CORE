<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogInput;

/** Immutable, bounded input for the public monthly archive index. */
final class BlogPublicArchivePeriodsQuery
{
    public const DEFAULT_LIMIT = 24;
    public const MAX_LIMIT = 120;
    public const MAX_OFFSET = 10_000;

    private readonly string $locale;

    public function __construct(
        string $locale,
        private readonly int $limit = self::DEFAULT_LIMIT,
        private readonly int $offset = 0
    ) {
        $this->locale = BlogInput::locale($locale);
        if (
            $this->limit < 1
            || $this->limit > self::MAX_LIMIT
            || $this->offset < 0
            || $this->offset > self::MAX_OFFSET
        ) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function offset(): int
    {
        return $this->offset;
    }
}
