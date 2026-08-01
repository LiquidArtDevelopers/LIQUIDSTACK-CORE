<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDatabasePlanner;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationException;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationPreconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationProviderInterface;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class MutableMigrationPreconditionFixture implements
    MigrationPreconditionVerifierInterface
{
    /** @var list<bool> */
    public static array $results = [true];
    public static int $calls = 0;
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
        $position = self::$calls++;
        if (self::$throws) {
            throw new RuntimeException('Sensitive precondition detail.');
        }

        return self::$results[
            min($position, max(0, count(self::$results) - 1))
        ] ?? false;
    }
}

final class AlternateMigrationPreconditionFixture implements
    MigrationPreconditionVerifierInterface
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

final class ChecksumPostconditionFixture implements
    MigrationPostconditionVerifierInterface
{
    public function __construct(private readonly string $version = 'post-v1')
    {
    }

    public function contractVersion(): string
    {
        return $this->version;
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        return true;
    }
}

final class PreconditionMigrationProviderFixture implements
    MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public static function migrations(): iterable
    {
        yield MigrationDefinition::sql(
            id: '0001_precondition',
            description: 'Prueba la precondición.',
            statementsByDriver: MigrationPreconditionTest::statements(),
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            preconditionVerifier: new MutableMigrationPreconditionFixture()
        );
    }
}

final class LaterPreconditionMigrationProviderFixture implements
    MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public static function migrations(): iterable
    {
        yield MigrationDefinition::sql(
            id: '0001_base',
            description: 'Prepara la base sin precondición.',
            statementsByDriver: MigrationPreconditionTest::statements(),
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true
        );
        yield MigrationDefinition::sql(
            id: '0002_invalid_precondition',
            description: 'No puede declarar una precondición tardía.',
            statementsByDriver: MigrationPreconditionTest::statements(),
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            preconditionVerifier: new MutableMigrationPreconditionFixture()
        );
    }
}

