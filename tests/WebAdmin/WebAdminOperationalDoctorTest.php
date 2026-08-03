<?php

declare(strict_types=1);

use App\Core\Composer\MigrationCommandRuntime;
use App\Core\Composer\MigrationCommandRuntimeFactoryInterface;
use App\Core\Modules\Diagnostics\ModuleDoctor;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class OperationalDoctorRuntimeFactory implements
    MigrationCommandRuntimeFactoryInterface
{
    public function __construct(
        private readonly ?MigrationCommandRuntime $runtime,
        private readonly bool $fail = false
    ) {
    }

    public function create(
        string $projectRoot,
        string $coreRoot
    ): MigrationCommandRuntime {
        if ($this->fail || $this->runtime === null) {
            throw new RuntimeException(
                'sensitive-dsn-and-password-must-not-leak'
            );
        }

        return $this->runtime;
    }
}

final class WebAdminOperationalDoctorTest extends TestCase
{
    private string $projectRoot;
    private string $coreRoot;
    private Filesystem $filesystem;
    private PDO $pdo;
    private MigrationCatalog $catalog;
    private MigrationScopeCollection $scopes;
    private string $previousExceptionTraceSetting;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite es necesario para estas pruebas.');
        }

        $this->previousExceptionTraceSetting = (string) ini_get(
            'zend.exception_ignore_args'
        );
        ini_set('zend.exception_ignore_args', '1');
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-operational-doctor-'
            . bin2hex(random_bytes(8));
        $this->coreRoot = dirname(__DIR__, 2);
        $this->filesystem->mkdir([
            $this->projectRoot . '/App/config',
            $this->projectRoot . '/src/scss',
        ]);
        $this->filesystem->mirror(
            $this->coreRoot . '/modules/webadmin/published/assets',
            $this->projectRoot . '/public/assets/modules/webadmin'
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode([
                'require' => [
                    'liquidstack/core' => '^1.9',
                    'liquidstack/webadmin' => '*',
                ],
            ], JSON_THROW_ON_ERROR)
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/config.php',
            "<?php\n"
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/src/scss/_config.scss',
            '$color00: #fff;' . PHP_EOL
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/.env',
            implode("\n", [
                'BBDD_SERVER=localhost',
                'BBDD_USER=fixture',
                'BBDD_PASS=sensitive-db-password-must-not-leak',
                'BBDD_NAME=fixture',
                WebAdminConfig::SECURITY_KEY_ENV . '=' . $this->securityKey(),
                'LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL=superadmin@example.invalid',
                'LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL=admin@example.invalid',
                '',
            ])
        );

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $registry = ModuleRegistry::forProject(
            $this->projectRoot,
            $this->coreRoot
        );
        $this->catalog = MigrationCatalog::fromRegistry($registry);
        $this->scopes = MigrationScopeCollection::fromTablePrefixes([
            'webadmin' => WebAdminConfig::DEFAULT_TABLE_PREFIX,
        ]);
        (new MigrationRunner())->apply(
            $this->pdo,
            $this->catalog,
            $this->scopes
        );
    }

    protected function tearDown(): void
    {
        ini_set(
            'zend.exception_ignore_args',
            $this->previousExceptionTraceSetting
        );
        $this->filesystem->remove($this->projectRoot);
    }

    public function testDoctorReportsOperationalRuntimeAndBootstrapReadiness(): void
    {
        $before = $this->databaseSnapshot();
        $runtime = new MigrationCommandRuntime(
            $this->pdo,
            $this->catalog,
            $this->scopes
        );
        $report = (new ModuleDoctor(
            migrationRuntimeFactory: new OperationalDoctorRuntimeFactory(
                $runtime
            )
        ))->inspect($this->projectRoot, $this->coreRoot);
        $payload = $report->toArray();
        $webAdmin = $payload['module_diagnostics']['webadmin'];

        self::assertTrue($report->isHealthy());
        self::assertTrue($webAdmin['readiness']['runtime_ready']);
        self::assertTrue($webAdmin['readiness']['bootstrap_ready']);
        self::assertSame(
            'connected',
            $webAdmin['readiness']['database_connection']
        );
        self::assertSame('applied', $webAdmin['readiness']['migrations']);
        self::assertSame('sqlite', $webAdmin['database']['connection']['driver']);
        self::assertSame([
            'public/assets/modules/webadmin/webadmin.css',
            'public/assets/modules/webadmin/webadmin.js',
        ], $webAdmin['assets']['required']);
        self::assertContains(
            'webadmin.runtime.password_policy',
            array_column($payload['checks'], 'id')
        );
        self::assertSame($before, $this->databaseSnapshot());

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(
            'sensitive-db-password-must-not-leak',
            $encoded
        );
        self::assertStringNotContainsString($this->securityKey(), $encoded);
        self::assertStringNotContainsString(
            'superadmin@example.invalid',
            $encoded
        );
    }

    public function testConnectionFailureIsGenericAndBlocksBothScopes(): void
    {
        $report = (new ModuleDoctor(
            migrationRuntimeFactory: new OperationalDoctorRuntimeFactory(
                null,
                true
            )
        ))->inspect($this->projectRoot, $this->coreRoot);
        $payload = $report->toArray();
        $webAdmin = $payload['module_diagnostics']['webadmin'];
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        self::assertFalse($report->isHealthy());
        self::assertFalse($webAdmin['readiness']['runtime_ready']);
        self::assertFalse($webAdmin['readiness']['bootstrap_ready']);
        self::assertSame(
            'unavailable',
            $webAdmin['readiness']['database_connection']
        );
        self::assertContains(
            'webadmin.database.connection',
            array_column($payload['checks'], 'id')
        );
        self::assertStringNotContainsString(
            'sensitive-dsn-and-password-must-not-leak',
            $encoded
        );
    }

    public function testMissingRuntimeAssetBlocksWebAdminReadiness(): void
    {
        $this->filesystem->remove(
            $this->projectRoot
                . '/public/assets/modules/webadmin/webadmin.js'
        );
        $before = $this->databaseSnapshot();
        $runtime = new MigrationCommandRuntime(
            $this->pdo,
            $this->catalog,
            $this->scopes
        );

        $report = (new ModuleDoctor(
            migrationRuntimeFactory: new OperationalDoctorRuntimeFactory(
                $runtime
            )
        ))->inspect($this->projectRoot, $this->coreRoot);
        $webAdmin = $report->toArray()['module_diagnostics']['webadmin'];

        self::assertFalse($report->isHealthy());
        self::assertFalse($webAdmin['assets']['ready']);
        self::assertSame(
            ['public/assets/modules/webadmin/webadmin.js'],
            $webAdmin['assets']['missing']
        );
        self::assertFalse($webAdmin['readiness']['runtime_ready']);
        self::assertSame($before, $this->databaseSnapshot());
    }

    private function securityKey(): string
    {
        return rtrim(strtr(
            base64_encode(str_repeat('O', 32)),
            '+/',
            '-_'
        ), '=');
    }

    /** @return array<string, mixed> */
    private function databaseSnapshot(): array
    {
        $schema = $this->pdo->query(
            "SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name"
        )->fetchAll(PDO::FETCH_ASSOC);
        $counts = [];
        foreach ($schema as $entry) {
            if (
                ($entry['type'] ?? null) !== 'table'
                || !is_string($entry['name'] ?? null)
                || str_starts_with($entry['name'], 'sqlite_')
            ) {
                continue;
            }
            $table = str_replace('"', '""', $entry['name']);
            $counts[$entry['name']] = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM "' . $table . '"'
            )->fetchColumn();
        }

        return ['schema' => $schema, 'counts' => $counts];
    }
}
