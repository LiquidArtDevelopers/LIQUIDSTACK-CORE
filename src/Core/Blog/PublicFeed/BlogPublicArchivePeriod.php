<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogInput;

/** Public monthly archive projection without persistence identifiers. */
final class BlogPublicArchivePeriod
{
    private readonly string $locale;

    public function __construct(
        string $locale,
        private readonly int $year,
        private readonly int $month,
        private readonly int $count
    ) {
        $this->locale = BlogInput::locale($locale);
        if (
            $this->year < BlogPublicArchiveQuery::MIN_YEAR
            || $this->year > BlogPublicArchiveQuery::MAX_YEAR
            || $this->month < 1
            || $this->month > 12
            || $this->count < 1
        ) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function year(): int
    {
        return $this->year;
    }

    public function month(): int
    {
        return $this->month;
    }

    public function count(): int
    {
        return $this->count;
    }

    /** @return array{locale: string, year: int, month: int, count: int} */
    public function toResourceData(): array
    {
        return [
            'locale' => $this->locale,
            'year' => $this->year,
            'month' => $this->month,
            'count' => $this->count,
        ];
    }
}
