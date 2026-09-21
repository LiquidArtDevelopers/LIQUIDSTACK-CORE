<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Commerce\Configuration\CommerceConfigException;
use App\Core\Commerce\Configuration\CommerceConfigLoader;
use App\Core\Commerce\Outbox\CommerceInquiryMailMessageFactory;
use App\Core\Commerce\Outbox\CommerceInquiryOutboxDispatcher;
use App\Core\Commerce\Outbox\CommerceInquiryOutboxRepository;
use App\Core\Commerce\Persistence\CommerceTableNames;
use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Environment\ProjectEnvironmentLoader;
use App\Core\Modules\Commerce\CommerceHttpSchemaGate;
use App\Core\Modules\ConfiguredModuleDatabaseConnectionResolver;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\WebAdmin\Mail\WebAdminMailConfiguration;
use App\Core\WebAdmin\Mail\WebAdminMailConfigurationException;
use App\Core\WebAdmin\Mail\WebAdminMailConfigurationLoader;
use App\Core\WebAdmin\Mail\WebAdminMailTransportFactory;
use App\Core\WebAdmin\Mail\WebAdminMailTransportInterface;
use App\Core\WebAdmin\Security\ExceptionTraceGuard;
use App\Core\WebAdmin\Support\SystemClock;
use Closure;
use Throwable;

final class CommerceMailDispatchCommandRuntimeFactory implements
    CommerceMailDispatchCommandRuntimeFactoryInterface
{
    /** @var Closure(array<string, mixed>, string): PdoConnectionFactoryInterface */
    private readonly Closure $connectionFactoryResolver;
    /** @var Closure(WebAdminMailConfiguration): WebAdminMailTransportInterface */
    private readonly Closure $transportResolver;

    /**
     * @param null|callable(array<string, mixed>, string): PdoConnectionFactoryInterface $connectionFactoryResolver
     * @param null|callable(WebAdminMailConfiguration): WebAdminMailTransportInterface $transportResolver
     */
    public function __construct(
        private readonly ProjectEnvironmentLoader $environmentLoader = new ProjectEnvironmentLoader(),
        private readonly CommerceConfigLoader $configLoader = new CommerceConfigLoader(),
        private readonly WebAdminMailConfigurationLoader $mailConfigurationLoader = new WebAdminMailConfigurationLoader(),
        ?callable $connectionFactoryResolver = null,
        ?callable $transportResolver = null,
        private readonly ConfiguredModuleDatabaseConnectionResolver $databaseConnectionResolver = new ConfiguredModuleDatabaseConnectionResolver(),
        private readonly ConfiguredMigrationScopeFactory $scopeFactory = new ConfiguredMigrationScopeFactory(),
        private readonly CommerceHttpSchemaGate $schemaGate = new CommerceHttpSchemaGate()
    ) {
        $this->connectionFactoryResolver = $connectionFactoryResolver === null
            ? static fn (array $environment, string $connection): PdoConnectionFactoryInterface =>
                (new ConfiguredPdoConnectionFactoryResolver())->resolve($connection, $environment)
            : Closure::fromCallable($connectionFactoryResolver);
        $this->transportResolver = $transportResolver === null
            ? static fn (WebAdminMailConfiguration $configuration): WebAdminMailTransportInterface =>
                (new WebAdminMailTransportFactory())->create($configuration)
            : Closure::fromCallable($transportResolver);
    }

    public function create(string $projectRoot, string $coreRoot): CommerceMailDispatchCommandRuntimeInterface
    {
        try {
            $registry = ModuleRegistry::forProject($projectRoot, $coreRoot);
            if (!$registry->isEnabled('commerce')) {
                throw new CommerceMailDispatchCommandRuntimeException('commerce.mail.module_not_enabled');
            }
            try {
                ExceptionTraceGuard::assertEnabled();
            } catch (Throwable) {
                throw new CommerceMailDispatchCommandRuntimeException('commerce.mail.exception_trace_unsafe');
            }
            $environment = $this->environmentLoader->load($projectRoot);
            if (!$environment->isUsable()) {
                throw new CommerceMailDispatchCommandRuntimeException('commerce.mail.environment_unusable');
            }
            try {
                $languages = (new ModuleRuntimeContext($projectRoot, $environment->values()))->languages();
                $config = $this->configLoader->load($projectRoot, $languages);
                // Validate all SMTP inputs before opening PDO or claiming work.
                $mail = $this->mailConfigurationLoader->load($environment->values());
            } catch (CommerceConfigException|WebAdminMailConfigurationException) {
                throw new CommerceMailDispatchCommandRuntimeException('commerce.mail.configuration_invalid');
            } catch (Throwable) {
                throw new CommerceMailDispatchCommandRuntimeException('commerce.mail.routing_unavailable');
            }
            $connection = $this->databaseConnectionResolver->resolve($registry, $projectRoot);
            if ($connection !== $config->databaseConnection()) {
                throw new CommerceMailDispatchCommandRuntimeException('commerce.mail.configuration_invalid');
            }
            $connectionFactory = ($this->connectionFactoryResolver)($environment->values(), $connection);
            if (!$connectionFactory instanceof PdoConnectionFactoryInterface) {
                throw new CommerceMailDispatchCommandRuntimeException('commerce.mail.connection_factory_invalid');
            }
            $pdo = $connectionFactory->connect();
            $scopes = $this->scopeFactory->create($registry, $projectRoot);
            $scope = $scopes->get('commerce');
            if ($scope === null || !$this->schemaGate->isPublicInquiryReady($pdo, $registry, $scopes)) {
                throw new CommerceMailDispatchCommandRuntimeException('commerce.mail.schema_not_ready');
            }
            $transport = ($this->transportResolver)($mail);
            if (!$transport instanceof WebAdminMailTransportInterface) {
                throw new CommerceMailDispatchCommandRuntimeException('commerce.mail.runtime_unavailable');
            }
            $tables = CommerceTableNames::fromPdo($pdo, $scope->tablePrefix());

            return new CommerceMailDispatchCommandRuntime(new CommerceInquiryOutboxDispatcher(
                new CommerceInquiryOutboxRepository($pdo, $tables),
                new CommerceInquiryMailMessageFactory($mail),
                $transport,
                new SystemClock()
            ));
        } catch (CommerceMailDispatchCommandRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new CommerceMailDispatchCommandRuntimeException('commerce.mail.runtime_unavailable');
        }
    }
}
