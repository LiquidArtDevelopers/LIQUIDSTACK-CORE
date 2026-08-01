<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDatabasePlanner;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\WebAdmin\WebAdminHttpSchemaGate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class WebAdminHttpSchemaGateCountingPdo extends PDO
{
    /** @var list<string> */
    private array $sql = [];

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->exec('PRAGMA foreign_keys = ON');
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

final class WebAdminHttpSchemaGateTest extends TestCase
{
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite es necesario para estas pruebas.');
        }
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-webadmin-http-schema-gate-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectRoot);
        $this->writeRequirements(['liquidstack/blog' => '*']);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
    }

    public function testReadyGateUsesBoundedReadsAndNeverAuditsTableDdl(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->clearSqlLog();

        self::assertTrue($this->gate($pdo));

        $sql = strtolower(implode("\n", $pdo->sqlLog()));
        self::assertCount(6, $pdo->sqlLog());
        foreach ([
            'information_schema',
            'sqlite_master',
            'pragma table_info',
            'pragma index_list',
            'pragma index_xinfo',
            'pragma foreign_key_list',
            'pragma foreign_key_check',
            'pragma integrity_check',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $sql);
        }
        self::assertStringContainsString(
            'from ls_module_migrations where module_id = :module',
            $sql
        );
        self::assertStringContainsString('ls_webadmin_roles', $sql);
        self::assertStringContainsString('ls_webadmin_capabilities', $sql);
        self::assertStringContainsString('ls_webadmin_state', $sql);
    }

    public function testFullDdlAuditBelongsToPlannerAndNotToHttpGate(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec('DROP INDEX ls_webadmin_ix_se_expiry');

        self::assertTrue(
            $this->gate($pdo),
            'El gate HTTP no debe repetir la auditoria exhaustiva de migrate/doctor.'
        );

        $plan = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $this->catalog(),
            $this->scopes()
        );
        self::assertFalse($plan->isApplicable());
        self::assertContains(
            'migration.postcondition_drift',
            array_column($plan->blockers(), 'code')
        );
    }

    public function testCanonicalSeedDriftFailsClosed(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec(
            "UPDATE ls_webadmin_roles SET label_key = 'drifted' "
            . "WHERE code = 'site_admin'"
        );

        self::assertFalse($this->gate($pdo));
    }

    public function testRegistryMustContainExactWebAdminChecksumsAndScope(): void
    {
        $wrongChecksum = $this->sqliteWithSchema();
        $wrongChecksum->exec(
            "UPDATE ls_module_migrations SET checksum = '"
            . str_repeat('f', 64) . "' WHERE module_id = 'webadmin'"
        );
        self::assertFalse($this->gate($wrongChecksum));

        $wrongScope = $this->sqliteWithSchema();
        $wrongScope->exec(
            "UPDATE ls_module_migrations SET scope_hash = '"
            . str_repeat('e', 64) . "' WHERE module_id = 'webadmin'"
        );
        self::assertFalse($this->gate($wrongScope));

        $unknown = $this->sqliteWithSchema();
        $this->insertRegistryRecord($unknown, 'webadmin', '9999_unknown');
        self::assertFalse($this->gate($unknown));
    }

    public function testInvalidOrMissingRegistryFailsClosed(): void
    {
        $missing = $this->sqlite();
        self::assertFalse($this->gate($missing));

        $invalid = $this->sqliteWithSchema();
        $invalid->exec(
            "UPDATE ls_module_migrations SET checksum = 'invalid' "
            . "WHERE module_id = 'webadmin'"
        );
        self::assertFalse($this->gate($invalid));
    }

    public function testInactiveWebAdminAndUnsafeConnectionFailClosed(): void
    {
        $pdo = $this->sqliteWithSchema();
        $this->writeRequirements(['liquidstack/core' => '^1.9']);
        self::assertFalse($this->gate($pdo));

        $this->writeRequirements(['liquidstack/blog' => '*']);
        $pdo->exec('PRAGMA foreign_keys = OFF');
        self::assertFalse($this->gate($pdo));
    }

    public function testBlogOnlyRegistryBlockerDoesNotDisableWebAdmin(): void
    {
        $pdo = $this->sqliteWithSchema();
        $this->insertRegistryRecord($pdo, 'blog', '9999_unknown_blog');
        $pdo->exec(
            "UPDATE ls_module_migrations SET checksum = 'broken-blog-only' "
            . "WHERE module_id = 'blog'"
        );

        self::assertTrue($this->gate($pdo));
    }

    private function gate(PDO $pdo): bool
    {
        return (new WebAdminHttpSchemaGate())->isReady(
            $pdo,
            $this->registry(),
            $this->scope()
        );
    }

    private function sqliteWithSchema(): WebAdminHttpSchemaGateCountingPdo
    {
        $pdo = $this->sqlite();
        (new MigrationRunner())->apply(
            $pdo,
            $this->catalog(),
            $this->scopes()
        );

        return $pdo;
    }

    private function sqlite(): WebAdminHttpSchemaGateCountingPdo
    {
        return new WebAdminHttpSchemaGateCountingPdo();
    }

    private function registry(): ModuleRegistry
    {
        return ModuleRegistry::forProject(
            $this->projectRoot,
            dirname(__DIR__, 2)
        );
    }

    private function catalog(): MigrationCatalog
    {
        return MigrationCatalog::fromRegistry($this->registry());
    }

    private function scope(): MigrationScope
    {
        return MigrationScope::forTablePrefix('webadmin', 'ls_webadmin_');
    }

    private function scopes(): MigrationScopeCollection
    {
        return MigrationScopeCollection::fromTablePrefixes([
            'webadmin' => 'ls_webadmin_',
            'blog' => 'ls_blog_',
        ]);
    }

    /** @param array<string, string> $requirements */
    private function writeRequirements(array $requirements): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode(['require' => $requirements], JSON_THROW_ON_ERROR)
        );
    }

    private function insertRegistryRecord(
        PDO $pdo,
        string $module,
        string $migration
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO ls_module_migrations '
            . '(module_id, migration_id, checksum, scope_hash, batch, applied_at) '
            . 'VALUES (:module, :migration, :checksum, :scope, 2, :applied_at)'
        );
        $statement->execute([
            'module' => $module,
            'migration' => $migration,
            'checksum' => str_repeat('a', 64),
            'scope' => str_repeat('b', 64),
            'applied_at' => '2026-08-01 00:00:00.000000',
        ]);
    }
}
