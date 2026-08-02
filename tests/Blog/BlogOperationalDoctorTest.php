<?php

declare(strict_types=1);

use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Composer\MigrationCommandRuntime;
use App\Core\Composer\MigrationCommandRuntimeFactoryInterface;
use App\Core\Modules\Diagnostics\ModuleDoctor;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\ModuleRegistry;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogOperationalDoctorRuntimeFactoryFixture implements
    MigrationCommandRuntimeFactoryInterface
{
    public function __construct(
        private readonly MigrationCommandRuntime $runtime
    ) {
    }

    public function create(
        string $projectRoot,
        string $coreRoot
    ): MigrationCommandRuntime {
        return $this->runtime;
    }
}

final class BlogOperationalDoctorTest extends TestCase
{
    private string $projectRoot;
    private string $coreRoot;
    private Filesystem $filesystem;
    private PDO $pdo;
    private MigrationCatalog $catalog;
    private App\Core\Modules\Migrations\MigrationScopeCollection $scopes;
    private string $previousExceptionTraceSetting;

    protected function setUp(): void
    {
        $this->previousExceptionTraceSetting = (string) ini_get(
            'zend.exception_ignore_args'
        );
        ini_set('zend.exception_ignore_args', '1');
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-blog-operational-doctor-'
            . bin2hex(random_bytes(8));
        $this->coreRoot = dirname(__DIR__, 2);
        $this->filesystem->mkdir([
            $this->projectRoot . '/App/config',
            $this->projectRoot . '/src/scss',
        ]);
        $this->filesystem->mirror(
            $this->coreRoot . '/modules/webadmin/published/assets',
            $this->projectRoot . '/public/assets/modules/webadmin'
        );
        $this->filesystem->mirror(
            $this->coreRoot . '/modules/blog/published/assets',
            $this->projectRoot . '/public/assets/modules/blog'
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode([
                'require' => [
                    'liquidstack/core' => '*',
                    'liquidstack/blog' => '*',
                ],
            ], JSON_THROW_ON_ERROR) . PHP_EOL
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/config.php',
            "<?php\n"
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/langs.php',
            "<?php\nreturn ['es', 'en'];\n"
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/src/scss/_config.scss',
            '$color00: #fff;' . PHP_EOL
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/.env',
            implode("\n", [
                'BBDD_SERVER=localhost',
                'BBDD_USER=fixture',
                'BBDD_PASS=blog-doctor-secret-must-not-leak',
                'BBDD_NAME=fixture',
                WebAdminConfig::SECURITY_KEY_ENV . '=' . $this->securityKey(),
                BlogPublicOrigin::ENV . '=https://canonical.example.test',
                'LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL=superadmin@example.invalid',
                'LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL=admin@example.invalid',
                '',
            ])
        );

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $registry = ModuleRegistry::forProject(
            $this->projectRoot,
            $this->coreRoot
        );
        $this->catalog = MigrationCatalog::fromRegistry($registry);
        $this->scopes = (new ConfiguredMigrationScopeFactory())->create(
            $registry,
            $this->projectRoot
        );
        (new MigrationRunner())->apply(
            $this->pdo,
            $this->catalog,
            $this->scopes
        );
    }

    protected function tearDown(): void
    {
        ini_set(
            'zend.exception_ignore_args',
            $this->previousExceptionTraceSetting
        );
        $this->filesystem->remove($this->projectRoot);
    }

