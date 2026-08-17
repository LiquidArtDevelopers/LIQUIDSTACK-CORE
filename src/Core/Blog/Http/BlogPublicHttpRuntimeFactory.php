<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\Analytics\BlogAnalyticsPageGrantCodec;
use App\Core\Blog\BlogService;
use App\Core\Blog\Categories\BlogCategoryPublicProjectionService;
use App\Core\Blog\Categories\Persistence\PdoBlogCategoryRepository;
use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\PublicFeed\PdoBlogPublicCatalogRepository;
use App\Core\Blog\PublicFeed\PdoBlogPublicCardMediaRepository;
use App\Core\Blog\PublicShell\BlogPublicShellDefaultSecurityPolicy;
use App\Core\Blog\PublicShell\BlogPublicShellSecurityConfig;
use App\Core\Blog\Seo\PdoBlogUrlHistoryRepository;
use App\Core\Blog\Seo\BlogPublicRobotsPolicy;
use App\Core\Blog\Seo\PdoBlogDummyCategoryRobotsOverride;
use App\Core\Blog\PublicDelivery\BlogPublicMediaDelivery;
use App\Core\Blog\PublicDelivery\PdoBlogPublicMediaRepository;
use App\Core\Blog\StructuredContent\Persistence\PdoBlogStructuredContentRepository;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Environment\ProjectRuntimeProfile;
use App\Core\Modules\Blog\BlogHttpSchemaGate;
use App\Core\Modules\Blog\BlogCategoryHttpSchemaGate;
use App\Core\Modules\Blog\BlogMigrationRequirements;
use App\Core\Modules\Blog\BlogLayoutEditorSchemaGate;
use App\Core\Modules\Blog\BlogStructuredContentSchemaGate;
use App\Core\Modules\Blog\BlogPostTombstoneSchemaGate;
use App\Core\Modules\Blog\BlogRobotsPreferencesSchemaGate;
use App\Core\Modules\Blog\BlogUrlHistorySchemaGate;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Modules\ConfiguredModuleDatabaseConnectionResolver;
use App\Core\Modules\WebAdmin\WebAdminMediaHttpSchemaGate;
use App\Core\Modules\WebAdmin\WebAdminProfileHttpSchemaGate;
use App\Core\WebAdmin\Media\PrivateMediaStorage;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Security\InvalidSecurityKey;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Profile\PdoWebAdminProfileRepository;
use Closure;
use Throwable;

