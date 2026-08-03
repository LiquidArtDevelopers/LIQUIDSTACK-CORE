<?php

declare(strict_types=1);

namespace App\Core\Blog\Sitemap;

use App\Core\Blog\Sitemap\Cache\PrivateBlogSitemapCacheStorage;
use App\Core\Blog\Sitemap\Persistence\BlogSitemapStateRepositoryInterface;
use DateTimeImmutable;

/** Durable pre-commit fence for every public visibility transition. */
final class BlogSitemapPublicationCoordinator
{
    public function __construct(
        private readonly BlogSitemapStateRepositoryInterface $stateRepository,
        private readonly PrivateBlogSitemapCacheStorage $storage
    ) {
    }

    public function begin(): BlogSitemapPublicationFence
    {
        $state = $this->stateRepository->lock();
        $generation = $state->cacheGeneration();
        if ($generation === null
            || !hash_equals($generation, $this->storage->markerGeneration())) {
            throw new \RuntimeException(
                'Blog sitemap cache generation is not active.'
            );
        }
        $lease = $this->storage->acquireExclusive();
        try {
            $this->storage->block(
                $lease,
                $generation,
                $state->publicRevision()
            );

            return new BlogSitemapPublicationFence($state, $lease);
        } catch (\Throwable $exception) {
            $lease->release();
            throw $exception;
        }
    }

    public function complete(
        BlogSitemapPublicationFence $fence,
        DateTimeImmutable $now
    ): void {
        $this->stateRepository->incrementRevision(
            $fence->state()->publicRevision(),
            $now
        );
    }
}
