<?php

declare(strict_types=1);

use App\Core\Composer\Command\MigrateCommand;
use App\Core\Composer\MigrationCommandRuntime;
use App\Core\Composer\MigrationCommandRuntimeFactory;
use App\Core\Composer\MigrationCommandRuntimeFactoryInterface;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationProviderInterface;
use Composer\Console\Application as ComposerApplication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class CliWebAdminMigrationProviderFixture implements
    MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public static function migrations(): iterable
    {
        yield MigrationDefinition::sql(
            id: '0001_create_users',
            description: 'Crea usuarios WebAdmin.',
            statementsByDriver: [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {{table:users}} (id BIGINT PRIMARY KEY)',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {{table:users}} (id INTEGER PRIMARY KEY)',
                ],
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true
        );
    }
}

final class CliBlogMigrationProviderFixture implements
    MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'blog';
    }

    public static function migrations(): iterable
    {
        yield MigrationDefinition::sql(
            id: '0001_create_posts',
            description: 'Crea entradas de blog.',
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

final class CliDestructiveMigrationProviderFixture implements
    MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public static function migrations(): iterable
    {
        yield MigrationDefinition::sql(
            id: '0001_destructive',
            description: 'Cambio destructivo controlado.',
            statementsByDriver: [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {{table:destructive}} (id BIGINT PRIMARY KEY)',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {{table:destructive}} (id INTEGER PRIMARY KEY)',
                ],
            ],
            destructive: true,
            transactionalDrivers: ['sqlite'],
            retrySafe: true
        );
    }
}

final class CountingCliMigrationRuntimeFactoryFixture implements
    MigrationCommandRuntimeFactoryInterface
{
    public int $calls = 0;

    public function create(
        string $projectRoot,
        string $coreRoot
    ): MigrationCommandRuntime {
        $this->calls++;
        throw new RuntimeException('This factory must not be called.');
    }
}

