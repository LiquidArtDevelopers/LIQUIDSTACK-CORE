<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicDelivery;

use RuntimeException;

/** Stable, non-sensitive failure at the public Blog media boundary. */
final class BlogPublicMediaException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Blog public media is unavailable.');
    }
}
