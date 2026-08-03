<?php

declare(strict_types=1);

namespace App\Core\Blog\Sitemap\Cache;

use RuntimeException;

final class BlogSitemapCacheException extends RuntimeException
{
    public function __construct(private readonly string $issueCode)
    {
        parent::__construct('Blog sitemap cache is unavailable.');
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
