<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\PublicDelivery\BlogPublicMediaDelivery;
use App\Core\Blog\PublicDelivery\PdoBlogPublicMediaRepository;
use App\Core\Blog\StructuredContent\Persistence\PdoBlogStructuredContentRepository;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Modules\Blog\BlogHttpSchemaGate;
use App\Core\Modules\Blog\BlogMigrationRequirements;
use App\Core\Modules\Blog\BlogStructuredContentSchemaGate;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Modules\ConfiguredModuleDatabaseConnectionResolver;
use App\Core\Modules\WebAdmin\WebAdminMediaHttpSchemaGate;
use App\Core\WebAdmin\Media\PrivateMediaStorage;
use Closure;
use Throwable;

final class BlogPublicHttpRuntimeFactory implements
    BlogPublicHttpRuntimeFactoryInterface
{
    /** @var Closure(array<string, mixed>, string): PdoConnectionFactoryInterface */
    private readonly Closure $connectionFactoryResolver;
    private readonly ConfiguredModuleDatabaseConnectionResolver $databaseConnectionResolver;

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
        private readonly BlogHttpSchemaGate $schemaGate =
            new BlogHttpSchemaGate(),
        ?ConfiguredModuleDatabaseConnectionResolver $databaseConnectionResolver = null,
        private readonly BlogStructuredContentSchemaGate
            $structuredContentSchemaGate =
                new BlogStructuredContentSchemaGate(),
        private readonly WebAdminMediaHttpSchemaGate $mediaSchemaGate =
            new WebAdminMediaHttpSchemaGate(),
        private readonly MigrationFeatureGate $migrationFeatureGate =
            new MigrationFeatureGate()
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
                $context->environment(),
                $this->databaseConnectionResolver->resolve(
                    $registry,
                    $context->projectRoot()
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
                    'blog.schema_not_ready'
                );
            }

            $structuredContent = null;
            $mediaDelivery = null;
            $structuredMigrationApplied = $this->migrationFeatureGate->isReady(
                $pdo,
                $registry,
                $scopes,
                BlogMigrationRequirements::structuredContent()
            );
            if (
                $structuredMigrationApplied
                && !$this->structuredContentSchemaGate->isReady(
                    $pdo,
                    $registry,
                    $scopes
                )
            ) {
                throw new BlogPublicHttpRuntimeException(
                    'blog.structured_schema_not_ready'
                );
            }
            if ($structuredMigrationApplied) {
                $structuredContent = new PdoBlogStructuredContentRepository(
                    $pdo,
                    $blogScope
                );
                $webAdminScope = $scopes->get('webadmin');
                if (
                    $webAdminScope !== null
                    && $this->mediaSchemaGate->isReady(
                        $pdo,
                        $registry,
                        $webAdminScope
                    )
                ) {
                    try {
                        $mediaDelivery = new BlogPublicMediaDelivery(
                            new PdoBlogPublicMediaRepository(
                                $pdo,
                                $blogScope,
                                $webAdminScope
                            ),
                            PrivateMediaStorage::forProject(
                                $context->projectRoot(),
                                $context->environment()
                            )
                        );
                    } catch (Throwable) {
                        // Text-only structured documents remain usable. Any
                        // image block and the public media endpoint fail closed.
                        $mediaDelivery = null;
                    }
                }
            }

            return new BlogPublicHttpRuntime(
                $config,
                $origin,
                new BlogService(new PdoBlogRepository($pdo, $blogScope)),
                $structuredContent,
                $mediaDelivery
            );
        } catch (BlogPublicHttpRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPublicHttpRuntimeException();
        }
    }
}
