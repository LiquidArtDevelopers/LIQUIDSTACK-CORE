<?php

declare(strict_types=1);

namespace App\Core\Blog\Persistence;

/** Immutable destination recovered from an idempotent copy request. */
final readonly class BlogCopyOperationResult
{
    public function __construct(
        private string $postPublicId,
        private string $locale
    ) {
    }

    public function postPublicId(): string
    {
        return $this->postPublicId;
    }

    public function locale(): string
    {
        return $this->locale;
    }
}
