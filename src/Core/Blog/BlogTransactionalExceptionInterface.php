<?php

declare(strict_types=1);

namespace App\Core\Blog;

/**
 * Marker for stable, non-sensitive domain failures that may cross the PDO
 * transaction boundary without being collapsed into a storage error.
 */
interface BlogTransactionalExceptionInterface
{
}
