<?php

declare(strict_types=1);

namespace App\Core\Blog\Sitemap;

use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Sitemap\Cache\BlogSitemapCacheException;
use App\Core\Blog\Sitemap\Cache\PrivateBlogSitemapCacheStorage;
use App\Core\Blog\Sitemap\Persistence\PdoBlogSitemapStateRepository;
use App\Core\Modules\Blog\BlogMigrationRequirements;
use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PDO;

/** Single construction boundary for every cache-aware Blog write runtime. */
final class BlogSitemapPublicationCoordinatorFactory
{
    public function __construct(
        private readonly MigrationFeatureGate $featureGate =
            new MigrationFeatureGate()
    ) {
    }

    /** @param array<string, mixed> $environment */
    public function create(
        BlogConfig $config,
        PDO $pdo,
        ModuleRegistry $registry,
        MigrationScopeCollection $scopes,
        MigrationScope $blogScope,
        string $projectRoot,
        array $environment
    ): ?BlogSitemapPublicationCoordinator {
        if (!$config->sitemapCache()->enabled()) {
            return null;
        }
        if (!$this->featureGate->isReady(
            $pdo,
            $registry,
            $scopes,
            BlogMigrationRequirements::sitemapCache()
        )) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache_schema_not_ready'
            );
        }
        $storage = PrivateBlogSitemapCacheStorage::forProject(
            $projectRoot,
            $environment
        );
        if (($storage->diagnostic()['ready'] ?? false) !== true) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache_storage_not_ready'
            );
        }
        $state = new PdoBlogSitemapStateRepository($pdo, $blogScope);
        $generation = $state->current()->cacheGeneration();
        if ($generation === null
            || !hash_equals($generation, $storage->markerGeneration())) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache_generation_mismatch'
            );
        }

        return new BlogSitemapPublicationCoordinator($state, $storage);
    }
}
