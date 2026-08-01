<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDatabasePlanner;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationException;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationProviderInterface;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class MutableMigrationPostconditionFixture implements
    MigrationPostconditionVerifierInterface
{
    public static bool $satisfied = true;
    public static bool $throws = false;

    public function __construct(private readonly string $version = 'v1')
    {
    }

    public function contractVersion(): string
    {
        return $this->version;
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        if (self::$throws) {
            throw new RuntimeException('Sensitive database detail.');
        }

        return self::$satisfied;
    }
}

final class AlternateMigrationPostconditionFixture implements
    MigrationPostconditionVerifierInterface
{
    public function contractVersion(): string
    {
        return 'v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        return true;
    }
}

final class PlannerMySqlSessionStatementFixture extends PDOStatement
{
    /** @param array<string, mixed> $row */
    public function __construct(private readonly array $row)
    {
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        return $this->row;
    }
}

final class PlannerUnsafeMySqlPdoFixture extends PDO
{
    public function __construct()
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'mysql',
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_SERVER_VERSION => '8.0.36',
            default => null,
        };
    }

    public function query(
        string $query,
        ?int $fetchMode = null,
        mixed ...$fetchModeArgs
    ): PDOStatement|false {
        return new PlannerMySqlSessionStatementFixture([
            'time_zone' => '+00:00',
            'foreign_key_checks' => 0,
            'unique_checks' => 1,
            'sql_mode' => 'STRICT_TRANS_TABLES',
        ]);
    }
}

final class PostconditionMigrationProviderFixture implements
    MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public static function migrations(): iterable
    {
        yield MigrationDefinition::sql(
            id: '0001_postcondition',
            description: 'Prueba la postcondición.',
            statementsByDriver: [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {{table:verified}} (id BIGINT PRIMARY KEY)',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {{table:verified}} (id INTEGER PRIMARY KEY)',
                ],
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier: new MutableMigrationPostconditionFixture()
        );
    }
}

final class PhaseMigrationPostconditionFixture implements
    MigrationPostconditionVerifierInterface
{
    public function __construct(private readonly bool $expectsCurrent)
    {
    }

    public function contractVersion(): string
    {
        return $this->expectsCurrent ? 'phase-current-v1' : 'phase-legacy-v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        $count = (int) $pdo->query(
            'SELECT COUNT(*) FROM '
            . $scope->quotedTable('phase', 'sqlite')
            . " WHERE state = 'current'"
        )->fetchColumn();

        return $this->expectsCurrent ? $count === 1 : $count === 0;
    }
}

