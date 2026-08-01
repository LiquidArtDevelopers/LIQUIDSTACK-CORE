<?php

declare(strict_types=1);

use App\Core\Composer\Command\WebAdminMailDispatchCommand;
use App\Core\Composer\WebAdminMailDispatchCommandRuntimeFactory;
use App\Core\Composer\WebAdminMailDispatchCommandRuntimeException;
use App\Core\Composer\WebAdminMailDispatchCommandRuntimeFactoryInterface;
use App\Core\Composer\WebAdminMailDispatchCommandRuntimeInterface;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Modules\Migrations\MigrationApplyOptions;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDatabasePlanner;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use App\Core\WebAdmin\Mail\WebAdminMailConfiguration;
use App\Core\WebAdmin\Mail\WebAdminMailMessage;
use App\Core\WebAdmin\Mail\WebAdminMailTransportInterface;
use App\Core\WebAdmin\Outbox\WebAdminOutboxDispatchReport;
use Composer\Console\Application as ComposerApplication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class MailDispatchCliRuntimeFixture implements
    WebAdminMailDispatchCommandRuntimeInterface
{
    public int $calls = 0;
    public ?int $limit = null;

    public function __construct(
        private readonly WebAdminOutboxDispatchReport|Throwable $outcome
    ) {
    }

    public function dispatch(int $limit): WebAdminOutboxDispatchReport
    {
        ++$this->calls;
        $this->limit = $limit;
        if ($this->outcome instanceof Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}

final class MailDispatchCliRuntimeFactoryFixture implements
    WebAdminMailDispatchCommandRuntimeFactoryInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly WebAdminMailDispatchCommandRuntimeInterface|Throwable $outcome
    ) {
    }

    public function create(
        string $projectRoot,
        string $coreRoot
    ): WebAdminMailDispatchCommandRuntimeInterface {
        ++$this->calls;
        if ($this->outcome instanceof Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}

final class MailDispatchCliPdoFactoryFixture implements
    PdoConnectionFactoryInterface
{
    public int $connectCalls = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function connect(): PDO
    {
        ++$this->connectCalls;

        return $this->pdo;
    }
}

final class MailDispatchCliTransportFixture implements
    WebAdminMailTransportInterface
{
    /** @var list<WebAdminMailMessage> */
    public array $messages = [];

    public function __construct(private readonly ?Closure $onSend = null)
    {
    }

    public function send(WebAdminMailMessage $message): void
    {
        $this->messages[] = $message;
        if ($this->onSend !== null) {
            ($this->onSend)($message);
        }
    }
}

final class WebAdminMailDispatchCommandTest extends TestCase
{
    private const MANAGED_ENVIRONMENT_NAMES = [
        'RAIZ',
        'DEV_MODE',
        'BBDD_SERVER',
        'BBDD_USER',
        'BBDD_PASS',
        'BBDD_NAME',
        'LIQUIDSTACK_DB_HOST',
        'LIQUIDSTACK_DB_PORT',
        'LIQUIDSTACK_DB_NAME',
        'LIQUIDSTACK_DB_USER',
        'LIQUIDSTACK_DB_PASSWORD',
        'LIQUIDSTACK_DB_CHARSET',
        WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV,
        WebAdminMailConfiguration::TRANSPORT_ENV,
        WebAdminMailConfiguration::SMTP_HOST_ENV,
        WebAdminMailConfiguration::SMTP_PORT_ENV,
        WebAdminMailConfiguration::SMTP_ENCRYPTION_ENV,
        WebAdminMailConfiguration::SMTP_USERNAME_ENV,
        WebAdminMailConfiguration::SMTP_PASSWORD_ENV,
        WebAdminMailConfiguration::FROM_ADDRESS_ENV,
        WebAdminMailConfiguration::FROM_NAME_ENV,
    ];

    private Filesystem $filesystem;
    private string $temporaryRoot;
    private string $previousExceptionIgnoreArgs;

    /**
     * @var array<string, array{
     *     process: string|false,
     *     env_exists: bool,
     *     env_value: mixed
     * }>
     */
    private array $environmentBackup = [];

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->temporaryRoot = sys_get_temp_dir()
            . '/liquidstack-webadmin-mail-cli-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->temporaryRoot);
        $this->previousExceptionIgnoreArgs = (string) ini_get(
            'zend.exception_ignore_args'
        );
        self::assertNotFalse(ini_set('zend.exception_ignore_args', '1'));

        foreach (self::MANAGED_ENVIRONMENT_NAMES as $name) {
            $this->environmentBackup[$name] = [
                'process' => getenv($name),
                'env_exists' => array_key_exists($name, $_ENV),
                'env_value' => $_ENV[$name] ?? null,
            ];
            putenv($name);
            unset($_ENV[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environmentBackup as $name => $backup) {
            if ($backup['process'] === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $backup['process']);
            }
            if ($backup['env_exists']) {
                $_ENV[$name] = $backup['env_value'];
            } else {
                unset($_ENV[$name]);
            }
        }
        ini_set(
            'zend.exception_ignore_args',
            $this->previousExceptionIgnoreArgs
        );
        $this->filesystem->remove($this->temporaryRoot);
    }

    public function testConstructionIsSideEffectFree(): void
    {
        $runtime = new MailDispatchCliRuntimeFixture($this->report());
        $factory = new MailDispatchCliRuntimeFactoryFixture($runtime);
        $command = new WebAdminMailDispatchCommand(
            __DIR__,
            dirname(__DIR__, 2),
            $factory
        );

        self::assertSame(0, $factory->calls);
        self::assertSame(0, $runtime->calls);
        self::assertSame(
            'liquidstack:webadmin:mail:dispatch',
            $command->getName()
        );
    }

    /** @dataProvider invalidInputProvider */
    public function testInvalidInputDoesNotBuildTheRuntime(
        array $input,
        string $expectedCode
    ): void {
        $runtime = new MailDispatchCliRuntimeFixture($this->report());
        $factory = new MailDispatchCliRuntimeFactoryFixture($runtime);
        $tester = $this->tester($factory);

        $status = $tester->execute($input);

        self::assertSame(Command::INVALID, $status);
        self::assertSame(0, $factory->calls);
        self::assertSame(0, $runtime->calls);
        self::assertStringContainsString($expectedCode, $tester->getDisplay());
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidInputProvider(): iterable
    {
        yield 'format' => [
            ['--format' => 'xml'],
            'webadmin.mail.format_invalid',
        ];
        yield 'zero limit' => [
            ['--limit' => '0'],
            'webadmin.mail.limit_invalid',
        ];
        yield 'negative limit' => [
            ['--limit' => '-1'],
            'webadmin.mail.limit_invalid',
        ];
        yield 'oversized limit' => [
            ['--limit' => '101'],
            'webadmin.mail.limit_invalid',
        ];
        yield 'non numeric limit' => [
            ['--limit' => '2e1'],
            'webadmin.mail.limit_invalid',
        ];
    }

    public function testDefaultBatchReturnsOnlySafeCounters(): void
    {
        $runtime = new MailDispatchCliRuntimeFixture(
            new WebAdminOutboxDispatchReport(2, 2, 2, 0, 0, 0)
        );
        $factory = new MailDispatchCliRuntimeFactoryFixture($runtime);
        $tester = $this->tester($factory);

        $status = $tester->execute(['--format' => 'json']);
        $display = $tester->getDisplay();
        $payload = json_decode($display, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $factory->calls);
        self::assertSame(1, $runtime->calls);
        self::assertSame(20, $runtime->limit);
        self::assertTrue($payload['ok']);
        self::assertSame('webadmin-mail-dispatch', $payload['operation']);
        self::assertSame([
            'examined' => 2,
            'claimed' => 2,
            'sent' => 2,
            'retry_scheduled' => 0,
            'permanently_failed' => 0,
            'fenced' => 0,
        ], $payload['result']);
        self::assertStringNotContainsString('@', $display);
        self::assertStringNotContainsString('token', $display);
    }

    public function testDeliveryProblemsReturnFailureWithBoundedCounters(): void
    {
        $runtime = new MailDispatchCliRuntimeFixture(
            new WebAdminOutboxDispatchReport(4, 3, 1, 1, 1, 1)
        );
        $tester = $this->tester(
            new MailDispatchCliRuntimeFactoryFixture($runtime)
        );

        $status = $tester->execute([
            '--limit' => '4',
            '--format' => 'json',
        ]);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::FAILURE, $status);
        self::assertFalse($payload['ok']);
        self::assertSame(4, $runtime->limit);
        self::assertSame(1, $payload['result']['retry_scheduled']);
        self::assertSame(1, $payload['result']['permanently_failed']);
        self::assertSame(1, $payload['result']['fenced']);
    }

    public function testKnownRuntimeFailureIsStableAndUnknownCodeIsMapped(): void
    {
        foreach ([
            'webadmin.mail.schema_not_ready'
                => 'webadmin.mail.schema_not_ready',
            'private@example.test password=secret'
                => 'webadmin.mail.runtime_unavailable',
        ] as $actual => $expected) {
            $tester = $this->tester(
                new MailDispatchCliRuntimeFactoryFixture(
                    new WebAdminMailDispatchCommandRuntimeException($actual)
                )
            );

            $status = $tester->execute(['--format' => 'json']);
            $display = $tester->getDisplay();
            $payload = json_decode(
                $display,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            self::assertSame(Command::FAILURE, $status);
            self::assertSame($expected, $payload['error']['code']);
            self::assertStringNotContainsString('private@example.test', $display);
            self::assertStringNotContainsString('password=secret', $display);
        }
    }

    public function testUnexpectedDispatchFailureCannotLeakSecrets(): void
    {
        $runtime = new MailDispatchCliRuntimeFixture(new RuntimeException(
            'smtp recipient@example.test token=private SQL SELECT'
        ));
        $tester = $this->tester(
            new MailDispatchCliRuntimeFactoryFixture($runtime)
        );

        $status = $tester->execute(['--format' => 'json']);
        $display = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(
            'webadmin.mail.dispatch_failed',
            $display
        );
        self::assertStringNotContainsString('recipient@example.test', $display);
        self::assertStringNotContainsString('private', $display);
        self::assertStringNotContainsString('SELECT', $display);
    }

    public function testRealFactoryRejectsDisabledModuleBeforeEnvironmentAndDatabase(): void
    {
        $project = $this->createProject(
            'module-disabled',
            false,
            null
        );
        $this->filesystem->mkdir($project . '/.env');
        $databaseCalls = 0;
        $transportCalls = 0;
        $factory = new WebAdminMailDispatchCommandRuntimeFactory(
            connectionFactoryResolver: static function (
                array $_environment
            ) use (
                &$databaseCalls
            ): PdoConnectionFactoryInterface {
                ++$databaseCalls;
                throw new RuntimeException('Database must not be reached.');
            },
            transportResolver: static function (
                WebAdminMailConfiguration $_configuration
            ) use (
                &$transportCalls
            ): WebAdminMailTransportInterface {
                ++$transportCalls;
                throw new RuntimeException('Transport must not be reached.');
            }
        );

        $tester = $this->tester(
            $factory,
            $project,
            dirname(__DIR__, 2)
        );
        $status = $tester->execute(['--format' => 'json']);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(
            'webadmin.mail.module_not_enabled',
            $payload['error']['code']
        );
        self::assertSame(0, $databaseCalls);
        self::assertSame(0, $transportCalls);
    }

    public function testRealFactoryRejectsInvalidEnvironmentBeforeDatabase(): void
    {
        $project = $this->createProject(
            'environment-invalid',
            true,
            null
        );
        $this->filesystem->mkdir($project . '/.env');
        $databaseCalls = 0;
        $transportCalls = 0;
        $factory = new WebAdminMailDispatchCommandRuntimeFactory(
            connectionFactoryResolver: static function (
                array $_environment
            ) use (
                &$databaseCalls
            ): PdoConnectionFactoryInterface {
                ++$databaseCalls;
                throw new RuntimeException('Database must not be reached.');
            },
            transportResolver: static function (
                WebAdminMailConfiguration $_configuration
            ) use (
                &$transportCalls
            ): WebAdminMailTransportInterface {
                ++$transportCalls;
                throw new RuntimeException('Transport must not be reached.');
            }
        );

        $tester = $this->tester(
            $factory,
            $project,
            dirname(__DIR__, 2)
        );
        $status = $tester->execute(['--format' => 'json']);
        $display = $tester->getDisplay();
        $payload = json_decode($display, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(
            'webadmin.mail.environment_unusable',
            $payload['error']['code']
        );
        self::assertSame(0, $databaseCalls);
        self::assertSame(0, $transportCalls);
        self::assertStringNotContainsString($project, $display);
    }

    public function testRealFactoryPropagatesLiquidStackAsSecondResolverArgument(): void
    {
        $project = $this->createProject(
            'dedicated-connection',
            true,
            $this->environment([
                'LIQUIDSTACK_DB_HOST' => 'dedicated.invalid',
                'LIQUIDSTACK_DB_PORT' => '3306',
                'LIQUIDSTACK_DB_NAME' => 'dedicated_database',
                'LIQUIDSTACK_DB_USER' => 'dedicated_user',
                'LIQUIDSTACK_DB_PASSWORD' => 'dedicated_secret',
                'LIQUIDSTACK_DB_CHARSET' => 'utf8mb4',
            ]),
            'dedicated_dispatch_'
        );
        $this->filesystem->dumpFile(
            $project . '/App/config/modules/webadmin.php',
            "<?php\nreturn ['database' => ["
                . "'connection' => 'liquidstack', "
                . "'table_prefix' => 'dedicated_dispatch_']];\n"
        );
        $pdoFactory = new MailDispatchCliPdoFactoryFixture($this->sqlite());
        $receivedConnection = null;
        $resolverCalls = 0;
        $transportCalls = 0;
        $factory = new WebAdminMailDispatchCommandRuntimeFactory(
            connectionFactoryResolver: static function (
                array $_environment,
                string $connection
            ) use (
                &$receivedConnection,
                &$resolverCalls,
                $pdoFactory
            ): PdoConnectionFactoryInterface {
                ++$resolverCalls;
                $receivedConnection = $connection;

                return $pdoFactory;
            },
            transportResolver: static function () use (
                &$transportCalls
            ): WebAdminMailTransportInterface {
                ++$transportCalls;

                return new MailDispatchCliTransportFixture();
            }
        );

        try {
            $factory->create($project, dirname(__DIR__, 2));
            self::fail('El esquema pendiente debía impedir el dispatcher.');
        } catch (WebAdminMailDispatchCommandRuntimeException $exception) {
            self::assertSame(
                'webadmin.mail.schema_not_ready',
                $exception->issueCode()
            );
        }
        self::assertSame(1, $resolverCalls);
        self::assertSame(1, $pdoFactory->connectCalls);
        self::assertSame(0, $transportCalls);
        self::assertSame('liquidstack', $receivedConnection);
    }

    public function testRealFactoryRejectsModuleConnectionMismatchBeforeResolver(): void
    {
        $project = $this->createProject(
            'mismatched-blog',
            true,
            $this->environment()
        );
        $this->filesystem->dumpFile(
            $project . '/composer.json',
            json_encode(['require' => [
                'liquidstack/core' => '^1.9',
                'liquidstack/blog' => '*',
            ]], JSON_THROW_ON_ERROR)
        );
        $this->filesystem->dumpFile(
            $project . '/App/config/langs.php',
            "<?php\nreturn ['es'];\n"
        );
        $this->filesystem->dumpFile(
            $project . '/App/config/modules/webadmin.php',
            "<?php\nreturn ['database' => ["
                . "'connection' => 'liquidstack']];\n"
        );
        $this->filesystem->dumpFile(
            $project . '/App/config/modules/blog.php',
            "<?php\nreturn ['database' => ["
                . "'connection' => 'shared']];\n"
        );
        $resolverCalls = 0;
        $transportCalls = 0;
        $factory = new WebAdminMailDispatchCommandRuntimeFactory(
            connectionFactoryResolver: static function () use (
                &$resolverCalls
            ): PdoConnectionFactoryInterface {
                ++$resolverCalls;
                throw new RuntimeException('Must not resolve or connect.');
            },
            transportResolver: static function () use (
                &$transportCalls
            ): WebAdminMailTransportInterface {
                ++$transportCalls;
                throw new RuntimeException('Must not build transport.');
            }
        );

        try {
            $factory->create($project, dirname(__DIR__, 2));
            self::fail('El mismatch debía fallar antes del resolver PDO.');
        } catch (WebAdminMailDispatchCommandRuntimeException $exception) {
            self::assertSame(
                'webadmin.mail.runtime_unavailable',
                $exception->issueCode()
            );
        }
        self::assertSame(0, $resolverCalls);
        self::assertSame(0, $transportCalls);
    }

    public function testRealFactoryValidatesMailConfigurationBeforeDatabase(): void
    {
        $invalidOrigin = 'https://admin.example.test/?secret=origin-leak';
        $project = $this->createProject(
            'mail-invalid',
            true,
            $this->environment([
                WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV =>
                    $invalidOrigin,
            ])
        );
        $databaseCalls = 0;
        $transportCalls = 0;
        $factory = new WebAdminMailDispatchCommandRuntimeFactory(
            connectionFactoryResolver: static function (
                array $_environment
            ) use (
                &$databaseCalls
            ): PdoConnectionFactoryInterface {
                ++$databaseCalls;
                throw new RuntimeException('Database must not be reached.');
            },
            transportResolver: static function (
                WebAdminMailConfiguration $_configuration
            ) use (
                &$transportCalls
            ): WebAdminMailTransportInterface {
                ++$transportCalls;
                throw new RuntimeException('Transport must not be reached.');
            }
        );

        $tester = $this->tester(
            $factory,
            $project,
            dirname(__DIR__, 2)
        );
        $status = $tester->execute(['--format' => 'json']);
        $display = $tester->getDisplay();
        $payload = json_decode($display, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(
            'webadmin.mail.configuration_invalid',
            $payload['error']['code']
        );
        self::assertSame(0, $databaseCalls);
        self::assertSame(0, $transportCalls);
        self::assertStringNotContainsString($invalidOrigin, $display);
        self::assertStringNotContainsString('origin-leak', $display);
    }

    public function testRealFactoryRejectsLocalCaptureOutsideDevBeforeDatabase(): void
    {
        $project = $this->createProject(
            'local-capture-production-blocked',
            true,
            $this->localCaptureEnvironment([
                'RAIZ' => 'https://www.example.test',
                'DEV_MODE' => '0',
            ])
        );
        $databaseCalls = 0;
        $transportCalls = 0;
        $factory = new WebAdminMailDispatchCommandRuntimeFactory(
            connectionFactoryResolver: static function () use (
                &$databaseCalls
            ): PdoConnectionFactoryInterface {
                ++$databaseCalls;
                throw new RuntimeException('Database must not be reached.');
            },
            transportResolver: static function () use (
                &$transportCalls
            ): WebAdminMailTransportInterface {
                ++$transportCalls;
                throw new RuntimeException('Transport must not be reached.');
            }
        );

        $tester = $this->tester($factory, $project, dirname(__DIR__, 2));
        $status = $tester->execute(['--format' => 'json']);
        $display = $tester->getDisplay();
        $payload = json_decode($display, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(
            'webadmin.mail.configuration_invalid',
            $payload['error']['code']
        );
        self::assertSame(0, $databaseCalls);
        self::assertSame(0, $transportCalls);
        self::assertStringNotContainsString('https://www.example.test', $display);
    }

    public function testRealFactoryBuildsLocalCaptureRuntimeWithoutSending(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite es necesario para probar el CLI.');
        }

        $prefix = 'local_capture_webadmin_';
        $project = $this->createProject(
            'local-capture-runtime',
            true,
            $this->localCaptureEnvironment(),
            $prefix
        );
        $coreRoot = dirname(__DIR__, 2);
        $pdo = $this->sqlite();
        $this->applyWebAdminSchema($pdo, $project, $coreRoot, $prefix);
        $pdoFactory = new MailDispatchCliPdoFactoryFixture($pdo);
        $factory = new WebAdminMailDispatchCommandRuntimeFactory(
            connectionFactoryResolver: static fn (
                array $_environment
            ): PdoConnectionFactoryInterface => $pdoFactory
        );

        $runtime = $factory->create($project, $coreRoot);
        $report = $runtime->dispatch(1);

        self::assertSame(0, $report->examined());
        self::assertSame(1, $pdoFactory->connectCalls);
    }

    public function testRealFactoryRequiresAppliedSchemaBeforeBuildingTransport(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite es necesario para probar el CLI.');
        }

        $project = $this->createProject(
            'schema-pending',
            true,
            $this->environment()
        );
        $pdo = $this->sqlite();
        $pdoFactory = new MailDispatchCliPdoFactoryFixture($pdo);
        $transportCalls = 0;
        $factory = new WebAdminMailDispatchCommandRuntimeFactory(
            connectionFactoryResolver: static fn (
                array $_environment
            ): PdoConnectionFactoryInterface => $pdoFactory,
            transportResolver: static function (
                WebAdminMailConfiguration $_configuration
            ) use (
                &$transportCalls
            ): WebAdminMailTransportInterface {
                ++$transportCalls;
                throw new RuntimeException('Transport must not be reached.');
            }
        );

        $tester = $this->tester(
            $factory,
            $project,
            dirname(__DIR__, 2)
        );
        $status = $tester->execute(['--format' => 'json']);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(
            'webadmin.mail.schema_not_ready',
            $payload['error']['code']
        );
        self::assertSame(1, $pdoFactory->connectCalls);
        self::assertSame(0, $transportCalls);
        self::assertSame(
            0,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'"
            )->fetchColumn(),
            'The readiness probe must not create the migration registry.'
        );
    }

    public function testRealSqliteRuntimeDispatchesInviteAndEmptyBatchIsIdempotent(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite es necesario para probar el CLI.');
        }

        $prefix = 'dispatch_webadmin_';
        $recipient = 'invited-owner@example.test';
        $mailPassword = 'smtp-password-must-remain-private';
        $project = $this->createProject(
            'runtime-success',
            true,
            $this->environment([
                WebAdminMailConfiguration::SMTP_PASSWORD_ENV =>
                    $mailPassword,
            ]),
            $prefix
        );
        $coreRoot = dirname(__DIR__, 2);
        $pdo = $this->sqlite();
        $this->applyWebAdminSchema($pdo, $project, $coreRoot, $prefix);
        $outboxId = $this->queueInvite($pdo, $prefix, $recipient);
        $pdoFactory = new MailDispatchCliPdoFactoryFixture($pdo);
        $transport = new MailDispatchCliTransportFixture();
        $transportCalls = 0;
        $factory = new WebAdminMailDispatchCommandRuntimeFactory(
            connectionFactoryResolver: static fn (
                array $_environment
            ): PdoConnectionFactoryInterface => $pdoFactory,
            transportResolver: static function (
                WebAdminMailConfiguration $configuration
            ) use ($transport, &$transportCalls): WebAdminMailTransportInterface {
                ++$transportCalls;
                self::assertSame(
                    'https://admin.example.test',
                    $configuration->publicOrigin()
                );

                return $transport;
            }
        );

        $firstTester = $this->tester(
            $factory,
            $project,
            $coreRoot
        );
        $firstStatus = $firstTester->execute([
            '--limit' => '1',
            '--format' => 'json',
        ]);
        $firstDisplay = $firstTester->getDisplay();
        $first = json_decode(
            $firstDisplay,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::SUCCESS, $firstStatus);
        self::assertSame([
            'examined' => 1,
            'claimed' => 1,
            'sent' => 1,
            'retry_scheduled' => 0,
            'permanently_failed' => 0,
            'fenced' => 0,
        ], $first['result']);
        self::assertCount(1, $transport->messages);
        $message = $transport->messages[0];
        self::assertSame($recipient, $message->recipientEmail());
        self::assertSame(
            1,
            preg_match(
                '/[?&]token=([A-Za-z0-9_-]{43})/',
                $message->textBody(),
                $matches
            )
        );
        $rawToken = $matches[1];
        self::assertStringContainsString(
            'https://admin.example.test/admin/activate?token=',
            $message->textBody()
        );
        foreach ([$recipient, $mailPassword, $rawToken] as $secret) {
            self::assertStringNotContainsString($secret, $firstDisplay);
        }

        $outbox = $pdo->query(
            'SELECT status, attempts, sent_at, last_error_code FROM "'
            . $prefix . 'outbox" WHERE id = ' . $outboxId
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($outbox);
        self::assertSame('sent', $outbox['status']);
        self::assertSame(1, (int) $outbox['attempts']);
        self::assertNotNull($outbox['sent_at']);
        self::assertNull($outbox['last_error_code']);
        $token = $pdo->query(
            'SELECT token_hash, delivered_at, used_at, revoked_at FROM "'
            . $prefix . 'action_tokens"'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($token);
        self::assertSame(hash('sha256', $rawToken), $token['token_hash']);
        self::assertNotNull($token['delivered_at']);
        self::assertNull($token['used_at']);
        self::assertNull($token['revoked_at']);

        $secondTester = $this->tester($factory, $project, $coreRoot);
        $secondStatus = $secondTester->execute([
            '--limit' => '100',
            '--format' => 'json',
        ]);
        $second = json_decode(
            $secondTester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::SUCCESS, $secondStatus);
        self::assertSame([
            'examined' => 0,
            'claimed' => 0,
            'sent' => 0,
            'retry_scheduled' => 0,
            'permanently_failed' => 0,
            'fenced' => 0,
        ], $second['result']);
        self::assertCount(1, $transport->messages);
        self::assertSame(2, $transportCalls);
        self::assertSame(2, $pdoFactory->connectCalls);
        self::assertSame(
            1,
            (int) $pdo->query(
                'SELECT COUNT(*) FROM "' . $prefix . 'action_tokens"'
            )->fetchColumn()
        );
    }

    public function testRealDispatchFailureRedactsMessageAndEnvironmentSecrets(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite es necesario para probar el CLI.');
        }

        $prefix = 'failed_webadmin_';
        $recipient = 'private-recipient@example.test';
        $mailPassword = 'private-smtp-password-7f3b';
        $project = $this->createProject(
            'runtime-failure',
            true,
            $this->environment([
                WebAdminMailConfiguration::SMTP_PASSWORD_ENV =>
                    $mailPassword,
            ]),
            $prefix
        );
        $coreRoot = dirname(__DIR__, 2);
        $pdo = $this->sqlite();
        $this->applyWebAdminSchema($pdo, $project, $coreRoot, $prefix);
        $outboxId = $this->queueInvite($pdo, $prefix, $recipient);
        $rawToken = null;
        $transportDetail = 'smtp-internal-detail-8f9f';
        $transport = new MailDispatchCliTransportFixture(
            static function (WebAdminMailMessage $message) use (
                &$rawToken,
                $mailPassword,
                $transportDetail
            ): void {
                self::assertSame(
                    1,
                    preg_match(
                        '/[?&]token=([A-Za-z0-9_-]{43})/',
                        $message->textBody(),
                        $matches
                    )
                );
                $rawToken = $matches[1];
                throw new RuntimeException(
                    $transportDetail . ' recipient='
                    . $message->recipientEmail()
                    . ' password=' . $mailPassword
                    . ' token=' . $rawToken
                );
            }
        );
        $pdoFactory = new MailDispatchCliPdoFactoryFixture($pdo);
        $factory = new WebAdminMailDispatchCommandRuntimeFactory(
            connectionFactoryResolver: static fn (
                array $_environment
            ): PdoConnectionFactoryInterface => $pdoFactory,
            transportResolver: static fn (
                WebAdminMailConfiguration $_configuration
            ): WebAdminMailTransportInterface => $transport
        );

        $tester = $this->tester($factory, $project, $coreRoot);
        $status = $tester->execute([
            '--limit' => '1',
            '--format' => 'json',
        ]);
        $display = $tester->getDisplay();
        $payload = json_decode($display, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(1, $payload['result']['retry_scheduled']);
        self::assertSame(0, $payload['result']['sent']);
        self::assertIsString($rawToken);
        foreach (
            [$recipient, $mailPassword, $transportDetail, $rawToken]
            as $secret
        ) {
            self::assertStringNotContainsString($secret, $display);
        }
        $outbox = $pdo->query(
            'SELECT * FROM "' . $prefix . 'outbox" WHERE id = ' . $outboxId
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($outbox);
        self::assertSame('pending', $outbox['status']);
        self::assertSame(1, (int) $outbox['attempts']);
        self::assertSame('outbox.delivery_failed', $outbox['last_error_code']);
        self::assertNull($outbox['action_token_id']);
        $tokens = $pdo->query(
            'SELECT * FROM "' . $prefix . 'action_tokens"'
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $tokens);
        self::assertNotNull($tokens[0]['revoked_at']);
        $databaseDump = json_encode(
            [$outbox, $tokens],
            JSON_THROW_ON_ERROR
        );
        self::assertStringNotContainsString($rawToken, $databaseDump);
        self::assertStringNotContainsString($transportDetail, $databaseDump);
        self::assertStringNotContainsString($mailPassword, $databaseDump);
    }

    public function testRealFactoryMapsConnectionExceptionWithoutLeakingIt(): void
    {
        $databaseSecret = 'pdo-password-secret-bd54';
        $project = $this->createProject(
            'connection-failure',
            true,
            $this->environment(['BBDD_PASS' => $databaseSecret])
        );
        $transportCalls = 0;
        $factory = new WebAdminMailDispatchCommandRuntimeFactory(
            connectionFactoryResolver: static function (
                array $_environment
            ) use (
                $databaseSecret
            ): PdoConnectionFactoryInterface {
                throw new RuntimeException(
                    'PDO DSN and password=' . $databaseSecret
                );
            },
            transportResolver: static function (
                WebAdminMailConfiguration $_configuration
            ) use (
                &$transportCalls
            ): WebAdminMailTransportInterface {
                ++$transportCalls;
                throw new RuntimeException('Transport must not be reached.');
            }
        );

        $tester = $this->tester(
            $factory,
            $project,
            dirname(__DIR__, 2)
        );
        $status = $tester->execute(['--format' => 'json']);
        $display = $tester->getDisplay();
        $payload = json_decode($display, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(
            'webadmin.mail.runtime_unavailable',
            $payload['error']['code']
        );
        self::assertSame(0, $transportCalls);
        self::assertStringNotContainsString($databaseSecret, $display);
        self::assertStringNotContainsString('PDO DSN', $display);
    }

    private function tester(
        WebAdminMailDispatchCommandRuntimeFactoryInterface $factory,
        ?string $projectRoot = null,
        ?string $coreRoot = null
    ): CommandTester {
        $command = new WebAdminMailDispatchCommand(
            $projectRoot ?? __DIR__,
            $coreRoot ?? dirname(__DIR__, 2),
            $factory
        );
        $application = new ComposerApplication();
        $application->setAutoExit(false);
        $application->add($command);

        return new CommandTester($command);
    }

    private function report(): WebAdminOutboxDispatchReport
    {
        return new WebAdminOutboxDispatchReport(0, 0, 0, 0, 0, 0);
    }

    /** @param array<string, string> $overrides */
    private function environment(array $overrides = []): string
    {
        $values = array_replace([
            'BBDD_SERVER' => 'db.example.test',
            'BBDD_USER' => 'webadmin_fixture',
            'BBDD_PASS' => 'database-password-must-remain-private',
            'BBDD_NAME' => 'webadmin_fixture',
            WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV =>
                'https://admin.example.test',
            WebAdminMailConfiguration::SMTP_HOST_ENV =>
                'smtp.example.test',
            WebAdminMailConfiguration::SMTP_PORT_ENV => '587',
            WebAdminMailConfiguration::SMTP_ENCRYPTION_ENV => 'starttls',
            WebAdminMailConfiguration::SMTP_USERNAME_ENV =>
                'mailer@example.test',
            WebAdminMailConfiguration::SMTP_PASSWORD_ENV =>
                'smtp-password-must-remain-private',
            WebAdminMailConfiguration::FROM_ADDRESS_ENV =>
                'no-reply@example.test',
            WebAdminMailConfiguration::FROM_NAME_ENV => 'LiquidStack WebAdmin',
        ], $overrides);

        $lines = [];
        foreach ($values as $name => $value) {
            $lines[] = $name . '=' . json_encode(
                $value,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            );
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param array<string, string> $overrides */
    private function localCaptureEnvironment(array $overrides = []): string
    {
        return $this->environment(array_replace([
            'RAIZ' => 'http://localhost:1309',
            'DEV_MODE' => '1',
            WebAdminMailConfiguration::TRANSPORT_ENV =>
                WebAdminMailConfiguration::TRANSPORT_LOCAL_CAPTURE_SMTP,
            WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV => '',
            WebAdminMailConfiguration::SMTP_HOST_ENV => '127.0.0.1',
            WebAdminMailConfiguration::SMTP_PORT_ENV => '1025',
            WebAdminMailConfiguration::SMTP_ENCRYPTION_ENV => '',
            WebAdminMailConfiguration::SMTP_USERNAME_ENV => '',
            WebAdminMailConfiguration::SMTP_PASSWORD_ENV => '',
            WebAdminMailConfiguration::FROM_ADDRESS_ENV =>
                'webadmin@aiwa.test',
            WebAdminMailConfiguration::FROM_NAME_ENV => 'AIWA WebAdmin dev',
        ], $overrides));
    }

    private function createProject(
        string $name,
        bool $webAdminEnabled,
        ?string $environment,
        string $prefix = 'dispatch_webadmin_'
    ): string {
        $project = $this->temporaryRoot . '/' . $name;
        $this->filesystem->mkdir($project . '/App/config/modules');
        $requirements = ['liquidstack/core' => '^1.9'];
        if ($webAdminEnabled) {
            $requirements['liquidstack/webadmin'] = '*';
        }
        $this->filesystem->dumpFile(
            $project . '/composer.json',
            json_encode(
                ['require' => $requirements],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            )
        );
        $this->filesystem->dumpFile(
            $project . '/App/config/modules/webadmin.php',
            "<?php\nreturn [\n"
                . "    'database' => ['table_prefix' => '"
                . $prefix . "'],\n"
                . "];\n"
        );
        if ($environment !== null) {
            $this->filesystem->dumpFile($project . '/.env', $environment);
        }

        return $project;
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function applyWebAdminSchema(
        PDO $pdo,
        string $project,
        string $coreRoot,
        string $prefix
    ): void {
        $registry = ModuleRegistry::forProject($project, $coreRoot);
        $catalog = MigrationCatalog::fromRegistry($registry);
        $scopes = MigrationScopeCollection::fromTablePrefixes([
            'webadmin' => $prefix,
        ]);
        $planner = new MigrationDatabasePlanner();
        $preview = $planner->plan($pdo, $catalog, $scopes);
        (new MigrationRunner())->apply(
            $pdo,
            $catalog,
            $scopes,
            new MigrationApplyOptions(expectedPlanHash: $preview->hash())
        );
    }

    private function queueInvite(
        PDO $pdo,
        string $prefix,
        string $recipient
    ): int {
        self::assertMatchesRegularExpression(
            '/\A[a-z][a-z0-9_]+_\z/',
            $prefix
        );
        $timestamp = '2000-01-01 00:00:00.000000';
        $user = $pdo->prepare(
            'INSERT INTO "' . $prefix . 'users" '
            . '(public_id, email_canonical, status, auth_version, invited_at, '
            . 'created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?, ?)'
        );
        $user->execute([
            '00000000-0000-4000-8000-000000000001',
            $recipient,
            'invited',
            $timestamp,
            $timestamp,
            $timestamp,
        ]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO "' . $prefix . 'credentials" '
            . '(user_id, password_hash, password_set_at, created_at, '
            . 'updated_at) VALUES (?, NULL, NULL, ?, ?)'
        )->execute([$userId, $timestamp, $timestamp]);
        $pdo->prepare(
            'INSERT INTO "' . $prefix . 'outbox" '
            . '(kind, user_id, locale, status, attempts, available_at, '
            . 'created_at) VALUES (?, ?, ?, ?, 0, ?, ?)'
        )->execute([
            'invite',
            $userId,
            'es',
            'pending',
            $timestamp,
            $timestamp,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
