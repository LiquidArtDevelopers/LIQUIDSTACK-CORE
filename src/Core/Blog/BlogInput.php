<?php

declare(strict_types=1);

namespace App\Core\Blog;

use DateTimeImmutable;
use DateTimeZone;

/** Shared canonical validation for public Blog contracts and DB hydration. */
final class BlogInput
{
    public const MAX_LIST_LIMIT = 100;
    public const MAX_LIST_OFFSET = 1_000_000;

    private const UUID =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';
    private const UUID_V4 =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    public static function publicId(string $value): string
    {
        if (preg_match(self::UUID, $value) !== 1) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $value;
    }

    public static function generatedPublicId(string $value): string
    {
        if (preg_match(self::UUID_V4, $value) !== 1) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $value;
    }

    public static function locale(string $value): string
    {
        if (
            strlen($value) < 2
            || strlen($value) > 16
            || preg_match(
                '/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})*\z/',
                $value
            ) !== 1
        ) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $value;
    }

    public static function slug(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (
            strlen($value) > BlogDraft::MAX_SLUG_BYTES
            || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value) !== 1
        ) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $value;
    }

    public static function lockVersion(int $value): int
    {
        if ($value < 1) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $value;
    }

    public static function expectedLockVersion(int $value): int
    {
        if ($value < 1 || $value >= PHP_INT_MAX) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $value;
    }

    public static function listLimit(int $value): int
    {
        if ($value < 1 || $value > self::MAX_LIST_LIMIT) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $value;
    }

    public static function listOffset(int $value): int
    {
        if ($value < 0 || $value > self::MAX_LIST_OFFSET) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $value;
    }

    public static function utc(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->setTimezone(new DateTimeZone('UTC'));
    }

    public static function requiredSingleLine(
        string $value,
        int $maxBytes
    ): string {
        $validated = self::singleLine($value, $maxBytes);
        if (trim($validated) === '') {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $validated;
    }

    public static function nullableSingleLine(
        ?string $value,
        int $maxBytes
    ): ?string {
        return $value === null ? null : self::singleLine($value, $maxBytes);
    }

    public static function multiline(
        string $value,
        int $maxBytes
    ): string {
        self::assertUtf8AndLength($value, $maxBytes);
        if (
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1
            || strip_tags($value) !== $value
        ) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $value);
        if (strlen($normalized) > $maxBytes) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $normalized;
    }

    public static function nullableMultiline(
        ?string $value,
        int $maxBytes
    ): ?string {
        return $value === null ? null : self::multiline($value, $maxBytes);
    }

    private static function singleLine(string $value, int $maxBytes): string
    {
        self::assertUtf8AndLength($value, $maxBytes);
        if (
            preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || strip_tags($value) !== $value
        ) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $value;
    }

    private static function assertUtf8AndLength(
        string $value,
        int $maxBytes
    ): void {
        if (
            $maxBytes < 1
            || strlen($value) > $maxBytes
            || preg_match('//u', $value) !== 1
        ) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
    }
}
