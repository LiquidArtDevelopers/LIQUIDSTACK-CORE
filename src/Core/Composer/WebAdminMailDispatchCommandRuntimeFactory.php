<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Environment\ProjectEnvironmentLoader;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Modules\ConfiguredModuleDatabaseConnectionResolver;
use App\Core\Modules\WebAdmin\WebAdminHttpSchemaGate;
use App\Core\WebAdmin\Configuration\WebAdminConfigException;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use App\Core\WebAdmin\Mail\WebAdminCredentialMailMessageFactory;
use App\Core\WebAdmin\Mail\WebAdminMailConfiguration;
use App\Core\WebAdmin\Mail\WebAdminMailConfigurationException;
use App\Core\WebAdmin\Mail\WebAdminMailConfigurationLoader;
use App\Core\WebAdmin\Mail\WebAdminMailTransportFactory;
use App\Core\WebAdmin\Mail\WebAdminMailTransportInterface;
use App\Core\WebAdmin\Outbox\WebAdminOutboxDispatcher;
use App\Core\WebAdmin\Outbox\WebAdminOutboxRepository;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Routing\WebAdminRoutePolicy;
use App\Core\WebAdmin\Security\ExceptionTraceGuard;
use App\Core\WebAdmin\Support\SystemClock;
use Closure;
use Throwable;

final class WebAdminMailDispatchCommandRuntimeFactory implements
    WebAdminMailDispatchCommandRuntimeFactoryInterface
{
    /** @var Closure(array<string, mixed>, string): PdoConnectionFactoryInterface */
    private readonly Closure $connectionFactoryResolver;

    /** @var Closure(WebAdminMailConfiguration): WebAdminMailTransportInterface */
    private readonly Closure $transportResolver;
    private readonly ConfiguredModuleDatabaseConnectionResolver $databaseConnectionResolver;

    /**
     * @param null|callable(array<string, mixed>, string): PdoConnectionFactoryInterface $connectionFactoryResolver
     * @param null|callable(WebAdminMailConfiguration): WebAdminMailTransportInterface $transportResolver
     */
    public function __construct(
        private readonly ProjectEnvironmentLoader $environmentLoader = new ProjectEnvironmentLoader(),
        private readonly WebAdminConfigLoader $webAdminConfigLoader = new WebAdminConfigLoader(),
        private readonly WebAdminMailConfigurationLoader $mailConfigurationLoader = new WebAdminMailConfigurationLoader(),
        ?callable $connectionFactoryResolver = null,
        ?callable $transportResolver = null,
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
        $this->transportResolver = $transportResolver === null
            ? static fn (
                WebAdminMailConfiguration $configuration
            ): WebAdminMailTransportInterface =>
                (new WebAdminMailTransportFactory())->create($configuration)
            : Closure::fromCallable($transportResolver);
        $this->databaseConnectionResolver = $databaseConnectionResolver
            ?? new ConfiguredModuleDatabaseConnectionResolver(
                $webAdminConfigLoader
            );
    }

    public function create(
        string $projectRoot,
        string $coreRoot
    ): WebAdminMailDispatchCommandRuntimeInterface {
        try {
            $registry = ModuleRegistry::forProject($projectRoot, $coreRoot);
            if (!$registry->isEnabled('webadmin')) {
                throw new WebAdminMailDispatchCommandRuntimeException(
                    'webadmin.mail.module_not_enabled'
                );
            }

            try {
                ExceptionTraceGuard::assertEnabled();
            } catch (Throwable) {
                throw new WebAdminMailDispatchCommandRuntimeException(
                    'webadmin.mail.exception_trace_unsafe'
                );
            }

            $environment = $this->environmentLoader->load($projectRoot);
            if (!$environment->isUsable()) {
                throw new WebAdminMailDispatchCommandRuntimeException(
                    'webadmin.mail.environment_unusable'
                );
            }

            try {
                $config = $this->webAdminConfigLoader->load($projectRoot);
                $mailConfiguration = $this->mailConfigurationLoader->load(
                    $environment->values()
                );
            } catch (
                WebAdminConfigException|WebAdminMailConfigurationException
            ) {
                throw new WebAdminMailDispatchCommandRuntimeException(
                    'webadmin.mail.configuration_invalid'
                );
            }

            try {
                $languages = (new ModuleRuntimeContext(
                    $projectRoot,
                    $environment->values()
                ))->languages();
            } catch (Throwable) {
                throw new WebAdminMailDispatchCommandRuntimeException(
                    'webadmin.mail.routing_unavailable'
                );
            }
            $route = (new WebAdminRoutePolicy())->resolve(
                $projectRoot,
                $config->basePath(),
                $languages
            );
            $effectiveBasePath = $route->registeredPath();
            if ($effectiveBasePath === null) {
                throw new WebAdminMailDispatchCommandRuntimeException(
                    'webadmin.mail.routing_unavailable'
                );
            }

            $connectionFactory = ($this->connectionFactoryResolver)(
                $environment->values(),
                $this->databaseConnectionResolver->resolve(
                    $registry,
                    $projectRoot
                )
            );
            if (!$connectionFactory instanceof PdoConnectionFactoryInterface) {
                throw new WebAdminMailDispatchCommandRuntimeException(
                    'webadmin.mail.connection_factory_invalid'
                );
            }
            $pdo = $connectionFactory->connect();
            $scope = MigrationScope::forTablePrefix(
                'webadmin',
                $config->tablePrefix()
            );
            if (!(new WebAdminHttpSchemaGate())->isReady(
                $pdo,
                $registry,
                $scope
            )) {
                throw new WebAdminMailDispatchCommandRuntimeException(
                    'webadmin.mail.schema_not_ready'
                );
            }

            $transport = ($this->transportResolver)($mailConfiguration);
            if (!$transport instanceof WebAdminMailTransportInterface) {
                throw new WebAdminMailDispatchCommandRuntimeException(
                    'webadmin.mail.runtime_unavailable'
                );
            }
            $tables = WebAdminTableNames::fromPdo(
                $pdo,
                $config->tablePrefix()
            );
            $dispatcher = new WebAdminOutboxDispatcher(
                new WebAdminOutboxRepository($pdo, $tables),
                new WebAdminCredentialMailMessageFactory(
                    $mailConfiguration,
                    $effectiveBasePath
                ),
                $transport,
                new SystemClock()
            );

            return new WebAdminMailDispatchCommandRuntime($dispatcher);
        } catch (WebAdminMailDispatchCommandRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new WebAdminMailDispatchCommandRuntimeException(
                'webadmin.mail.runtime_unavailable'
            );
        }
    }
}
