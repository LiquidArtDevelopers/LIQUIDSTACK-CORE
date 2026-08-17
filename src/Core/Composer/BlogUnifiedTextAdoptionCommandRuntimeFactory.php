<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Blog\Audit\WebAdminBlogMutationAuditAdapter;
use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Blog\EditorialWorkflow\Persistence\PdoBlogEditorialWorkspaceRepository;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\StructuredContent\Adoption\BlogUnifiedTextAdoptionException;
use App\Core\Blog\StructuredContent\Adoption\BlogUnifiedTextAdoptionService;
use App\Core\Blog\StructuredContent\Adoption\PdoBlogUnifiedTextAdoptionActorGate;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredEditorService;
use App\Core\Blog\StructuredContent\Media\PdoWebAdminMediaAvailabilityAdapter;
use App\Core\Blog\StructuredContent\Persistence\PdoBlogStructuredContentRepository;
use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Environment\ProjectEnvironmentLoader;
use App\Core\Modules\Blog\BlogCategoryHttpSchemaGate;
use App\Core\Modules\Blog\BlogHttpSchemaGate;
use App\Core\Modules\Blog\BlogLayoutEditorSchemaGate;
use App\Core\Modules\Blog\BlogPostTombstoneSchemaGate;
use App\Core\Modules\Blog\BlogPrivateDraftPublicationSchemaGate;
use App\Core\Modules\Blog\BlogRobotsPreferencesSchemaGate;
use App\Core\Modules\Blog\BlogStructuredContentSchemaGate;
use App\Core\Modules\ConfiguredModuleDatabaseConnectionResolver;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Modules\WebAdmin\WebAdminHttpSchemaGate;
use App\Core\Modules\WebAdmin\WebAdminMediaHttpSchemaGate;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use Closure;
use PDO;
use Throwable;

