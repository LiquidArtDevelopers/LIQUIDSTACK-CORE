<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogInput;

/** Immutable SSR/load-more projection; it contains presentation data only. */
final class BlogPublicResourceBatch
{
    /** @var list<array<string, mixed>> */
    private readonly array $items;
    private readonly ?string $nextUrl;

    /** @param list<array<string, mixed>> $items */
    public function __construct(
        array $items,
        private readonly bool $hasNext,
        private readonly ?int $nextOffset,
        ?string $nextUrl = null
    ) {
        if (!array_is_list($items)) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new BlogException(BlogException::INVALID_INPUT);
            }
        }
        if (
            ($this->hasNext && ($this->nextOffset ?? -1) < 0)
            || (!$this->hasNext && $this->nextOffset !== null)
            || (!$this->hasNext && $nextUrl !== null)
        ) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        $this->items = $items;
        $this->nextUrl = self::normalizeNextUrl($nextUrl);
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        return $this->items;
    }

    public function hasNext(): bool
    {
        return $this->hasNext;
    }

    public function nextOffset(): ?int
    {
        return $this->nextOffset;
    }

    public function nextUrl(): ?string
    {
        return $this->nextUrl;
    }

    /**
     * @return array{
     *     items:list<array<string,mixed>>,
     *     has_next:bool,
     *     next_offset:?int,
     *     next_url:?string
     * }
     */
    public function toResourceData(): array
    {
        return [
            'items' => $this->items,
            'has_next' => $this->hasNext,
            'next_offset' => $this->nextOffset,
            'next_url' => $this->nextUrl,
        ];
    }

    private static function normalizeNextUrl(?string $nextUrl): ?string
    {
        if ($nextUrl === null) {
            return null;
        }
        $nextUrl = BlogInput::requiredSingleLine($nextUrl, 2_048);
        if (
            !str_starts_with($nextUrl, '/')
            || str_starts_with($nextUrl, '//')
            || str_contains($nextUrl, '\\')
        ) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $nextUrl;
    }
}
