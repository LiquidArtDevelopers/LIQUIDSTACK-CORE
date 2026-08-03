<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Blog\Sitemap\Cache\BlogSitemapCacheInitializationResult;
use App\Core\Blog\Sitemap\Cache\PrivateBlogSitemapCacheStorage;
use App\Core\Blog\Sitemap\Persistence\PdoBlogSitemapStateRepository;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\SystemClock;
use PDO;
use Throwable;

final class BlogSitemapCacheInitCommandRuntime implements
    BlogSitemapCacheInitCommandRuntimeInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PdoBlogSitemapStateRepository $stateRepository,
        private readonly PrivateBlogSitemapCacheStorage $storage,
        private readonly ClockInterface $clock = new SystemClock()
    ) {
    }

    public function initialize(): BlogSitemapCacheInitializationResult
    {
        if ($this->pdo->inTransaction() || !$this->pdo->beginTransaction()) {
            throw new BlogSitemapCacheInitCommandRuntimeException(
                'blog.sitemap_cache.init.transaction_failed'
            );
        }
        try {
            $state = $this->stateRepository->lock();
            $result = $this->storage->initialize();
            $generation = $state->cacheGeneration();
            $activated = false;
            if ($generation === null) {
                $this->stateRepository->activateGeneration(
                    $result->generation(),
                    $this->clock->now()
                );
                $activated = true;
            } elseif (!hash_equals($generation, $result->generation())) {
                throw new BlogSitemapCacheInitCommandRuntimeException(
                    'blog.sitemap_cache.init.generation_mismatch'
                );
            }
            if (!$this->pdo->commit()) {
                throw new BlogSitemapCacheInitCommandRuntimeException(
                    'blog.sitemap_cache.init.transaction_failed'
                );
            }

            return $activated && !$result->changed()
                ? new BlogSitemapCacheInitializationResult(
                    $result->generation(),
                    true
                )
                : $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if ($exception instanceof BlogSitemapCacheInitCommandRuntimeException) {
                throw $exception;
            }
            throw new BlogSitemapCacheInitCommandRuntimeException(
                'blog.sitemap_cache.init.failed'
            );
        }
    }
}
