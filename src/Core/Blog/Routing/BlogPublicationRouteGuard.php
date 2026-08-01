<?php

declare(strict_types=1);

namespace App\Core\Blog\Routing;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogInput;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Routing\StaticRouteCollisionInspector;
use Throwable;

final class BlogPublicationRouteGuard
{
    public function __construct(
        private readonly StaticRouteCollisionInspector $inspector =
            new StaticRouteCollisionInspector()
    ) {
    }

    public function assertAvailable(
        string $projectRoot,
        BlogConfig $config,
        string $locale,
        string $slug
    ): void {
        $locale = BlogInput::locale($locale);
        $slug = BlogInput::slug($slug)
            ?? throw new BlogException(BlogException::INVALID_INPUT);
        $base = $config->publicPath($locale);
        if ($base === null) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        $path = $base . '/' . $slug;

        try {
            $assessment = $this->inspector->inspect($projectRoot, $path);
        } catch (Throwable) {
            throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
        }
        if (!$assessment['complete']) {
            throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
        }
        foreach ($assessment['collisions'] as $collision) {
            if ($collision['route'] === $path) {
                throw new BlogException(BlogException::SLUG_CONFLICT);
            }
        }

        $publicTarget = rtrim($projectRoot, '/\\') . '/public' . $path;
        if (file_exists($publicTarget) || is_link($publicTarget)) {
            throw new BlogException(BlogException::SLUG_CONFLICT);
        }
    }
}
