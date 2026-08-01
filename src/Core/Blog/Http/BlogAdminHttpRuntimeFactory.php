<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\Audit\WebAdminBlogMutationAuditAdapter;
use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Database\SharedPdoConnectionFactory;
use App\Core\Modules\Blog\BlogHttpSchemaGate;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Modules\WebAdmin\WebAdminHttpSchemaGate;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationRepository;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
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

/** Composes Blog administration and WebAdmin over one shared PDO. */
final class BlogAdminHttpRuntimeFactory implements
    BlogAdminHttpRuntimeFactoryInterface
{
    public const SECURITY_KEY_ENV = WebAdminConfig::SECURITY_KEY_ENV;

    /** @var Closure(array<string, mixed>): PdoConnectionFactoryInterface */
    private readonly Closure $connectionFactoryResolver;
    private readonly BlogConfigLoader $blogConfigLoader;
    private readonly WebAdminConfigLoader $webAdminConfigLoader;
    private readonly ConfiguredMigrationScopeFactory $scopeFactory;
    private readonly BlogHttpSchemaGate $blogSchemaGate;
    private readonly WebAdminHttpSchemaGate $webAdminSchemaGate;
    private readonly ClockInterface $clock;
    private readonly UuidGeneratorInterface $uuidGenerator;
    private readonly SecureTokenGenerator $tokenGenerator;

    /**
     * @param null|callable(array<string, mixed>): PdoConnectionFactoryInterface $connectionFactoryResolver
     */
    public function __construct(
        private readonly ?string $coreRoot = null,
        ?callable $connectionFactoryResolver = null,
        ?BlogConfigLoader $blogConfigLoader = null,
        ?WebAdminConfigLoader $webAdminConfigLoader = null,
        ?ConfiguredMigrationScopeFactory $scopeFactory = null,
        ?BlogHttpSchemaGate $blogSchemaGate = null,
        ?WebAdminHttpSchemaGate $webAdminSchemaGate = null,
        ?ClockInterface $clock = null,
        ?UuidGeneratorInterface $uuidGenerator = null,
        ?SecureTokenGenerator $tokenGenerator = null
    ) {
        $this->connectionFactoryResolver = $connectionFactoryResolver === null
            ? static fn (array $environment): PdoConnectionFactoryInterface =>
                new SharedPdoConnectionFactory($environment)
            : Closure::fromCallable($connectionFactoryResolver);
        $this->blogConfigLoader = $blogConfigLoader
            ?? new BlogConfigLoader();
        $this->webAdminConfigLoader = $webAdminConfigLoader
            ?? new WebAdminConfigLoader();
        $this->scopeFactory = $scopeFactory
            ?? new ConfiguredMigrationScopeFactory(
                $this->webAdminConfigLoader,
                $this->blogConfigLoader
            );
        $this->blogSchemaGate = $blogSchemaGate
            ?? new BlogHttpSchemaGate();
        $this->webAdminSchemaGate = $webAdminSchemaGate
            ?? new WebAdminHttpSchemaGate();
        $this->clock = $clock ?? new SystemClock();
        $this->uuidGenerator = $uuidGenerator
            ?? new RandomUuidV4Generator();
        $this->tokenGenerator = $tokenGenerator
            ?? new SecureTokenGenerator();
    }

    public function create(
        ModuleRuntimeContext $context,
        WebAdminConfig $webAdminConfig
    ): BlogAdminHttpRuntime {
        try {
            $this->assertTraceSafety();
            if (!PasswordHasher::runtimeSupportsArgon2id()) {
                throw new BlogAdminHttpRuntimeException(
                    'blog.password_policy_unsupported'
                );
            }
            if (!$context->environmentIsUsable()) {
                throw new BlogAdminHttpRuntimeException(
                    'blog.environment_unusable'
                );
            }

            $projectRoot = $context->projectRoot();
            $registry = $this->registry($projectRoot);
            if (!$registry->isEnabled('blog')) {
                throw new BlogAdminHttpRuntimeException(
                    'blog.module_not_enabled'
                );
            }
            if (!$registry->isEnabled('webadmin')) {
                throw new BlogAdminHttpRuntimeException(
                    'blog.webadmin_dependency_not_enabled'
                );
            }

            $languages = $context->languages();
            $blogConfig = $this->loadBlogConfig(
                $projectRoot,
                $languages
            );
            $canonicalWebAdminConfig = $this->loadWebAdminConfig(
                $projectRoot
            );
            if (!$this->sameWebAdminConfig(
                $canonicalWebAdminConfig,
                $webAdminConfig
            )) {
                throw new BlogAdminHttpRuntimeException(
                    'blog.webadmin_config_mismatch'
                );
            }

            $scopes = $this->configuredScopes($registry, $projectRoot);
            $blogScope = $scopes->get('blog');
            $webAdminScope = $scopes->get('webadmin');
            if (
                $blogScope === null
                || $webAdminScope === null
                || $blogScope->tablePrefix()
                    !== $blogConfig->tablePrefix()
                || $webAdminScope->tablePrefix()
                    !== $canonicalWebAdminConfig->tablePrefix()
            ) {
                throw new BlogAdminHttpRuntimeException(
                    'blog.scope_unavailable'
                );
            }

            $environment = $context->environment();
            $securityKey = $this->securityKey($environment);
            $connectionFactory = $this->connectionFactory($environment);
            try {
                $pdo = $connectionFactory->connect();
            } catch (Throwable) {
                throw new BlogAdminHttpRuntimeException(
                    'blog.connection_unavailable'
                );
            }
            if (!$this->webAdminSchemaGate->isReady(
                $pdo,
                $registry,
                $webAdminScope
            )) {
                throw new BlogAdminHttpRuntimeException(
                    'blog.webadmin_schema_not_ready'
                );
            }
            if (!$this->blogSchemaGate->isReady(
                $pdo,
                $registry,
                $scopes
            )) {
                throw new BlogAdminHttpRuntimeException(
                    'blog.schema_not_ready'
                );
            }

            $tables = WebAdminTableNames::fromPdo(
                $pdo,
                $canonicalWebAdminConfig->tablePrefix()
            );
            $passwordHasher = PasswordHasher::productive();
            $authentication = new WebAdminAuthenticationService(
                new WebAdminAuthenticationRepository($pdo, $tables),
                $webAdminConfig,
                $securityKey,
                $this->clock,
                $this->uuidGenerator,
                $passwordHasher,
                $this->tokenGenerator
            );
            $authorization = new WebAdminAuthorizationService(
                $pdo,
                $tables,
                $this->clock,
                $this->tokenGenerator,
                $passwordHasher
            );
            $mutationActorGate = new WebAdminMutationActorGate(
                $pdo,
                $tables,
                $webAdminConfig,
                $securityKey,
                $this->clock,
                $this->tokenGenerator,
                $passwordHasher
            );
            $service = new BlogService(
                new PdoBlogRepository($pdo, $blogScope),
                $this->uuidGenerator,
                $this->clock,
                new WebAdminBlogMutationAuditAdapter(
                    $pdo,
                    $tables,
                    $this->uuidGenerator
                )
            );

            return new BlogAdminHttpRuntime(
                $projectRoot,
                $languages,
                $blogConfig,
                $webAdminConfig,
                $service,
                $authentication,
                $authorization,
                $pdo,
                $mutationActorGate
            );
        } catch (BlogAdminHttpRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogAdminHttpRuntimeException();
        }
    }

    private function assertTraceSafety(): void
    {
        try {
            ExceptionTraceGuard::assertEnabled();
        } catch (Throwable) {
            throw new BlogAdminHttpRuntimeException(
                'blog.exception_trace_guard_failed'
            );
        }
    }

    private function registry(string $projectRoot): ModuleRegistry
    {
        try {
            return ModuleRegistry::forProject(
                $projectRoot,
                $this->coreRoot ?? dirname(__DIR__, 4)
            );
        } catch (Throwable) {
            throw new BlogAdminHttpRuntimeException(
                'blog.registry_invalid'
            );
        }
    }

    /** @param list<string> $languages */
    private function loadBlogConfig(
        string $projectRoot,
        array $languages
    ): BlogConfig {
        try {
            return $this->blogConfigLoader->load(
                $projectRoot,
                $languages
            );
        } catch (Throwable) {
            throw new BlogAdminHttpRuntimeException(
                'blog.config_invalid'
            );
        }
    }

    private function loadWebAdminConfig(
        string $projectRoot
    ): WebAdminConfig {
        try {
            return $this->webAdminConfigLoader->load($projectRoot);
        } catch (Throwable) {
            throw new BlogAdminHttpRuntimeException(
                'blog.webadmin_config_invalid'
            );
        }
    }

    private function configuredScopes(
        ModuleRegistry $registry,
        string $projectRoot
    ): MigrationScopeCollection {
        try {
            return $this->scopeFactory->create($registry, $projectRoot);
        } catch (Throwable) {
            throw new BlogAdminHttpRuntimeException(
                'blog.scope_unavailable'
            );
        }
    }

    /** @param array<string, mixed> $environment */
    private function securityKey(
        #[\SensitiveParameter] array $environment
    ): SecurityKey
    {
        $encoded = $environment[self::SECURITY_KEY_ENV] ?? null;
        if (!is_string($encoded) || $encoded === '') {
            throw new BlogAdminHttpRuntimeException(
                'blog.security_key_missing'
            );
        }
        try {
            return SecurityKey::fromBase64Url($encoded);
        } catch (InvalidSecurityKey) {
            throw new BlogAdminHttpRuntimeException(
                'blog.security_key_invalid'
            );
        }
    }

    /**
     * @param array<string, mixed> $environment
     */
    private function connectionFactory(
        #[\SensitiveParameter] array $environment
    ): PdoConnectionFactoryInterface {
        try {
            $factory = ($this->connectionFactoryResolver)($environment);
        } catch (Throwable) {
            throw new BlogAdminHttpRuntimeException(
                'blog.connection_unavailable'
            );
        }
        if (!$factory instanceof PdoConnectionFactoryInterface) {
            throw new BlogAdminHttpRuntimeException(
                'blog.connection_factory_invalid'
            );
        }

        return $factory;
    }

    private function sameWebAdminConfig(
        WebAdminConfig $first,
        WebAdminConfig $second
    ): bool {
        // The private route provider may resolve a different effective base
        // path after collision checks. Persistence and session invariants
        // must still match the canonical project configuration.
        return $first->tablePrefix() === $second->tablePrefix()
            && $first->cookieName() === $second->cookieName()
            && $first->idleTtlSeconds() === $second->idleTtlSeconds()
            && $first->absoluteTtlSeconds()
                === $second->absoluteTtlSeconds();
    }
}
