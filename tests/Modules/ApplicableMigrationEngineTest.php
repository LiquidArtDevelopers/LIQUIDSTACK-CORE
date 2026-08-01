<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\MigrationApplyOptions;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDatabasePlanner;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationException;
use App\Core\Modules\Migrations\MigrationProviderInterface;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\Migrations\SqliteMigrationLock;
use App\Core\Modules\ModuleRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ApplicableWebadminMigrationProviderFixture implements MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public static function migrations(): iterable
    {
        yield self::definition(
            '0002_create_sessions',
            'Crea sesiones.',
            'sessions'
        );
        yield self::definition(
            '0001_create_users',
            'Crea usuarios.',
            'users'
        );
    }

    public static function definition(
        string $id,
        string $description,
        string $table
    ): MigrationDefinition {
        return MigrationDefinition::sql(
            id: $id,
            description: $description,
            statementsByDriver: [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {{table:' . $table . '}} (id BIGINT PRIMARY KEY)',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {{table:' . $table . '}} (id INTEGER PRIMARY KEY)',
                ],
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true
        );
    }
}

final class LateInsertedWebadminMigrationProviderFixture implements
    MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public static function migrations(): iterable
    {
        yield ApplicableWebadminMigrationProviderFixture::definition(
            '0000_inserted_late',
            'No puede ejecutarse despues de migraciones posteriores.',
            'inserted_late'
        );
        yield ApplicableWebadminMigrationProviderFixture::definition(
            '0001_create_users',
            'Crea usuarios.',
            'users'
        );
        yield ApplicableWebadminMigrationProviderFixture::definition(
            '0002_create_sessions',
            'Crea sesiones.',
            'sessions'
        );
    }
}

final class ApplicableBlogMigrationProviderFixture implements MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'blog';
    }

    public static function migrations(): iterable
    {
        yield MigrationDefinition::sql(
            id: '0001_create_posts',
            description: 'Crea artículos.',
            statementsByDriver: [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {{table:posts}} (id BIGINT PRIMARY KEY)',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {{table:posts}} (id INTEGER PRIMARY KEY)',
                ],
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true
        );
    }
}

final class ChangedWebadminMigrationProviderFixture implements MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public static function migrations(): iterable
    {
        yield MigrationDefinition::sql(
            id: '0001_create_users',
            description: 'Crea usuarios.',
            statementsByDriver: [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {{table:users}} (id BIGINT PRIMARY KEY, changed INT)',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {{table:users}} (id INTEGER PRIMARY KEY, changed INTEGER)',
                ],
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true
        );

        yield MigrationDefinition::sql(
            id: '0002_create_sessions',
            description: 'Crea sesiones.',
            statementsByDriver: [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {{table:sessions}} (id BIGINT PRIMARY KEY)',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {{table:sessions}} (id INTEGER PRIMARY KEY)',
                ],
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true
        );
    }
}

final class FailingMigrationProviderFixture implements MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public static function migrations(): iterable
    {
        yield MigrationDefinition::sql(
            id: '0001_create_before_failure',
            description: 'Primer paso.',
            statementsByDriver: [
                'mysql' => ['CREATE TABLE IF NOT EXISTS {{table:before_failure}} (id BIGINT)'],
                'sqlite' => ['CREATE TABLE {{table:before_failure}} (id INTEGER)'],
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true
        );
        yield MigrationDefinition::sql(
            id: '0002_fail',
            description: 'Falla de prueba.',
            statementsByDriver: [
                'mysql' => ['CREATE TABLE IF NOT EXISTS {{table:failure_marker}} (id BIGINT)'],
                'sqlite' => [
                    'INSERT INTO {{table:missing_failure_target}} (id) VALUES (1)',
                ],
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true
        );
    }
}

final class DestructiveMigrationProviderFixture implements MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public static function migrations(): iterable
    {
        yield MigrationDefinition::sql(
            id: '0001_destructive',
            description: 'Transformación destructiva.',
            statementsByDriver: [
                'mysql' => ['CREATE TABLE IF NOT EXISTS {{table:destructive}} (id BIGINT)'],
                'sqlite' => ['CREATE TABLE {{table:destructive}} (id INTEGER)'],
            ],
            destructive: true,
            transactionalDrivers: ['sqlite'],
            retrySafe: true
        );
    }
}

