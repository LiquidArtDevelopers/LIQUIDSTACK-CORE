<?php

declare(strict_types=1);

use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Http\BlogPublicHttpRuntimeException;
use App\Core\Blog\Http\BlogPublicHttpRuntimeFactory;
use App\Core\Blog\PublicFeed\BlogPublicCatalogQuery;
use App\Core\Blog\PublicFeed\BlogPublicDiscoveryRepositoryInterface;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\WebAdmin\Media\PrivateMediaStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogPublicHttpRuntimeFactoryTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-blog-public-runtime-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->root . '/App/config');
        $this->filesystem->dumpFile(
            $this->root . '/App/config/langs.php',
            "<?php\nreturn ['es'];\n"
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testDisabledModuleFailsBeforeConnection(): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/composer.json',
            "{\"require\": {}}\n"
        );
        $connectionRequested = false;
        $factory = new BlogPublicHttpRuntimeFactory(
            connectionFactoryResolver: static function () use (
                &$connectionRequested
            ): PdoConnectionFactoryInterface {
                $connectionRequested = true;
                throw new RuntimeException('must not connect');
            }
        );

        try {
            $factory->create(new ModuleRuntimeContext($this->root, [
                BlogPublicOrigin::ENV => 'https://example.test',
            ]));
            self::fail('Disabled Blog must fail closed.');
        } catch (BlogPublicHttpRuntimeException $exception) {
            self::assertSame(
                'blog.module_not_enabled',
                $exception->issueCode()
            );
        }
        self::assertFalse($connectionRequested);
    }

    public function testUnusableEnvironmentFailsBeforeConnection(): void
    {
        $connectionRequested = false;
        $factory = new BlogPublicHttpRuntimeFactory(
            connectionFactoryResolver: static function () use (
                &$connectionRequested
            ): PdoConnectionFactoryInterface {
                $connectionRequested = true;
                throw new RuntimeException('must not connect');
            }
        );

        try {
            $factory->create(new ModuleRuntimeContext(
                $this->root,
                [],
                false
            ));
            self::fail('Unusable environment must fail closed.');
        } catch (BlogPublicHttpRuntimeException $exception) {
            self::assertSame(
                'blog.environment_unusable',
                $exception->issueCode()
            );
        }
        self::assertFalse($connectionRequested);
    }

    public function testAppliedSchemaBuildsReadOnlyPublicRuntime(): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/composer.json',
            json_encode([
                'require' => [
                    'liquidstack/core' => '*',
                    'liquidstack/blog' => '*',
                ],
            ], JSON_THROW_ON_ERROR) . PHP_EOL
        );
        $this->writeModuleDatabaseConfig('webadmin', 'liquidstack');
        $this->writeModuleDatabaseConfig('blog', 'liquidstack');
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $coreRoot = dirname(__DIR__, 2);
        $registry = ModuleRegistry::forProject($this->root, $coreRoot);
        $scopes = (new ConfiguredMigrationScopeFactory())->create(
            $registry,
            $this->root
        );
        (new MigrationRunner())->apply(
            $pdo,
            MigrationCatalog::fromRegistry($registry),
            $scopes
        );
        $connection = new class($pdo) implements
            PdoConnectionFactoryInterface {
            public int $calls = 0;

            public function __construct(private readonly PDO $pdo)
            {
            }

            public function connect(): PDO
            {
                ++$this->calls;

                return $this->pdo;
            }
        };
        $receivedConnection = null;
        $factory = new BlogPublicHttpRuntimeFactory(
            coreRoot: $coreRoot,
            connectionFactoryResolver: static function (
                array $_environment,
                string $connectionProfile
            ) use (
                $connection,
                &$receivedConnection
            ): PdoConnectionFactoryInterface {
                $receivedConnection = $connectionProfile;

                return $connection;
            }
        );

        $runtime = $factory->create(new ModuleRuntimeContext($this->root, [
            BlogPublicOrigin::ENV => 'https://example.test',
        ]));

        self::assertSame('/blog', $runtime->config()->publicPath('es'));
        self::assertSame('https://example.test', $runtime->origin()->value());
        self::assertSame([], $runtime->service()->listPosts());
        self::assertSame('liquidstack', $receivedConnection);
        self::assertFalse($runtime->__debugInfo()['public_media']);
        self::assertTrue($runtime->__debugInfo()['category_projection']);
        self::assertTrue($runtime->__debugInfo()['catalog_repository']);
        self::assertNotNull($runtime->categoryProjection());
        self::assertNotNull($runtime->catalogRepository());
        self::assertInstanceOf(
            BlogPublicDiscoveryRepositoryInterface::class,
            $runtime->catalogRepository()
        );
        self::assertSame([], $runtime->categoryProjection()->filtersForLocale(
            'es'
        ));
        self::assertSame([], $runtime->catalogRepository()?->search(
            new BlogPublicCatalogQuery('es')
        ));
        self::assertSame(1, $connection->calls);

        $localEnvironment = [
            BlogPublicOrigin::ENV => 'https://example.test',
            'DEV_MODE' => '1',
            'RAIZ' => 'http://localhost:1309',
        ];
        PrivateMediaStorage::forProject(
            $this->root,
            $localEnvironment
        )->initialize();
        $mediaReadyRuntime = $factory->create(new ModuleRuntimeContext(
            $this->root,
            $localEnvironment
        ));
        self::assertTrue($mediaReadyRuntime->__debugInfo()['public_media']);
        self::assertSame(2, $connection->calls);

        $pdo->exec(
            'CREATE TRIGGER ls_blog_corrupt_structured_gate '
            . 'AFTER INSERT ON ls_blog_content_docs BEGIN SELECT 1; END'
        );
        try {
            $factory->create(new ModuleRuntimeContext($this->root, [
                BlogPublicOrigin::ENV => 'https://example.test',
            ]));
            self::fail('An applied but invalid 0005 schema must fail closed.');
        } catch (BlogPublicHttpRuntimeException $exception) {
            self::assertSame(
                'blog.structured_schema_not_ready',
                $exception->issueCode()
            );
        }

        $pdo->exec('DROP TRIGGER ls_blog_corrupt_structured_gate');
        $pdo->exec(
            "DELETE FROM ls_module_migrations WHERE module_id = 'blog' "
            . "AND migration_id IN ('0005_blog_structured_content', "
            . "'0006_blog_sitemap_publication_state', "
            . "'0007_blog_post_tombstones', "
            . "'0008_blog_article_delete_capability', "
            . "'0009_blog_analytics', "
            . "'0010_blog_analytics_view_capability')"
        );
        foreach ([
            'ls_blog_analytics_views',
            'ls_blog_analytics_sessions',
            'ls_blog_post_tombstones',
        ] as $table) {
            $pdo->exec('DROP TABLE ' . $table);
        }
        $pdo->exec('DROP TABLE ls_blog_sitemap_state');
        foreach ([
            'ls_blog_revision_media',
            'ls_blog_content_media',
            'ls_blog_content_revisions',
            'ls_blog_content_docs',
        ] as $table) {
            $pdo->exec('DROP TABLE ' . $table);
        }
        $legacyRuntime = $factory->create(new ModuleRuntimeContext(
            $this->root,
            [BlogPublicOrigin::ENV => 'https://example.test']
        ));
        self::assertSame([], $legacyRuntime->service()->listPosts());
        self::assertTrue(
            $legacyRuntime->__debugInfo()['category_projection']
        );
        self::assertTrue(
            $legacyRuntime->__debugInfo()['catalog_repository']
        );
        self::assertNotNull($legacyRuntime->catalogRepository());

        $pdo->exec(
            "DELETE FROM ls_module_migrations WHERE module_id = 'blog' "
            . "AND migration_id IN ('0003_blog_categories', "
            . "'0004_blog_category_capabilities')"
        );
        foreach ([
            'ls_blog_post_categories',
            'ls_blog_category_locales',
            'ls_blog_categories',
        ] as $table) {
            $pdo->exec('DROP TABLE ' . $table);
        }
        $baseRuntime = $factory->create(new ModuleRuntimeContext(
            $this->root,
            [BlogPublicOrigin::ENV => 'https://example.test']
        ));
        self::assertSame([], $baseRuntime->service()->listPosts());
        self::assertFalse(
            $baseRuntime->__debugInfo()['category_projection']
        );
        self::assertFalse(
            $baseRuntime->__debugInfo()['catalog_repository']
        );
        self::assertNull($baseRuntime->catalogRepository());
    }

    public function testDatabaseConnectionMismatchFailsBeforeResolverAndConnector(): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/composer.json',
            json_encode(['require' => [
                'liquidstack/core' => '*',
                'liquidstack/blog' => '*',
            ]], JSON_THROW_ON_ERROR) . PHP_EOL
        );
        $this->writeModuleDatabaseConfig('webadmin', 'liquidstack');
        $this->writeModuleDatabaseConfig('blog', 'shared');
        $resolverCalls = 0;
        $factory = new BlogPublicHttpRuntimeFactory(
            coreRoot: dirname(__DIR__, 2),
            connectionFactoryResolver: static function () use (
                &$resolverCalls
            ): PdoConnectionFactoryInterface {
                ++$resolverCalls;
                throw new RuntimeException('Must not resolve or connect.');
            }
        );

        try {
            $factory->create(new ModuleRuntimeContext($this->root, [
                BlogPublicOrigin::ENV => 'https://example.test',
            ]));
            self::fail('El mismatch debía fallar antes del resolver PDO.');
        } catch (BlogPublicHttpRuntimeException $exception) {
            self::assertSame(
                'blog.public_runtime_unavailable',
                $exception->issueCode()
            );
        }
        self::assertSame(0, $resolverCalls);
    }

    private function writeModuleDatabaseConfig(
        string $module,
        string $connection
    ): void {
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/' . $module . '.php',
            "<?php\nreturn ['database' => ["
                . "'connection' => '" . $connection . "']];\n"
        );
    }
}
