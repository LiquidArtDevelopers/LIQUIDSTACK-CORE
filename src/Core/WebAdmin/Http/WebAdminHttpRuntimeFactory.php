<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Database\SharedPdoConnectionFactory;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\ModuleWebAdminNavigationProviderInterface;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Modules\WebAdmin\WebAdminHttpSchemaGate;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationRepository;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\CredentialAction\CredentialActionRepository;
use App\Core\WebAdmin\CredentialAction\CredentialActionService;
use App\Core\WebAdmin\Navigation\WebAdminNavigationCatalog;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\ExceptionTraceGuard;
use App\Core\WebAdmin\Security\InvalidSecurityKey;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\SystemClock;
use App\Core\WebAdmin\UserManagement\ActiveModuleSet;
use App\Core\WebAdmin\UserManagement\UserManagementRepository;
use App\Core\WebAdmin\UserManagement\UserManagementService;
use Closure;
use Throwable;

final class WebAdminHttpRuntimeFactory implements
    WebAdminHttpRuntimeFactoryInterface
{
    public const SECURITY_KEY_ENV = WebAdminConfig::SECURITY_KEY_ENV;

    /** @var Closure(array<string, mixed>): PdoConnectionFactoryInterface */
    private readonly Closure $connectionFactoryResolver;

    /**
     * @param null|callable(array<string, mixed>): PdoConnectionFactoryInterface $connectionFactoryResolver
     */
    public function __construct(
        private readonly ?string $coreRoot = null,
        ?callable $connectionFactoryResolver = null
    ) {
        $this->connectionFactoryResolver = $connectionFactoryResolver === null
            ? static fn (array $environment): PdoConnectionFactoryInterface =>
                new SharedPdoConnectionFactory($environment)
            : Closure::fromCallable($connectionFactoryResolver);
    }

    public function create(
        ModuleRuntimeContext $context,
        WebAdminConfig $config
    ): WebAdminHttpRuntime {
        try {
            ExceptionTraceGuard::assertEnabled();
            if (!PasswordHasher::runtimeSupportsArgon2id()) {
                throw new WebAdminHttpRuntimeException(
                    'webadmin.password_policy_unsupported'
                );
            }
            $environment = $context->environment();
            $encodedKey = $environment[self::SECURITY_KEY_ENV] ?? null;
            if (!is_string($encodedKey) || $encodedKey === '') {
                throw new WebAdminHttpRuntimeException(
                    'webadmin.security_key_missing'
                );
            }
            try {
                $securityKey = SecurityKey::fromBase64Url($encodedKey);
            } catch (InvalidSecurityKey) {
                throw new WebAdminHttpRuntimeException(
                    'webadmin.security_key_invalid'
                );
            }
            $connectionFactory = ($this->connectionFactoryResolver)(
                $environment
            );
            if (!$connectionFactory instanceof PdoConnectionFactoryInterface) {
                throw new WebAdminHttpRuntimeException(
                    'webadmin.connection_factory_invalid'
                );
            }
            $pdo = $connectionFactory->connect();
            $registry = ModuleRegistry::forProject(
                $context->projectRoot(),
                $this->coreRoot ?? dirname(__DIR__, 4)
            );
            if (!$registry->isEnabled('webadmin')) {
                throw new WebAdminHttpRuntimeException(
                    'webadmin.module_not_enabled'
                );
            }

            $scope = MigrationScope::forTablePrefix(
                'webadmin',
                $config->tablePrefix()
            );
            if (!(new WebAdminHttpSchemaGate())->isReady(
                $pdo,
                $registry,
                $scope
            )) {
                throw new WebAdminHttpRuntimeException(
                    'webadmin.schema_not_ready'
                );
            }

            $tables = WebAdminTableNames::fromPdo(
                $pdo,
                $config->tablePrefix()
            );
            $navigation = $this->navigationCatalog($registry);
            $clock = new SystemClock();
            $uuidGenerator = new RandomUuidV4Generator();
            $passwordHasher = PasswordHasher::productive();
            $authentication = new WebAdminAuthenticationService(
                new WebAdminAuthenticationRepository($pdo, $tables),
                $config,
                $securityKey,
                $clock,
                $uuidGenerator,
                $passwordHasher
            );

            return new WebAdminHttpRuntime(
                $config,
                $authentication,
                new WebAdminAuthorizationService($pdo, $tables, $clock),
                new CredentialActionService(
                    new CredentialActionRepository($pdo, $tables),
                    $config,
                    $securityKey,
                    $clock,
                    $uuidGenerator,
                    $passwordHasher
                ),
                new UserManagementService(
                    new UserManagementRepository($pdo, $tables),
                    ActiveModuleSet::fromRegistry($registry),
                    $config,
                    $securityKey,
                    $clock,
                    $uuidGenerator,
                    $passwordHasher
                ),
                $navigation
            );
        } catch (WebAdminHttpRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new WebAdminHttpRuntimeException(
                'webadmin.runtime_unavailable'
            );
        }
    }

    private function navigationCatalog(
        ModuleRegistry $registry
    ): WebAdminNavigationCatalog {
        $items = [];

        foreach ($registry->webAdminNavigationProviders() as $registered) {
            $className = $registered['class'];
            $provider = new $className();
            if (!$provider instanceof ModuleWebAdminNavigationProviderInterface) {
                throw new \RuntimeException(
                    'Invalid WebAdmin navigation provider.'
                );
            }

            $item = $provider->webAdminNavigationItem();
            if ($item->module() !== $registered['module']) {
                throw new \RuntimeException(
                    'WebAdmin navigation provider module mismatch.'
                );
            }

            $items[] = $item;
        }

        return new WebAdminNavigationCatalog($items);
    }
}
