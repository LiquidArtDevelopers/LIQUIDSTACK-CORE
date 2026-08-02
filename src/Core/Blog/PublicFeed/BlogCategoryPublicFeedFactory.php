<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\Categories\BlogCategoryPublicProjectionService;
use App\Core\Blog\Categories\Persistence\PdoBlogCategoryRepository;
use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Blog\Http\BlogPublicHttpRuntimeException;
use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Modules\Blog\BlogCategoryHttpSchemaGate;
use App\Core\Modules\ConfiguredModuleDatabaseConnectionResolver;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use Closure;
use Throwable;

/**
 * Builds category filters/cards for project-owned views without exposing PDO,
 * table prefixes or internal persistence identifiers to a resource.
 */
final class BlogCategoryPublicFeedFactory
{
    /** @var Closure(array<string, mixed>, string): PdoConnectionFactoryInterface */
    private readonly Closure $connectionFactoryResolver;
    private readonly ConfiguredModuleDatabaseConnectionResolver
        $databaseConnectionResolver;

    /**
     * @param null|callable(array<string, mixed>, string): PdoConnectionFactoryInterface $connectionFactoryResolver
     */
    public function __construct(
        private readonly ?string $coreRoot = null,
        ?callable $connectionFactoryResolver = null,
        private readonly BlogConfigLoader $configLoader =
            new BlogConfigLoader(),
        private readonly ConfiguredMigrationScopeFactory $scopeFactory =
            new ConfiguredMigrationScopeFactory(),
        private readonly BlogCategoryHttpSchemaGate $schemaGate =
            new BlogCategoryHttpSchemaGate(),
        ?ConfiguredModuleDatabaseConnectionResolver
            $databaseConnectionResolver = null
    ) {
        $this->connectionFactoryResolver = $connectionFactoryResolver === null
            ? static fn (
                array $environment,
                string $connection
            ): PdoConnectionFactoryInterface =>
                (new ConfiguredPdoConnectionFactoryResolver())->resolve(
                    $connection,
                    $environment
                )
            : Closure::fromCallable($connectionFactoryResolver);
        $this->databaseConnectionResolver = $databaseConnectionResolver
            ?? new ConfiguredModuleDatabaseConnectionResolver();
    }

    /** @param array<string, mixed> $environment */
    public function create(
        string $projectRoot,
        array $environment,
        bool $environmentUsable = true
    ): BlogCategoryPublicProjectionService {
        try {
            if (!$environmentUsable) {
                throw new BlogPublicHttpRuntimeException(
                    'blog.environment_unusable'
                );
            }
            $context = new ModuleRuntimeContext(
                $projectRoot,
                $environment,
                true
            );
            $registry = ModuleRegistry::forProject(
                $projectRoot,
                $this->coreRoot ?? dirname(__DIR__, 4)
            );
            if (
                !$registry->isEnabled('blog')
                || !$registry->isEnabled('webadmin')
            ) {
                throw new BlogPublicHttpRuntimeException(
                    'blog.module_not_enabled'
                );
            }

            $config = $this->configLoader->load(
                $projectRoot,
                $context->languages()
            );
            $scopes = $this->scopeFactory->create($registry, $projectRoot);
            $blogScope = $scopes->get('blog');
            if (
                $blogScope === null
                || $blogScope->tablePrefix() !== $config->tablePrefix()
            ) {
                throw new BlogPublicHttpRuntimeException(
                    'blog.scope_unavailable'
                );
            }

            $connectionFactory = ($this->connectionFactoryResolver)(
                $environment,
                $this->databaseConnectionResolver->resolve(
                    $registry,
                    $projectRoot
                )
            );
            if (!$connectionFactory instanceof PdoConnectionFactoryInterface) {
                throw new BlogPublicHttpRuntimeException(
                    'blog.connection_factory_invalid'
                );
            }
            $pdo = $connectionFactory->connect();
            if (!$this->schemaGate->isPublicReady(
                $pdo,
                $registry,
                $scopes
            )) {
                throw new BlogPublicHttpRuntimeException(
                    'blog.categories.schema_not_ready'
                );
            }

            return new BlogCategoryPublicProjectionService(
                $config,
                new PdoBlogCategoryRepository($pdo, $blogScope)
            );
        } catch (BlogPublicHttpRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPublicHttpRuntimeException(
                'blog.categories.public_feed_unavailable'
            );
        }
    }
}