final class BlogPublicHttpRuntimeFactory implements
    BlogPublicHttpRuntimeFactoryInterface
{
    /** @var Closure(array<string, mixed>, string): PdoConnectionFactoryInterface */
    private readonly Closure $connectionFactoryResolver;
    private readonly ConfiguredModuleDatabaseConnectionResolver $databaseConnectionResolver;

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
        private readonly BlogHttpSchemaGate $schemaGate =
            new BlogHttpSchemaGate(),
        private readonly BlogCategoryHttpSchemaGate $categorySchemaGate =
            new BlogCategoryHttpSchemaGate(),
        ?ConfiguredModuleDatabaseConnectionResolver $databaseConnectionResolver = null,
        private readonly BlogStructuredContentSchemaGate
            $structuredContentSchemaGate =
                new BlogStructuredContentSchemaGate(),
        private readonly WebAdminMediaHttpSchemaGate $mediaSchemaGate =
            new WebAdminMediaHttpSchemaGate(),
        private readonly MigrationFeatureGate $migrationFeatureGate =
            new MigrationFeatureGate(),
        private readonly BlogPostTombstoneSchemaGate $postTombstoneSchemaGate =
            new BlogPostTombstoneSchemaGate(),
        private readonly BlogRobotsPreferencesSchemaGate
            $robotsPreferencesSchemaGate =
                new BlogRobotsPreferencesSchemaGate(),
        private readonly BlogUrlHistorySchemaGate $urlHistorySchemaGate =
            new BlogUrlHistorySchemaGate()
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
        $this->databaseConnectionResolver = $databaseConnectionResolver
            ?? new ConfiguredModuleDatabaseConnectionResolver();
    }

    public function create(
        ModuleRuntimeContext $context
    ): BlogPublicHttpRuntime {
        try {
            if (!$context->environmentIsUsable()) {
                throw new BlogPublicHttpRuntimeException(
                    'blog.environment_unusable'
                );
            }

            $registry = ModuleRegistry::forProject(
                $context->projectRoot(),
                $this->coreRoot ?? dirname(__DIR__, 4)
            );
            if (
                !$registry->isEnabled('blog')
                || !$registry->isEnabled('webadmin')
            ) {
                throw new BlogPublicHttpRuntimeException(
                    'blog.module_not_enabled'
                );
            }

            $languages = $context->languages();
            $config = $this->configLoader->load(
                $context->projectRoot(),
                $languages
            );
            $publicShellSecurityPolicy = BlogPublicShellSecurityConfig::fromProject(
                $context->projectRoot(),
                BlogPublicShellDefaultSecurityPolicy::fromEnvironment(
                    $context->environment(),
                    $context->environmentIsUsable()
                )
            )->securityPolicy();
            $origin = BlogPublicOrigin::fromEnvironment(
                $context->environment()
            );
            $scopes = $this->scopeFactory->create(
                $registry,
                $context->projectRoot()
            );
            $blogScope = $scopes->get('blog');
            if ($blogScope === null) {
                throw new BlogPublicHttpRuntimeException(
                    'blog.scope_unavailable'
                );
            }
            $webAdminScope = $scopes->get('webadmin');

            $connectionFactory = ($this->connectionFactoryResolver)(
                $context->environment(),
                $this->databaseConnectionResolver->resolve(
                    $registry,
                    $context->projectRoot()
                )
            );
            if (!$connectionFactory instanceof PdoConnectionFactoryInterface) {
                throw new BlogPublicHttpRuntimeException(
                    'blog.connection_factory_invalid'
                );
            }
            $pdo = $connectionFactory->connect();
            if (!$this->schemaGate->isPublicReady(
                $pdo,
                $registry,
                $scopes
            )) {
                throw new BlogPublicHttpRuntimeException(
                    'blog.schema_not_ready'
                );
            }

            $structuredContent = null;
            $mediaDelivery = null;
            $cardMediaRepository = null;
            $robotsPreferencesReady = $this->robotsPreferencesSchemaGate
                ->isReady($pdo, $registry, $scopes);
            $categorySchemaReady = $this->categorySchemaGate->isPublicReady(
                $pdo,
                $registry,
                $scopes
            );
            $categoryProjection = $categorySchemaReady
                ? new BlogCategoryPublicProjectionService(
                    $config,
                    new PdoBlogCategoryRepository($pdo, $blogScope)
                )
                : null;
            $catalogRepository = $categorySchemaReady
                ? new PdoBlogPublicCatalogRepository($pdo, $blogScope)
                : null;
            $structuredMigrationApplied = $this->migrationFeatureGate->isReady(
                $pdo,
                $registry,
                $scopes,
                BlogMigrationRequirements::structuredContent()
            );
            if (
                $structuredMigrationApplied
                && !$this->structuredContentSchemaGate->isReady(
                    $pdo,
                    $registry,
                    $scopes
                )
            ) {
                throw new BlogPublicHttpRuntimeException(
                    'blog.structured_schema_not_ready'
                );
            }
            if ($structuredMigrationApplied) {
                $layoutEditorReady = (new BlogLayoutEditorSchemaGate())
                    ->isReady($pdo, $registry, $scopes);
                $structuredContent = new PdoBlogStructuredContentRepository(
                    $pdo,
                    $blogScope,
                    layoutReady: $layoutEditorReady,
                    robotsSettingsReady: $robotsPreferencesReady
                );
                if (
                    $webAdminScope !== null
                    && $this->mediaSchemaGate->isReady(
                        $pdo,
                        $registry,
                        $webAdminScope
                    )
                ) {
                    try {
                        $storage = PrivateMediaStorage::forProject(
                            $context->projectRoot(),
                            $context->environment()
                        );
                        if (($storage->diagnostic()['ready'] ?? false)
                            !== true) {
                            throw new \RuntimeException(
                                'Private media storage is not initialized.'
                            );
                        }
                        $mediaDelivery = new BlogPublicMediaDelivery(
                            new PdoBlogPublicMediaRepository(
                                $pdo,
                                $blogScope,
                                $webAdminScope
                            ),
                            $storage
                        );
                        try {
                            $cardMediaRepository =
                                new PdoBlogPublicCardMediaRepository(
                                    $pdo,
                                    $blogScope,
                                    $webAdminScope
                                );
                        } catch (Throwable) {
                            // Card thumbnails are optional. A projection
                            // failure must not disable article media delivery.
                            $cardMediaRepository = null;
                        }
                    } catch (Throwable) {
                        // Text-only structured documents remain usable. Any
                        // image block and the public media endpoint fail closed.
                        $mediaDelivery = null;
                        $cardMediaRepository = null;
                    }
                }
            }

            $analyticsCollectionReady = false;
            $analyticsPageGrants = null;
            $analyticsSecurityKey = $this->securityKey(
                $context->environment()
            );
            if (
                $config->analytics()->enabled()
                && $this->analyticsEnvironmentAllowsCollection(
                    $context,
                    $config->analytics()->collectInDevelopment()
                )
                && $this->migrationFeatureGate->isReady(
                    $pdo,
                    $registry,
                    $scopes,
                    BlogMigrationRequirements::analyticsCollection()
                )
                && $analyticsSecurityKey instanceof SecurityKey
            ) {
                $analyticsCollectionReady = true;
                $analyticsPageGrants = new BlogAnalyticsPageGrantCodec(
                    $analyticsSecurityKey,
                    $origin
                );
            }
            $urlHistory = $this->urlHistorySchemaGate->isReady(
                $pdo,
                $registry,
                $scopes
            ) ? new PdoBlogUrlHistoryRepository($pdo, $blogScope) : null;
            $profiles = null;
            if (
                $webAdminScope !== null
                && (new WebAdminProfileHttpSchemaGate())->isReady(
                    $pdo,
                    $registry,
                    $scopes
                )
            ) {
                $profiles = new PdoWebAdminProfileRepository(
                    $pdo,
                    WebAdminTableNames::fromPdo(
                        $pdo,
                        $webAdminScope->tablePrefix()
                    )
                );
            }

            return new BlogPublicHttpRuntime(
                $config,
                $origin,
                new BlogService(new PdoBlogRepository(
                    $pdo,
                    $blogScope,
                    $this->postTombstoneSchemaGate->isReady(
                        $pdo,
                        $registry,
                        $scopes
                    ),
                    $robotsPreferencesReady,
                    reservedCategoryPolicyEnabled: $categorySchemaReady
                )),
                $structuredContent,
                $mediaDelivery,
                $categoryProjection,
                $catalogRepository,
                $analyticsCollectionReady,
                $analyticsPageGrants,
                $urlHistory,
                $profiles,
                new BlogPublicRobotsPolicy(
                    $categorySchemaReady
                        ? new PdoBlogDummyCategoryRobotsOverride(
                            $pdo,
                            $blogScope
                        )
                        : null
                ),
                $cardMediaRepository,
                $publicShellSecurityPolicy
            );
        } catch (BlogPublicHttpRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPublicHttpRuntimeException();
        }
    }

    private function analyticsEnvironmentAllowsCollection(
        ModuleRuntimeContext $context,
        bool $collectInDevelopment
    ): bool {
        $profile = ProjectRuntimeProfile::fromEnvironment(
            $context->environment()
        );

        return !$profile->isDevelopmentLoopbackHttp()
            || $collectInDevelopment;
    }

    /** @param array<string, mixed> $environment */
    private function securityKey(array $environment): ?SecurityKey
    {
        $encoded = $environment[WebAdminConfig::SECURITY_KEY_ENV] ?? null;
        if (!is_string($encoded) || $encoded === '') {
            return null;
        }

        try {
            return SecurityKey::fromBase64Url($encoded);
        } catch (InvalidSecurityKey) {
            return null;
        }
    }
}
