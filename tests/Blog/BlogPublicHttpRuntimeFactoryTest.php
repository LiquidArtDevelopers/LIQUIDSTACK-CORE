<?php

declare(strict_types=1);

use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Http\BlogPublicHttpRuntimeException;
use App\Core\Blog\Http\BlogPublicHttpRuntimeFactory;
use App\Core\Blog\PublicFeed\BlogPublicCatalogQuery;
use App\Core\Blog\PublicFeed\BlogPublicDiscoveryRepositoryInterface;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\Migrations\MigrationApplyOptions;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Modules\Blog\BlogUrlHistoryMigrationPostconditionVerifier;
use App\Core\WebAdmin\Media\PrivateMediaStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogPublicRuntimeCountingPdo extends PDO
{
    /** @var list<string> */
    private array $sql = [];

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function exec(string $statement): int|false
    {
        $this->sql[] = $statement;

        return parent::exec($statement);
    }

    public function prepare(
        string $query,
        array $options = []
    ): PDOStatement|false {
        $this->sql[] = $query;

        return parent::prepare($query, $options);
    }

    public function query(
        string $query,
        ?int $fetchMode = null,
        mixed ...$fetchModeArgs
    ): PDOStatement|false {
        $this->sql[] = $query;

        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function clearSqlLog(): void
    {
        $this->sql = [];
    }

    /** @return list<string> */
    public function sqlLog(): array
    {
        return $this->sql;
    }
}

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
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/blog-public.php',
            <<<'PHP'
<?php
return [
    'security_sources' => [
        'script' => ['https://webda.eus'],
        'style' => ['https://webda.eus'],
        'image' => ['https://webda.eus'],
        'connect' => ['https://webda.eus'],
    ],
];
PHP
        );
        $pdo = new BlogPublicRuntimeCountingPdo();
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
            $scopes,
            new MigrationApplyOptions(
                allowDestructive: true,
                backupConfirmed: true
            )
        );
        $pdo->clearSqlLog();
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

        $factorySql = strtolower(implode("\n", $pdo->sqlLog()));
        foreach ([
            'information_schema',
            'sqlite_master',
            'pragma table_info',
            'pragma index_list',
            'pragma index_info',
            'pragma foreign_key_list',
            'pragma foreign_key_check',
            'pragma integrity_check',
            'show create table',
            'show columns',
            'show index',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $factorySql);
        }
        self::assertStringContainsString('where 1 = 0', $factorySql);

        self::assertSame('/blog', $runtime->config()->publicPath('es'));
        self::assertSame('https://example.test', $runtime->origin()->value());
        self::assertSame([], $runtime->service()->listPosts());
        self::assertSame('liquidstack', $receivedConnection);
        self::assertFalse($runtime->__debugInfo()['public_media']);
        self::assertFalse($runtime->__debugInfo()['card_media']);
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
        self::assertSame([
            'categories' => [],
            'tags' => [],
        ], $runtime->publicFeed()->taxonomiesForArticle('es', 'missing'));
        $productionCsp = $runtime->publicShellSecurityPolicy()
            ->context()
            ->headers()['Content-Security-Policy'];
        self::assertStringContainsString('https://webda.eus', $productionCsp);
        self::assertStringContainsString(
            'upgrade-insecure-requests',
            $productionCsp
        );
        self::assertStringNotContainsString(
            'localhost:5173',
            $productionCsp
        );
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
        self::assertTrue($mediaReadyRuntime->__debugInfo()['card_media']);
        $developmentCsp = $mediaReadyRuntime->publicShellSecurityPolicy()
            ->context()
            ->headers()['Content-Security-Policy'];
        self::assertStringContainsString('https://webda.eus', $developmentCsp);
        self::assertStringContainsString(
            'http://localhost:5173',
            $developmentCsp
        );
        self::assertStringContainsString(
            'ws://localhost:5173',
            $developmentCsp
        );
        self::assertStringNotContainsString(
            'upgrade-insecure-requests',
            $developmentCsp
        );
        self::assertSame(2, $connection->calls);

        $pdo->exec(
            'CREATE TRIGGER ls_blog_corrupt_structured_gate '
            . 'AFTER INSERT ON ls_blog_content_docs BEGIN SELECT 1; END'
        );
        $factory->create(new ModuleRuntimeContext($this->root, [
            BlogPublicOrigin::ENV => 'https://example.test',
        ]));
        self::assertSame(3, $connection->calls);
        $blogScope = $scopes->get('blog');
        self::assertNotNull($blogScope);
        self::assertFalse(
            (new BlogUrlHistoryMigrationPostconditionVerifier())->verify(
                $pdo,
                $blogScope
            ),
            'migrate/doctor must retain trigger auditing outside HTTP.'
        );

        $pdo->exec(
            'ALTER TABLE ls_blog_tag_assignment_workspace_items RENAME TO '
            . 'ls_blog_tag_assignment_workspace_items_hold'
        );
        try {
            $factory->create(new ModuleRuntimeContext($this->root, [
                BlogPublicOrigin::ENV => 'https://example.test',
            ]));
            self::fail('An applied but corrupt tag schema must fail closed.');
        } catch (BlogPublicHttpRuntimeException $exception) {
            self::assertSame(
                'blog.tags_schema_not_ready',
                $exception->issueCode()
            );
        } finally {
            $pdo->exec(
                'ALTER TABLE ls_blog_tag_assignment_workspace_items_hold '
                . 'RENAME TO ls_blog_tag_assignment_workspace_items'
            );
        }

        $pdo->exec(
            "DELETE FROM ls_module_migrations WHERE module_id = 'blog' "
            . "AND migration_id IN ('0020_blog_tags', "
            . "'0021_blog_localization_tags', "
            . "'0022_blog_tag_assignment_heads', "
            . "'0023_blog_tag_assignment_workspaces', "
            . "'0024_blog_tag_assignment_workspace_items', "
            . "'0025_blog_tag_capabilities')"
        );
        $pdo->clearSqlLog();
        $pendingTagsRuntime = $factory->create(new ModuleRuntimeContext(
            $this->root,
            [BlogPublicOrigin::ENV => 'https://example.test']
        ));
        self::assertSame([
            'categories' => [],
            'tags' => [],
        ], $pendingTagsRuntime->publicFeed()->taxonomiesForArticle(
            'es',
            'missing'
        ));
        $pendingTagsSql = strtolower(implode("\n", $pdo->sqlLog()));
        self::assertStringNotContainsString(
            'ls_blog_localization_tags',
            $pendingTagsSql
        );
        self::assertStringNotContainsString(
            'from "ls_blog_tags"',
            $pendingTagsSql
        );

        $pdo->exec('DROP TRIGGER ls_blog_corrupt_structured_gate');
        $pdo->exec(
            "DELETE FROM ls_module_migrations WHERE module_id = 'blog' "
            . "AND migration_id IN ('0005_blog_structured_content', "
            . "'0006_blog_sitemap_publication_state', "
            . "'0007_blog_post_tombstones', "
            . "'0008_blog_article_delete_capability', "
            . "'0009_blog_analytics', "
            . "'0010_blog_analytics_view_capability', "
            . "'0011_blog_layout_editor_v2', "
            . "'0012_blog_editor_preferences', "
            . "'0013_blog_settings_manage_capability', "
            . "'0014_blog_private_draft_publication', "
            . "'0015_blog_robots_preferences', "
            . "'0016_blog_url_history', "
            . "'0017_blog_dummy_category', "
            . "'0018_blog_dummy_category_normalization')"
        );
        $pdo->exec('DROP TABLE ls_blog_url_history');
        $pdo->exec('DROP TABLE ls_blog_revision_robots');
        $pdo->exec('DROP TABLE ls_blog_robots_settings');
        foreach ([
            'ls_blog_category_assignment_workspace_items',
            'ls_blog_category_assignment_workspaces',
            'ls_blog_category_assignment_heads',
            'ls_blog_editorial_workspaces',
            'ls_blog_publication_heads',
            'ls_blog_editor_preferences',
            'ls_blog_content_layout_revisions',
            'ls_blog_content_layout_docs',
        ] as $table) {
            $pdo->exec('DROP TABLE ' . $table);
        }
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
        $pdo->exec(
            "DELETE FROM ls_blog_category_locales WHERE slug = 'dummy'"
        );
        $pdo->exec(
            "DELETE FROM ls_blog_categories WHERE public_id = "
            . "'00000000-0000-4000-8000-000000000017'"
        );
        try {
            $factory->create(new ModuleRuntimeContext(
                $this->root,
                [BlogPublicOrigin::ENV => 'https://example.test']
            ));
            self::fail('Pre-0018 public runtime must fail closed.');
        } catch (BlogPublicHttpRuntimeException $exception) {
            self::assertSame('blog.schema_not_ready', $exception->issueCode());
        }
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
