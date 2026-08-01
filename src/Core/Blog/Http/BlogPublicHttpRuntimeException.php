<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use RuntimeException;

/** Stable and non-sensitive public Blog bootstrap failure. */
final class BlogPublicHttpRuntimeException extends RuntimeException
{
    public function __construct(
        private readonly string $issueCode = 'blog.public_runtime_unavailable'
    ) {
        parent::__construct('Blog public runtime is unavailable.');
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
