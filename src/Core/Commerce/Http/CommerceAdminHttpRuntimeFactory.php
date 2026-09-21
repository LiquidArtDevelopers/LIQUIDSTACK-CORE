<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use App\Core\Commerce\Admin\CommerceAdminReadRepository;
use App\Core\Commerce\Admin\CommerceAdminMediaRepository;
use App\Core\Commerce\Admin\CommerceAdminTaxonomyRepository;
use App\Core\Commerce\CommerceCatalogService;
use App\Core\Commerce\Configuration\CommerceConfigLoader;
use App\Core\Commerce\Persistence\CommerceTableNames;
use App\Core\Commerce\Persistence\PdoCommerceCatalogRepository;
use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Modules\Commerce\CommerceHttpSchemaGate;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Modules\WebAdmin\WebAdminHttpSchemaGate;
use App\Core\Modules\WebAdmin\WebAdminMediaHttpSchemaGate;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationRepository;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use App\Core\WebAdmin\Navigation\WebAdminNavigationCatalogFactory;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\ExceptionTraceGuard;
use App\Core\WebAdmin\Security\InvalidSecurityKey;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\SystemClock;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use Closure;
use Throwable;

final class CommerceAdminHttpRuntimeFactory implements CommerceAdminHttpRuntimeFactoryInterface
{
    /** @var Closure(array<string, mixed>, string): PdoConnectionFactoryInterface */
    private readonly Closure $connectionFactoryResolver;

    /**
     * @param null|callable(array<string, mixed>, string): PdoConnectionFactoryInterface $connectionFactoryResolver
     */
    public function __construct(
        private readonly ?string $coreRoot = null,
        ?callable $connectionFactoryResolver = null,
        private readonly CommerceConfigLoader $commerceConfigLoader = new CommerceConfigLoader(),
        private readonly WebAdminConfigLoader $webAdminConfigLoader = new WebAdminConfigLoader(),
        private readonly ConfiguredMigrationScopeFactory $scopeFactory = new ConfiguredMigrationScopeFactory(),
        private readonly CommerceHttpSchemaGate $commerceSchemaGate = new CommerceHttpSchemaGate(),
        private readonly WebAdminHttpSchemaGate $webAdminSchemaGate = new WebAdminHttpSchemaGate(),
        private readonly WebAdminMediaHttpSchemaGate $webAdminMediaSchemaGate = new WebAdminMediaHttpSchemaGate(),
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly UuidGeneratorInterface $uuidGenerator = new RandomUuidV4Generator(),
        private readonly SecureTokenGenerator $tokenGenerator = new SecureTokenGenerator()
    ) {
        $this->connectionFactoryResolver = $connectionFactoryResolver === null
            ? static fn (array $environment, string $connection): PdoConnectionFactoryInterface =>
                (new ConfiguredPdoConnectionFactoryResolver())->resolve(
                    $connection,
                    $environment
                )
            : Closure::fromCallable($connectionFactoryResolver);
    }

