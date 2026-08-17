<?php

declare(strict_types=1);

use App\Core\Blog\PublicFeed\BlogPublicCollectionResolver;

/*
 * Managed one-require support for reusable public Blog collections.
 *
 * After this file returns, `$blogPublicCollections` can resolve a typed,
 * presentation-only collection for any project resource. This adapter emits
 * no headers or HTML and contains no project-specific query or copy.
 */

$blogPublicCollections = BlogPublicCollectionResolver::current();
