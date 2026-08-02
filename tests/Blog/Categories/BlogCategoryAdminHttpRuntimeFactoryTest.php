<?php

declare(strict_types=1);

namespace Tests\Blog\Categories;

use App\Core\Blog\Http\BlogAdminHttpRuntimeException;
use App\Core\Blog\Http\BlogAdminHttpRuntimeFactory;
use App\Core\Blog\Http\BlogCategoryAdminHttpRuntimeFactory;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CategoryAdminRuntimePdoFactory implements
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

final class BlogCategoryAdminHttpRuntimeFactoryTest extends TestCase
{
    private string $projectRoot;
    private string $coreRoot;
    private Filesystem $filesystem;
    private PDO $pdo;
    private CategoryAdminRuntimePdoFactory $connection;
    private string $previousTraceSetting;

    protected function setUp(): void
    {
        $this->previousTraceSetting = (string) ini_get(
            'zend.exception_ignore_args'
        );
        ini_set('zend.exception_ignore_args', '1');
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-blog-category-runtime-'
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
        $this->connection = new CategoryAdminRuntimePdoFactory($this->pdo);
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->previousTraceSetting);
        if (isset($this->filesystem, $this->projectRoot)) {
            $this->filesystem->remove($this->projectRoot);
        }
    }

    public function testFactoryComposesTheIndependentCategoryRuntime(): void
    {
        $runtime = $this->categoryFactory()->create(
            $this->context(),
            WebAdminConfig::defaults()
        );

        self::assertSame(['es'], $runtime->languages());
        self::assertSame([], $runtime->categoryService()->list());
        self::assertSame([], $runtime->blogService()->listPosts());
    }

    public function testPendingCategoryMigrationsDoNotDisableBaseAdminRuntime(): void
    {
        $this->pdo->exec(
            "DELETE FROM ls_module_migrations WHERE module_id = 'blog' "
            . "AND migration_id IN ('0003_blog_categories', "
            . "'0004_blog_category_capabilities', "
            . "'0005_blog_structured_content')"
        );
        $this->pdo->exec(
            "DELETE FROM ls_webadmin_capabilities WHERE code IN "
            . "('blog.categories.view', 'blog.categories.edit')"
        );
        $this->pdo->exec('DROP TABLE ls_blog_post_categories');
        $this->pdo->exec('DROP TABLE ls_blog_category_locales');
        $this->pdo->exec('DROP TABLE ls_blog_categories');

        try {
            $this->categoryFactory()->create(
                $this->context(),
                WebAdminConfig::defaults()
            );
            self::fail('Pending category migrations must fail closed.');
        } catch (BlogAdminHttpRuntimeException $exception) {
            self::assertSame(
                'blog.categories.schema_not_ready',
                $exception->issueCode()
            );
        }

        $base = new BlogAdminHttpRuntimeFactory(
            coreRoot: $this->coreRoot,
            connectionFactoryResolver: fn () => $this->connection
        );
        self::assertSame([], $base->create(
            $this->context(),
            WebAdminConfig::defaults()
        )->service()->listPosts());
    }

    public function testPendingCapabilityMigrationBlocksOnlyCategoryAdmin(): void
    {
        $statement = $this->pdo->prepare(
            "DELETE FROM ls_module_migrations WHERE module_id = 'blog' "
            . 'AND migration_id = :migration_id'
        );
        self::assertTrue($statement->execute([
            'migration_id' => '0004_blog_category_capabilities',
        ]));

        try {
            $this->categoryFactory()->create(
                $this->context(),
                WebAdminConfig::defaults()
            );
            self::fail('Category admin must require capability migration.');
        } catch (BlogAdminHttpRuntimeException $exception) {
            self::assertSame(
                'blog.categories.schema_not_ready',
                $exception->issueCode()
            );
        }
    }

    private function categoryFactory(): BlogCategoryAdminHttpRuntimeFactory
    {
        return new BlogCategoryAdminHttpRuntimeFactory(
            coreRoot: $this->coreRoot,
            connectionFactoryResolver: fn () => $this->connection
        );
    }

    private function context(): ModuleRuntimeContext
    {
        return new ModuleRuntimeContext($this->projectRoot, [
            WebAdminConfig::SECURITY_KEY_ENV => rtrim(strtr(
                base64_encode(str_repeat('R', 32)),
                '+/',
                '-_'
            ), '='),
        ]);
    }
}
