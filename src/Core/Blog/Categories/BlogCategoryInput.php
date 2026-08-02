<?php

declare(strict_types=1);

namespace App\Core\Blog\Categories;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogInput;
use DateTimeImmutable;

final class BlogCategoryInput
{
    public static function publicId(string $value): string
    {
        try {
            return BlogInput::publicId($value);
        } catch (BlogException) {
            throw new BlogCategoryException(
                BlogCategoryException::INVALID_INPUT
            );
        }
    }

    public static function generatedPublicId(string $value): string
    {
        try {
            return BlogInput::generatedPublicId($value);
        } catch (BlogException) {
            throw new BlogCategoryException(
                BlogCategoryException::INVALID_INPUT
            );
        }
    }

    public static function locale(string $value): string
    {
        try {
            return BlogInput::locale($value);
        } catch (BlogException) {
            throw new BlogCategoryException(
                BlogCategoryException::INVALID_INPUT
            );
        }
    }

    public static function slug(string $value): string
    {
        try {
            return BlogInput::slug($value)
                ?? throw new BlogException(BlogException::INVALID_INPUT);
        } catch (BlogException) {
            throw new BlogCategoryException(
                BlogCategoryException::INVALID_INPUT
            );
        }
    }

    public static function name(string $value): string
    {
        try {
            return BlogInput::requiredSingleLine(
                $value,
                BlogCategoryDraft::MAX_NAME_BYTES
            );
        } catch (BlogException) {
            throw new BlogCategoryException(
                BlogCategoryException::INVALID_INPUT
            );
        }
    }

    public static function expectedLockVersion(int $value): int
    {
        try {
            return BlogInput::expectedLockVersion($value);
        } catch (BlogException) {
            throw new BlogCategoryException(
                BlogCategoryException::INVALID_INPUT
            );
        }
    }

    public static function listLimit(int $value): int
    {
        try {
            return BlogInput::listLimit($value);
        } catch (BlogException) {
            throw new BlogCategoryException(
                BlogCategoryException::INVALID_INPUT
            );
        }
    }

    public static function listOffset(int $value): int
    {
        try {
            return BlogInput::listOffset($value);
        } catch (BlogException) {
            throw new BlogCategoryException(
                BlogCategoryException::INVALID_INPUT
            );
        }
    }

    public static function utc(DateTimeImmutable $value): DateTimeImmutable
    {
        return BlogInput::utc($value);
    }
}
