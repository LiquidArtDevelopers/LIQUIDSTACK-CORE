<?php

declare(strict_types=1);

namespace App\Core\Blog\Tags;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogInput;
use DateTimeImmutable;

/** Canonical validation shared by private and public tag projections. */
final class BlogTagInput
{
    public const MAX_NAME_CODEPOINTS = 64;
    public const MAX_NAME_BYTES = 255;
    public const MAX_SLUG_BYTES = 190;

    /**
     * Reject transport/control characters before any whitespace folding.
     * ZWJ (U+200D) deliberately remains valid for emoji display names.
     */
    private const PROHIBITED_TEXT_PATTERN =
        '/(?:\p{Cc}|\p{Zl}|\p{Zp}|(?!\x{200D})\p{Cf})/u';

    public static function publicId(string $value): string
    {
        try {
            return BlogInput::publicId($value);
        } catch (BlogException) {
            throw new BlogTagException(BlogTagException::INVALID_INPUT);
        }
    }

    public static function generatedPublicId(string $value): string
    {
        try {
            return BlogInput::generatedPublicId($value);
        } catch (BlogException) {
            throw new BlogTagException(BlogTagException::INVALID_INPUT);
        }
    }

    public static function locale(string $value): string
    {
        try {
            return BlogInput::locale($value);
        } catch (BlogException) {
            throw new BlogTagException(BlogTagException::INVALID_INPUT);
        }
    }

    public static function slug(string $value): string
    {
        if (
            strlen($value) > self::MAX_SLUG_BYTES
            || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value) !== 1
        ) {
            throw new BlogTagException(BlogTagException::INVALID_INPUT);
        }

        return $value;
    }

    public static function name(string $value): string
    {
        $value = self::canonicalText($value);
        if (
            $value === ''
            || strlen($value) > self::MAX_NAME_BYTES
        ) {
            throw new BlogTagException(BlogTagException::INVALID_INPUT);
        }
        $count = preg_match_all('/./us', $value, $matches);
        if ($count === false || $count > self::MAX_NAME_CODEPOINTS) {
            throw new BlogTagException(BlogTagException::INVALID_INPUT);
        }

        return $value;
    }

    /** NFC canonicalization with fail-closed checks on both representations. */
    public static function canonicalText(string $value): string
    {
        if (
            !self::hasSafeTextCharacters($value)
            || !function_exists('normalizer_normalize')
        ) {
            throw new BlogTagException(BlogTagException::INVALID_INPUT);
        }
        $canonical = normalizer_normalize($value, \Normalizer::FORM_C);
        if (
            !is_string($canonical)
            || !self::hasSafeTextCharacters($canonical)
        ) {
            throw new BlogTagException(BlogTagException::INVALID_INPUT);
        }

        return $canonical;
    }

    /**
     * Shared backend policy for raw CSV and canonical tag names.
     * Invalid UTF-8 is unsafe; printable Unicode and ZWJ are accepted.
     */
    public static function hasSafeTextCharacters(string $value): bool
    {
        return preg_match('//u', $value) === 1
            && preg_match(self::PROHIBITED_TEXT_PATTERN, $value) === 0;
    }

    public static function normalizedSha256(string $value): string
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $value) !== 1) {
            throw new BlogTagException(BlogTagException::INVALID_INPUT);
        }

        return $value;
    }

    public static function expectedLockVersion(int $value): int
    {
        try {
            return BlogInput::expectedLockVersion($value);
        } catch (BlogException) {
            throw new BlogTagException(BlogTagException::INVALID_INPUT);
        }
    }

    public static function workspaceVersion(int $value): int
    {
        if ($value < 0 || $value >= PHP_INT_MAX) {
            throw new BlogTagException(BlogTagException::INVALID_INPUT);
        }

        return $value;
    }

    public static function utc(DateTimeImmutable $value): DateTimeImmutable
    {
        return BlogInput::utc($value);
    }
}
