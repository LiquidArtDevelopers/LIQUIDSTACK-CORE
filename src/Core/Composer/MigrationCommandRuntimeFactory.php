<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Database\SharedPdoConnectionFactory;
use App\Core\Environment\ProjectEnvironmentLoader;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\ModuleRegistry;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use Closure;
use Throwable;

final class MigrationCommandRuntimeFactory implements
    MigrationCommandRuntimeFactoryInterface
{
    /**
     * @var Closure(array<string, mixed>): PdoConnectionFactoryInterface
     */
    private readonly Closure $connectionFactoryResolver;
    private readonly ConfiguredMigrationScopeFactory $scopeFactory;

    /**
     * @param null|callable(array<string, mixed>): PdoConnectionFactoryInterface $connectionFactoryResolver
     */
    public function __construct(
        private readonly ProjectEnvironmentLoader $environmentLoader = new ProjectEnvironmentLoader(),
        WebAdminConfigLoader $webAdminConfigLoader = new WebAdminConfigLoader(),
        ?callable $connectionFactoryResolver = null,
        ?ConfiguredMigrationScopeFactory $scopeFactory = null
    ) {
        $this->connectionFactoryResolver = $connectionFactoryResolver === null
            ? static fn (array $environment): PdoConnectionFactoryInterface =>
                new SharedPdoConnectionFactory($environment)
            : Closure::fromCallable($connectionFactoryResolver);
        $this->scopeFactory = $scopeFactory
            ?? new ConfiguredMigrationScopeFactory($webAdminConfigLoader);
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

            $connectionFactory = ($this->connectionFactoryResolver)(
                $environment->values()
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
