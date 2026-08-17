<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\PublicDelivery\BlogPublicMediaRoute;
use InvalidArgumentException;

/** One validated AVIF route and its intrinsic dimensions. */
final class BlogPublicCardThumbnailCandidate
{
    public function __construct(
        private readonly string $url,
        private readonly int $width,
        private readonly int $height
    ) {
        $route = BlogPublicMediaRoute::match($url);
        if (
            $route === null
            || $route['width'] !== $width
            || $width < 1
            || $width > 2_560
            || $height < 1
            || $height > 2_560
        ) {
            throw new InvalidArgumentException(
                'Invalid public Blog thumbnail candidate.'
            );
        }
    }

    public function url(): string
    {
        return $this->url;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }
}
