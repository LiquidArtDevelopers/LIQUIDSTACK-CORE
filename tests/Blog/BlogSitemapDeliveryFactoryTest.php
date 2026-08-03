<?php

declare(strict_types=1);

use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Http\BlogPublicHttpRuntimeException;
use App\Core\Blog\Http\BlogSitemapRenderer;
use App\Core\Blog\Sitemap\Cache\PrivateBlogSitemapCacheStorage;
use App\Core\Blog\Sitemap\Delivery\BlogSitemapDeliveryFactory;
use App\Core\Blog\Sitemap\Persistence\PdoBlogSitemapStateRepository;
use App\Core\Database\DatabaseConnectionException;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Http\Request;
use App\Core\Modules\Blog\BlogPublicRouteProvider;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Routing\ModulePublicRouteCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SwitchableBlogSitemapPdoFactoryFixture implements
    PdoConnectionFactoryInterface
{
    public ?string $failureCode = null;
    public int $calls = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function connect(): PDO
    {
        ++$this->calls;
        if ($this->failureCode !== null) {
            throw new DatabaseConnectionException($this->failureCode);
        }

        return $this->pdo;
    }
}

final class BlogSitemapDeliveryFactoryTest extends TestCase
{
    private string $root;
    private string $coreRoot;
    private Filesystem $filesystem;
    private PDO $pdo;
    private ModuleRegistry $registry;
    private ConfiguredMigrationScopeFactory $scopeFactory;
    private SwitchableBlogSitemapPdoFactoryFixture $connection;

    /** @var array<string, string> */
    private array $environment;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-blog-sitemap-factory-'
            . bin2hex(random_bytes(8));
        $this->coreRoot = dirname(__DIR__, 2);
        $this->filesystem->mkdir($this->root . '/App/config/modules');
        $this->filesystem->dumpFile(
            $this->root . '/composer.json',
            json_encode(['require' => [
                'liquidstack/core' => '*',
                'liquidstack/blog' => '*',
            ]], JSON_THROW_ON_ERROR) . PHP_EOL
        );
        $this->filesystem->dumpFile(
            $this->root . '/App/config/langs.php',
            "<?php\nreturn ['es'];\n"
        );
        $this->writeModuleConfig('webadmin', false);
        $this->writeModuleConfig('blog', false);