final class SupersessionMigrationProviderFixture implements
    MigrationProviderInterface
{
    public static bool $includeCurrent = true;

    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public static function migrations(): iterable
    {
        yield MigrationDefinition::sql(
            id: '0001_legacy_phase',
            description: 'Crea la fase inicial.',
            statementsByDriver: [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {{table:phase}} '
                        . '(state VARCHAR(20) PRIMARY KEY)',
                    "INSERT IGNORE INTO {{table:phase}} (state) VALUES ('legacy')",
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {{table:phase}} '
                        . '(state TEXT PRIMARY KEY)',
                    "INSERT INTO {{table:phase}} (state) VALUES ('legacy') "
                        . 'ON CONFLICT(state) DO NOTHING',
                ],
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier: new PhaseMigrationPostconditionFixture(false)
        );
        if (!self::$includeCurrent) {
            return;
        }
        yield MigrationDefinition::sql(
            id: '0002_current_phase',
            description: 'Establece la fase actual.',
            statementsByDriver: [
                'mysql' => [
                    "INSERT IGNORE INTO {{table:phase}} (state) VALUES ('current')",
                ],
                'sqlite' => [
                    "INSERT INTO {{table:phase}} (state) VALUES ('current') "
                        . 'ON CONFLICT(state) DO NOTHING',
                ],
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier: new PhaseMigrationPostconditionFixture(true),
            supersedesPostconditions: ['0001_legacy_phase']
        );
    }
}

final class UnknownSupersessionMigrationProviderFixture implements
    MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public static function migrations(): iterable
    {
        yield MigrationDefinition::sql(
            id: '0002_invalid_superseder',
            description: 'Declara un destino inexistente.',
            statementsByDriver: [
                'mysql' => ['CREATE TABLE IF NOT EXISTS {{table:invalid}} (id BIGINT)'],
                'sqlite' => ['CREATE TABLE IF NOT EXISTS {{table:invalid}} (id INTEGER)'],
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier: new MutableMigrationPostconditionFixture(),
            supersedesPostconditions: ['0001_missing']
        );
    }
}

final class MigrationPostconditionTest extends TestCase
{
    private string $root;
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite es necesario para estas pruebas.');
        }
        MutableMigrationPostconditionFixture::$satisfied = true;
        MutableMigrationPostconditionFixture::$throws = false;
        SupersessionMigrationProviderFixture::$includeCurrent = true;
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-postcondition-' . bin2hex(random_bytes(8));
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
                    'migrations' => [PostconditionMigrationProviderFixture::class],
                ],
                'project_files' => [],
            ], JSON_THROW_ON_ERROR)
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testVerifierRunsBeforeRegistryAndFailureRollsBackSqlite(): void
    {
        MutableMigrationPostconditionFixture::$satisfied = false;
        $pdo = $this->sqlite();

        try {
            (new MigrationRunner())->apply(
                $pdo,
                $this->catalog(),
                $this->scopes()
            );
            self::fail('Una postcondición fallida debía abortar la migración.');
        } catch (MigrationException $exception) {
            self::assertSame(
                'migration.postcondition_failed',
                $exception->issueCode()
            );
            self::assertSame('webadmin', $exception->moduleId());
            self::assertSame('0001_postcondition', $exception->migrationId());
            self::assertStringNotContainsString(
                'Sensitive',
                $exception->getMessage()
            );
        }

        self::assertSame(0, $this->tableCount($pdo, 'ls_webadmin_verified'));
        self::assertSame(0, $this->tableCount($pdo, MigrationRegistry::TABLE));
    }

    public function testAppliedPostconditionDriftIsAStableReadOnlyBlocker(): void
    {
        $pdo = $this->sqlite();
        $catalog = $this->catalog();
        (new MigrationRunner())->apply($pdo, $catalog, $this->scopes());
        $before = $this->schemaSnapshot($pdo);
        MutableMigrationPostconditionFixture::$satisfied = false;

        $plan = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $catalog,
            $this->scopes()
        );

        self::assertFalse($plan->isApplicable());
        self::assertSame(
            ['migration.postcondition_drift'],
            array_column($plan->blockers(), 'code')
        );
        self::assertSame('postcondition_drift', $plan->entries()[0]['status']);
        self::assertSame($before, $this->schemaSnapshot($pdo));

        try {
            (new MigrationRunner())->apply($pdo, $catalog, $this->scopes());
            self::fail('El drift aplicado debía bloquear una nueva ejecución.');
        } catch (MigrationException $exception) {
            self::assertSame(
                'migration.postcondition_drift',
                $exception->issueCode()
            );
        }
        self::assertSame(1, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_module_migrations'
        )->fetchColumn());
    }

    public function testFailedVerifierNeverRecordsIntoExistingRegistry(): void
    {
        $pdo = $this->sqlite();
        (new MigrationRegistry())->ensureExists($pdo);
        MutableMigrationPostconditionFixture::$satisfied = false;

        try {
            (new MigrationRunner())->apply(
                $pdo,
                $this->catalog(),
                $this->scopes()
            );
            self::fail('El verifier debía impedir el registro.');
        } catch (MigrationException $exception) {
            self::assertSame(
                'migration.postcondition_failed',
                $exception->issueCode()
            );
        }

        self::assertSame(1, $this->tableCount($pdo, MigrationRegistry::TABLE));
        self::assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_module_migrations'
        )->fetchColumn());
        self::assertSame(0, $this->tableCount($pdo, 'ls_webadmin_verified'));
    }

    public function testVerifierExceptionsFailClosedWithoutLeakingDetails(): void
    {
        MutableMigrationPostconditionFixture::$throws = true;
        $pdo = $this->sqlite();

        try {
            (new MigrationRunner())->apply(
                $pdo,
                $this->catalog(),
                $this->scopes()
            );
            self::fail('Una excepción del verifier debía fallar cerrado.');
        } catch (MigrationException $exception) {
            self::assertSame(
                'migration.postcondition_failed',
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                'Sensitive database detail',
                $exception->getMessage()
            );
        }
    }

    public function testVerifierClassAndContractVersionBelongToChecksum(): void
    {
        $first = $this->definition(new MutableMigrationPostconditionFixture('v1'));
        $same = $this->definition(new MutableMigrationPostconditionFixture('v1'));
        $versionChanged = $this->definition(
            new MutableMigrationPostconditionFixture('v2')
        );
        $classChanged = $this->definition(
            new AlternateMigrationPostconditionFixture()
        );
        $descriptionChanged = $this->definition(
            new MutableMigrationPostconditionFixture('v1'),
            'Otra descripción exclusivamente informativa.'
        );

        self::assertSame($first->checksum(), $same->checksum());
        self::assertNotSame($first->checksum(), $versionChanged->checksum());
        self::assertNotSame($first->checksum(), $classChanged->checksum());
        self::assertSame(
            $first->checksum(),
            $descriptionChanged->checksum(),
            'El copy descriptivo no forma parte del contrato ejecutable v2.'
        );
        self::assertInstanceOf(
            MigrationPostconditionVerifierInterface::class,
            $first->postconditionVerifier()
        );

        $statements = [
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS {{table:legacy}} (id BIGINT PRIMARY KEY)',
            ],
            'sqlite' => [
                'CREATE TABLE IF NOT EXISTS {{table:legacy}} (id INTEGER PRIMARY KEY)',
            ],
        ];
        $legacy = MigrationDefinition::sql(
            id: '0001_legacy_checksum',
            description: 'Mantiene el checksum histórico.',
            statementsByDriver: $statements,
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true
        );
        $legacyCanonical = json_encode([
            'schema' => 1,
            'id' => '0001_legacy_checksum',
            'description' => 'Mantiene el checksum histórico.',
            'destructive' => false,
            'retry_safe' => true,
            'transactional_drivers' => ['sqlite'],
            'statements' => $statements,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertSame(hash('sha256', $legacyCanonical), $legacy->checksum());
    }

    public function testInvalidVerifierContractVersionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->definition(new MutableMigrationPostconditionFixture('invalid version'));
    }

    public function testPlannerRejectsAnInjectedUnsafeMySqlSessionReadOnly(): void
    {
        $plan = (new MigrationDatabasePlanner())->plan(
            new PlannerUnsafeMySqlPdoFixture(),
            $this->catalog(),
            $this->scopes()
        );

        self::assertFalse($plan->isApplicable());
        self::assertSame(
            ['database.mysql_foreign_keys_disabled'],
            array_column($plan->blockers(), 'code')
        );
        self::assertSame('database_contract_invalid', $plan->entries()[0]['status']);
    }

    public function testAppliedSupersederRetiresTheObsoleteVerifier(): void
    {
        $this->configureProvider(SupersessionMigrationProviderFixture::class);
        $pdo = $this->sqlite();
        $catalog = $this->catalog();

        $result = (new MigrationRunner())->apply(
            $pdo,
            $catalog,
            $this->scopes()
        );
        $plan = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $catalog,
            $this->scopes()
        );

        self::assertCount(2, $result->applied());
        self::assertTrue($plan->isApplicable());
        self::assertSame(['applied', 'applied'], array_column(
            $plan->entries(),
            'status'
        ));
    }

    public function testPendingSupersederDoesNotHideOldDrift(): void
    {
        $this->configureProvider(SupersessionMigrationProviderFixture::class);
        SupersessionMigrationProviderFixture::$includeCurrent = false;
        $pdo = $this->sqlite();
        (new MigrationRunner())->apply(
            $pdo,
            $this->catalog(),
            $this->scopes()
        );
        $pdo->exec(
            "INSERT INTO ls_webadmin_phase (state) VALUES ('current')"
        );
        SupersessionMigrationProviderFixture::$includeCurrent = true;

        $plan = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $this->catalog(),
            $this->scopes()
        );

        self::assertFalse($plan->isApplicable());
        self::assertSame(
            ['migration.postcondition_drift'],
            array_column($plan->blockers(), 'code')
        );
        self::assertSame(
            ['postcondition_drift', 'pending'],
            array_column($plan->entries(), 'status')
        );
    }

    public function testCatalogRejectsAnUnknownPostconditionSupersession(): void
    {
        $this->configureProvider(
            UnknownSupersessionMigrationProviderFixture::class
        );
        $this->expectException(RuntimeException::class);

        $this->catalog();
    }

    public function testSupersessionIsCanonicalAndNeedsItsOwnVerifier(): void
    {
        $baseArguments = [
            'id' => '0002_checksum',
            'description' => 'Contrato de supersesion.',
            'statementsByDriver' => [
                'mysql' => ['CREATE TABLE IF NOT EXISTS {{table:x}} (id BIGINT)'],
                'sqlite' => ['CREATE TABLE IF NOT EXISTS {{table:x}} (id INTEGER)'],
            ],
            'destructive' => false,
            'transactionalDrivers' => ['sqlite'],
            'retrySafe' => true,
            'postconditionVerifier' => new MutableMigrationPostconditionFixture(),
        ];
        $first = MigrationDefinition::sql(
            ...$baseArguments,
            supersedesPostconditions: ['0001_b', '0001_a']
        );
        $same = MigrationDefinition::sql(
            ...$baseArguments,
            supersedesPostconditions: ['0001_a', '0001_b']
        );
        self::assertSame($first->checksum(), $same->checksum());

        $this->expectException(InvalidArgumentException::class);
        MigrationDefinition::sql(
            id: '0002_without_verifier',
            description: 'Contrato invalido.',
            statementsByDriver: $baseArguments['statementsByDriver'],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            supersedesPostconditions: ['0001_old']
        );
    }

    private function definition(
        MigrationPostconditionVerifierInterface $verifier,
        string $description = 'Comprueba el checksum.'
    ): MigrationDefinition {
        return MigrationDefinition::sql(
            id: '0001_contract_checksum',
            description: $description,
            statementsByDriver: [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {{table:checksum}} (id BIGINT PRIMARY KEY)',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {{table:checksum}} (id INTEGER PRIMARY KEY)',
                ],
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier: $verifier
        );
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
            $this->root
        ));
    }

    /** @param class-string<MigrationProviderInterface> $provider */
    private function configureProvider(string $provider): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/modules/webadmin/module.json',
            json_encode([
                'schema' => 1,
                'id' => 'webadmin',
                'package' => 'liquidstack/webadmin',
                'requires' => [],
                'providers' => ['migrations' => [$provider]],
                'project_files' => [],
            ], JSON_THROW_ON_ERROR)
        );
    }

    private function scopes(): MigrationScopeCollection
    {
        return MigrationScopeCollection::fromTablePrefixes([
            'webadmin' => 'ls_webadmin_',
        ]);
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
        return $pdo->query(
            "SELECT name, sql FROM sqlite_master WHERE type = 'table' ORDER BY name"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
