<?php

declare(strict_types=1);

use App\Core\Modules\Blog\BlogHttpSchemaGate;
use App\Core\Modules\Blog\BlogCategoryHttpSchemaGate;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogHttpSchemaGateTest extends TestCase
{
    private string $projectRoot;
    private string $coreRoot;
    private Filesystem $filesystem;
    private PDO $pdo;
    private ModuleRegistry $registry;
    private MigrationScopeCollection $scopes;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-blog-http-gate-'
            . bin2hex(random_bytes(8));
        $this->coreRoot = dirname(__DIR__, 2);
        $this->filesystem->mkdir($this->projectRoot . '/App/config');
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode([
                'require' => [
                    'liquidstack/core' => '*',
                    'liquidstack/blog' => '*',
                ],
            ], JSON_THROW_ON_ERROR)
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/langs.php',
            "<?php\nreturn ['es', 'en'];\n"
        );
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(
            PDO::ATTR_DEFAULT_FETCH_MODE,
            PDO::FETCH_ASSOC
        );
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->registry = ModuleRegistry::forProject(
            $this->projectRoot,
            $this->coreRoot
        );
        $this->scopes = (new ConfiguredMigrationScopeFactory())->create(
            $this->registry,
            $this->projectRoot
        );
        (new MigrationRunner())->apply(
            $this->pdo,
            MigrationCatalog::fromRegistry($this->registry),
            $this->scopes
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->filesystem, $this->projectRoot)) {
            $this->filesystem->remove($this->projectRoot);
        }
    }

    public function testAppliedCanonicalBlogContractIsReady(): void
    {
        self::assertTrue((new BlogHttpSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
    }

    public function testMissingCapabilityAndWrongScopeFailClosed(): void
    {
        $this->pdo->exec(
            "DELETE FROM ls_webadmin_capabilities "
            . "WHERE code = 'blog.articles.publish'"
        );
        self::assertFalse((new BlogHttpSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));

        self::assertFalse((new BlogHttpSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            MigrationScopeCollection::fromTablePrefixes([
                'webadmin' => 'ls_webadmin_',
                'blog' => 'wrong_blog_',
            ])
        ));
    }

    public function testMissingRegistryRecordAndTableDriftFailClosed(): void
    {
        $this->pdo->exec(
            "DELETE FROM ls_module_migrations WHERE module_id = 'blog' "
            . "AND migration_id IN ('0002_blog_capabilities', "
            . "'0003_blog_categories', '0004_blog_category_capabilities', "
            . "'0005_blog_structured_content')"
        );
        self::assertFalse((new BlogHttpSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));

        $this->pdo->exec('DROP TABLE ls_blog_post_localizations');
        self::assertFalse((new BlogHttpSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
    }

    public function testPublicContentAndSitemapDoNotRequireAdminCapabilityMigration(): void
    {
        $this->pdo->exec(
            "DELETE FROM ls_module_migrations WHERE module_id = 'blog' "
            . "AND migration_id IN ('0002_blog_capabilities', "
            . "'0003_blog_categories', '0004_blog_category_capabilities', "
            . "'0005_blog_structured_content')"
        );
        $gate = new BlogHttpSchemaGate();

        self::assertTrue($gate->isPublicReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertFalse($gate->isAdministrationReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
    }

    public function testCategoriesHaveAnIndependentMigrationGate(): void
    {
        $categoryGate = new BlogCategoryHttpSchemaGate();
        $blogGate = new BlogHttpSchemaGate();

        self::assertTrue($categoryGate->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));

        $this->pdo->exec(
            "DELETE FROM ls_module_migrations WHERE module_id = 'blog' "
            . "AND migration_id IN ('0004_blog_category_capabilities', "
            . "'0005_blog_structured_content')"
        );
        self::assertFalse($categoryGate->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertTrue($categoryGate->isPublicReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertFalse($categoryGate->isAdministrationReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertTrue($blogGate->isAdministrationReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
    }

    public function testExistingBlogAdminRemainsReadyBeforeCategoryMigrations(): void
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

        self::assertTrue((new BlogHttpSchemaGate())->isAdministrationReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertFalse((new BlogCategoryHttpSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
    }
}