    public function testDoctorReportsBlogReadyWithoutWritingOrLeaking(): void
    {
        $before = $this->databaseSnapshot();
        $report = (new ModuleDoctor(
            migrationRuntimeFactory:
                new BlogOperationalDoctorRuntimeFactoryFixture(
                    new MigrationCommandRuntime(
                        $this->pdo,
                        $this->catalog,
                        $this->scopes
                    )
                )
        ))->inspect($this->projectRoot, $this->coreRoot);
        $payload = $report->toArray();
        $blog = $payload['module_diagnostics']['blog'];

        self::assertTrue($report->isHealthy());
        self::assertTrue($blog['configuration']['ready']);
        self::assertTrue($blog['routing']['ready']);
        self::assertTrue(
            $blog['environment']['public_origin']['ready']
        );
        self::assertSame('ready', $blog['dependency']['status']);
        self::assertSame('applied', $blog['database']['status']);
        self::assertTrue($blog['readiness']['blog_ready']);
        self::assertSame([], $blog['readiness']['blockers']);
        self::assertSame($before, $this->databaseSnapshot());
        $originChecks = array_values(array_filter(
            $payload['checks'],
            static fn (array $check): bool =>
                ($check['id'] ?? null)
                    === 'blog.environment.public_origin'
        ));
        self::assertCount(1, $originChecks);
        self::assertSame('warning', $originChecks[0]['status'] ?? null);

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(
            'blog-doctor-secret-must-not-leak',
            $encoded
        );
        self::assertStringNotContainsString(
            'https://canonical.example.test',
            $encoded
        );
        self::assertStringNotContainsString('ls_blog_', $encoded);
    }

    public function testCapabilityDriftBlocksBlogReadiness(): void
    {
        $this->pdo->exec(
            "DELETE FROM ls_webadmin_capabilities "
            . "WHERE code = 'blog.articles.publish'"
        );
        $report = (new ModuleDoctor(
            migrationRuntimeFactory:
                new BlogOperationalDoctorRuntimeFactoryFixture(
                    new MigrationCommandRuntime(
                        $this->pdo,
                        $this->catalog,
                        $this->scopes
                    )
                )
        ))->inspect($this->projectRoot, $this->coreRoot);
        $blog = $report->toArray()['module_diagnostics']['blog'];

        self::assertFalse($report->isHealthy());
        self::assertFalse($blog['readiness']['blog_ready']);
        self::assertSame('blocked', $blog['database']['status']);
        self::assertContains(
            'database.migrations_not_ready',
            $blog['readiness']['blockers']
        );
    }

    public function testMissingBlogAssetsBlockReadinessWithoutAWrite(): void
    {
        $this->filesystem->remove(
            $this->projectRoot . '/public/assets/modules/blog'
        );
        $before = $this->databaseSnapshot();

        $report = (new ModuleDoctor(
            migrationRuntimeFactory:
                new BlogOperationalDoctorRuntimeFactoryFixture(
                    new MigrationCommandRuntime(
                        $this->pdo,
                        $this->catalog,
                        $this->scopes
                    )
                )
        ))->inspect($this->projectRoot, $this->coreRoot);
        $payload = $report->toArray();
        $blog = $payload['module_diagnostics']['blog'];

        self::assertFalse($report->isHealthy());
        self::assertFalse($blog['readiness']['blog_ready']);
        self::assertContains(
            'assets.missing_or_invalid',
            $blog['readiness']['blockers']
        );
        self::assertSame(
            ['public/assets/modules/blog'],
            $blog['assets']['missing']
        );
        self::assertSame($before, $this->databaseSnapshot());
    }

    private function securityKey(): string
    {
        return rtrim(strtr(
            base64_encode(str_repeat('B', 32)),
            '+/',
            '-_'
        ), '=');
    }

    /** @return array<string, mixed> */
    private function databaseSnapshot(): array
    {
        $schema = $this->pdo->query(
            "SELECT type, name, tbl_name, sql FROM sqlite_master "
            . "ORDER BY type, name"
        )->fetchAll(PDO::FETCH_ASSOC);
        $counts = [];
        foreach ($schema as $entry) {
            if (
                ($entry['type'] ?? null) !== 'table'
                || !is_string($entry['name'] ?? null)
                || str_starts_with($entry['name'], 'sqlite_')
            ) {
                continue;
            }
            $table = str_replace('"', '""', $entry['name']);
            $counts[$entry['name']] = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM "' . $table . '"'
            )->fetchColumn();
        }

        return ['schema' => $schema, 'counts' => $counts];
    }
}