final class LiquidStackMigrationCommandTest extends TestCase
{
    private string $root;
    private string $projectRoot;
    private string $databasePath;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite es necesario para probar el CLI.');
        }

        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-migration-cli-'
            . bin2hex(random_bytes(8));
        $this->projectRoot = $this->root . '/project';
        $this->databasePath = $this->root . '/database.sqlite';
        $this->filesystem->mkdir([
            $this->projectRoot . '/App/config/modules',
            $this->root . '/modules/webadmin',
            $this->root . '/modules/blog',
        ]);
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode([
                'require' => [
                    'liquidstack/core' => '^1.9',
                    'liquidstack/blog' => '*',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/.env',
            "BBDD_SERVER=must-not-leak-host\n"
                . "BBDD_USER=must-not-leak-user\n"
                . "BBDD_PASS=must-not-leak-password\n"
                . "BBDD_NAME=must_not_leak_database\n"
                . "CLI_PRIVATE_FIXTURE=must-not-leak-private\n"
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/langs.php',
            "<?php\n\nreturn ['es', 'en'];\n"
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/modules/webadmin.php',
            "<?php\nreturn [\n"
                . "    'database' => ['table_prefix' => 'custom_webadmin_'],\n"
                . "];\n"
        );
        $this->writeManifest(
            'webadmin',
            [],
            CliWebAdminMigrationProviderFixture::class
        );
        $this->writeManifest(
            'blog',
            ['webadmin'],
            CliBlogMigrationProviderFixture::class
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testDryRunReadsDatabaseStateWithoutMutationOrLeaks(): void
    {
        $tester = $this->tester();
        $before = $this->tables();

        $status = $tester->execute([
            '--dry-run' => true,
            '--format' => 'json',
        ]);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame($before, $this->tables());
        self::assertSame('migrate-dry-run', $payload['operation']);
        self::assertTrue($payload['migrations']['read_only']);
        self::assertSame(2, $payload['migrations']['counts']['pending']);
        self::assertSame(
            'registry_missing',
            $payload['migrations']['database_state']
        );
        self::assertStringNotContainsString(
            'must-not-leak',
            $tester->getDisplay()
        );
        self::assertStringNotContainsString(
            'CREATE TABLE',
            $tester->getDisplay()
        );
    }

    public function testCatalogPlanNeverBuildsTheDatabaseRuntime(): void
    {
        $factory = new CountingCliMigrationRuntimeFactoryFixture();
        $tester = $this->tester($factory);

        $tester->execute([
            '--plan' => true,
            '--format' => 'json',
        ]);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(0, $factory->calls);
        self::assertSame('migrate-plan', $payload['operation']);
        self::assertSame(
            'not_evaluated',
            $payload['migrations']['database_state']
        );
        self::assertSame([], $this->tables());
    }

    public function testJsonApplyRequiresYesBeforeOpeningRuntime(): void
    {
        $factory = new CountingCliMigrationRuntimeFactoryFixture();
        $tester = $this->tester($factory);

        $status = $tester->execute([
            '--apply' => true,
            '--format' => 'json',
        ]);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::INVALID, $status);
        self::assertSame(0, $factory->calls);
        self::assertSame(
            'migrate.json_apply_requires_yes',
            $payload['error']['code']
        );
    }

    public function testNonInteractiveApplyWithoutYesDoesNotMutate(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(
            ['--apply' => true],
            ['interactive' => false]
        );

        self::assertSame(Command::FAILURE, $status);
        self::assertSame([], $this->tables());
        self::assertStringContainsString(
            'migrate.confirmation_required',
            $tester->getDisplay()
        );
    }

    public function testInteractiveConfirmationAppliesPreviewedHashAndIsIdempotent(): void
    {
        $dryRun = $this->tester();
        self::assertSame(Command::SUCCESS, $dryRun->execute([
            '--dry-run' => true,
            '--format' => 'json',
        ]));
        $preview = json_decode(
            $dryRun->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $apply = $this->tester();
        $apply->setInputs(['yes']);
        self::assertSame(Command::SUCCESS, $apply->execute([
            '--apply' => true,
        ]));
        self::assertContains(
            'custom_webadmin_users',
            $this->tables()
        );
        self::assertContains('ls_blog_posts', $this->tables());
        self::assertContains('ls_module_migrations', $this->tables());

        $second = $this->tester();
        self::assertSame(Command::SUCCESS, $second->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
        ]));
        $result = json_decode(
            $second->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertFalse($result['changed']);
        self::assertSame([], $result['applied']);
        self::assertNotSame(
            $preview['migrations']['plan_hash'],
            $result['plan_hash'],
            'El segundo plan refleja que las migraciones ya están aplicadas.'
        );
    }

    public function testJsonApplyReturnsTheExactPreviewHash(): void
    {
        $dryRun = $this->tester();
        self::assertSame(Command::SUCCESS, $dryRun->execute([
            '--dry-run' => true,
            '--format' => 'json',
        ]));
        $preview = json_decode(
            $dryRun->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $apply = $this->tester();
        self::assertSame(Command::SUCCESS, $apply->execute([
            '--apply' => true,
            '--yes' => true,
            '--format' => 'json',
            '--lock-timeout' => '0',
        ]));
        $result = json_decode(
            $apply->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(
            $preview['migrations']['plan_hash'],
            $result['plan_hash']
        );
        self::assertTrue($result['changed']);
        self::assertCount(2, $result['applied']);
    }

    public function testDestructiveApplyNeedsBothIndependentGates(): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode([
                'require' => [
                    'liquidstack/core' => '^1.9',
                    'liquidstack/webadmin' => '*',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
        $this->writeManifest(
            'webadmin',
            [],
            CliDestructiveMigrationProviderFixture::class
        );

        $withoutAllow = $this->tester();
        self::assertSame(Command::FAILURE, $withoutAllow->execute([
            '--apply' => true,
            '--yes' => true,
        ]));
        self::assertStringContainsString(
            'migrations.destructive_not_allowed',
            $withoutAllow->getDisplay()
        );
        self::assertSame([], $this->tables());

        $withoutBackup = $this->tester();
        self::assertSame(Command::FAILURE, $withoutBackup->execute([
            '--apply' => true,
            '--yes' => true,
            '--allow-destructive' => true,
        ]));
        self::assertStringContainsString(
            'migrations.backup_not_confirmed',
            $withoutBackup->getDisplay()
        );
        self::assertSame([], $this->tables());

        $apply = $this->tester();
        self::assertSame(Command::SUCCESS, $apply->execute([
            '--apply' => true,
            '--yes' => true,
            '--allow-destructive' => true,
            '--backup-confirmed' => true,
        ]));
        self::assertContains(
            'custom_webadmin_destructive',
            $this->tables()
        );
    }

    public function testModesAndApplyOnlyOptionsAreStrictlyValidated(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::INVALID, $tester->execute([
            '--plan' => true,
            '--dry-run' => true,
        ]));

        $tester = $this->tester();
        self::assertSame(Command::INVALID, $tester->execute([
            '--dry-run' => true,
            '--yes' => true,
        ]));

        $tester = $this->tester();
        self::assertSame(Command::INVALID, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--lock-timeout' => '301',
        ]));

        $tester = $this->tester();
        self::assertSame(Command::INVALID, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--backup-confirmed' => true,
        ]));
        self::assertSame([], $this->tables());
    }

    public function testRuntimeFailureNeverExposesConnectorDetails(): void
    {
        $factory = new MigrationCommandRuntimeFactory(
            connectionFactoryResolver: static fn (array $environment):
                PdoConnectionFactoryInterface =>
                new class implements PdoConnectionFactoryInterface {
                    public function connect(): PDO
                    {
                        throw new RuntimeException(
                            'PDO says must-not-leak-password and raw DSN'
                        );
                    }
                }
        );
        $tester = $this->tester($factory);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--dry-run' => true,
            '--format' => 'json',
        ]));
        self::assertStringNotContainsString(
            'must-not-leak',
            $tester->getDisplay()
        );
        self::assertStringNotContainsString(
            'PDO says',
            $tester->getDisplay()
        );
        self::assertStringContainsString(
            'migrate.runtime_unavailable',
            $tester->getDisplay()
        );
    }

    private function tester(
        ?MigrationCommandRuntimeFactoryInterface $factory = null
    ): CommandTester {
        $command = new MigrateCommand(
            $this->projectRoot,
            $this->root,
            null,
            $factory ?? $this->sqliteRuntimeFactory()
        );
        $application = new ComposerApplication();
        $application->setAutoExit(false);
        $application->add($command);

        return new CommandTester($command);
    }

    private function sqliteRuntimeFactory(): MigrationCommandRuntimeFactory
    {
        $databasePath = $this->databasePath;

        return new MigrationCommandRuntimeFactory(
            connectionFactoryResolver: static fn (array $environment):
                PdoConnectionFactoryInterface =>
                new class($databasePath) implements
                    PdoConnectionFactoryInterface {
                    public function __construct(
                        private readonly string $databasePath
                    ) {
                    }

                    public function connect(): PDO
                    {
                        $pdo = new PDO('sqlite:' . $this->databasePath);
                        $pdo->setAttribute(
                            PDO::ATTR_ERRMODE,
                            PDO::ERRMODE_EXCEPTION
                        );
                        $pdo->setAttribute(
                            PDO::ATTR_DEFAULT_FETCH_MODE,
                            PDO::FETCH_ASSOC
                        );
                        $pdo->exec('PRAGMA foreign_keys = ON');

                        return $pdo;
                    }
                }
        );
    }

    /** @return list<string> */
    private function tables(): array
    {
        if (!file_exists($this->databasePath)) {
            return [];
        }
        $pdo = new PDO('sqlite:' . $this->databasePath);
        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_filter(
            is_array($tables) ? $tables : [],
            static fn (mixed $table): bool => is_string($table)
                && !str_starts_with($table, 'sqlite_')
        ));
    }

    /** @param list<string> $requires */
    private function writeManifest(
        string $id,
        array $requires,
        string $migrationProvider
    ): void {
        $this->filesystem->dumpFile(
            $this->root . '/modules/' . $id . '/module.json',
            json_encode([
                'schema' => 1,
                'id' => $id,
                'package' => 'liquidstack/' . $id,
                'requires' => $requires,
                'providers' => [
                    'migrations' => [$migrationProvider],
                ],
                'project_files' => [],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
    }
}
