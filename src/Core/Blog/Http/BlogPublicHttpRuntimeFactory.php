<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Database\SharedPdoConnectionFactory;
use App\Core\Modules\Blog\BlogHttpSchemaGate;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use Closure;
use Throwable;

final class BlogPublicHttpRuntimeFactory implements
    BlogPublicHttpRuntimeFactoryInterface
{
    /** @var Closure(array<string, mixed>): PdoConnectionFactoryInterface */
    private readonly Closure $connectionFactoryResolver;

    /**
     * @param null|callable(array<string, mixed>): PdoConnectionFactoryInterface $connectionFactoryResolver
     */
    public function __construct(
        private readonly ?string $coreRoot = null,
        ?callable $connectionFactoryResolver = null,
        private readonly BlogConfigLoader $configLoader =
            new BlogConfigLoader(),
        private readonly ConfiguredMigrationScopeFactory $scopeFactory =
            new ConfiguredMigrationScopeFactory(),
        private readonly BlogHttpSchemaGate $schemaGate =
            new BlogHttpSchemaGate()
    ) {
        $this->connectionFactoryResolver = $connectionFactoryResolver === null
            ? static fn (array $environment): PdoConnectionFactoryInterface =>
                new SharedPdoConnectionFactory($environment)
            : Closure::fromCallable($connectionFactoryResolver);
    }

    public function create(
        ModuleRuntimeContext $context
    ): BlogPublicHttpRuntime {
        try {
            if (!$context->environmentIsUsable()) {
                throw new BlogPublicHttpRuntimeException(
                    'blog.environment_unusable'
                );
            }

            $registry = ModuleRegistry::forProject(
                $context->projectRoot(),
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

            $languages = $context->languages();
            $config = $this->configLoader->load(
                $context->projectRoot(),
                $languages
            );
            $origin = BlogPublicOrigin::fromEnvironment(
                $context->environment()
            );
            $scopes = $this->scopeFactory->create(
                $registry,
                $context->projectRoot()
            );
            $blogScope = $scopes->get('blog');
            if ($blogScope === null) {
                throw new BlogPublicHttpRuntimeException(
                    'blog.scope_unavailable'
                );
            }

            $connectionFactory = ($this->connectionFactoryResolver)(
                $context->environment()
            );
            if (!$connectionFactory instanceof PdoConnectionFactoryInterface) {
                throw new BlogPublicHttpRuntimeException(
                    'blog.connection_factory_invalid'
                );
            }
            $pdo = $connectionFactory->connect();
            if (!$this->schemaGate->isReady($pdo, $registry, $scopes)) {
                throw new BlogPublicHttpRuntimeException(
                    'blog.schema_not_ready'
                );
            }

            return new BlogPublicHttpRuntime(
                $config,
                $origin,
                new BlogService(new PdoBlogRepository($pdo, $blogScope))
            );
        } catch (BlogPublicHttpRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPublicHttpRuntimeException();
        }
    }
}
