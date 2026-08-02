<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\PublishedPostCard;

/**
 * Presentation boundary for project-owned Blog indexes and resources.
 *
 * Resources consume the returned public arrays; they never receive PDO or
 * query the module database themselves.
 */
final class BlogPublicFeed
{
    public function __construct(
        private readonly BlogConfig $config,
        private readonly BlogService $service
    ) {
    }

    /**
     * @return list<array{
     *     locale: string,
     *     slug: string,
     *     url: string,
     *     h1: string,
     *     excerpt: string,
     *     published_at: string,
     *     updated_at: string
     * }>
     */
    public function cards(
        string $locale,
        int $limit = BlogService::DEFAULT_PUBLIC_LIST_LIMIT,
        int $offset = 0
    ): array {
        $basePath = $this->config->publicPath($locale);
        if ($basePath === null) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return array_map(
            static function (PublishedPostCard $card) use ($basePath): array {
                return [
                    'locale' => $card->locale(),
                    'slug' => $card->slug(),
                    'url' => $basePath . '/' . $card->slug(),
                    'h1' => $card->h1(),
                    'excerpt' => $card->excerpt(),
                    'published_at' => $card->publishedAt()->format(DATE_ATOM),
                    'updated_at' => $card->updatedAt()->format(DATE_ATOM),
                ];
            },
            $this->service->listPublishedCards($locale, $limit, $offset)
        );
    }
}
