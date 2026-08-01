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
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('https://example.test', $encoded);
        self::assertStringNotContainsString('ls_blog_', $encoded);
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
}
