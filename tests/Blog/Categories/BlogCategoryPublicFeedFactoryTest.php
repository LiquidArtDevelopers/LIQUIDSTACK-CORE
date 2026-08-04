<?php

declare(strict_types=1);

namespace Tests\Blog\Categories;

use App\Core\Blog\Http\BlogPublicHttpRuntimeException;
use App\Core\Blog\PublicFeed\BlogCategoryPublicFeedFactory;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\ModuleRegistry;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CategoryPublicFeedPdoFactory implements
    PdoConnectionFactoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function connect(): PDO
    {
        return $this->pdo;
    }
}

final class BlogCategoryPublicFeedFactoryTest extends TestCase
{
    private string $projectRoot;
    private string $coreRoot;
    private Filesystem $filesystem;
    private PDO $pdo;
    private BlogCategoryPublicFeedFactory $factory;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-blog-category-feed-'
            . bin2hex(random_bytes(8));
        $this->coreRoot = dirname(__DIR__, 3);
        $this->filesystem->mkdir($this->projectRoot . '/App/config');
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode(['require' => [
                'liquidstack/core' => '*',
                'liquidstack/blog' => '*',
            ]], JSON_THROW_ON_ERROR) . PHP_EOL
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/langs.php',
            "<?php\nreturn ['es'];\n"
        );

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $registry = ModuleRegistry::forProject(
            $this->projectRoot,
            $this->coreRoot
        );
        $scopes = (new ConfiguredMigrationScopeFactory())->create(
            $registry,
            $this->projectRoot
        );
        (new MigrationRunner())->apply(
            $this->pdo,
            MigrationCatalog::fromRegistry($registry),
            $scopes
        );
        $connection = new CategoryPublicFeedPdoFactory($this->pdo);
        $this->factory = new BlogCategoryPublicFeedFactory(
            coreRoot: $this->coreRoot,
            connectionFactoryResolver: static fn (
                array $_environment,
                string $_connection
            ): PdoConnectionFactoryInterface => $connection
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->filesystem, $this->projectRoot)) {
            $this->filesystem->remove($this->projectRoot);
        }
    }

    public function testFactoryReturnsOnlyTheSafeProjectionBoundary(): void
    {
        $feed = $this->factory->create($this->projectRoot, []);

        self::assertSame([], $feed->filtersForLocale('es'));
        self::assertSame([], $feed->postsForFilter('es', 'noticias'));
        self::assertSame([], get_object_vars($feed));
    }

    public function testPublicFeedNeedsCategorySchemaButNotAdminCapabilities(): void
    {
        $delete = $this->pdo->prepare(
            "DELETE FROM ls_module_migrations WHERE module_id = 'blog' "
            . 'AND migration_id = :migration_id'
        );
        $this->pdo->exec(
            "DELETE FROM ls_module_migrations WHERE module_id = 'blog' "
            . "AND migration_id IN ('0004_blog_category_capabilities', "
            . "'0005_blog_structured_content', "
            . "'0006_blog_sitemap_publication_state', "
            . "'0007_blog_post_tombstones', "
            . "'0008_blog_article_delete_capability', "
            . "'0009_blog_analytics', "
            . "'0010_blog_analytics_view_capability')"
        );
        self::assertSame([], $this->factory->create(
            $this->projectRoot,
            []
        )->filtersForLocale('es'));

        $delete->execute(['migration_id' => '0003_blog_categories']);
        try {
            $this->factory->create($this->projectRoot, []);
            self::fail('The public feed must require 0003.');
        } catch (BlogPublicHttpRuntimeException $exception) {
            self::assertSame(
                'blog.categories.schema_not_ready',
                $exception->issueCode()
            );
        }
    }
}
