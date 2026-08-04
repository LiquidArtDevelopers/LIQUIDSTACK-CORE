<?php

declare(strict_types=1);

namespace App\Core\Blog\Analytics;

use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Environment\ProjectRuntimeProfile;
use App\Core\Modules\Blog\BlogHttpSchemaGate;
use App\Core\Modules\Blog\BlogMigrationRequirements;
use App\Core\Modules\ConfiguredModuleDatabaseConnectionResolver;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use App\Core\WebAdmin\Security\InvalidSecurityKey;
use App\Core\WebAdmin\Security\SecurityKey;
use Closure;
use Throwable;

final class BlogAnalyticsHttpRuntimeFactory implements
    BlogAnalyticsHttpRuntimeFactoryInterface
{
    /** @var Closure(array<string, mixed>, string): PdoConnectionFactoryInterface */
    private readonly Closure $connectionFactoryResolver;

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
        private readonly BlogHttpSchemaGate $blogSchemaGate =
            new BlogHttpSchemaGate(),
        private readonly MigrationFeatureGate $migrationFeatureGate =
            new MigrationFeatureGate(),
        private readonly ConfiguredModuleDatabaseConnectionResolver
            $databaseConnectionResolver =
                new ConfiguredModuleDatabaseConnectionResolver(),
        private readonly WebAdminConfigLoader $webAdminConfigLoader =
            new WebAdminConfigLoader()
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
    }

    public function create(ModuleRuntimeContext $context): BlogAnalyticsHttpRuntime
    {
        try {
            if (!$context->environmentIsUsable()) {
                throw new BlogAnalyticsHttpRuntimeException();
            }
            $registry = ModuleRegistry::forProject(
                $context->projectRoot(),
                $this->coreRoot ?? dirname(__DIR__, 4)
            );
            if (
                !$registry->isEnabled('blog')
                || !$registry->isEnabled('webadmin')
            ) {
                throw new BlogAnalyticsHttpRuntimeException();
            }
            $config = $this->configLoader->load(
                $context->projectRoot(),
                $context->languages()
            );
            $webAdminConfig = $this->webAdminConfigLoader->load(
                $context->projectRoot()
            );
            if (!$config->analytics()->enabled()) {
                throw new BlogAnalyticsHttpRuntimeException();
            }
            $profile = ProjectRuntimeProfile::fromEnvironment(
                $context->environment()
            );
            if (
                $profile->isDevelopmentLoopbackHttp()
                && !$config->analytics()->collectInDevelopment()
            ) {
                throw new BlogAnalyticsHttpRuntimeException();
            }
            $origin = BlogPublicOrigin::fromEnvironment(
                $context->environment()
            );
            $scopes = $this->scopeFactory->create(
                $registry,
                $context->projectRoot()
            );
            $blogScope = $scopes->get('blog');
            if ($blogScope === null) {
                throw new BlogAnalyticsHttpRuntimeException();
            }
            $factory = ($this->connectionFactoryResolver)(
                $context->environment(),
                $this->databaseConnectionResolver->resolve(
                    $registry,
                    $context->projectRoot()
                )
            );
            if (!$factory instanceof PdoConnectionFactoryInterface) {
                throw new BlogAnalyticsHttpRuntimeException();
            }
            $pdo = $factory->connect();
            if (
                !$this->blogSchemaGate->isPublicReady(
                    $pdo,
                    $registry,
                    $scopes
                )
                || !$this->migrationFeatureGate->isReady(
                    $pdo,
                    $registry,
                    $scopes,
                    BlogMigrationRequirements::analyticsCollection()
                )
            ) {
                throw new BlogAnalyticsHttpRuntimeException();
            }
            $encodedKey = $context->environment()[
                WebAdminConfig::SECURITY_KEY_ENV
            ] ?? null;
            if (!is_string($encodedKey) || $encodedKey === '') {
                throw new BlogAnalyticsHttpRuntimeException();
            }
            try {
                $securityKey = SecurityKey::fromBase64Url($encodedKey);
            } catch (InvalidSecurityKey) {
                throw new BlogAnalyticsHttpRuntimeException();
            }
            $repository = new PdoBlogAnalyticsRepository($pdo, $blogScope);

            return new BlogAnalyticsHttpRuntime(
                $config->analytics(),
                $origin,
                new BlogAnalyticsCollector(
                    $repository,
                    $securityKey
                ),
                new BlogAnalyticsPageGrantCodec($securityKey, $origin),
                $webAdminConfig->cookieName()
            );
        } catch (BlogAnalyticsHttpRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogAnalyticsHttpRuntimeException();
        }
    }
}
