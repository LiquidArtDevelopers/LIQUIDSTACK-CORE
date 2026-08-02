<?php

declare(strict_types=1);

namespace App\Core\Blog\Categories;

use RuntimeException;

final class BlogCategoryException extends RuntimeException
{
    public const INVALID_INPUT = 'blog.category.invalid_input';
    public const NOT_FOUND = 'blog.category.not_found';
    public const POST_NOT_FOUND = 'blog.category.post_not_found';
    public const LOCALE_CONFLICT = 'blog.category.locale_conflict';
    public const SLUG_CONFLICT = 'blog.category.slug_conflict';
    public const LOCK_CONFLICT = 'blog.category.lock_conflict';
    public const STORAGE_UNAVAILABLE = 'blog.category.storage_unavailable';

    private const MESSAGES = [
        self::INVALID_INPUT => 'Invalid Blog category input.',
        self::NOT_FOUND => 'Blog category was not found.',
        self::POST_NOT_FOUND => 'Blog post was not found.',
        self::LOCALE_CONFLICT => 'Blog category locale already exists.',
        self::SLUG_CONFLICT => 'Blog category slug is unavailable.',
        self::LOCK_CONFLICT => 'Blog category localization has changed.',
        self::STORAGE_UNAVAILABLE => 'Blog category storage is unavailable.',
    ];

    public function __construct(private readonly string $issueCode)
    {
        if (!isset(self::MESSAGES[$issueCode])) {
            throw new \LogicException('Unknown Blog category issue code.');
        }
        parent::__construct(self::MESSAGES[$issueCode]);
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
