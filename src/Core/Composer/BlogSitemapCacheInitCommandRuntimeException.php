<?php

declare(strict_types=1);

namespace App\Core\Composer;

use RuntimeException;

final class BlogSitemapCacheInitCommandRuntimeException extends RuntimeException
{
    public function __construct(private readonly string $issueCode)
    {
        parent::__construct('Blog sitemap cache initialization is unavailable.');
    }

    public function issueCode(): string { return $this->issueCode; }
}
