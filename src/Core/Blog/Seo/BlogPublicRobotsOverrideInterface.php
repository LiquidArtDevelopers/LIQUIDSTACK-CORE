<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

use App\Core\Blog\BlogPostVariant;

/**
 * Extension point for internal policy such as a future Dummy category.
 *
 * Returning true can only make crawling stricter; it never enables indexing.
 */
interface BlogPublicRobotsOverrideInterface
{
    public function forcesNoIndexNoFollow(BlogPostVariant $variant): bool;
}