final class FailingReleaseSqlitePdoFixture extends PDO
{
    public bool $failCommit = true;

    public bool $failRollback = true;

    /** @var list<string> */
    public array $releaseStatements = [];

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function exec(string $statement): int|false
    {
        $normalized = strtoupper(trim($statement));
        if ($normalized === 'COMMIT' || $normalized === 'ROLLBACK') {
            $this->releaseStatements[] = $normalized;
        }
        if ($normalized === 'COMMIT' && $this->failCommit) {
            throw new RuntimeException('Forced COMMIT failure.');
        }
        if ($normalized === 'ROLLBACK' && $this->failRollback) {
            throw new RuntimeException('Forced ROLLBACK failure.');
        }

        return parent::exec($statement);
    }
}

final class ApplicableMigrationEngineTest extends TestCase
{
    private string $root;
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite es necesario para el motor de migraciones.');
        }

        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-applicable-migrations-'
            . bin2hex(random_bytes(8));
        $this->projectRoot = $this->root . '/project';
        $this->filesystem->mkdir([
            $this->projectRoot,
            $this->root . '/modules/webadmin',
            $this->root . '/modules/blog',
        ]);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testDatabasePlanIsReadOnlyAndKeepsDependencyThenIdOrder(): void
    {
        $catalog = $this->blogCatalog();
        $pdo = $this->sqlite();
        $plan = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $catalog,
            $this->scopes()
        );

        self::assertSame('registry_missing', $plan->toArray()['database_state']);
        self::assertSame([
            'webadmin:0001_create_users',
            'webadmin:0002_create_sessions',
            'blog:0001_create_posts',
        ], array_map(
            static fn (array $entry): string =>
                $entry['module'] . ':' . $entry['id'],
            $plan->pendingEntries()
        ));
        self::assertSame(0, $this->tableCount($pdo, MigrationRegistry::TABLE));
    }

    public function testRunnerAppliesOneBatchAndSecondRunIsIdempotent(): void
    {
        $catalog = $this->blogCatalog();
        $pdo = $this->sqlite();
        $runner = new MigrationRunner();
        $preflight = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $catalog,
            $this->scopes()
        );

        $result = $runner->apply(
            $pdo,
            $catalog,
            $this->scopes(),
            new MigrationApplyOptions(expectedPlanHash: $preflight->hash())
        );

        self::assertTrue($result->changed());
        self::assertSame(1, $result->batch());
        self::assertSame([
            'webadmin:0001_create_users',
            'webadmin:0002_create_sessions',
            'blog:0001_create_posts',
        ], array_map(
            static fn (array $entry): string =>
                $entry['module'] . ':' . $entry['id'],
            $result->applied()
        ));
        self::assertSame(3, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_module_migrations'
        )->fetchColumn());
        self::assertSame([1], array_map(
            'intval',
            $pdo->query(
                'SELECT DISTINCT batch FROM ls_module_migrations'
            )->fetchAll(PDO::FETCH_COLUMN)
        ));
        self::assertSame(1, $this->tableCount($pdo, 'ls_webadmin_users'));
        self::assertSame(1, $this->tableCount($pdo, 'ls_blog_posts'));

        $second = $runner->apply($pdo, $catalog, $this->scopes());
        self::assertFalse($second->changed());
        self::assertNull($second->batch());
        self::assertSame(3, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_module_migrations'
        )->fetchColumn());
    }

    public function testChecksumDriftBlocksWithoutMutation(): void
    {
        $pdo = $this->sqlite();
        (new MigrationRunner())->apply(
            $pdo,
            $this->blogCatalog(),
            $this->scopes()
        );
        $before = $this->schemaSnapshot($pdo);

        $this->writeManifest(
            'webadmin',
            [],
            ChangedWebadminMigrationProviderFixture::class
        );
        $changed = $this->catalog();
        $plan = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $changed,
            $this->scopes()
        );

        self::assertContains(
            'migration.checksum_mismatch',
            array_column($plan->blockers(), 'code')
        );
        try {
            (new MigrationRunner())->apply($pdo, $changed, $this->scopes());
            self::fail('El drift de checksum debía bloquear la aplicación.');
        } catch (MigrationException $exception) {
            self::assertSame('migration.checksum_mismatch', $exception->issueCode());
        }
        self::assertSame($before, $this->schemaSnapshot($pdo));
    }

    public function testScopeChangeAndUnknownAppliedMigrationBlock(): void
    {
        $catalog = $this->blogCatalog();
        $pdo = $this->sqlite();
        (new MigrationRunner())->apply($pdo, $catalog, $this->scopes());

        $changedScope = MigrationScopeCollection::fromTablePrefixes([
            'webadmin' => 'custom_webadmin_',
            'blog' => 'ls_blog_',
        ]);
        $scopePlan = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $catalog,
            $changedScope
        );
        self::assertContains(
            'migration.scope_mismatch',
            array_column($scopePlan->blockers(), 'code')
        );

        $pdo->exec(
            "INSERT INTO ls_module_migrations VALUES ("
            . "'webadmin','9999_unknown','" . str_repeat('a', 64) . "','"
            . $this->scopes()->get('webadmin')->hash()
            . "',2,'2026-08-01 00:00:00.000000')"
        );
        $unknownPlan = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $catalog,
            $this->scopes()
        );
        self::assertContains(
            'migration.unknown_applied',
            array_column($unknownPlan->blockers(), 'code')
        );
    }

    public function testMigrationInsertedBeforeAppliedIdIsBlocked(): void
    {
        $pdo = $this->sqlite();
        (new MigrationRunner())->apply(
            $pdo,
            $this->blogCatalog(),
            $this->scopes()
        );
        $before = $this->schemaSnapshot($pdo);

        $this->writeManifest(
            'webadmin',
            [],
            LateInsertedWebadminMigrationProviderFixture::class
        );
        $catalog = $this->catalog();
        $plan = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $catalog,
            $this->scopes()
        );
        $late = array_values(array_filter(
            $plan->entries(),
            static fn (array $entry): bool =>
                $entry['id'] === '0000_inserted_late'
        ));

        self::assertCount(1, $late);
        self::assertSame('out_of_order', $late[0]['status']);
        self::assertContains(
            'migration.out_of_order',
            array_column($plan->blockers(), 'code')
        );
        try {
            (new MigrationRunner())->apply($pdo, $catalog, $this->scopes());
            self::fail('Una migracion historica no debe ejecutarse tarde.');
        } catch (MigrationException $exception) {
            self::assertSame('migration.out_of_order', $exception->issueCode());
        }
        self::assertSame($before, $this->schemaSnapshot($pdo));
    }

    public function testSQLiteFailureRollsBackRegistryAndEveryMigration(): void
    {
        $this->writeManifest(
            'webadmin',
            [],
            FailingMigrationProviderFixture::class
        );
        $this->writeManifest('blog', ['webadmin'], null);
        $this->writeComposer(['liquidstack/webadmin' => '*']);
        $pdo = $this->sqlite();

        try {
            (new MigrationRunner())->apply(
                $pdo,
                $this->catalog(),
                $this->scopes()
            );
            self::fail('La sentencia inválida debía fallar.');
        } catch (MigrationException $exception) {
            self::assertSame('migration.execution_failed', $exception->issueCode());
            self::assertSame('0002_fail', $exception->migrationId());
        }

        self::assertSame(0, $this->tableCount($pdo, MigrationRegistry::TABLE));
        self::assertSame(0, $this->tableCount($pdo, 'ls_webadmin_before_failure'));
    }

    public function testDestructiveMigrationRequiresTwoExplicitGates(): void
    {
        $this->writeManifest(
            'webadmin',
            [],
            DestructiveMigrationProviderFixture::class
        );
        $this->writeManifest('blog', ['webadmin'], null);
        $this->writeComposer(['liquidstack/webadmin' => '*']);
        $catalog = $this->catalog();
        $pdo = $this->sqlite();
        $runner = new MigrationRunner();

        foreach ([
            [new MigrationApplyOptions(), 'migrations.destructive_not_allowed'],
            [
                new MigrationApplyOptions(allowDestructive: true),
                'migrations.backup_not_confirmed',
            ],
        ] as [$options, $code]) {
            try {
                $runner->apply($pdo, $catalog, $this->scopes(), $options);
                self::fail('La migración destructiva debía bloquearse.');
            } catch (MigrationException $exception) {
                self::assertSame($code, $exception->issueCode());
            }
        }
        self::assertSame(0, $this->tableCount($pdo, MigrationRegistry::TABLE));

        $result = $runner->apply(
            $pdo,
            $catalog,
            $this->scopes(),
            new MigrationApplyOptions(
                allowDestructive: true,
                backupConfirmed: true
            )
        );
        self::assertTrue($result->changed());
    }

    /** @dataProvider undeclaredDestructiveSqlProvider */
    public function testObviousDestructiveSqlCannotBypassTheDeclaredGate(
        string $mysql,
        string $sqlite
    ): void {
        $this->expectException(InvalidArgumentException::class);

        MigrationDefinition::sql(
            id: '0001_hidden_destructive_operation',
            description: 'Intenta ocultar una operación destructiva.',
            statementsByDriver: [
                'mysql' => [$mysql],
                'sqlite' => [$sqlite],
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true
        );
    }

    public static function undeclaredDestructiveSqlProvider(): iterable
    {
        yield 'mysql drop' => [
            'DROP TABLE IF EXISTS {{table:obsolete}}',
            'CREATE TABLE IF NOT EXISTS {{table:kept}} (id INTEGER)',
        ];
        yield 'sqlite delete' => [
            'CREATE TABLE IF NOT EXISTS {{table:kept}} (id BIGINT PRIMARY KEY)',
            'DELETE FROM {{table:kept}}',
        ];
        yield 'sqlite pragma mutation' => [
            'CREATE TABLE IF NOT EXISTS {{table:kept}} (id BIGINT PRIMARY KEY)',
            'PRAGMA foreign_keys = OFF',
        ];
        yield 'sqlite commented drop' => [
            'CREATE TABLE IF NOT EXISTS {{table:kept}} (id BIGINT PRIMARY KEY)',
            "-- disguised\nDROP TABLE IF EXISTS {{table:kept}}",
        ];
        yield 'sqlite cte delete' => [
            'CREATE TABLE IF NOT EXISTS {{table:kept}} (id BIGINT PRIMARY KEY)',
            'WITH doomed AS (SELECT id FROM {{table:kept}}) '
                . 'DELETE FROM {{table:kept}} WHERE id IN (SELECT id FROM doomed)',
        ];
    }

    public function testSQLiteForeignKeysMustAlreadyBeEnabledAndDryRunDoesNotEnableThem(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::assertSame('0', (string) $pdo->query(
            'PRAGMA foreign_keys'
        )->fetchColumn());

        $plan = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $this->catalog(),
            $this->scopes()
        );

        self::assertFalse($plan->isApplicable());
        self::assertContains(
            'database.sqlite_foreign_keys_disabled',
            array_column($plan->blockers(), 'code')
        );
        self::assertSame('0', (string) $pdo->query(
            'PRAGMA foreign_keys'
        )->fetchColumn(), 'El dry-run no puede mutar el contrato SQLite.');
        self::assertSame(0, $this->tableCount(
            $pdo,
            MigrationRegistry::TABLE
        ));

        try {
            (new MigrationRunner())->apply(
                $pdo,
                $this->catalog(),
                $this->scopes()
            );
            self::fail('Apply debía fallar cerrado sin claves foráneas.');
        } catch (MigrationException $exception) {
            self::assertSame(
                'database.sqlite_foreign_keys_disabled',
                $exception->issueCode()
            );
        }
        self::assertSame(0, $this->tableCount(
            $pdo,
            MigrationRegistry::TABLE
        ));
    }

    public function testSilentPdoModeIsBlockedBeforeAnyInspectionOrMutation(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $plan = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $this->catalog(),
            $this->scopes()
        );

        self::assertFalse($plan->isApplicable());
        self::assertSame(
            ['database.pdo_exception_mode_required'],
            array_column($plan->blockers(), 'code')
        );
        self::assertSame(0, $this->tableCount(
            $pdo,
            MigrationRegistry::TABLE
        ));

        try {
            (new MigrationRunner())->apply(
                $pdo,
                $this->catalog(),
                $this->scopes()
            );
            self::fail('ERRMODE_SILENT debía bloquear apply.');
        } catch (MigrationException $exception) {
            self::assertSame(
                'database.pdo_exception_mode_required',
                $exception->issueCode()
            );
        }
        self::assertSame(0, $this->tableCount(
            $pdo,
            MigrationRegistry::TABLE
        ));
    }

    public function testStalePlanAndConcurrentSQLiteLockFailClosed(): void
    {
        $catalog = $this->blogCatalog();
        $pdo = $this->sqlite();
        try {
            (new MigrationRunner())->apply(
                $pdo,
                $catalog,
                $this->scopes(),
                new MigrationApplyOptions(expectedPlanHash: str_repeat('0', 64))
            );
            self::fail('Un plan obsoleto debía bloquearse.');
        } catch (MigrationException $exception) {
            self::assertSame('migrations.plan_changed', $exception->issueCode());
        }
        self::assertSame(0, $this->tableCount($pdo, MigrationRegistry::TABLE));

        $database = $this->root . '/lock-test.sqlite';
        $first = $this->sqlite($database);
        $second = $this->sqlite($database);
        $lock = new SqliteMigrationLock();
        $lock->acquire($first, 0);
        try {
            (new MigrationRunner())->apply(
                $second,
                $catalog,
                $this->scopes(),
                new MigrationApplyOptions(lockTimeoutSeconds: 0)
            );
            self::fail('El segundo proceso debía respetar el lock.');
        } catch (MigrationException $exception) {
            self::assertSame('migrations.lock_timeout', $exception->issueCode());
        } finally {
            $lock->release($first, false);
        }
    }

    public function testSQLiteCommitFailureRollsBackAndRestoresTimeout(): void
    {
        $database = $this->root . '/commit-failure.sqlite';
        $first = $this->sqlite($database);
        $second = $this->sqlite($database);
        $first->exec('PRAGMA foreign_keys = ON');
        $first->exec('PRAGMA busy_timeout = 137');
        $second->exec('PRAGMA busy_timeout = 0');
        $lock = new SqliteMigrationLock();
        $lock->acquire($first, 0);
        $first->exec('CREATE TABLE parent (id INTEGER PRIMARY KEY)');
        $first->exec(
            'CREATE TABLE child (parent_id INTEGER, '
            . 'FOREIGN KEY (parent_id) REFERENCES parent(id) '
            . 'DEFERRABLE INITIALLY DEFERRED)'
        );
        $first->exec('INSERT INTO child (parent_id) VALUES (42)');

        try {
            $lock->release($first, true);
            self::fail('El COMMIT con FK diferida debia fallar.');
        } catch (MigrationException $exception) {
            self::assertSame('migrations.commit_failed', $exception->issueCode());
        }

        self::assertSame(
            137,
            (int) $first->query('PRAGMA busy_timeout')->fetchColumn()
        );
        self::assertSame(0, $this->tableCount($first, 'parent'));
        $second->exec('CREATE TABLE write_after_failure (id INTEGER)');
        self::assertSame(1, $this->tableCount($second, 'write_after_failure'));
    }

    public function testSQLiteLockKeepsAcquiredStateWhenCommitAndRollbackFail(): void
    {
        $pdo = new FailingReleaseSqlitePdoFixture();
        $pdo->exec('PRAGMA busy_timeout = 211');
        $lock = new SqliteMigrationLock();
        $lock->acquire($pdo, 0);

        try {
            $lock->release($pdo, true);
            self::fail('COMMIT y ROLLBACK forzados debian fallar.');
        } catch (MigrationException $exception) {
            self::assertSame(
                'migrations.commit_rollback_failed',
                $exception->issueCode()
            );
        }

        self::assertSame(211, (int) $pdo->query(
            'PRAGMA busy_timeout'
        )->fetchColumn());
        $pdo->failRollback = false;
        $lock->release($pdo, false);
        self::assertSame(
            ['COMMIT', 'ROLLBACK', 'ROLLBACK'],
            $pdo->releaseStatements
        );
    }

    public function testInvalidRegistrySchemaFailsClosed(): void
    {
        $catalog = $this->blogCatalog();
        $pdo = $this->sqlite();
        $pdo->exec(
            'CREATE TABLE ls_module_migrations (module_id TEXT PRIMARY KEY)'
        );

        try {
            (new MigrationDatabasePlanner())->plan(
                $pdo,
                $catalog,
                $this->scopes()
            );
            self::fail('Un registry incompatible debía bloquearse.');
        } catch (MigrationException $exception) {
            self::assertSame(
                'migrations.registry_schema_invalid',
                $exception->issueCode()
            );
        }
    }

    private function blogCatalog(): MigrationCatalog
    {
        $this->writeManifest(
            'webadmin',
            [],
            ApplicableWebadminMigrationProviderFixture::class
        );
        $this->writeManifest(
            'blog',
            ['webadmin'],
            ApplicableBlogMigrationProviderFixture::class
        );
        $this->writeComposer(['liquidstack/blog' => '*']);

        return $this->catalog();
    }

    private function catalog(): MigrationCatalog
    {
        return MigrationCatalog::fromRegistry(ModuleRegistry::forProject(
            $this->projectRoot,
            $this->root
        ));
    }

    private function scopes(): MigrationScopeCollection
    {
        return MigrationScopeCollection::fromTablePrefixes([
            'webadmin' => 'ls_webadmin_',
            'blog' => 'ls_blog_',
        ]);
    }

    private function sqlite(?string $path = null): PDO
    {
        $pdo = new PDO($path === null ? 'sqlite::memory:' : 'sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function tableCount(PDO $pdo, string $table): int
    {
        $statement = $pdo->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name"
        );
        $statement->execute(['name' => $table]);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, string> */
    private function schemaSnapshot(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT name, sql FROM sqlite_master WHERE type = 'table' ORDER BY name"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        return is_array($rows) ? $rows : [];
    }

    private function writeManifest(
        string $id,
        array $requires,
        ?string $provider
    ): void {
        $providers = $provider === null ? [] : [$provider];
        $this->filesystem->dumpFile(
            $this->root . '/modules/' . $id . '/module.json',
            json_encode([
                'schema' => 1,
                'id' => $id,
                'package' => 'liquidstack/' . $id,
                'requires' => $requires,
                'providers' => ['migrations' => $providers],
                'project_files' => [],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
    }

    /** @param array<string, string> $require */
    private function writeComposer(array $require): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode(
                ['require' => $require],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
    }
}
