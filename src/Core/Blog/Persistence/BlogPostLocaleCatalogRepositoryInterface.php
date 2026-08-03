<?php

declare(strict_types=1);

namespace App\Core\Blog\Persistence;

/**
 * Optional read capability for the locales already owned by one post.
 *
 * Keeping this outside BlogRepositoryInterface preserves existing repository
 * adapters while the canonical PDO implementation can drive the explicit
 * locale selector in WebAdmin.
 */
interface BlogPostLocaleCatalogRepositoryInterface
{
    /**
     * @return list<string>|null Existing locales, or null when the aggregate
     *     does not exist.
     */
    public function localesForPost(string $postPublicId, int $limit): ?array;
}
