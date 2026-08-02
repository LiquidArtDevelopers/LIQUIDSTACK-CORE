<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\Audit\WebAdminBlogMutationAuditAdapter;
use App\Core\Blog\BlogService;
use App\Core\Blog\Categories\Audit\WebAdminBlogCategoryAuditAdapter;
use App\Core\Blog\Categories\BlogCategoryService;
use App\Core\Blog\Categories\Persistence\PdoBlogCategoryRepository;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Modules\Blog\BlogCategoryHttpSchemaGate;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
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

/** Composes the optional category feature without changing Blog 0001 runtime. */
final class BlogCategoryAdminHttpRuntimeFactory implements
    BlogCategoryAdminHttpRuntimeFactoryInterface
{
    /** @var Closure(array<string, mixed>, string): PdoConnectionFactoryInterface */
    private readonly Closure $connectionFactoryResolver;
    private readonly BlogConfigLoader $blogConfigLoader;
    private readonly WebAdminConfigLoader $webAdminConfigLoader;
    private readonly ConfiguredMigrationScopeFactory $scopeFactory;
    private readonly BlogCategoryHttpSchemaGate $categorySchemaGate;
    private readonly WebAdminHttpSchemaGate $webAdminSchemaGate;
    private readonly ClockInterface $clock;
    private readonly UuidGeneratorInterface $uuidGenerator;
    private readonly SecureTokenGenerator $tokenGenerator;

    /**
     * @param null|callable(array<string, mixed>, string): PdoConnectionFactoryInterface $connectionFactoryResolver
     */
    public function __construct(
        private readonly ?string $coreRoot = null,
        ?callable $connectionFactoryResolver = null,
        ?BlogConfigLoader $blogConfigLoader = null,
        ?WebAdminConfigLoader $webAdminConfigLoader = null,
        ?ConfiguredMigrationScopeFactory $scopeFactory = null,
        ?BlogCategoryHttpSchemaGate $categorySchemaGate = null,
        ?WebAdminHttpSchemaGate $webAdminSchemaGate = null,
        ?ClockInterface $clock = null,
        ?UuidGeneratorInterface $uuidGenerator = null,
        ?SecureTokenGenerator $tokenGenerator = null
    ) {
        $this->connectionFactoryResolver = $connectionFactoryResolver === null
            ? static fn (array $environment, string $connection):
                PdoConnectionFactoryInterface =>
                    (new ConfiguredPdoConnectionFactoryResolver())->resolve(
                        $connection,
                        $environment
                    )
            : Closure::fromCallable($connectionFactoryResolver);
        $this->blogConfigLoader = $blogConfigLoader ?? new BlogConfigLoader();
        $this->webAdminConfigLoader = $webAdminConfigLoader
            ?? new WebAdminConfigLoader();
        $this->scopeFactory = $scopeFactory
            ?? new ConfiguredMigrationScopeFactory(
                $this->webAdminConfigLoader,
                $this->blogConfigLoader
            );
        $this->categorySchemaGate = $categorySchemaGate
            ?? new BlogCategoryHttpSchemaGate();
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
    ): BlogCategoryAdminHttpRuntimeInterface {
        try {
            ExceptionTraceGuard::assertEnabled();
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
            $root = $context->projectRoot();
            $registry = ModuleRegistry::forProject(
                $root,
                $this->coreRoot ?? dirname(__DIR__, 4)
            );
            if (!$registry->isEnabled('blog') || !$registry->isEnabled('webadmin')) {
                throw new BlogAdminHttpRuntimeException(
                    'blog.module_not_enabled'
                );
            }
            $languages = $context->languages();
            $blogConfig = $this->blogConfigLoader->load($root, $languages);
            $canonicalWebAdmin = $this->webAdminConfigLoader->load($root);
            if (!$this->sameWebAdminConfig($canonicalWebAdmin, $webAdminConfig)) {
                throw new BlogAdminHttpRuntimeException(
                    'blog.webadmin_config_mismatch'
                );
            }
            if ($blogConfig->databaseConnection()
                !== $canonicalWebAdmin->databaseConnection()) {
                throw new BlogAdminHttpRuntimeException(
                    'blog.database_connection_mismatch'
                );
            }
            $scopes = $this->scopeFactory->create($registry, $root);
            $blogScope = $scopes->get('blog');
            $webAdminScope = $scopes->get('webadmin');
            if (
                $blogScope === null
                || $webAdminScope === null
                || $blogScope->tablePrefix() !== $blogConfig->tablePrefix()
                || $webAdminScope->tablePrefix()
                    !== $canonicalWebAdmin->tablePrefix()
            ) {
                throw new BlogAdminHttpRuntimeException(
                    'blog.scope_unavailable'
                );
            }
            $environment = $context->environment();
            $securityKey = $this->securityKey($environment);
            $resolver = $this->connectionFactoryResolver;
            $factory = $resolver(
                $environment,
                $blogConfig->databaseConnection()
            );
            if (!$factory instanceof PdoConnectionFactoryInterface) {
                throw new BlogAdminHttpRuntimeException(
                    'blog.connection_factory_invalid'
                );
            }
            $pdo = $factory->connect();
            if (!$this->webAdminSchemaGate->isReady(
                $pdo,
                $registry,
                $webAdminScope
            )) {
                throw new BlogAdminHttpRuntimeException(
                    'blog.webadmin_schema_not_ready'
                );
            }
            if (!$this->categorySchemaGate->isAdministrationReady(
                $pdo,
                $registry,
                $scopes
            )) {
                throw new BlogAdminHttpRuntimeException(
                    'blog.categories.schema_not_ready'
                );
            }
            $tables = WebAdminTableNames::fromPdo(
                $pdo,
                $canonicalWebAdmin->tablePrefix()
            );
            $hasher = PasswordHasher::productive();
            $authentication = new WebAdminAuthenticationService(
                new WebAdminAuthenticationRepository($pdo, $tables),
                $webAdminConfig,
                $securityKey,
                $this->clock,
                $this->uuidGenerator,
                $hasher,
                $this->tokenGenerator
            );
            $authorization = new WebAdminAuthorizationService(
                $pdo,
                $tables,
                $this->clock,
                $this->tokenGenerator,
                $hasher
            );
            $actorGate = new WebAdminMutationActorGate(
                $pdo,
                $tables,
                $webAdminConfig,
                $securityKey,
                $this->clock,
                $this->tokenGenerator,
                $hasher
            );

            return new BlogCategoryAdminHttpRuntime(
                $languages,
                $blogConfig,
                $webAdminConfig,
                new BlogService(
                    new PdoBlogRepository($pdo, $blogScope),
                    $this->uuidGenerator,
                    $this->clock,
                    new WebAdminBlogMutationAuditAdapter(
                        $pdo,
                        $tables,
                        $this->uuidGenerator
                    )
                ),
                new BlogCategoryService(
                    new PdoBlogCategoryRepository($pdo, $blogScope),
                    $this->uuidGenerator,
                    $this->clock,
                    new WebAdminBlogCategoryAuditAdapter(
                        $pdo,
                        $tables,
                        $this->uuidGenerator
                    )
                ),
                $authentication,
                $authorization,
                $pdo,
                $actorGate
            );
        } catch (BlogAdminHttpRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogAdminHttpRuntimeException(
                'blog.categories.runtime_unavailable'
            );
        }
    }

    /** @param array<string, mixed> $environment */
    private function securityKey(array $environment): SecurityKey
    {
        $encoded = $environment[WebAdminConfig::SECURITY_KEY_ENV] ?? null;
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

    private function sameWebAdminConfig(
        WebAdminConfig $first,
        WebAdminConfig $second
    ): bool {
        return $first->tablePrefix() === $second->tablePrefix()
            && $first->databaseConnection() === $second->databaseConnection()
            && $first->cookieName() === $second->cookieName()
            && $first->idleTtlSeconds() === $second->idleTtlSeconds()
            && $first->absoluteTtlSeconds() === $second->absoluteTtlSeconds();
    }
}
