<?php

declare(strict_types=1);

namespace App\Core\Blog\Admin;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogInput;
use App\Core\Blog\BlogPostVariant;

/** Immutable, bounded input for the private editorial catalog. */
final class BlogAdminCatalogQuery
{
    public const DEFAULT_PAGE_SIZE = 20;
    /** Legacy list size used by non-catalog surfaces such as the trash. */
    public const PAGE_SIZE = 50;
    /** Legacy sentinel bound used by bounded secondary catalog projections. */
    public const OVERFLOW_LIMIT = self::PAGE_SIZE + 1;
    public const DEFAULT_SORT = 'updated';
    public const DEFAULT_DIRECTION = 'desc';
    public const SORT_TITLE = 'title';
    public const SORT_LOCALE = 'locale';
    public const SORT_STATUS = 'status';
    public const SORT_AUTHOR = 'author';
    public const SORT_ROBOTS = 'robots';
    public const SORT_UPDATED = 'updated';
    public const DIRECTION_ASC = 'asc';
    public const DIRECTION_DESC = 'desc';
    public const MIN_SEARCH_CHARACTERS = 2;
    public const MAX_SEARCH_CHARACTERS = 120;
    public const MAX_SEARCH_INPUT_BYTES = 480;

    private readonly ?string $search;
    private readonly ?string $status;
    private readonly ?string $locale;
    private readonly int $offset;
    private readonly int $pageSize;
    private readonly string $sort;
    private readonly string $direction;

    /** @var list<int> */
    private const PAGE_SIZES = [10, 20, 50];

    /** @var list<string> */
    private const SORTS = [
        self::SORT_TITLE,
        self::SORT_LOCALE,
        self::SORT_STATUS,
        self::SORT_AUTHOR,
        self::SORT_ROBOTS,
        self::SORT_UPDATED,
    ];

    public function __construct(
        ?string $search = null,
        ?string $status = null,
        ?string $locale = null,
        int $offset = 0,
        int $pageSize = self::DEFAULT_PAGE_SIZE,
        string $sort = self::DEFAULT_SORT,
        string $direction = self::DEFAULT_DIRECTION
    ) {
        $this->search = self::normalizeSearch($search);
        $this->status = self::normalizeStatus($status);
        $this->locale = self::normalizeLocale($locale);
        $this->pageSize = self::normalizePageSize($pageSize);
        $this->sort = self::normalizeSort($sort);
        $this->direction = self::normalizeDirection($direction);
        $this->offset = BlogInput::listOffset($offset);
        if ($this->offset % $this->pageSize !== 0) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
    }

    public function search(): ?string
    {
        return $this->search;
    }

    public function status(): ?string
    {
        return $this->status;
    }

    public function locale(): ?string
    {
        return $this->locale;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function limit(): int
    {
        return $this->pageSize + 1;
    }

    public function pageSize(): int
    {
        return $this->pageSize;
    }

    public function sort(): string
    {
        return $this->sort;
    }

    public function direction(): string
    {
        return $this->direction;
    }

    public function pageNumber(): int
    {
        return intdiv($this->offset, $this->pageSize) + 1;
    }

    /** @return list<int> */
    public static function pageSizes(): array
    {
        return self::PAGE_SIZES;
    }

    public static function supportsPageSize(int $value): bool
    {
        return in_array($value, self::PAGE_SIZES, true);
    }

    public static function supportsSort(string $value): bool
    {
        return in_array($value, self::SORTS, true);
    }

    public static function supportsDirection(string $value): bool
    {
        return in_array(
            $value,
            [self::DIRECTION_ASC, self::DIRECTION_DESC],
            true
        );
    }

    public function hasFilters(): bool
    {
        return $this->search !== null
            || $this->status !== null
            || $this->locale !== null;
    }

    /** @return array<string, string> */
    public function queryParameters(): array
    {
        $parameters = [];
        if ($this->search !== null) {
            $parameters['q'] = $this->search;
        }
        if ($this->status !== null) {
            $parameters['status'] = $this->status;
        }
        if ($this->locale !== null) {
            $parameters['locale'] = $this->locale;
        }
        if ($this->sort !== self::DEFAULT_SORT) {
            $parameters['sort'] = $this->sort;
        }
        if (
            $this->direction !== self::DEFAULT_DIRECTION
            || $this->sort !== self::DEFAULT_SORT
        ) {
            $parameters['dir'] = $this->direction;
        }
        if ($this->pageSize !== self::DEFAULT_PAGE_SIZE) {
            $parameters['per_page'] = (string) $this->pageSize;
        }

        return $parameters;
    }

    private static function normalizeSearch(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $validated = BlogInput::nullableSingleLine(
            $value,
            self::MAX_SEARCH_INPUT_BYTES
        );
        $normalized = preg_replace('/\s+/u', ' ', $validated ?? '');
        if (!is_string($normalized)) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        $normalized = trim($normalized);
        if ($normalized === '') {
            return null;
        }
        $length = mb_strlen($normalized, 'UTF-8');
        if (
            $length < self::MIN_SEARCH_CHARACTERS
            || $length > self::MAX_SEARCH_CHARACTERS
        ) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $normalized;
    }

    private static function normalizeStatus(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!in_array(
            $value,
            [BlogPostVariant::DRAFT, BlogPostVariant::PUBLISHED],
            true
        )) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $value;
    }

    private static function normalizeLocale(?string $value): ?string
    {
        return $value === null || $value === ''
            ? null
            : BlogInput::locale($value);
    }

    private static function normalizePageSize(int $value): int
    {
        if (!self::supportsPageSize($value)) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $value;
    }

    private static function normalizeSort(string $value): string
    {
        if (!self::supportsSort($value)) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $value;
    }

    private static function normalizeDirection(string $value): string
    {
        if (!self::supportsDirection($value)) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $value;
    }
}
