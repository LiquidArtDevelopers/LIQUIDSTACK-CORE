<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Environment\ProjectEnvironmentLoader;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ConfiguredModuleDatabaseConnectionResolver;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use Closure;
use Throwable;

final class WebAdminBootstrapCommandRuntimeFactory implements
    WebAdminBootstrapCommandRuntimeFactoryInterface
{
    /**
     * @var Closure(array<string, mixed>, string): PdoConnectionFactoryInterface
     */
    private readonly Closure $connectionFactoryResolver;
    private readonly ConfiguredMigrationScopeFactory $scopeFactory;
    private readonly ConfiguredModuleDatabaseConnectionResolver $databaseConnectionResolver;

    /**
     * @param null|callable(array<string, mixed>, string): PdoConnectionFactoryInterface $connectionFactoryResolver
     */
    public function __construct(
        private readonly ProjectEnvironmentLoader $environmentLoader = new ProjectEnvironmentLoader(),
        private readonly WebAdminConfigLoader $webAdminConfigLoader = new WebAdminConfigLoader(),
        ?callable $connectionFactoryResolver = null,
        ?ConfiguredMigrationScopeFactory $scopeFactory = null,
        ?ConfiguredModuleDatabaseConnectionResolver $databaseConnectionResolver = null
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
        $this->scopeFactory = $scopeFactory
            ?? new ConfiguredMigrationScopeFactory($webAdminConfigLoader);
        $this->databaseConnectionResolver = $databaseConnectionResolver
            ?? new ConfiguredModuleDatabaseConnectionResolver(
                $webAdminConfigLoader
            );
    }

    public function create(
        string $projectRoot,
        string $coreRoot
    ): WebAdminBootstrapCommandRuntimeInterface {
        try {
            $registry = ModuleRegistry::forProject($projectRoot, $coreRoot);
            if (!$registry->isEnabled('webadmin')) {
                throw new WebAdminBootstrapCommandRuntimeException(
                    'webadmin.bootstrap.module_not_enabled'
                );
            }

            $environment = $this->environmentLoader->load($projectRoot);
            if (!$environment->isUsable()) {
                throw new WebAdminBootstrapCommandRuntimeException(
                    'webadmin.bootstrap.environment_unusable'
                );
            }

            $config = $this->webAdminConfigLoader->load($projectRoot);
            $catalog = MigrationCatalog::fromRegistry($registry);
            $scopes = $this->scopeFactory->create(
                $registry,
                $projectRoot
            );

            $connectionFactory = ($this->connectionFactoryResolver)(
                $environment->values(),
                $this->databaseConnectionResolver->resolve(
                    $registry,
                    $projectRoot
                )
            );
            if (!$connectionFactory instanceof PdoConnectionFactoryInterface) {
                throw new WebAdminBootstrapCommandRuntimeException(
                    'webadmin.bootstrap.connection_factory_invalid'
                );
            }

            return new WebAdminBootstrapCommandRuntime(
                $connectionFactory->connect(),
                $catalog,
                $scopes,
                $this->bootstrapEnvironment($environment->values()),
                $config
            );
        } catch (WebAdminBootstrapCommandRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new WebAdminBootstrapCommandRuntimeException(
                'webadmin.bootstrap.runtime_unavailable'
            );
        }
    }

    /**
     * The runtime needs only the two bootstrap identities. Keeping database
     * credentials and unrelated process variables out of its state narrows
     * the lifetime and accidental exposure surface of those values.
     *
     * @param array<string, mixed> $environment
     * @return array<string, mixed>
     */
    private function bootstrapEnvironment(array $environment): array
    {
        $bootstrap = [];
        foreach (WebAdminConfig::BOOTSTRAP_EMAIL_ENV as $name) {
            if (array_key_exists($name, $environment)) {
                $bootstrap[$name] = $environment[$name];
            }
        }

        return $bootstrap;
    }
}
