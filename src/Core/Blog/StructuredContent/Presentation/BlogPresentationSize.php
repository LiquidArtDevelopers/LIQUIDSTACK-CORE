<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Presentation;

/** Closed public scale for the overall size of one structured Blog module. */
final class BlogPresentationSize
{
    public const SMALL = 's';
    public const MEDIUM = 'm';
    public const LARGE = 'l';
    public const EXTRA_LARGE = 'xl';

    /** @var array<string, string> */
    private const CANONICAL = [
        self::SMALL => self::SMALL,
        self::MEDIUM => self::MEDIUM,
        self::LARGE => self::LARGE,
        self::EXTRA_LARGE => self::EXTRA_LARGE,
    ];

    /** @var array<string, string> */
    private const LEGACY = [
        'default' => self::MEDIUM,
        'small' => self::SMALL,
        'large' => self::LARGE,
        'xlarge' => self::EXTRA_LARGE,
    ];

    public static function isCanonical(mixed $value): bool
    {
        return is_string($value) && isset(self::CANONICAL[$value]);
    }

    public static function isLegacy(mixed $value): bool
    {
        return is_string($value) && isset(self::LEGACY[$value]);
    }

    public static function canonicalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return self::CANONICAL[$value] ?? self::LEGACY[$value] ?? null;
    }

    /** @return list<string> */
    public static function canonicalValues(): array
    {
        return array_keys(self::CANONICAL);
    }
}
