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
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use Closure;
use Throwable;

final class MigrationCommandRuntimeFactory implements
    MigrationCommandRuntimeFactoryInterface
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
        WebAdminConfigLoader $webAdminConfigLoader = new WebAdminConfigLoader(),
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
    ): MigrationCommandRuntime {
        try {
            $environment = $this->environmentLoader->load($projectRoot);
            if (!$environment->isUsable()) {
                throw new MigrationCommandRuntimeException(
                    'migrate.environment_unusable'
                );
            }

            $registry = ModuleRegistry::forProject($projectRoot, $coreRoot);
            $catalog = MigrationCatalog::fromRegistry($registry);
            $scopes = $this->scopeFactory->create(
                $registry,
                $projectRoot
            );
            $databaseConnection = $this->databaseConnectionResolver->resolve(
                $registry,
                $projectRoot
            );

            $connectionFactory = ($this->connectionFactoryResolver)(
                $environment->values(),
                $databaseConnection
            );
            if (!$connectionFactory instanceof PdoConnectionFactoryInterface) {
                throw new MigrationCommandRuntimeException(
                    'migrate.connection_factory_invalid'
                );
            }

            return new MigrationCommandRuntime(
                $connectionFactory->connect(),
                $catalog,
                $scopes
            );
        } catch (MigrationCommandRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MigrationCommandRuntimeException(
                'migrate.runtime_unavailable'
            );
        }
    }
}
