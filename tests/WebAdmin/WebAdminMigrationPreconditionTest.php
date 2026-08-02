<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDatabasePlanner;
use App\Core\Modules\Migrations\MigrationException;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\WebAdmin\WebAdminInitialNamespacePrecondition;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class WebAdminNamespaceScalarStatement extends PDOStatement
{
    public function __construct(private readonly mixed $value)
    {
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->value;
    }
}

final class WebAdminNamespaceMySqlPdo extends PDO
{
    public string $version = '10.4.32-MariaDB';
    public int $tableCollisions = 0;
    public int $constraintCollisions = 0;
    /** @var list<string> */
    public array $preparedSql = [];

    public function __construct()
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : null;
    }

    public function query(
        string $query,
        ?int $fetchMode = null,
        mixed ...$fetchModeArgs
    ): PDOStatement|false {
        return new WebAdminNamespaceScalarStatement($this->version);
    }

    public function prepare(
        string $query,
        array $options = []
    ): PDOStatement|false {
        $this->preparedSql[] = $query;

        return new WebAdminNamespaceScalarStatement(
            str_contains($query, 'TABLE_CONSTRAINTS')
                ? $this->constraintCollisions
                : $this->tableCollisions
        );
    }
}

final class WebAdminMigrationPreconditionTest extends TestCase
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
            . '/liquidstack-webadmin-precondition-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectRoot);
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode([
                'require' => ['liquidstack/webadmin' => '*'],
            ], JSON_THROW_ON_ERROR)
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
    }

    public function testProviderDeclaresVersionedPreconditionAndFreshReplayWorks(): void
    {
        $migrations = iterator_to_array(
            WebAdminMigrationProvider::migrations(),
            false
        );
        $migration = null;
        foreach ($migrations as $candidate) {
            if ($candidate->id() === '0001_webadmin_identity_and_access') {
                $migration = $candidate;
                break;
            }
        }
        self::assertNotNull($migration);
        self::assertInstanceOf(
            WebAdminInitialNamespacePrecondition::class,
            $migration->preconditionVerifier()
        );
        self::assertSame(
            'webadmin-initial-namespace-empty-v1',
            $migration->preconditionVerifier()?->contractVersion()
        );

        $pdo = $this->sqlite();
        $runner = new MigrationRunner();
        self::assertTrue(
            $runner->apply($pdo, $this->catalog(), $this->scopes())->changed()
        );
        self::assertFalse(
            $runner->apply($pdo, $this->catalog(), $this->scopes())->changed()
        );
    }

    public function testPartialForeignTableIsPreservedExactlyAndCreatesNothing(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec(
            'CREATE TABLE ls_webadmin_users ('
            . 'marker TEXT PRIMARY KEY, payload BLOB NOT NULL) WITHOUT ROWID'
        );
        $pdo->exec(
            "INSERT INTO ls_webadmin_users VALUES ('foreign', X'0001FEFF00')"
        );
        $before = $this->completeSnapshot($pdo);
        $changes = (int) $pdo->query('SELECT total_changes()')->fetchColumn();

        $plan = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $this->catalog(),
            $this->scopes()
        );
        self::assertSame(
            ['migration.precondition_failed'],
            array_column($plan->blockers(), 'code')
        );
        self::assertSame('precondition_failed', $plan->entries()[0]['status']);

        try {
            (new MigrationRunner())->apply(
                $pdo,
                $this->catalog(),
                $this->scopes()
            );
            self::fail('El estado parcial debía bloquear antes de escribir.');
        } catch (MigrationException $exception) {
            self::assertSame(
                'migration.precondition_failed',
                $exception->issueCode()
            );
        }

        self::assertSame($before, $this->completeSnapshot($pdo));
        self::assertSame(
            $changes,
            (int) $pdo->query('SELECT total_changes()')->fetchColumn()
        );
        self::assertSame(0, $this->objectCount($pdo, MigrationRegistry::TABLE));
        self::assertSame(0, $this->objectCount($pdo, 'ls_webadmin_roles'));
        self::assertSame(0, $this->objectCount($pdo, 'ls_webadmin_state'));
    }

    public function testViewsTemporaryObjectsAndConstraintNamesAlsoCollide(): void
    {
        $verifier = new WebAdminInitialNamespacePrecondition();
        $scope = $this->scope();

        $view = $this->sqlite();
        $view->exec('CREATE TABLE source_data (id INTEGER)');
        $view->exec(
            'CREATE VIEW ls_webadmin_foreign_view AS '
            . 'SELECT id FROM source_data'
        );
        self::assertFalse($verifier->verify($view, $scope));

        $temporary = $this->sqlite();
        $temporary->exec(
            'CREATE TEMP TABLE LS_WEBADMIN_TEMP_COLLISION (id INTEGER)'
        );
        self::assertFalse($verifier->verify($temporary, $scope));

        $constraint = $this->sqlite();
        $constraint->exec(
            'CREATE TABLE foreign_owner (value INTEGER, '
            . 'CONSTRAINT "ls_webadmin_foreign_check" CHECK (value > 0))'
        );
        self::assertFalse($verifier->verify($constraint, $scope));

        $unrelated = $this->sqlite();
        $unrelated->exec(
            "CREATE TABLE foreign_owner (value INTEGER, note TEXT "
            . "DEFAULT 'constraint ls_webadmin_not_an_object', "
            . 'CONSTRAINT unrelated_check CHECK (value > 0))'
        );
        self::assertTrue($verifier->verify($unrelated, $scope));
    }

    public function testMySqlRequiresSupportedServerAndEmptyTablesAndConstraints(): void
    {
        $verifier = new WebAdminInitialNamespacePrecondition();

        $unsupported = new WebAdminNamespaceMySqlPdo();
        $unsupported->version = '5.7.44';
        self::assertFalse($verifier->verify($unsupported, $this->scope()));
        self::assertSame([], $unsupported->preparedSql);

        $table = new WebAdminNamespaceMySqlPdo();
        $table->tableCollisions = 1;
        self::assertFalse($verifier->verify($table, $this->scope()));
        self::assertCount(1, $table->preparedSql);
        self::assertStringContainsString(
            'information_schema.TABLES',
            $table->preparedSql[0]
        );

        $constraint = new WebAdminNamespaceMySqlPdo();
        $constraint->constraintCollisions = 1;
        self::assertFalse($verifier->verify($constraint, $this->scope()));
        self::assertCount(2, $constraint->preparedSql);
        self::assertStringContainsString(
            'information_schema.TABLE_CONSTRAINTS',
            $constraint->preparedSql[1]
        );

        $empty = new WebAdminNamespaceMySqlPdo();
        self::assertTrue($verifier->verify($empty, $this->scope()));
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function catalog(): MigrationCatalog
    {
        return MigrationCatalog::fromRegistry(ModuleRegistry::forProject(
            $this->projectRoot,
            dirname(__DIR__, 2)
        ));
    }

    private function scope(): MigrationScope
    {
        return MigrationScope::forTablePrefix('webadmin', 'ls_webadmin_');
    }

    private function scopes(): MigrationScopeCollection
    {
        return MigrationScopeCollection::fromTablePrefixes([
            'webadmin' => 'ls_webadmin_',
        ]);
    }

    private function objectCount(PDO $pdo, string $name): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM sqlite_master WHERE name = :name'
        );
        $statement->execute(['name' => $name]);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function completeSnapshot(PDO $pdo): array
    {
        return [
            'schema' => $pdo->query(
                "SELECT type || ':' || name, COALESCE(sql, '') "
                . 'FROM sqlite_master WHERE name NOT LIKE \'sqlite_%\' '
                . 'ORDER BY type, name'
            )->fetchAll(PDO::FETCH_KEY_PAIR),
            'foreign_rows' => $pdo->query(
                'SELECT marker, hex(payload) AS payload '
                . 'FROM ls_webadmin_users ORDER BY marker'
            )->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}
