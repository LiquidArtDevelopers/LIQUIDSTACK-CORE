<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Blog\Audit\WebAdminBlogMutationAuditAdapter;
use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureCatalog;
use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureException;
use App\Core\Blog\QaFixtures\PdoBlogQaMatrixFixturePersistence;
use App\Core\Blog\QaFixtures\WebAdminBlogQaMatrixFixtureMediaProbe;
use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Environment\ProjectEnvironmentLoader;
use App\Core\Modules\Blog\BlogDummyCategoryNormalizationPostcondition;
use App\Core\Modules\Blog\BlogLayoutEditorSchemaGate;
use App\Core\Modules\Blog\BlogPrivateDraftPublicationSchemaGate;
use App\Core\Modules\Blog\BlogRobotsPreferencesSchemaGate;
use App\Core\Modules\Blog\BlogStructuredContentSchemaGate;
use App\Core\Modules\Blog\BlogUrlHistorySchemaGate;
use App\Core\Modules\ConfiguredModuleDatabaseConnectionResolver;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\Migrations\MigrationFeatureRequirement;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Modules\WebAdmin\WebAdminHttpSchemaGate;
use App\Core\Modules\WebAdmin\WebAdminMediaHttpSchemaGate;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use App\Core\WebAdmin\Media\PdoMediaRepository;
use App\Core\WebAdmin\Media\PrivateMediaStorage;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use Closure;
use PDO;
use Throwable;

final class BlogQaSeedMatrixCommandRuntimeFactory implements
    BlogQaSeedMatrixCommandRuntimeFactoryInterface
{
    /** @var Closure(array<string, mixed>, string): PdoConnectionFactoryInterface */
    private readonly Closure $connectionFactoryResolver;

    /** @param null|callable(array<string, mixed>, string): PdoConnectionFactoryInterface $connectionFactoryResolver */
    public function __construct(
        private readonly ProjectEnvironmentLoader $environmentLoader =
            new ProjectEnvironmentLoader(),
        private readonly ConfiguredMigrationScopeFactory $scopeFactory =
            new ConfiguredMigrationScopeFactory(),
        private readonly MigrationFeatureGate $featureGate =
            new MigrationFeatureGate(),
        ?callable $connectionFactoryResolver = null,
        private readonly ConfiguredModuleDatabaseConnectionResolver
            $databaseConnectionResolver =
                new ConfiguredModuleDatabaseConnectionResolver(),
        private readonly BlogConfigLoader $blogConfigLoader =
            new BlogConfigLoader(),
        private readonly WebAdminConfigLoader $webAdminConfigLoader =
            new WebAdminConfigLoader()
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
        string $coreRoot,
        bool $allowMysql
    ): BlogQaSeedMatrixCommandRuntimeInterface {
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
            if (($values['DEV_MODE'] ?? null) !== '1') {
                throw $this->failure('dev_mode_required');
            }

            $scopes = $this->scopeFactory->create($registry, $projectRoot);
            $blogScope = $scopes->get('blog');
            $webAdminScope = $scopes->get('webadmin');
            $blogConfig = $this->blogConfigLoader->load(
                $projectRoot,
                (new ModuleRuntimeContext($projectRoot))
                    ->languages()
            );
            $webAdminConfig = $this->webAdminConfigLoader->load($projectRoot);
            if (
                $blogScope === null
                || $webAdminScope === null
                || $blogScope->tablePrefix() !== $blogConfig->tablePrefix()
                || $webAdminScope->tablePrefix()
                    !== $webAdminConfig->tablePrefix()
                || $blogConfig->databaseConnection()
                    !== $webAdminConfig->databaseConnection()
            ) {
                throw $this->failure('scope_unavailable');
            }

            $connection = $this->databaseConnectionResolver->resolve(
                $registry,
                $projectRoot
            );
            $factory = ($this->connectionFactoryResolver)(
                $values,
                $connection
            );
            $pdo = $factory->connect();
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (
                !is_string($driver)
                || !in_array($driver, ['sqlite', 'mysql'], true)
            ) {
                throw $this->failure('driver_unsupported');
            }
            if ($driver === 'mysql' && !$allowMysql) {
                throw $this->failure('mysql_confirmation_required');
            }

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
                || !(new BlogUrlHistorySchemaGate())->isReady(
                    $pdo,
                    $registry,
                    $scopes
                )
                || !$this->featureGate->isReady(
                    $pdo,
                    $registry,
                    $scopes,
                    new MigrationFeatureRequirement(
                        'blog',
                        'blog.qa_matrix_fixtures',
                        ['0018_blog_dummy_category_normalization']
                    )
                )
                || !(new BlogDummyCategoryNormalizationPostcondition())->verify(
                    $pdo,
                    $blogScope
                )
            ) {
                throw $this->failure('schema_not_ready');
            }

            $tables = WebAdminTableNames::fromPdo(
                $pdo,
                $webAdminScope->tablePrefix()
            );
            $mediaRepository = new PdoMediaRepository($pdo, $tables);
            $mediaStorage = PrivateMediaStorage::forProject(
                $projectRoot,
                $values
            );

            return new BlogQaSeedMatrixCommandRuntime(
                new BlogQaMatrixFixtureCatalog(),
                new WebAdminBlogQaMatrixFixtureMediaProbe(
                    $mediaRepository,
                    $mediaStorage
                ),
                new PdoBlogQaMatrixFixturePersistence(
                    $pdo,
                    $blogScope,
                    $tables,
                    new WebAdminBlogMutationAuditAdapter($pdo, $tables)
                )
            );
        } catch (BlogQaMatrixFixtureException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->failure('runtime_unavailable');
        }
    }

    private function failure(string $suffix): BlogQaMatrixFixtureException
    {
        return new BlogQaMatrixFixtureException(
            'blog.qa_fixture.' . $suffix
        );
    }
}