final class MigrationPreconditionTest extends TestCase
{
    private string $root;
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite es necesario para estas pruebas.');
        }
        MutableMigrationPreconditionFixture::$results = [true];
        MutableMigrationPreconditionFixture::$calls = 0;
        MutableMigrationPreconditionFixture::$throws = false;
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-precondition-' . bin2hex(random_bytes(8));
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
                        PreconditionMigrationProviderFixture::class,
                    ],
                ],
                'project_files' => [],
            ], JSON_THROW_ON_ERROR)
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testPlannerReportsFailedPreconditionWithoutWriting(): void
    {
        MutableMigrationPreconditionFixture::$results = [false];
        $pdo = $this->sqlite();
        $before = $this->snapshot($pdo);

        $plan = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $this->catalog(),
            $this->scopes()
        );

        self::assertFalse($plan->isApplicable());
        self::assertSame(
            ['migration.precondition_failed'],
            array_column($plan->blockers(), 'code')
        );
        self::assertSame('precondition_failed', $plan->entries()[0]['status']);
        self::assertSame($before, $this->snapshot($pdo));
        self::assertSame(1, MutableMigrationPreconditionFixture::$calls);
    }

    public function testRunnerRevalidatesUnderLockBeforeAnySchemaWrite(): void
    {
        MutableMigrationPreconditionFixture::$results = [true, false];
        $pdo = $this->sqlite();

        try {
            (new MigrationRunner())->apply(
                $pdo,
                $this->catalog(),
                $this->scopes()
            );
            self::fail('La revalidación bajo lock debía bloquear el lote.');
        } catch (MigrationException $exception) {
            self::assertSame(
                'migration.precondition_failed',
                $exception->issueCode()
            );
            self::assertSame('webadmin', $exception->moduleId());
            self::assertSame('0001_precondition', $exception->migrationId());
        }

        self::assertSame(2, MutableMigrationPreconditionFixture::$calls);
        self::assertSame([], $this->snapshot($pdo));
        self::assertFalse($pdo->inTransaction());
    }

    public function testPreconditionExceptionsFailClosedWithoutDetails(): void
    {
        MutableMigrationPreconditionFixture::$throws = true;
        $pdo = $this->sqlite();

        try {
            (new MigrationRunner())->apply(
                $pdo,
                $this->catalog(),
                $this->scopes()
            );
            self::fail('La excepción debía fallar cerrada.');
        } catch (MigrationException $exception) {
            self::assertSame(
                'migration.precondition_failed',
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                'Sensitive precondition detail',
                $exception->getMessage()
            );
        }
        self::assertSame([], $this->snapshot($pdo));
    }

    public function testFreshApplyAndIdempotentReplayRemainValid(): void
    {
        $pdo = $this->sqlite();
        $runner = new MigrationRunner();
        $first = $runner->apply($pdo, $this->catalog(), $this->scopes());
        $callsAfterFirst = MutableMigrationPreconditionFixture::$calls;
        $second = $runner->apply($pdo, $this->catalog(), $this->scopes());

        self::assertTrue($first->changed());
        self::assertFalse($second->changed());
        self::assertSame(2, $callsAfterFirst);
        self::assertSame(
            $callsAfterFirst,
            MutableMigrationPreconditionFixture::$calls,
            'Una migración aplicada no vuelve a exigir su precondición inicial.'
        );
        self::assertSame(1, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_module_migrations'
        )->fetchColumn());
    }

    public function testSchemasOneToThreeKeepTheirHistoricalChecksums(): void
    {
        $statements = self::statements();
        $base = [
            'id' => '0001_checksum',
            'destructive' => false,
            'retry_safe' => true,
            'transactional_drivers' => ['sqlite'],
            'statements' => $statements,
        ];

        $schemaOne = MigrationDefinition::sql(
            id: '0001_checksum',
            description: 'Copy histórico schema 1.',
            statementsByDriver: $statements,
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true
        );
        self::assertSame($this->checksum([
            'schema' => 1,
            'id' => '0001_checksum',
            'description' => 'Copy histórico schema 1.',
            'destructive' => false,
            'retry_safe' => true,
            'transactional_drivers' => ['sqlite'],
            'statements' => $statements,
        ]), $schemaOne->checksum());

        $postcondition = new ChecksumPostconditionFixture();
        $schemaTwo = MigrationDefinition::sql(
            id: '0001_checksum',
            description: 'Copy excluido schema 2.',
            statementsByDriver: $statements,
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier: $postcondition
        );
        $postContract = [
            'class' => ChecksumPostconditionFixture::class,
            'contract_version' => 'post-v1',
        ];
        self::assertSame($this->checksum([
            'schema' => 2,
            ...$base,
            'postcondition' => $postContract,
        ]), $schemaTwo->checksum());

        $schemaThree = MigrationDefinition::sql(
            id: '0001_checksum',
            description: 'Copy excluido schema 3.',
            statementsByDriver: $statements,
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier: $postcondition,
            supersedesPostconditions: ['0000_b', '0000_a']
        );
        self::assertSame($this->checksum([
            'schema' => 3,
            ...$base,
            'postcondition' => $postContract,
            'supersedes_postconditions' => ['0000_a', '0000_b'],
        ]), $schemaThree->checksum());
    }

    public function testPreconditionClassAndVersionUseSchemaFourChecksum(): void
    {
        $first = $this->definition(new MutableMigrationPreconditionFixture('v1'));
        $same = $this->definition(new MutableMigrationPreconditionFixture('v1'));
        $version = $this->definition(new MutableMigrationPreconditionFixture('v2'));
        $class = $this->definition(new AlternateMigrationPreconditionFixture());
        $copy = $this->definition(
            new MutableMigrationPreconditionFixture('v1'),
            'Copy informativo diferente.'
        );

        self::assertSame($first->checksum(), $same->checksum());
        self::assertNotSame($first->checksum(), $version->checksum());
        self::assertNotSame($first->checksum(), $class->checksum());
        self::assertSame($first->checksum(), $copy->checksum());
        self::assertSame($this->checksum([
            'schema' => 4,
            'id' => '0001_checksum',
            'destructive' => false,
            'retry_safe' => true,
            'transactional_drivers' => ['sqlite'],
            'statements' => self::statements(),
            'precondition' => [
                'class' => MutableMigrationPreconditionFixture::class,
                'contract_version' => 'v1',
            ],
        ]), $first->checksum());
        self::assertInstanceOf(
            MigrationPreconditionVerifierInterface::class,
            $first->preconditionVerifier()
        );
    }

    public function testInvalidPreconditionVersionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->definition(
            new MutableMigrationPreconditionFixture('invalid version')
        );
    }

    public function testCatalogRejectsPreconditionsAfterInitialMigration(): void
    {
        $this->configureProvider(
            LaterPreconditionMigrationProviderFixture::class
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'solo puede declararse en su migracion inicial'
        );

        $this->catalog();
    }

    /** @return array{mysql: list<string>, sqlite: list<string>} */
    public static function statements(): array
    {
        return [
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS {{table:ready}} '
                    . '(id BIGINT PRIMARY KEY)',
            ],
            'sqlite' => [
                'CREATE TABLE IF NOT EXISTS {{table:ready}} '
                    . '(id INTEGER PRIMARY KEY)',
            ],
        ];
    }

    private function definition(
        MigrationPreconditionVerifierInterface $precondition,
        string $description = 'Checksum con precondición.'
    ): MigrationDefinition {
        return MigrationDefinition::sql(
            id: '0001_checksum',
            description: $description,
            statementsByDriver: self::statements(),
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            preconditionVerifier: $precondition
        );
    }

    /** @param array<string, mixed> $contract */
    private function checksum(array $contract): string
    {
        return hash('sha256', json_encode(
            $contract,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        ));
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
                'providers' => [
                    'migrations' => [$provider],
                ],
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

    /** @return array<string, string> */
    private function snapshot(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT type || ':' || name, COALESCE(sql, '') "
            . 'FROM sqlite_master WHERE name NOT LIKE \'sqlite_%\' '
            . 'ORDER BY type, name'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
