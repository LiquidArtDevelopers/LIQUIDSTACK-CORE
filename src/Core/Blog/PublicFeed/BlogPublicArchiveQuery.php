<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogInput;
use DateTimeImmutable;
use DateTimeZone;

/** Immutable, bounded input for one public year or month archive. */
final class BlogPublicArchiveQuery
{
    public const MIN_YEAR = 1000;
    public const MAX_YEAR = 9999;
    public const MAX_STORAGE_TIMESTAMP = '9999-12-31 23:59:59.999999';
    public const MAX_LIMIT = 50;
    public const MAX_OFFSET = 10_000;

    private readonly string $locale;

    public function __construct(
        string $locale,
        private readonly int $year,
        private readonly ?int $month = null,
        private readonly int $limit = 12,
        private readonly int $offset = 0
    ) {
        $this->locale = BlogInput::locale($locale);
        if (
            $this->year < self::MIN_YEAR
            || $this->year > self::MAX_YEAR
            || (
                $this->month !== null
                && ($this->month < 1 || $this->month > 12)
            )
            || $this->limit < 1
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

    public function year(): int
    {
        return $this->year;
    }

    public function month(): ?int
    {
        return $this->month;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function startInclusive(): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf(
            '%04d-%02d-01 00:00:00.000000',
            $this->year,
            $this->month ?? 1
        ), new DateTimeZone('UTC'));
    }

    public function endExclusive(): ?DateTimeImmutable
    {
        if (
            $this->year === self::MAX_YEAR
            && ($this->month === null || $this->month === 12)
        ) {
            return null;
        }

        return $this->startInclusive()->modify(
            $this->month === null ? '+1 year' : '+1 month'
        );
    }
}
