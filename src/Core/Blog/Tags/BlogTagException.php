<?php

declare(strict_types=1);

namespace App\Core\Blog\Tags;

use RuntimeException;

final class BlogTagException extends RuntimeException
{
    public const INVALID_INPUT = 'blog.tag.invalid_input';
    public const VARIANT_NOT_FOUND = 'blog.tag.variant_not_found';
    public const LOCK_CONFLICT = 'blog.tag.lock_conflict';
    public const STORAGE_UNAVAILABLE = 'blog.tag.storage_unavailable';

    private const MESSAGES = [
        self::INVALID_INPUT => 'Invalid Blog tag input.',
        self::VARIANT_NOT_FOUND => 'Blog variant was not found.',
        self::LOCK_CONFLICT => 'Blog tags have changed.',
        self::STORAGE_UNAVAILABLE => 'Blog tag storage is unavailable.',
    ];

    public function __construct(private readonly string $issueCode)
    {
        if (!isset(self::MESSAGES[$issueCode])) {
            throw new \LogicException('Unknown Blog tag issue code.');
        }
        parent::__construct(self::MESSAGES[$issueCode]);
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
