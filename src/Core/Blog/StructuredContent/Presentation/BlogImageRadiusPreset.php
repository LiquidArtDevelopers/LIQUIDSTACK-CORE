<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Presentation;

/** Closed radius presets for one public Blog image. */
final class BlogImageRadiusPreset
{
    public const DEFAULT = 'default';
    public const NONE = 'none';
    public const SMALL = 'small';
    public const MEDIUM = 'medium';
    public const LARGE = 'large';

    private const VALUES = [
        self::DEFAULT => true,
        self::NONE => true,
        self::SMALL => true,
        self::MEDIUM => true,
        self::LARGE => true,
    ];

    public static function supports(mixed $value): bool
    {
        return is_string($value) && isset(self::VALUES[$value]);
    }

    public static function resolve(
        mixed $value,
        bool $fullDirectSectionChild
    ): ?string {
        if ($value === null || $value === self::DEFAULT) {
            return $fullDirectSectionChild ? self::NONE : self::MEDIUM;
        }

        return self::supports($value) ? $value : null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_keys(self::VALUES);
    }
}
