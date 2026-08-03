<?php

declare(strict_types=1);

namespace App\Core\Blog\Sitemap\Persistence;

use RuntimeException;

final class BlogSitemapStateException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Blog sitemap publication state is unavailable.');
    }
}