    public function create(
        ModuleRuntimeContext $context,
        WebAdminConfig $webAdminConfig
    ): CommerceAdminHttpRuntimeInterface {
        try {
            ExceptionTraceGuard::assertEnabled();
            if (!PasswordHasher::runtimeSupportsArgon2id()) {
                throw new CommerceAdminHttpRuntimeException(
                    'commerce.password_policy_unsupported'
                );
            }
            $root = $context->projectRoot();
            $registry = ModuleRegistry::forProject(
                $root,
                $this->coreRoot ?? dirname(__DIR__, 4)
            );
            if (!$registry->isEnabled('commerce') || !$registry->isEnabled('webadmin')) {
                throw new CommerceAdminHttpRuntimeException(
                    'commerce.module_not_enabled'
                );
            }
            $languages = $context->languages();
            $commerceConfig = $this->commerceConfigLoader->load($root, $languages);
            $canonicalWebAdmin = $this->webAdminConfigLoader->load(
                $root,
                $context->environment()
            );
            if (
                $commerceConfig->databaseConnection()
                    !== $canonicalWebAdmin->databaseConnection()
                || $canonicalWebAdmin->tablePrefix()
                    !== $webAdminConfig->tablePrefix()
                || $canonicalWebAdmin->cookieName()
                    !== $webAdminConfig->cookieName()
            ) {
                throw new CommerceAdminHttpRuntimeException(
                    'commerce.webadmin_config_mismatch'
                );
            }
            $scopes = $this->scopeFactory->create($registry, $root);
            $commerceScope = $scopes->get('commerce');
            $webAdminScope = $scopes->get('webadmin');
            if (
                $commerceScope === null
                || $webAdminScope === null
                || $commerceScope->tablePrefix() !== $commerceConfig->tablePrefix()
            ) {
                throw new CommerceAdminHttpRuntimeException(
                    'commerce.scope_unavailable'
                );
            }
            $environment = $context->environment();
            $factory = ($this->connectionFactoryResolver)(
                $environment,
                $commerceConfig->databaseConnection()
            );
            $pdo = $factory->connect();
            if (
                !$this->webAdminSchemaGate->isReady($pdo, $registry, $webAdminScope)
                || !$this->commerceSchemaGate->isAdministrationReady(
                    $pdo,
                    $registry,
                    $scopes
                )
            ) {
                throw new CommerceAdminHttpRuntimeException(
                    'commerce.schema_not_ready'
                );
            }
            $securityKey = $this->securityKey($environment);
            $webAdminTables = WebAdminTableNames::fromPdo(
                $pdo,
                $canonicalWebAdmin->tablePrefix()
            );
            $commerceTables = CommerceTableNames::fromPdo(
                $pdo,
                $commerceConfig->tablePrefix()
            );
            $hasher = PasswordHasher::productive();
            $authentication = new WebAdminAuthenticationService(
                new WebAdminAuthenticationRepository($pdo, $webAdminTables),
                $canonicalWebAdmin,
                $securityKey,
                $this->clock,
                $this->uuidGenerator,
                $hasher,
                $this->tokenGenerator
            );
            $authorization = new WebAdminAuthorizationService(
                $pdo,
                $webAdminTables,
                $this->clock,
                $this->tokenGenerator,
                $hasher
            );
            $repository = new PdoCommerceCatalogRepository($pdo, $commerceTables);
            $mutationGate = new WebAdminMutationActorGate(
                $pdo,
                $webAdminTables,
                $canonicalWebAdmin,
                $securityKey,
                $this->clock,
                $this->tokenGenerator,
                $hasher
            );
            $mediaReady = $this->webAdminMediaSchemaGate->isReady(
                $pdo,
                $registry,
                $webAdminScope
            );
            $quarantineEnabled = $mediaReady
                && $this->webAdminMediaSchemaGate->supportsQuarantineDeletion(
                    $pdo,
                    $registry,
                    $webAdminScope
                );

            return new CommerceAdminHttpRuntime(
                $languages,
                $commerceConfig,
                $canonicalWebAdmin,
                new CommerceCatalogService(
                    $repository,
                    $commerceConfig->defaultLocale(),
                    $languages
                ),
                new CommerceAdminReadRepository(
                    $pdo,
                    $commerceTables,
                    $mediaReady ? $webAdminTables : null,
                    $quarantineEnabled
                ),
                $authentication,
                $authorization,
                WebAdminNavigationCatalogFactory::fromRegistry($registry),
                $pdo,
                $mutationGate,
                $this->clock,
                $mediaReady ? new CommerceAdminMediaRepository(
                    $pdo,
                    $commerceTables,
                    $webAdminTables,
                    $mutationGate,
                    $quarantineEnabled
                ) : null,
                new CommerceAdminTaxonomyRepository(
                    $pdo,
                    $commerceTables,
                    $mutationGate
                )
            );
        } catch (CommerceAdminHttpRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new CommerceAdminHttpRuntimeException(
                'commerce.admin_runtime_unavailable'
            );
        }
    }

    /** @param array<string, mixed> $environment */
    private function securityKey(array $environment): SecurityKey
    {
        $encoded = $environment[WebAdminConfig::SECURITY_KEY_ENV] ?? null;
        if (!is_string($encoded) || $encoded === '') {
            throw new CommerceAdminHttpRuntimeException(
                'commerce.security_key_missing'
            );
        }
        try {
            return SecurityKey::fromBase64Url($encoded);
        } catch (InvalidSecurityKey) {
            throw new CommerceAdminHttpRuntimeException(
                'commerce.security_key_invalid'
            );
        }
    }
}
