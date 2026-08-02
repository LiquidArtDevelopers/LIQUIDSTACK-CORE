<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent;

use App\Core\Blog\BlogTransactionalExceptionInterface;
use RuntimeException;

/** Stable, content-free issue contract for structured editorial operations. */
final class BlogStructuredContentException extends RuntimeException implements
    BlogTransactionalExceptionInterface
{
    public const INVALID_INPUT = 'blog.structured_content.invalid_input';
    public const STORAGE_UNAVAILABLE =
        'blog.structured_content.storage_unavailable';
    public const CORRUPT_DOCUMENT =
        'blog.structured_content.corrupt_document';
    public const MEDIA_UNAVAILABLE =
        'blog.structured_content.media_unavailable';
    public const MEDIA_NOT_FOUND =
        'blog.structured_content.media_not_found';
    public const REVISION_NOT_FOUND =
        'blog.structured_content.revision_not_found';
    public const PLAIN_SAVE_BLOCKED =
        'blog.structured_content.plain_save_blocked';

    private const MESSAGES = [
        self::INVALID_INPUT => 'Invalid structured Blog content input.',
        self::STORAGE_UNAVAILABLE =>
            'Structured Blog content storage is unavailable.',
        self::CORRUPT_DOCUMENT =>
            'Structured Blog content failed its integrity check.',
        self::MEDIA_UNAVAILABLE =>
            'The structured Blog media catalog is unavailable.',
        self::MEDIA_NOT_FOUND =>
            'A structured Blog media reference is unavailable.',
        self::REVISION_NOT_FOUND =>
            'The structured Blog revision was not found.',
        self::PLAIN_SAVE_BLOCKED =>
            'Structured Blog content cannot be replaced by plain text.',
    ];

    public function __construct(private readonly string $issueCode)
    {
        if (!isset(self::MESSAGES[$issueCode])) {
            throw new \LogicException(
                'Unknown structured Blog content issue code.'
            );
        }

        parent::__construct(self::MESSAGES[$issueCode]);
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
