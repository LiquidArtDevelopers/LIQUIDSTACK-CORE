<?php

declare(strict_types=1);

use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Diagnostics\BlogDiagnosticService;
use App\Core\Modules\Migrations\MigrationDatabasePlan;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogDiagnosticServiceTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-blog-doctor-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir([
            $this->root . '/App/config/routes',
            $this->root . '/public',
        ]);
        $this->filesystem->dumpFile(
            $this->root . '/App/config/routes/get.php',
            "<?php\nreturn [];\n"
        );
        $this->filesystem->dumpFile(
            $this->root . '/App/config/routes/post.php',
            "<?php\nreturn [];\n"
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testCompleteRuntimeIsBlogReadyWithoutExposingOriginOrPrefix(): void
    {
        $report = $this->inspect($this->appliedPlan());
        $data = $report->toArray();

        self::assertTrue($report->isReady());
        self::assertTrue($data['readiness']['blog_ready']);
        self::assertSame([], $data['readiness']['blockers']);
        self::assertSame('applied', $data['database']['status']);
        self::assertSame(
            BlogPublicOrigin::SOURCE_LEGACY,
            $data['environment']['public_origin']['source']
        );
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('https://example.test', $encoded);
        self::assertStringNotContainsString('ls_blog_', $encoded);
    }

    public function testEffectiveConfigurationReportsProjectOwnedArticleView(): void
    {
        $this->filesystem->mkdir([
            $this->root . '/App/config/modules',
            $this->root . '/App/views',
        ]);
        $this->filesystem->dumpFile(
            $this->root . '/App/views/blog-article.php',
            "<?php declare(strict_types=1);\n"
        );
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/blog.php',
            "<?php\nreturn ['public_article_view' => "
                . "'App/views/blog-article.php'];\n"
        );

        $data = $this->inspect($this->appliedPlan())->toArray();

        self::assertTrue($data['configuration']['ready']);
        self::assertSame(
            'App/views/blog-article.php',
            $data['configuration']['effective']['public_article_view']
        );
    }

    public function testLegacyOriginMismatchIsAVisibleNonBlockingCompatibilityState(): void
    {
        $report = (new BlogDiagnosticService())->inspect(
            $this->root,
            ['es', 'en'],
            [
                BlogPublicOrigin::PROJECT_ORIGIN_ENV =>
                    'https://canonical.example.test',
                BlogPublicOrigin::ENV =>
                    'https://legacy.example.test',
            ],
            '/admin',
            true,
            $this->appliedPlan(),
            true
        );
        $data = $report->toArray();

        self::assertTrue($report->isReady());
        self::assertTrue(
            $data['environment']['public_origin'][
                'legacy_compatibility_override'
            ]
        );
        self::assertSame(
            BlogPublicOrigin::SOURCE_LEGACY_COMPATIBILITY,
            $data['environment']['public_origin']['source']
        );
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(
            'canonical.example.test',
            $encoded
        );
        self::assertStringNotContainsString('legacy.example.test', $encoded);
    }

    public function testLiquidStackProfileIsReportedWithoutOriginPrefixOrDatabaseSecrets(): void
    {
        $this->writeModuleDatabaseConfig(
            'blog',
            'liquidstack',
            'private_blog_'
        );
        $this->writeModuleDatabaseConfig(
            'webadmin',
            'liquidstack',
            'private_webadmin_'
        );
        $environment = [
            BlogPublicOrigin::ENV => 'https://private-origin.example.test',
            'LIQUIDSTACK_DB_PASSWORD' => 'private-database-password',
            'BBDD_PASS' => 'unused-legacy-password',
        ];

        $report = (new BlogDiagnosticService())->inspect(
            $this->root,
            ['es', 'en'],
            $environment,
            '/admin',
            true,
            $this->appliedPlan(),
            true
        );
        $data = $report->toArray();
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);

        self::assertTrue($report->isReady());
        self::assertTrue($data['configuration']['ready']);
        self::assertSame(
            'liquidstack',
            $data['configuration']['effective']['database']['connection']
        );
        self::assertSame([], $data['configuration']['issues']);
        foreach ([
            'https://private-origin.example.test',
            'private_blog_',
            'private_webadmin_',
            'private-database-password',
            'unused-legacy-password',
        ] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $encoded);
        }
    }

    /** @dataProvider databaseConnectionMismatchProvider */
    public function testDatabaseConnectionMismatchFailsClosedWithStableSafeIssue(
        string $blogConnection,
        string $webAdminConnection
    ): void {
        $this->writeModuleDatabaseConfig(
            'blog',
            $blogConnection,
            'mismatch_private_blog_'
        );
        $this->writeModuleDatabaseConfig(
            'webadmin',
            $webAdminConnection,
            'mismatch_private_webadmin_'
        );
        $environment = [
            BlogPublicOrigin::ENV => 'https://mismatch-private.example.test',
            'LIQUIDSTACK_DB_PASSWORD' => 'mismatch-dedicated-secret',
            'BBDD_PASS' => 'mismatch-shared-secret',
        ];

        $report = (new BlogDiagnosticService())->inspect(
            $this->root,
            ['es', 'en'],
            $environment,
            '/admin',
            true,
            $this->appliedPlan(),
            true
        );
        $data = $report->toArray();
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);

        self::assertFalse($report->isReady());
        self::assertFalse($data['configuration']['ready']);
        self::assertSame(
            $blogConnection,
            $data['configuration']['effective']['database']['connection']
        );
        self::assertSame([[
            'code' => 'database.connection_mismatch',
            'key' => 'database.connection',
        ]], $data['configuration']['issues']);
        self::assertSame(
            ['configuration.invalid'],
            $data['readiness']['blockers']
        );
        foreach ([
            'mismatch_private_blog_',
            'mismatch_private_webadmin_',
            'https://mismatch-private.example.test',
            'mismatch-dedicated-secret',
            'mismatch-shared-secret',
        ] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $encoded);
        }
    }

    public static function databaseConnectionMismatchProvider(): iterable
    {
        yield 'Blog dedicated and WebAdmin shared' => [
            'liquidstack',
            'shared',
        ];
        yield 'Blog shared and WebAdmin dedicated' => [
            'shared',
            'liquidstack',
        ];
    }

    public function testPendingCrossScopeCapabilityMigrationBlocksReadiness(): void
    {
        $plan = $this->plan('applied', 'pending');
        $data = $this->inspect($plan)->toArray();

        self::assertFalse($data['readiness']['blog_ready']);
        self::assertSame(
            ['0002_blog_capabilities'],
            $data['database']['pending']
        );
        self::assertContains(
            'database.migrations_not_ready',
            $data['readiness']['blockers']
        );
        self::assertTrue($data['database']['public_content']['ready']);
        self::assertFalse($data['database']['administration']['ready']);
    }

    public function testFuturePendingMigrationDoesNotBlockCurrentBlogRuntime(): void
    {
        $plan = new MigrationDatabasePlan(
            'sqlite',
            true,
            [
                $this->entry('0001_blog_posts', 'applied', null),
                $this->entry(
                    '0002_blog_capabilities',
                    'applied',
                    'webadmin'
                ),
                $this->entry('0003_future_feature', 'pending', null),
            ],
            []
        );
        $data = $this->inspect($plan)->toArray();

        self::assertTrue($data['readiness']['blog_ready']);
        self::assertSame('applied', $data['database']['status']);
        self::assertTrue($data['database']['public_content']['ready']);
        self::assertTrue($data['database']['administration']['ready']);
        self::assertFalse($data['database']['features']['ready']);
        self::assertSame(
            ['0003_future_feature'],
            $data['database']['features']['pending']
        );
    }

    public function testConfigurationOriginDependencyAndDatabaseFailIndependently(): void
    {
        $this->filesystem->mkdir($this->root . '/App/config/modules');
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/blog.php',
            "<?php\nreturn ['database' => ['password' => 'secret']];\n"
        );
        $report = (new BlogDiagnosticService())->inspect(
            $this->root,
            ['es'],
            [],
            null,
            false,
            null,
            true
        );
        $data = $report->toArray();
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);

        self::assertFalse($report->isReady());
        self::assertContains(
            'configuration.invalid',
            $data['readiness']['blockers']
        );
        self::assertContains(
            'environment.public_origin_invalid',
            $data['readiness']['blockers']
        );
        self::assertContains(
            'dependency.webadmin_not_ready',
            $data['readiness']['blockers']
        );
        self::assertStringNotContainsString('secret', $encoded);
    }

    public function testNoDatabaseModeIsExplicitlyNotReadyAndReadOnly(): void
    {
        $data = $this->inspect(null, false)->toArray();

        self::assertFalse($data['readiness']['blog_ready']);
        self::assertSame('not_checked', $data['database']['status']);
        self::assertContains(
            'database.not_checked',
            $data['readiness']['blockers']
        );
    }

    public function testRequiredAssetsAreValidatedInsideProject(): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/public/blog-admin.css',
            '/* fixture */'
        );

        $report = (new BlogDiagnosticService())->inspect(
            $this->root,
            ['es', 'en'],
            [BlogPublicOrigin::ENV => 'https://example.test'],
            '/admin',
            true,
            $this->appliedPlan(),
            true,
            [
                'public/blog-admin.css',
                'public/missing.js',
                '../outside-project.txt',
            ]
        );
        $data = $report->toArray();

        self::assertFalse($report->isReady());
        self::assertSame(
            ['public/missing.js'],
            $data['assets']['missing']
        );
        self::assertSame(
            ['../outside-project.txt'],
            $data['assets']['invalid']
        );
        self::assertContains(
            'assets.missing_or_invalid',
            $data['readiness']['blockers']
        );
    }

    private function inspect(
        ?MigrationDatabasePlan $plan,
        bool $inspectDatabase = true
    ): \App\Core\Blog\Diagnostics\BlogDiagnosticReport {
        return (new BlogDiagnosticService())->inspect(
            $this->root,
            ['es', 'en'],
            [BlogPublicOrigin::ENV => 'https://example.test'],
            '/admin',
            true,
            $plan,
            $inspectDatabase
        );
    }

    private function appliedPlan(): MigrationDatabasePlan
    {
        return $this->plan('applied', 'applied');
    }

    private function plan(
        string $schemaStatus,
        string $capabilityStatus
    ): MigrationDatabasePlan {
        return new MigrationDatabasePlan(
            'sqlite',
            true,
            [
                $this->entry('0001_blog_posts', $schemaStatus, null),
                $this->entry(
                    '0002_blog_capabilities',
                    $capabilityStatus,
                    'webadmin'
                ),
            ],
            []
        );
    }

    /** @return array<string, mixed> */
    private function entry(
        string $id,
        string $status,
        ?string $target
    ): array {
        return [
            'module' => 'blog',
            'target_scope_module' => $target,
            'id' => $id,
            'description' => 'not exposed',
            'checksum' => str_repeat('a', 64),
            'scope_hash' => str_repeat('b', 64),
            'destructive' => false,
            'status' => $status,
        ];
    }

    private function writeModuleDatabaseConfig(
        string $module,
        string $connection,
        string $tablePrefix
    ): void {
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/' . $module . '.php',
            "<?php\n\nreturn " . var_export([
                'database' => [
                    'connection' => $connection,
                    'table_prefix' => $tablePrefix,
                ],
            ], true) . ";\n"
        );
    }
}