        $this->environment = [
            'RAIZ' => 'http://localhost:1309',
            'DEV_MODE' => '1',
        ];
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(
            PDO::ATTR_DEFAULT_FETCH_MODE,
            PDO::FETCH_ASSOC
        );
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->registry = ModuleRegistry::forProject(
            $this->root,
            $this->coreRoot
        );
        $this->scopeFactory = new ConfiguredMigrationScopeFactory();
        (new MigrationRunner())->apply(
            $this->pdo,
            MigrationCatalog::fromRegistry($this->registry),
            $this->scopeFactory->create($this->registry, $this->root)
        );
        $this->connection = new SwitchableBlogSitemapPdoFactoryFixture(
            $this->pdo
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->filesystem, $this->root)) {
            $this->filesystem->remove($this->root);
        }
    }

    public function testUncachedRouteUsesDatabaseWithoutCreatingStorage(): void
    {
        $routes = $this->routes($this->factory());

        $response = $routes->dispatch($this->request());

        self::assertNotNull($response);
        self::assertSame(200, $response->status());
        self::assertSame(
            'database',
            $response->headers()['X-LiquidStack-Sitemap-Source'] ?? null
        );
        self::assertStringContainsString('<urlset ', $response->body());
        self::assertDirectoryDoesNotExist(
            $this->root . '/storage/liquidstack/blog/sitemap-cache'
        );
        self::assertSame(1, $this->connection->calls);
    }

    public function testFreshRoutePromotesSnapshotAndConnectionOutageUsesIt(): void
    {
        $this->enableCacheAndActivateGeneration();
        $routes = $this->routes($this->factory());

        $fresh = $routes->dispatch($this->request());
        self::assertNotNull($fresh);
        self::assertSame(200, $fresh->status());
        self::assertSame(
            'database',
            $fresh->headers()['X-LiquidStack-Sitemap-Source'] ?? null
        );
        self::assertSame(
            'present',
            $this->storage()->diagnostic()['snapshot']
        );

        $this->connection->failureCode = 'database.connection_unavailable';
        $stale = $routes->dispatch($this->request());

        self::assertNotNull($stale);
        self::assertSame(200, $stale->status());
        self::assertSame($fresh->body(), $stale->body());
        self::assertSame(
            $fresh->headers()['ETag'],
            $stale->headers()['ETag']
        );
        self::assertSame(
            'stale-cache',
            $stale->headers()['X-LiquidStack-Sitemap-Source'] ?? null
        );
        self::assertArrayHasKey('Warning', $stale->headers());
        self::assertSame(2, $this->connection->calls);
    }

    public function testUnclassifiedConnectionFailureDoesNotUseSnapshot(): void
    {
        $service = $this->freshCachedService();
        $this->connection->failureCode = 'database.contract_invalid';

        $this->expectException(BlogPublicHttpRuntimeException::class);
        $service->document();
    }

    public function testSchemaDriftDoesNotUseSnapshot(): void
    {
        $service = $this->freshCachedService();
        $this->pdo->exec('DROP TABLE ls_blog_post_localizations');

        $this->expectException(BlogPublicHttpRuntimeException::class);
        $service->document();
    }

    public function testQueryHydrationFailureDoesNotUseSnapshot(): void
    {
        $service = $this->freshCachedService();
        $this->insertPublishedRowWithInvalidTimestamp();

        $this->expectException(BlogPublicHttpRuntimeException::class);
        $service->document();
    }

    public function testRendererFailureDoesNotUseSnapshot(): void
    {
        $this->enableCacheAndActivateGeneration();
        $this->factory()->create($this->context())->document();
        $failingRendererFactory = $this->factory(new BlogSitemapRenderer(1));

        $this->expectException(BlogPublicHttpRuntimeException::class);
        $failingRendererFactory->create($this->context())->document();
    }

    public function testGenerationMismatchDoesNotUsePreviousSnapshot(): void
    {
        $service = $this->freshCachedService();
        $marker = $this->root
            . '/storage/liquidstack/blog/sitemap-cache/'
            . PrivateBlogSitemapCacheStorage::MARKER;
        $this->filesystem->dumpFile(
            $marker,
            json_encode([
                'schema' => 1,
                'generation' => '22222222-2222-4222-8222-222222222222',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL
        );

        self::assertTrue($this->storage()->diagnostic()['ready']);
        $this->expectException(BlogPublicHttpRuntimeException::class);
        $service->document();
    }

    private function freshCachedService():
        \App\Core\Blog\Sitemap\Delivery\BlogSitemapDeliveryService
    {
        $this->enableCacheAndActivateGeneration();
        $service = $this->factory()->create($this->context());
        $fresh = $service->document();
        self::assertFalse($fresh->stale());
        self::assertSame('present', $this->storage()->diagnostic()['snapshot']);

        return $service;
    }

    private function enableCacheAndActivateGeneration(): void
    {
        $this->writeModuleConfig('blog', true);
        $storage = $this->storage();
        $generation = $storage->initialize()->generation();
        $scope = $this->scopeFactory
            ->create($this->registry, $this->root)
            ->get('blog');
        self::assertNotNull($scope);
        $state = new PdoBlogSitemapStateRepository($this->pdo, $scope);
        $this->pdo->beginTransaction();
        try {
            $state->lock();
            $state->activateGeneration(
                $generation,
                new DateTimeImmutable('2026-08-03 12:00:00 UTC')
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function insertPublishedRowWithInvalidTimestamp(): void
    {
        $this->pdo->exec(
            "INSERT INTO ls_blog_posts "
            . "(public_id, created_by_user_public_id) VALUES "
            . "('11111111-1111-4111-8111-111111111111', "
            . "'33333333-3333-4333-8333-333333333333')"
        );
        $this->pdo->exec(
            "INSERT INTO ls_blog_post_localizations "
            . "(public_id, post_id, locale, slug, h1, seo_title, "
            . "meta_description, excerpt, body_text, status, published_at, "
            . "created_by_user_public_id, updated_by_user_public_id, "
            . "updated_at) VALUES "
            . "('22222222-2222-4222-8222-222222222222', 1, 'es', "
            . "'matrix', 'Matrix', 'Matrix SEO', 'Matrix description', "
            . "'Matrix excerpt', 'Matrix body', 'published', "
            . "'2026-08-03 12:00:00.000000', "
            . "'33333333-3333-4333-8333-333333333333', "
            . "'33333333-3333-4333-8333-333333333333', "
            . "'invalid-timestamp')"
        );
    }

    private function factory(
        ?BlogSitemapRenderer $renderer = null
    ): BlogSitemapDeliveryFactory {
        return new BlogSitemapDeliveryFactory(
            coreRoot: $this->coreRoot,
            connectionFactoryResolver: fn (): PdoConnectionFactoryInterface =>
                $this->connection,
            renderer: $renderer ?? new BlogSitemapRenderer()
        );
    }

    private function context(): ModuleRuntimeContext
    {
        return new ModuleRuntimeContext($this->root, $this->environment);
    }

    private function routes(
        BlogSitemapDeliveryFactory $factory
    ): ModulePublicRouteCollection {
        $context = $this->context();
        $routes = new ModulePublicRouteCollection(
            'blog',
            BlogPublicRouteProvider::publicRoutePrefixes($context)
        );
        (new BlogPublicRouteProvider(
            sitemapDeliveryFactory: $factory
        ))->registerPublicRoutes($routes, $context);

        return $routes;
    }

    private function request(): Request
    {
        return Request::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/blog-sitemap.xml',
        ]);
    }

    private function storage(): PrivateBlogSitemapCacheStorage
    {
        return PrivateBlogSitemapCacheStorage::forProject(
            $this->root,
            $this->environment
        );
    }

    private function writeModuleConfig(
        string $module,
        bool $cacheEnabled
    ): void {
        $cache = $module === 'blog'
            ? "\n    'sitemap_cache' => [\n"
                . "        'enabled' => "
                . ($cacheEnabled ? 'true' : 'false') . ",\n"
                . "        'ttl_seconds' => 300,\n"
                . "    ],"
            : '';
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/' . $module . '.php',
            "<?php\nreturn [\n"
                . "    'database' => ['connection' => 'shared'],"
                . $cache . "\n];\n"
        );
    }
}
