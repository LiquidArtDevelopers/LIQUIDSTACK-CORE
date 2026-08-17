<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

use InvalidArgumentException;

/** Immutable semantic-level contract shared by validation and the editor SSR. */
final class BlogHeadingLevelPolicy
{
    /** @var list<int> */
    private const ALLOWED_LEVELS = [2, 3, 4, 5, 6];

    /** @var array<string, int> */
    private const CONTEXTUAL_DEFAULTS = [
        'section' => 2,
        'article' => 3,
        'div' => 3,
    ];

    public function allows(mixed $level): bool
    {
        return is_int($level)
            && in_array($level, self::ALLOWED_LEVELS, true);
    }

    /** @return list<int> */
    public function allowedLevels(): array
    {
        return self::ALLOWED_LEVELS;
    }

    public function defaultFor(string $parentType): int
    {
        $level = self::CONTEXTUAL_DEFAULTS[$parentType] ?? null;
        if ($level === null) {
            throw new InvalidArgumentException(
                'Unsupported Blog heading parent type.'
            );
        }

        return $level;
    }

    /**
     * @return array{
     *   allowed_levels: list<int>,
     *   defaults: array{section: int, article: int, div: int}
     * }
     */
    public function toSafeArray(): array
    {
        return [
            'allowed_levels' => self::ALLOWED_LEVELS,
            'defaults' => self::CONTEXTUAL_DEFAULTS,
        ];
    }
}
