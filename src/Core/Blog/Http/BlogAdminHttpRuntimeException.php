<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use RuntimeException;

/** Stable and non-sensitive Blog administration bootstrap failure. */
final class BlogAdminHttpRuntimeException extends RuntimeException
{
    public function __construct(
        private readonly string $issueCode =
            'blog.admin_runtime_unavailable'
    ) {
        parent::__construct('Blog admin runtime is unavailable.');
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
