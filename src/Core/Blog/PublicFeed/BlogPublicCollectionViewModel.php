<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\BlogException;

/** Immutable presentation state for one bounded public Blog collection. */
final class BlogPublicCollectionViewModel
{
    public const STATE_READY = 'ready';
    public const STATE_EMPTY = 'empty';
    public const STATE_UNAVAILABLE = 'unavailable';

    /** @var list<array<string, mixed>> */
    private readonly array $items;

    /** @param list<array<string, mixed>> $items */
    private function __construct(
        private readonly string $state,
        array $items
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
            ($this->state === self::STATE_READY && $items === [])
            || (
                in_array(
                    $this->state,
                    [self::STATE_EMPTY, self::STATE_UNAVAILABLE],
                    true
                )
                && $items !== []
            )
            || !in_array($this->state, [
                self::STATE_READY,
                self::STATE_EMPTY,
                self::STATE_UNAVAILABLE,
            ], true)
        ) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        $this->items = $items;
    }

    /** @param list<array<string, mixed>> $items */
    public static function ready(array $items): self
    {
        return new self(self::STATE_READY, $items);
    }

    public static function empty(): self
    {
        return new self(self::STATE_EMPTY, []);
    }

    public static function unavailable(): self
    {
        return new self(self::STATE_UNAVAILABLE, []);
    }

    public function state(): string
    {
        return $this->state;
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isReady(): bool
    {
        return $this->state === self::STATE_READY;
    }

    public function isEmpty(): bool
    {
        return $this->state === self::STATE_EMPTY;
    }

    public function isUnavailable(): bool
    {
        return $this->state === self::STATE_UNAVAILABLE;
    }
}
