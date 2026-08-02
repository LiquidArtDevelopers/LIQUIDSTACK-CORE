<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\Migrations\MigrationFeatureRequirement;
use App\Core\Modules\Migrations\MigrationProviderInterface;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class MigrationFeatureGateProviderFixture implements
    MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public static function migrations(): iterable
    {
        yield self::migration('0001_base', 'base_fixture');
        yield self::migration('0002_future', 'future_fixture');
    }

    private static function migration(
        string $id,
        string $table
    ): MigrationDefinition {
        return MigrationDefinition::sql(
            id: $id,
            description: 'Fixture de corte funcional.',
            statementsByDriver: [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {{table:' . $table
                        . '}} (`id` BIGINT)',
                ],
                'sqlite' => [
                    'CREATE TABLE {{table:' . $table
                        . '}} ("id" INTEGER)',
                ],
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true
        );
    }
}

final class MigrationFeatureGateTest extends TestCase
{
    private string $root;
    private string $projectRoot;
    private Filesystem $filesystem;
    private PDO $pdo;
    private ModuleRegistry $moduleRegistry;
    private MigrationCatalog $catalog;
    private MigrationScopeCollection $scopes;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-feature-gate-'
            . bin2hex(random_bytes(8));
        $this->projectRoot = $this->root . '/project';
        $this->filesystem->mkdir([
            $this->projectRoot,
            $this->root . '/modules/webadmin',
        ]);
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode([
                'require' => ['liquidstack/webadmin' => '*'],
            ], JSON_THROW_ON_ERROR)
        );
        $this->filesystem->dumpFile(
            $this->root . '/modules/webadmin/module.json',
            json_encode([
                'schema' => 1,
                'id' => 'webadmin',
                'package' => 'liquidstack/webadmin',
                'requires' => [],
                'providers' => [
                    'migrations' => [
                        MigrationFeatureGateProviderFixture::class,
                    ],
                ],
                'project_files' => [],
            ], JSON_THROW_ON_ERROR)
        );
        $this->moduleRegistry = ModuleRegistry::forProject(
            $this->projectRoot,
            $this->root
        );
        $this->catalog = MigrationCatalog::fromRegistry(
            $this->moduleRegistry
        );
        $this->scopes = MigrationScopeCollection::fromTablePrefixes([
            'webadmin' => 'ls_webadmin_',
        ]);
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        (new MigrationRegistry())->ensureExists($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->filesystem, $this->root)) {
            $this->filesystem->remove($this->root);
        }
    }

    public function testKnownFutureCatalogMigrationMayRemainPending(): void
    {
        $this->recordCatalogMigration('0001_base');
        $gate = new MigrationFeatureGate();

        self::assertTrue($gate->isReady(
            $this->pdo,
            $this->moduleRegistry,
            $this->scopes,
            $this->requirement('webadmin.base', ['0001_base'])
        ));
        self::assertFalse($gate->isReady(
            $this->pdo,
            $this->moduleRegistry,
            $this->scopes,
            $this->requirement(
                'webadmin.future',
                ['0001_base', '0002_future']
            )
        ));
    }

    public function testAppliedRegistryStillMustBeAnUntamperedCatalogPrefix(): void
    {
        $this->recordCatalogMigration('0001_base');
        $this->pdo->exec(
            "UPDATE ls_module_migrations SET checksum = '"
            . str_repeat('f', 64) . "' WHERE module_id = 'webadmin'"
        );

        self::assertFalse((new MigrationFeatureGate())->isReady(
            $this->pdo,
            $this->moduleRegistry,
            $this->scopes,
            $this->requirement('webadmin.base', ['0001_base'])
        ));
    }

    /** @param list<string> $migrations */
    private function requirement(
        string $feature,
        array $migrations
    ): MigrationFeatureRequirement {
        return new MigrationFeatureRequirement(
            'webadmin',
            $feature,
            $migrations
        );
    }

    private function recordCatalogMigration(string $id): void
    {
        foreach ($this->catalog->entries() as $entry) {
            if ($entry['migration']->id() !== $id) {
                continue;
            }
            $scope = $this->scopes->get('webadmin');
            self::assertNotNull($scope);
            (new MigrationRegistry())->record(
                $this->pdo,
                'webadmin',
                $entry['migration'],
                $scope,
                1,
                new DateTimeImmutable('2026-08-01T00:00:00Z')
            );

            return;
        }

        self::fail('Migration fixture not found: ' . $id);
    }
}
