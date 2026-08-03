<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Blog\Sitemap\Cache\PrivateBlogSitemapCacheStorage;
use App\Core\Blog\Sitemap\Persistence\PdoBlogSitemapStateRepository;
use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Environment\ProjectEnvironmentLoader;
use App\Core\Environment\ProjectRuntimeProfile;
use App\Core\Modules\Blog\BlogMigrationRequirements;
use App\Core\Modules\ConfiguredModuleDatabaseConnectionResolver;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use Closure;
use Throwable;

final class BlogSitemapCacheInitCommandRuntimeFactory implements
    BlogSitemapCacheInitCommandRuntimeFactoryInterface
{
    /** @var Closure(array<string, mixed>, string): PdoConnectionFactoryInterface */
    private readonly Closure $connectionFactoryResolver;
    private readonly ConfiguredModuleDatabaseConnectionResolver
        $databaseConnectionResolver;

    /** @param null|callable(array<string, mixed>, string): PdoConnectionFactoryInterface $connectionFactoryResolver */
    public function __construct(
        private readonly ProjectEnvironmentLoader $environmentLoader =
            new ProjectEnvironmentLoader(),
        private readonly BlogConfigLoader $configLoader =
            new BlogConfigLoader(),
        private readonly ConfiguredMigrationScopeFactory $scopeFactory =
            new ConfiguredMigrationScopeFactory(),
        private readonly MigrationFeatureGate $featureGate =
            new MigrationFeatureGate(),
        ?callable $connectionFactoryResolver = null,
        ?ConfiguredModuleDatabaseConnectionResolver
            $databaseConnectionResolver = null
    ) {
        $this->connectionFactoryResolver = $connectionFactoryResolver === null
            ? static fn (array $environment, string $connection):
                PdoConnectionFactoryInterface =>
                    (new ConfiguredPdoConnectionFactoryResolver())->resolve(
                        $connection,
                        $environment
                    )
            : Closure::fromCallable($connectionFactoryResolver);
        $this->databaseConnectionResolver = $databaseConnectionResolver
            ?? new ConfiguredModuleDatabaseConnectionResolver();
    }

    public function create(
        string $projectRoot,
        string $coreRoot,
        bool $sharedStorageConfirmed
    ): BlogSitemapCacheInitCommandRuntimeInterface {
        try {
            $registry = ModuleRegistry::forProject($projectRoot, $coreRoot);
            if (!$registry->isEnabled('blog')) {
                throw new BlogSitemapCacheInitCommandRuntimeException(
                    'blog.sitemap_cache.init.module_not_enabled'
                );
            }
            $environment = $this->environmentLoader->load($projectRoot);
            if (!$environment->isUsable()) {
                throw new BlogSitemapCacheInitCommandRuntimeException(
                    'blog.sitemap_cache.init.environment_unusable'
                );
            }
            $profile = ProjectRuntimeProfile::fromEnvironment(
                $environment->values()
            );
            if (!$profile->isDevelopmentLoopbackHttp()
                && !$sharedStorageConfirmed) {
                throw new BlogSitemapCacheInitCommandRuntimeException(
                    'blog.sitemap_cache.init.shared_storage_confirmation_required'
                );
            }
            $context = new ModuleRuntimeContext(
                $projectRoot,
                $environment->values(),
                true
            );
            $config = $this->configLoader->load(
                $projectRoot,
                $context->languages()
            );
            if (!$config->sitemapCache()->enabled()) {
                throw new BlogSitemapCacheInitCommandRuntimeException(
                    'blog.sitemap_cache.init.not_enabled'
                );
            }
            $scopes = $this->scopeFactory->create($registry, $projectRoot);
            $blogScope = $scopes->get('blog');
            if ($blogScope === null) {
                throw new BlogSitemapCacheInitCommandRuntimeException(
                    'blog.sitemap_cache.init.scope_unavailable'
                );
            }
            $connectionFactory = ($this->connectionFactoryResolver)(
                $environment->values(),
                $this->databaseConnectionResolver->resolve(
                    $registry,
                    $projectRoot
                )
            );
            $pdo = $connectionFactory->connect();
            if (!$this->featureGate->isReady(
                $pdo,
                $registry,
                $scopes,
                BlogMigrationRequirements::sitemapCache()
            )) {
                throw new BlogSitemapCacheInitCommandRuntimeException(
                    'blog.sitemap_cache.init.schema_not_ready'
                );
            }

            return new BlogSitemapCacheInitCommandRuntime(
                $pdo,
                new PdoBlogSitemapStateRepository($pdo, $blogScope),
                PrivateBlogSitemapCacheStorage::forProject(
                    $projectRoot,
                    $environment->values()
                )
            );
        } catch (BlogSitemapCacheInitCommandRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogSitemapCacheInitCommandRuntimeException(
                'blog.sitemap_cache.init.runtime_unavailable'
            );
        }
    }
}
