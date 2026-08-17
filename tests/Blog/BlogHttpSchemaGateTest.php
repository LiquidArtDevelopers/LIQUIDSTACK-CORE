<?php

declare(strict_types=1);

use App\Core\Modules\Blog\BlogHttpSchemaGate;
use App\Core\Modules\Blog\BlogCategoryHttpSchemaGate;
use App\Core\Modules\Blog\BlogLayoutEditorSchemaGate;
use App\Core\Modules\Blog\BlogEditorPreferencesSchemaGate;
use App\Core\Modules\Blog\BlogPostTombstoneSchemaGate;
use App\Core\Modules\Blog\BlogPrivateDraftPublicationSchemaGate;
use App\Core\Modules\Blog\BlogStructuredContentSchemaGate;
use App\Core\Modules\Blog\BlogUrlHistoryMigrationPostconditionVerifier;
use App\Core\Modules\Blog\BlogUrlHistorySchemaGate;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationApplyOptions;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogHttpSchemaGateCountingPdo extends PDO
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

final class BlogHttpSchemaGateTest extends TestCase
{
    private string $projectRoot;
    private string $coreRoot;
    private Filesystem $filesystem;
    private BlogHttpSchemaGateCountingPdo $pdo;
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
        $this->pdo = new BlogHttpSchemaGateCountingPdo();
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
            $this->scopes,
            new MigrationApplyOptions(
                allowDestructive: true,
                backupConfirmed: true
            )
        );
        $this->pdo->clearSqlLog();
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
        self::assertTrue((new BlogPrivateDraftPublicationSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
    }

    public function testRequestGatesNeverRunExhaustiveSchemaMetadataAudits(): void
    {
        self::assertTrue((new BlogHttpSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertTrue((new BlogCategoryHttpSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertTrue((new BlogStructuredContentSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertTrue((new BlogPostTombstoneSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertTrue((new BlogPrivateDraftPublicationSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertTrue((new BlogUrlHistorySchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));

        $sql = strtolower(implode("\n", $this->pdo->sqlLog()));
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
            self::assertStringNotContainsString($forbidden, $sql);
        }
        self::assertStringNotContainsString('select count(*)', $sql);
        foreach ([
            'ls_blog_posts',
            'ls_blog_categories',
            'ls_blog_content_docs',
            'ls_blog_post_tombstones',
            'ls_blog_publication_heads',
            'ls_blog_url_history',
        ] as $table) {
            self::assertStringContainsString($table, $sql);
        }
    }

    public function testRuntimeBoundariesFailClosedForMissingTablesOrColumns(): void
    {
        $this->pdo->exec(
            'ALTER TABLE ls_blog_url_history RENAME COLUMN updated_at '
                . 'TO drifted_updated_at'
        );
        self::assertFalse((new BlogUrlHistorySchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));

        $this->pdo->exec('DROP TABLE ls_blog_url_history');
        self::assertFalse((new BlogUrlHistorySchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));

        $this->pdo->exec('DROP TABLE ls_blog_post_tombstones');
        self::assertFalse((new BlogPostTombstoneSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));

        $this->pdo->exec('DROP TABLE ls_blog_content_media');
        self::assertFalse((new BlogStructuredContentSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));

        $this->pdo->exec('DROP TABLE ls_blog_publication_heads');
        self::assertFalse((new BlogPrivateDraftPublicationSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
    }

    public function testCopyOperationMigrationOnlyGatesAdministration(): void
    {
        $this->pdo->exec(
            "DELETE FROM ls_module_migrations WHERE module_id = 'blog' "
                . "AND migration_id = "
                . "'0019_blog_copy_operation_idempotency'"
        );
        $this->pdo->exec('DROP TABLE ls_blog_copy_operations');
        self::assertTrue((new BlogHttpSchemaGate())->isPublicReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertFalse((new BlogHttpSchemaGate())->isAdministrationReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertTrue((new BlogCategoryHttpSchemaGate())->isPublicReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));

        (new MigrationRunner())->apply(
            $this->pdo,
            MigrationCatalog::fromRegistry($this->registry),
            $this->scopes,
            new MigrationApplyOptions(
                allowDestructive: true,
                backupConfirmed: true
            )
        );
        self::assertTrue((new BlogHttpSchemaGate())->isPublicReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertTrue((new BlogHttpSchemaGate())->isAdministrationReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertTrue((new BlogCategoryHttpSchemaGate())->isPublicReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
    }

    public function testPrivateDraftGateStillRequiresItsMigrationRecord(): void
    {
        $this->pdo->exec(
            "DELETE FROM ls_module_migrations WHERE module_id = 'blog' "
                . "AND migration_id = '0014_blog_private_draft_publication'"
        );

        self::assertFalse((new BlogPrivateDraftPublicationSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
    }

    public function testPrivateDraftGateFailsClosedAfterCanonicalSchemaDrift(): void
    {
        $this->pdo->exec('DROP TABLE ls_blog_publication_heads');

        self::assertFalse((new BlogPrivateDraftPublicationSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
    }

    public function testEditorPreferencesAreAnIndependentOptionalGate(): void
    {
        $preferences = new BlogEditorPreferencesSchemaGate();
        self::assertTrue($preferences->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));

        $this->pdo->exec(
            "DELETE FROM ls_module_migrations WHERE module_id = 'blog' "
                . "AND migration_id IN ('0013_blog_settings_manage_capability', "
                . "'0014_blog_private_draft_publication', "
                . "'0015_blog_robots_preferences', "
                . "'0016_blog_url_history', "
                . "'0017_blog_dummy_category', "
                . "'0018_blog_dummy_category_normalization', "
                . "'0019_blog_copy_operation_idempotency')"
        );

        self::assertFalse($preferences->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertFalse((new BlogHttpSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertTrue((new BlogLayoutEditorSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
    }

    public function testExtendedSchemaGatesAcceptTheLayoutEditorNamespace(): void
    {
        self::assertTrue((new BlogCategoryHttpSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertTrue((new BlogStructuredContentSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertTrue((new BlogPostTombstoneSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertTrue((new BlogLayoutEditorSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));

        $this->pdo->exec('DROP INDEX ls_blog_ix_pt_time');
        self::assertTrue((new BlogPostTombstoneSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        $scope = $this->scopes->get('blog');
        self::assertNotNull($scope);
        self::assertFalse(
            (new BlogUrlHistoryMigrationPostconditionVerifier())->verify(
                $this->pdo,
                $scope
            ),
            'migrate/doctor must still detect exact index drift.'
        );
    }

    public function testLayoutEditorGateDegradesToV1Without0011Record(): void
    {
        $this->pdo->exec(
            "DELETE FROM ls_module_migrations WHERE module_id = 'blog' "
                . "AND migration_id IN ('0011_blog_layout_editor_v2', "
                . "'0012_blog_editor_preferences', "
                . "'0013_blog_settings_manage_capability', "
                . "'0014_blog_private_draft_publication', "
                . "'0015_blog_robots_preferences', "
                . "'0016_blog_url_history', "
                . "'0017_blog_dummy_category', "
                . "'0018_blog_dummy_category_normalization', "
                . "'0019_blog_copy_operation_idempotency')"
        );

        self::assertFalse((new BlogLayoutEditorSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertTrue((new BlogStructuredContentSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
    }

    public function testLayoutEditorGateFailsClosedWithoutRuntimeCompanionShape(): void
    {
        $this->pdo->exec('DROP TABLE ls_blog_content_layout_revisions');

        self::assertFalse((new BlogLayoutEditorSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
    }

    public function testTombstoneGateRequiresItsRecordedMigration(): void
    {
        $this->pdo->exec(
            "DELETE FROM ls_module_migrations WHERE module_id = 'blog' "
                . "AND migration_id IN ('0007_blog_post_tombstones', "
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
                . "'0018_blog_dummy_category_normalization', "
                . "'0019_blog_copy_operation_idempotency')"
        );

        self::assertFalse((new BlogPostTombstoneSchemaGate())->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertFalse((new BlogHttpSchemaGate())->isPublicReady(
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
            . "'0005_blog_structured_content', "
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
                . "'0018_blog_dummy_category_normalization', "
                . "'0019_blog_copy_operation_idempotency')"
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
            . "'0005_blog_structured_content', "
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
                . "'0018_blog_dummy_category_normalization', "
                . "'0019_blog_copy_operation_idempotency')"
        );
        $gate = new BlogHttpSchemaGate();

        self::assertFalse($gate->isPublicReady(
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
            . "'0005_blog_structured_content', "
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
                . "'0018_blog_dummy_category_normalization', "
                . "'0019_blog_copy_operation_idempotency')"
        );
        self::assertFalse($categoryGate->isReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertFalse($categoryGate->isPublicReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertFalse($categoryGate->isAdministrationReady(
            $this->pdo,
            $this->registry,
            $this->scopes
        ));
        self::assertFalse($blogGate->isAdministrationReady(
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
            . "'0005_blog_structured_content', "
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
                . "'0018_blog_dummy_category_normalization', "
                . "'0019_blog_copy_operation_idempotency')"
        );
        $this->pdo->exec(
            "DELETE FROM ls_webadmin_capabilities WHERE code IN "
            . "('blog.categories.view', 'blog.categories.edit')"
        );
        $this->pdo->exec('DROP TABLE ls_blog_post_categories');
        $this->pdo->exec('DROP TABLE ls_blog_category_locales');
        $this->pdo->exec('DROP TABLE ls_blog_categories');

        self::assertFalse((new BlogHttpSchemaGate())->isAdministrationReady(
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
