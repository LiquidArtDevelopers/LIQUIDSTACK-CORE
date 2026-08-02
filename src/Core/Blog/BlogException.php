<?php

declare(strict_types=1);

namespace App\Core\Blog;

use RuntimeException;

/** Stable, non-sensitive errors exposed by the Blog domain boundary. */
final class BlogException extends RuntimeException implements
    BlogTransactionalExceptionInterface
{
    public const INVALID_INPUT = 'blog.invalid_input';
    public const ACTOR_GATE_FAILED = 'blog.actor_gate_failed';
    public const POST_NOT_FOUND = 'blog.post_not_found';
    public const VARIANT_NOT_FOUND = 'blog.variant_not_found';
    public const LOCALE_CONFLICT = 'blog.locale_conflict';
    public const SLUG_CONFLICT = 'blog.slug_conflict';
    public const LOCK_CONFLICT = 'blog.lock_conflict';
    public const INVALID_STATE = 'blog.invalid_state';
    public const PUBLISH_INCOMPLETE = 'blog.publish_incomplete';
    public const SITEMAP_OVERFLOW = 'blog.sitemap_overflow';
    public const STORAGE_UNAVAILABLE = 'blog.storage_unavailable';

    private const MESSAGES = [
        self::INVALID_INPUT => 'Invalid Blog input.',
        self::ACTOR_GATE_FAILED => 'Blog actor authorization failed.',
        self::POST_NOT_FOUND => 'Blog post was not found.',
        self::VARIANT_NOT_FOUND => 'Blog post variant was not found.',
        self::LOCALE_CONFLICT => 'Blog locale already exists.',
        self::SLUG_CONFLICT => 'Blog slug is unavailable.',
        self::LOCK_CONFLICT => 'Blog post variant has changed.',
        self::INVALID_STATE => 'Blog state transition is not allowed.',
        self::PUBLISH_INCOMPLETE => 'Blog post variant is incomplete.',
        self::SITEMAP_OVERFLOW => 'Blog sitemap entry limit was exceeded.',
        self::STORAGE_UNAVAILABLE => 'Blog storage is unavailable.',
    ];

    public function __construct(private readonly string $issueCode)
    {
        if (!isset(self::MESSAGES[$issueCode])) {
            throw new \LogicException('Unknown Blog issue code.');
        }

        parent::__construct(self::MESSAGES[$issueCode]);
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
