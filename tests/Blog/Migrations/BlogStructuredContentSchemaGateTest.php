<?php

declare(strict_types=1);

namespace Tests\Blog\Migrations;

use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Blog\BlogStructuredContentSchemaGate;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogStructuredContentSchemaGateTest extends TestCase
{
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-blog-structured-gate-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectRoot);
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode([
                'require' => ['liquidstack/blog' => '*'],
            ], JSON_THROW_ON_ERROR)
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->filesystem, $this->projectRoot)) {
            $this->filesystem->remove($this->projectRoot);
        }
    }

    public function testGateRequiresRecordedUntamperedMigrationAndExactSchema(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $registry = ModuleRegistry::forProject(
            $this->projectRoot,
            dirname(__DIR__, 3)
        );
        $scopes = MigrationScopeCollection::fromTablePrefixes([
            'blog' => 'gate_blog_',
            'webadmin' => 'gate_admin_',
        ]);
        $migrationRegistry = new MigrationRegistry();
        $migrationRegistry->ensureExists($pdo);
        $gate = new BlogStructuredContentSchemaGate();

        self::assertFalse($gate->isReady($pdo, $registry, $scopes));

        $adminScope = $scopes->get('webadmin');
        $blogScope = $scopes->get('blog');
        self::assertNotNull($adminScope);
        self::assertNotNull($blogScope);
        $adminBase = iterator_to_array(
            WebAdminMigrationProvider::migrations(),
            false
        )[0];
        foreach ($adminBase->statementsFor('sqlite', $adminScope) as $sql) {
            $pdo->exec($sql);
        }

        foreach (MigrationCatalog::fromRegistry($registry)->entries() as $entry) {
            if ($entry['module'] !== 'blog') {
                continue;
            }
            $migration = $entry['migration'];
            $target = $migration->targetScope('blog', $scopes);
            self::assertNotNull($target);
            foreach ($migration->statementsFor('sqlite', $target) as $sql) {
                $pdo->exec($sql);
            }
            $migrationRegistry->record(
                $pdo,
                'blog',
                $migration,
                $target,
                1,
                new DateTimeImmutable('2026-08-02T00:00:00Z')
            );
        }

        self::assertTrue($gate->isReady($pdo, $registry, $scopes));
        $pdo->exec('DROP INDEX "gate_blog_ix_cd_updated"');
        self::assertFalse($gate->isReady($pdo, $registry, $scopes));
    }
}
