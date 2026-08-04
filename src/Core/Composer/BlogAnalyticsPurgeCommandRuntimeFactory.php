<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Blog\Analytics\PdoBlogAnalyticsRepository;
use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Environment\ProjectEnvironmentLoader;
use App\Core\Modules\Blog\BlogMigrationRequirements;
use App\Core\Modules\ConfiguredModuleDatabaseConnectionResolver;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use Closure;
use Throwable;

final class BlogAnalyticsPurgeCommandRuntimeFactory implements
    BlogAnalyticsPurgeCommandRuntimeFactoryInterface
{
    /** @var Closure(array<string, mixed>, string): PdoConnectionFactoryInterface */
    private readonly Closure $connectionFactoryResolver;

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
        private readonly ConfiguredModuleDatabaseConnectionResolver
            $databaseConnectionResolver =
                new ConfiguredModuleDatabaseConnectionResolver()
    ) {
        $this->connectionFactoryResolver = $connectionFactoryResolver === null
            ? static fn (array $environment, string $connection):
                PdoConnectionFactoryInterface =>
                    (new ConfiguredPdoConnectionFactoryResolver())->resolve(
                        $connection,
                        $environment
                    )
            : Closure::fromCallable($connectionFactoryResolver);
    }

    public function create(
        string $projectRoot,
        string $coreRoot
    ): BlogAnalyticsPurgeCommandRuntimeInterface {
        try {
            $registry = ModuleRegistry::forProject($projectRoot, $coreRoot);
            if (!$registry->isEnabled('blog')) {
                throw new BlogAnalyticsPurgeCommandRuntimeException(
                    'blog.analytics.purge.module_not_enabled'
                );
            }
            $environment = $this->environmentLoader->load($projectRoot);
            if (!$environment->isUsable()) {
                throw new BlogAnalyticsPurgeCommandRuntimeException(
                    'blog.analytics.purge.environment_unusable'
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
            $scopes = $this->scopeFactory->create($registry, $projectRoot);
            $scope = $scopes->get('blog');
            if ($scope === null) {
                throw new BlogAnalyticsPurgeCommandRuntimeException(
                    'blog.analytics.purge.scope_unavailable'
                );
            }
            $factory = ($this->connectionFactoryResolver)(
                $environment->values(),
                $this->databaseConnectionResolver->resolve(
                    $registry,
                    $projectRoot
                )
            );
            $pdo = $factory->connect();
            if (!$this->featureGate->isReady(
                $pdo,
                $registry,
                $scopes,
                BlogMigrationRequirements::analyticsCollection()
            )) {
                throw new BlogAnalyticsPurgeCommandRuntimeException(
                    'blog.analytics.purge.schema_not_ready'
                );
            }

            return new BlogAnalyticsPurgeCommandRuntime(
                new PdoBlogAnalyticsRepository($pdo, $scope),
                $config->analytics()->retentionDays()
            );
        } catch (BlogAnalyticsPurgeCommandRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogAnalyticsPurgeCommandRuntimeException(
                'blog.analytics.purge.runtime_unavailable'
            );
        }
    }
}
