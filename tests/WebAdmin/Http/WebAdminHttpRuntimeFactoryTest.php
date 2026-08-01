<?php

declare(strict_types=1);

use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Modules\Migrations\MigrationApplyOptions;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDatabasePlanner;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Http\WebAdminHttpRuntime;
use App\Core\WebAdmin\Http\WebAdminHttpRuntimeException;
use App\Core\WebAdmin\Http\WebAdminHttpRuntimeFactory;
use App\Core\WebAdmin\UserManagement\ActiveModuleSet;
use App\Core\WebAdmin\UserManagement\UserManagementService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class HttpRuntimePdoFactory implements PdoConnectionFactoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function connect(): PDO
    {
        return $this->pdo;
    }
}

final class WebAdminHttpRuntimeFactoryTest extends TestCase
{
    private string $projectRoot;
    private Filesystem $filesystem;
    private PDO $pdo;
    private string $previousExceptionTraceSetting;

    protected function setUp(): void
    {
        $this->previousExceptionTraceSetting = (string) ini_get(
            'zend.exception_ignore_args'
        );
        ini_set('zend.exception_ignore_args', '1');
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-webadmin-http-runtime-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectRoot);
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode([
                'require' => [
                    'liquidstack/core' => '^1.9',
                    'liquidstack/webadmin' => '*',
                ],
            ], JSON_THROW_ON_ERROR)
        );
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    protected function tearDown(): void
    {
        ini_set(
            'zend.exception_ignore_args',
            $this->previousExceptionTraceSetting
        );
        $this->filesystem->remove($this->projectRoot);
    }

    public function testRuntimeRequiresAppliedAndVerifiedSchema(): void
    {
        $factory = $this->factory();
        $context = new ModuleRuntimeContext(
            $this->projectRoot,
            $this->environment()
        );

        try {
            $factory->create($context, WebAdminConfig::defaults());
            self::fail('A pending schema must not reach authentication.');
        } catch (WebAdminHttpRuntimeException $exception) {
            self::assertSame(
                'webadmin.schema_not_ready',
                $exception->issueCode()
            );
        }
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'"
        )->fetchColumn());

        $this->applyMigrations();

        $runtime = $factory->create(
            $context,
            WebAdminConfig::defaults()
        );
        self::assertSame(
            WebAdminConfig::DEFAULT_COOKIE_NAME,
            $runtime->config()->cookieName()
        );
        self::assertInstanceOf(
            UserManagementService::class,
            $runtime->userManagement()
        );
        self::assertSame(
            ['webadmin'],
            $this->activeModules($runtime->userManagement())->ids()
        );
        self::assertNull(
            $runtime->userManagement()->listEditors('invalid-session-token')
        );
    }

    public function testUserManagementReceivesEveryModuleEnabledByRegistry(): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode([
                'require' => [
                    'liquidstack/core' => '^1.9',
                    'liquidstack/blog' => '*',
                ],
            ], JSON_THROW_ON_ERROR)
        );
        $this->applyMigrations();

        $runtime = $this->factory()->create(
            new ModuleRuntimeContext(
                $this->projectRoot,
                $this->environment()
            ),
            WebAdminConfig::defaults()
        );

        self::assertSame(
            ['blog', 'webadmin'],
            $this->activeModules($runtime->userManagement())->ids()
        );
    }

    public function testRuntimeFailsClosedWhenUserManagementWasNotComposed(): void
    {
        $this->applyMigrations();
        $composed = $this->factory()->create(
            new ModuleRuntimeContext(
                $this->projectRoot,
                $this->environment()
            ),
            WebAdminConfig::defaults()
        );
        $legacy = new WebAdminHttpRuntime(
            $composed->config(),
            $composed->authentication(),
            $composed->authorization(),
            $composed->credentialActions()
        );
        self::assertSame([], $legacy->navigation()->items());

        try {
            $legacy->userManagement();
            self::fail('A missing user-management service must fail closed.');
        } catch (WebAdminHttpRuntimeException $exception) {
            self::assertSame(
                'webadmin.user_management_unavailable',
                $exception->issueCode()
            );
        }
    }

    public function testInvalidSecurityKeyFailsBeforeConnectingAndNeverLeaks(): void
    {
        $calls = 0;
        $factory = new WebAdminHttpRuntimeFactory(
            dirname(__DIR__, 3),
            static function () use (&$calls): PdoConnectionFactoryInterface {
                ++$calls;
                throw new RuntimeException('must not connect');
            }
        );
        $context = new ModuleRuntimeContext($this->projectRoot, [
            WebAdminHttpRuntimeFactory::SECURITY_KEY_ENV
                => 'invalid-secret-key-must-not-leak',
        ]);

        try {
            $factory->create($context, WebAdminConfig::defaults());
            self::fail('The invalid key must fail closed.');
        } catch (WebAdminHttpRuntimeException $exception) {
            self::assertSame(
                'webadmin.security_key_invalid',
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                'invalid-secret-key-must-not-leak',
                $exception->getMessage()
            );
        }
        self::assertSame(0, $calls);
    }

    public function testRuntimePropagatesLiquidStackAsSecondResolverArgument(): void
    {
        $this->writeModuleDatabaseConfig('webadmin', 'liquidstack');
        $receivedConnection = null;
        $resolverCalls = 0;
        $factory = new WebAdminHttpRuntimeFactory(
            dirname(__DIR__, 3),
            function (
                array $_environment,
                string $connection
            ) use (
                &$receivedConnection,
                &$resolverCalls
            ): PdoConnectionFactoryInterface {
                ++$resolverCalls;
                $receivedConnection = $connection;

                return new HttpRuntimePdoFactory($this->pdo);
            }
        );

        try {
            $factory->create(
                new ModuleRuntimeContext(
                    $this->projectRoot,
                    $this->environment()
                ),
                $this->liquidStackWebAdminConfig()
            );
            self::fail('El esquema pendiente debía bloquear el runtime.');
        } catch (WebAdminHttpRuntimeException $exception) {
            self::assertSame(
                'webadmin.schema_not_ready',
                $exception->issueCode()
            );
        }
        self::assertSame(1, $resolverCalls);
        self::assertSame('liquidstack', $receivedConnection);
    }

    public function testModuleConnectionMismatchFailsBeforeResolverAndConnector(): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode(['require' => [
                'liquidstack/core' => '^1.9',
                'liquidstack/blog' => '*',
            ]], JSON_THROW_ON_ERROR)
        );
        $this->writeModuleDatabaseConfig('webadmin', 'liquidstack');
        $this->writeModuleDatabaseConfig('blog', 'shared');
        $resolverCalls = 0;
        $factory = new WebAdminHttpRuntimeFactory(
            dirname(__DIR__, 3),
            static function () use (
                &$resolverCalls
            ): PdoConnectionFactoryInterface {
                ++$resolverCalls;
                throw new RuntimeException('Must not resolve or connect.');
            }
        );

        try {
            $factory->create(
                new ModuleRuntimeContext(
                    $this->projectRoot,
                    $this->environment()
                ),
                $this->liquidStackWebAdminConfig()
            );
            self::fail('El mismatch debía fallar antes del resolver PDO.');
        } catch (WebAdminHttpRuntimeException $exception) {
            self::assertSame(
                'webadmin.runtime_unavailable',
                $exception->issueCode()
            );
        }
        self::assertSame(0, $resolverCalls);
    }

    public function testRuntimeRejectsRegisteredSchemaWhosePostconditionDrifted(): void
    {
        $this->applyMigrations();
        $this->pdo->exec(
            "UPDATE ls_webadmin_roles SET label_key = 'drifted' "
            . "WHERE code = 'editor'"
        );

        try {
            $this->factory()->create(
                new ModuleRuntimeContext(
                    $this->projectRoot,
                    $this->environment()
                ),
                WebAdminConfig::defaults()
            );
            self::fail('A registered but drifted schema must fail closed.');
        } catch (WebAdminHttpRuntimeException $exception) {
            self::assertSame(
                'webadmin.schema_not_ready',
                $exception->issueCode()
            );
        }
    }

    private function factory(): WebAdminHttpRuntimeFactory
    {
        return new WebAdminHttpRuntimeFactory(
            dirname(__DIR__, 3),
            fn (): PdoConnectionFactoryInterface =>
                new HttpRuntimePdoFactory($this->pdo)
        );
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        $key = rtrim(strtr(
            base64_encode(str_repeat('R', 32)),
            '+/',
            '-_'
        ), '=');

        return [WebAdminHttpRuntimeFactory::SECURITY_KEY_ENV => $key];
    }

    private function liquidStackWebAdminConfig(): WebAdminConfig
    {
        return new WebAdminConfig(
            WebAdminConfig::DEFAULT_BASE_PATH,
            WebAdminConfig::DEFAULT_TABLE_PREFIX,
            WebAdminConfig::DEFAULT_COOKIE_NAME,
            WebAdminConfig::DEFAULT_IDLE_TTL_SECONDS,
            WebAdminConfig::DEFAULT_ABSOLUTE_TTL_SECONDS,
            'test',
            'liquidstack'
        );
    }

    private function writeModuleDatabaseConfig(
        string $module,
        string $connection
    ): void {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/modules/' . $module . '.php',
            "<?php\nreturn ['database' => ["
                . "'connection' => '" . $connection . "']];\n"
        );
    }

    /** @return array{MigrationCatalog, MigrationScopeCollection} */
    private function migrationContext(): array
    {
        $registry = ModuleRegistry::forProject(
            $this->projectRoot,
            dirname(__DIR__, 3)
        );

        $prefixes = [
            'webadmin' => WebAdminConfig::DEFAULT_TABLE_PREFIX,
        ];
        if ($registry->isEnabled('blog')) {
            $prefixes['blog'] = 'ls_blog_';
        }

        return [
            MigrationCatalog::fromRegistry($registry),
            MigrationScopeCollection::fromTablePrefixes($prefixes),
        ];
    }

    private function applyMigrations(): void
    {
        [$catalog, $scopes] = $this->migrationContext();
        $preview = (new MigrationDatabasePlanner())->plan(
            $this->pdo,
            $catalog,
            $scopes
        );
        (new MigrationRunner())->apply(
            $this->pdo,
            $catalog,
            $scopes,
            new MigrationApplyOptions(expectedPlanHash: $preview->hash())
        );
    }

    private function activeModules(
        UserManagementService $service
    ): ActiveModuleSet {
        $property = new ReflectionProperty(
            UserManagementService::class,
            'activeModules'
        );
        $value = $property->getValue($service);
        self::assertInstanceOf(ActiveModuleSet::class, $value);

        return $value;
    }
}
