<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogInput;

/** Immutable, bounded input for the public Blog catalog read model. */
final class BlogPublicCatalogQuery
{
    public const MODE_ANY = 'any';
    public const MODE_ALL = 'all';
    public const MIN_SEARCH_CHARACTERS = 2;
    public const MAX_SEARCH_CHARACTERS = 120;
    public const MAX_SEARCH_INPUT_BYTES = 480;
    public const MAX_CATEGORIES = 10;
    public const MAX_LIMIT = 50;
    public const MAX_OFFSET = 10_000;

    private readonly string $locale;
    private readonly ?string $search;
    /** @var list<string> */
    private readonly array $categorySlugs;
    private readonly string $categoryMode;
    private readonly int $limit;
    private readonly int $offset;
    private readonly ?string $excludeSlug;

    /** @param list<string> $categorySlugs */
    public function __construct(
        string $locale,
        ?string $search = null,
        array $categorySlugs = [],
        string $categoryMode = self::MODE_ANY,
        int $limit = 12,
        int $offset = 0,
        ?string $excludeSlug = null
    ) {
        $this->locale = BlogInput::locale($locale);
        $this->search = self::normalizeSearch($search);
        $this->categorySlugs = self::normalizeCategorySlugs(
            $categorySlugs
        );
        if (!in_array(
            $categoryMode,
            [self::MODE_ANY, self::MODE_ALL],
            true
        )) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        if ($offset < 0 || $offset > self::MAX_OFFSET) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        $this->categoryMode = $categoryMode;
        $this->limit = $limit;
        $this->offset = $offset;
        $this->excludeSlug = $excludeSlug === null
            ? null
            : (BlogInput::slug($excludeSlug)
                ?? throw new BlogException(BlogException::INVALID_INPUT));
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function search(): ?string
    {
        return $this->search;
    }

    /** @return list<string> */
    public function categorySlugs(): array
    {
        return $this->categorySlugs;
    }

    public function categoryMode(): string
    {
        return $this->categoryMode;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function excludeSlug(): ?string
    {
        return $this->excludeSlug;
    }

    public function hasFilters(): bool
    {
        return $this->search !== null
            || $this->categorySlugs !== []
            || $this->excludeSlug !== null;
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

    /** @param array<array-key, mixed> $values @return list<string> */
    private static function normalizeCategorySlugs(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new BlogException(BlogException::INVALID_INPUT);
            }
            $slug = BlogInput::slug($value);
            if ($slug === null) {
                throw new BlogException(BlogException::INVALID_INPUT);
            }
            $normalized[$slug] = $slug;
            if (count($normalized) > self::MAX_CATEGORIES) {
                throw new BlogException(BlogException::INVALID_INPUT);
            }
        }

        return array_values($normalized);
    }
}