/** Composes the explicit content-adoption command without an HTTP session. */
final class BlogUnifiedTextAdoptionCommandRuntimeFactory implements
    BlogUnifiedTextAdoptionCommandRuntimeFactoryInterface
{
    /** @var Closure(array<string, mixed>, string): PdoConnectionFactoryInterface */
    private readonly Closure $connectionFactoryResolver;

    /** @param null|callable(array<string, mixed>, string): PdoConnectionFactoryInterface $connectionFactoryResolver */
    public function __construct(
        private readonly ProjectEnvironmentLoader $environmentLoader =
            new ProjectEnvironmentLoader(),
        private readonly BlogConfigLoader $blogConfigLoader =
            new BlogConfigLoader(),
        private readonly WebAdminConfigLoader $webAdminConfigLoader =
            new WebAdminConfigLoader(),
        private readonly ConfiguredMigrationScopeFactory $scopeFactory =
            new ConfiguredMigrationScopeFactory(),
        ?callable $connectionFactoryResolver = null,
        private readonly ConfiguredModuleDatabaseConnectionResolver
            $databaseConnectionResolver =
                new ConfiguredModuleDatabaseConnectionResolver()
    ) {
        $this->connectionFactoryResolver = $connectionFactoryResolver === null
            ? static fn (array $environment, string $connection):
                PdoConnectionFactoryInterface =>
                    (new ConfiguredPdoConnectionFactoryResolver())->resolve(
                        $connection,
                        $environment
                    )
            : Closure::fromCallable($connectionFactoryResolver);
    }

    public function create(
        string $projectRoot,
        string $coreRoot
    ): BlogUnifiedTextAdoptionCommandRuntimeInterface {
        try {
            $registry = ModuleRegistry::forProject($projectRoot, $coreRoot);
            if (
                !$registry->isEnabled('blog')
                || !$registry->isEnabled('webadmin')
            ) {
                throw $this->failure('modules_not_enabled');
            }
            $environment = $this->environmentLoader->load($projectRoot);
            if (!$environment->isUsable()) {
                throw $this->failure('environment_unusable');
            }
            $values = $environment->values();
            $context = new ModuleRuntimeContext($projectRoot, $values, true);
            $blogConfig = $this->blogConfigLoader->load(
                $projectRoot,
                $context->languages()
            );
            $webAdminConfig = $this->webAdminConfigLoader->load($projectRoot);
            if (
                $blogConfig->databaseConnection()
                    !== $webAdminConfig->databaseConnection()
            ) {
                throw $this->failure('database_connection_mismatch');
            }
            $scopes = $this->scopeFactory->create($registry, $projectRoot);
            $blogScope = $scopes->get('blog');
            $webAdminScope = $scopes->get('webadmin');
            if (
                $blogScope === null
                || $webAdminScope === null
                || $blogScope->tablePrefix() !== $blogConfig->tablePrefix()
                || $webAdminScope->tablePrefix()
                    !== $webAdminConfig->tablePrefix()
            ) {
                throw $this->failure('scope_unavailable');
            }
            $connectionFactory = ($this->connectionFactoryResolver)(
                $values,
                $this->databaseConnectionResolver->resolve(
                    $registry,
                    $projectRoot
                )
            );
            $pdo = $connectionFactory->connect();
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (!is_string($driver) || !in_array(
                $driver,
                ['sqlite', 'mysql'],
                true
            )) {
                throw $this->failure('driver_unsupported');
            }

            // Blog/category administration are the fail-closed 0018 frontier;
            // their bounded Dummy readiness prevents QA fixtures entering the
            // catalog. Tombstones must also be ready so trash stays excluded.
            if (
                !(new WebAdminHttpSchemaGate())->isReady(
                    $pdo,
                    $registry,
                    $webAdminScope
                )
                || !(new WebAdminMediaHttpSchemaGate())->isReady(
                    $pdo,
                    $registry,
                    $webAdminScope
                )
                || !(new BlogHttpSchemaGate())->isAdministrationReady(
                    $pdo,
                    $registry,
                    $scopes
                )
                || !(new BlogCategoryHttpSchemaGate())
                    ->isAdministrationReady($pdo, $registry, $scopes)
                || !(new BlogStructuredContentSchemaGate())->isReady(
                    $pdo,
                    $registry,
                    $scopes
                )
                || !(new BlogLayoutEditorSchemaGate())->isReady(
                    $pdo,
                    $registry,
                    $scopes
                )
                || !(new BlogPrivateDraftPublicationSchemaGate())->isReady(
                    $pdo,
                    $registry,
                    $scopes
                )
                || !(new BlogRobotsPreferencesSchemaGate())->isReady(
                    $pdo,
                    $registry,
                    $scopes
                )
                || !(new BlogPostTombstoneSchemaGate())->isReady(
                    $pdo,
                    $registry,
                    $scopes
                )
            ) {
                throw $this->failure('schema_not_ready');
            }

            $tables = WebAdminTableNames::fromPdo(
                $pdo,
                $webAdminConfig->tablePrefix()
            );
            $blogRepository = new PdoBlogRepository(
                $pdo,
                $blogScope,
                postTombstonesEnabled: true,
                robotsSettingsEnabled: true,
                adminUserScope: $webAdminScope,
                adminCategoryProjectionEnabled: true,
                reservedCategoryPolicyEnabled: true
            );
            $contentRepository = new PdoBlogStructuredContentRepository(
                $pdo,
                $blogScope,
                layoutReady: true,
                robotsSettingsReady: true
            );
            $workflow = new PdoBlogEditorialWorkspaceRepository(
                $pdo,
                $blogScope,
                true
            );
            $media = new PdoWebAdminMediaAvailabilityAdapter(
                $pdo,
                $webAdminScope
            );
            $audit = new WebAdminBlogMutationAuditAdapter($pdo, $tables);
            $editor = new BlogStructuredEditorService(
                $blogRepository,
                $contentRepository,
                $media,
                auditPort: $audit,
                layoutReady: true,
                workflowRepository: $workflow
            );

            return new BlogUnifiedTextAdoptionCommandRuntime(
                new BlogUnifiedTextAdoptionService(
                    new BlogService($blogRepository),
                    $editor,
                    new PdoBlogUnifiedTextAdoptionActorGate(
                        $pdo,
                        $tables,
                        $blogScope
                    ),
                    $driver
                )
            );
        } catch (BlogUnifiedTextAdoptionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->failure('runtime_unavailable');
        }
    }

    private function failure(string $suffix): BlogUnifiedTextAdoptionException
    {
        return new BlogUnifiedTextAdoptionException(
            'blog.unified_text_adoption.' . $suffix
        );
    }
}
