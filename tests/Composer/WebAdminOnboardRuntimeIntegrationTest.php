<?php

declare(strict_types=1);

use App\Core\Composer\Command\WebAdminOnboardCommand;
use App\Core\Composer\WebAdminBootstrapCommandRuntimeFactory;
use App\Core\Composer\WebAdminMailDispatchCommandRuntimeFactory;
use App\Core\Composer\WebAdminOnboardCommandRuntimeFactory;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Modules\Migrations\MigrationApplyOptions;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDatabasePlanner;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Mail\WebAdminMailConfiguration;
use App\Core\WebAdmin\Mail\WebAdminMailMessage;
use App\Core\WebAdmin\Mail\WebAdminMailTransportInterface;
use Composer\Console\Application as ComposerApplication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class WebAdminOnboardRuntimeIntegrationTest extends TestCase
{
    private const PREFIX = 'onboard_webadmin_';
    private const SUPERADMIN = 'bootstrap-superadmin@example.test';
    private const SITE_ADMIN = 'bootstrap-site-admin@example.test';
    private const EDITOR = 'older-editor@example.test';
    private const SMTP_PASSWORD = 'smtp-password-integration-private';

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
        'LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL',
        'LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL',
        WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV,
        WebAdminMailConfiguration::TRANSPORT_ENV,
        WebAdminMailConfiguration::SMTP_HOST_ENV,
        WebAdminMailConfiguration::SMTP_PORT_ENV,
        WebAdminMailConfiguration::SMTP_ENCRYPTION_ENV,
        WebAdminMailConfiguration::SMTP_USERNAME_ENV,
        WebAdminMailConfiguration::SMTP_PASSWORD_ENV,
        WebAdminMailConfiguration::FROM_ADDRESS_ENV,
        WebAdminMailConfiguration::FROM_NAME_ENV,
        WebAdminMailConfiguration::GENERAL_SMTP_HOST_ENV,
        WebAdminMailConfiguration::GENERAL_SMTP_PORT_ENV,
        WebAdminMailConfiguration::GENERAL_SMTP_ENCRYPTION_ENV,
        WebAdminMailConfiguration::GENERAL_SMTP_USERNAME_ENV,
        WebAdminMailConfiguration::GENERAL_SMTP_PASSWORD_ENV,
        WebAdminMailConfiguration::GENERAL_FROM_NAME_ENV,
        WebAdminMailConfiguration::GENERAL_LEGACY_FROM_NAME_ENV,
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
            . '/liquidstack-webadmin-onboard-integration-'
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

        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped(
                'pdo_sqlite is required for WebAdmin onboarding integration.'
            );
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

    public function testFreshOnboardingDeliversOnlyBootstrapInvitesAndRerunsWithoutBootstrapEnvironment(): void
    {
        [$project, $pdo] = $this->projectWithAppliedSchema('fresh', true);
        $editorOutboxId = $this->queueOlderEditorInvite($pdo);
        $transport = new OnboardRuntimeIntegrationTransport();

        $first = $this->executeOnboarding($project, $pdo, $transport);

        self::assertSame(Command::SUCCESS, $first['status'], $first['display']);
        self::assertTrue($first['payload']['ok']);
        self::assertSame(
            [
                'status' => 'completed',
                'changed' => true,
                'created_accounts' => 2,
                'reconciled_accounts' => 0,
                'queued_invites' => 2,
            ],
            $first['payload']['result']['bootstrap']
        );
        self::assertSame(
            $this->dispatchCounters(2, 2, 2, 0, 0, 0),
            $first['payload']['result']['dispatch']
        );
        self::assertSame(
            $this->readinessCounters(true, 0, 2, 0),
            $first['payload']['result']['readiness']
        );
        self::assertCount(2, $transport->messages);
        $recipients = array_map(
            static fn (WebAdminMailMessage $message): string =>
                $message->recipientEmail(),
            $transport->messages
        );
        sort($recipients);
        $expectedRecipients = [self::SUPERADMIN, self::SITE_ADMIN];
        sort($expectedRecipients);
        self::assertSame($expectedRecipients, $recipients);
        self::assertNotContains(self::EDITOR, $recipients);

        $editorOutbox = $this->outboxRow($pdo, $editorOutboxId);
        self::assertSame('pending', $editorOutbox['status']);
        self::assertSame(0, (int) $editorOutbox['attempts']);
        self::assertNull($editorOutbox['action_token_id']);
        self::assertNull($editorOutbox['sent_at']);
        self::assertSame(3, $this->countRows($pdo, 'users'));
        self::assertSame(3, $this->countRows($pdo, 'outbox'));
        self::assertSame(2, $this->countRows($pdo, 'action_tokens'));
        self::assertSame(
            2,
            $this->scalarCount(
                $pdo,
                'SELECT COUNT(*) FROM "' . self::PREFIX
                    . 'outbox" WHERE status = \'sent\''
            )
        );
        self::assertSame(
            2,
            $this->scalarCount(
                $pdo,
                'SELECT COUNT(*) FROM "' . self::PREFIX
                    . 'action_tokens" WHERE delivered_at IS NOT NULL '
                    . 'AND used_at IS NULL AND revoked_at IS NULL'
            )
        );
        $this->assertSafeOutput($first['display'], $transport->messages);

        $this->writeEnvironment($project, false);
        $second = $this->executeOnboarding($project, $pdo, $transport);

        self::assertSame(Command::SUCCESS, $second['status'], $second['display']);
        self::assertSame(
            [
                'status' => 'already_completed',
                'changed' => false,
                'created_accounts' => 0,
                'reconciled_accounts' => 0,
                'queued_invites' => 0,
            ],
            $second['payload']['result']['bootstrap']
        );
        self::assertSame(
            $this->dispatchCounters(0, 0, 0, 0, 0, 0),
            $second['payload']['result']['dispatch']
        );
        self::assertSame(
            $this->readinessCounters(true, 0, 2, 0),
            $second['payload']['result']['readiness']
        );
        self::assertCount(2, $transport->messages);
        self::assertSame(3, $this->countRows($pdo, 'users'));
        self::assertSame(3, $this->countRows($pdo, 'outbox'));
        self::assertSame(2, $this->countRows($pdo, 'action_tokens'));
        self::assertSame('pending', $this->outboxRow(
            $pdo,
            $editorOutboxId
        )['status']);
        $this->assertSafeOutput($second['display'], $transport->messages);
    }

    public function testTransportFailureLeavesBothInvitesInBackoffAndReportsOnlySafeAggregates(): void
    {
        [$project, $pdo] = $this->projectWithAppliedSchema('failure', true);
        $privateDetail = 'transport-private-detail-41d8';
        $transport = new OnboardRuntimeIntegrationTransport(
            static function (WebAdminMailMessage $message) use (
                $privateDetail
            ): void {
                preg_match(
                    '/[?&]token=([A-Za-z0-9_-]{43})/',
                    $message->textBody(),
                    $matches
                );
                throw new RuntimeException(
                    $privateDetail
                    . ' recipient=' . $message->recipientEmail()
                    . ' password=' . self::SMTP_PASSWORD
                    . ' token=' . ($matches[1] ?? 'missing')
                );
            }
        );

        $result = $this->executeOnboarding($project, $pdo, $transport);

        self::assertSame(Command::FAILURE, $result['status']);
        self::assertFalse($result['payload']['ok']);
        self::assertSame(
            'webadmin.onboard.incomplete',
            $result['payload']['error']['code']
        );
        self::assertSame(
            $this->dispatchCounters(2, 2, 0, 2, 0, 0),
            $result['payload']['result']['dispatch']
        );
        self::assertSame(
            $this->readinessCounters(false, 0, 0, 2),
            $result['payload']['result']['readiness']
        );
        self::assertCount(2, $transport->messages);

        $outboxRows = $pdo->query(
            'SELECT status, attempts, available_at, created_at, '
            . 'action_token_id, last_error_code, sent_at FROM "'
            . self::PREFIX . 'outbox" ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $outboxRows);
        foreach ($outboxRows as $row) {
            self::assertSame('pending', $row['status']);
            self::assertSame(1, (int) $row['attempts']);
            self::assertGreaterThan($row['created_at'], $row['available_at']);
            self::assertNull($row['action_token_id']);
            self::assertSame('outbox.delivery_failed', $row['last_error_code']);
            self::assertNull($row['sent_at']);
        }

        $tokenRows = $pdo->query(
            'SELECT delivered_at, used_at, revoked_at FROM "'
            . self::PREFIX . 'action_tokens" ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $tokenRows);
        foreach ($tokenRows as $row) {
            self::assertNull($row['delivered_at']);
            self::assertNull($row['used_at']);
            self::assertNotNull($row['revoked_at']);
        }

        $tokens = $this->rawTokens($transport->messages);
        self::assertCount(2, $tokens);
        foreach ([
            self::SUPERADMIN,
            self::SITE_ADMIN,
            self::SMTP_PASSWORD,
            $privateDetail,
            ...$tokens,
        ] as $privateValue) {
            self::assertStringNotContainsString(
                $privateValue,
                $result['display']
            );
        }
        self::assertStringNotContainsString('@', $result['display']);

        $second = $this->executeOnboarding($project, $pdo, $transport);
        self::assertSame(Command::FAILURE, $second['status']);
        self::assertSame(
            $this->dispatchCounters(0, 0, 0, 0, 0, 0),
            $second['payload']['result']['dispatch']
        );
        self::assertSame(
            $this->readinessCounters(false, 0, 0, 2),
            $second['payload']['result']['readiness']
        );
        self::assertCount(2, $transport->messages);
        self::assertSame(2, $this->countRows($pdo, 'action_tokens'));
    }

    /**
     * @return array{0: string, 1: PDO}
     */
    private function projectWithAppliedSchema(
        string $name,
        bool $withBootstrapEnvironment
    ): array {
        $project = $this->temporaryRoot . '/' . $name;
        $this->filesystem->mkdir($project . '/App/config/modules');
        $this->filesystem->dumpFile(
            $project . '/composer.json',
            json_encode(
                ['require' => [
                    'liquidstack/core' => '^1.22',
                    'liquidstack/webadmin' => '*',
                ]],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            )
        );
        $this->filesystem->dumpFile(
            $project . '/App/config/modules/webadmin.php',
            "<?php\nreturn [\n"
                . "    'database' => ['table_prefix' => '"
                . self::PREFIX . "'],\n"
                . "];\n"
        );
        $this->filesystem->dumpFile(
            $project . '/App/config/langs.php',
            "<?php\nreturn ['es'];\n"
        );
        $this->writeEnvironment($project, $withBootstrapEnvironment);

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $this->applyWebAdminSchema($pdo, $project);

        return [$project, $pdo];
    }

    private function writeEnvironment(
        string $project,
        bool $withBootstrapEnvironment
    ): void {
        $values = [
            'BBDD_SERVER' => 'database.example.test',
            'BBDD_USER' => 'integration_user',
            'BBDD_PASS' => 'database-password-private',
            'BBDD_NAME' => 'integration_database',
            WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV =>
                'https://admin.example.test',
            WebAdminMailConfiguration::SMTP_HOST_ENV =>
                'smtp.example.test',
            WebAdminMailConfiguration::SMTP_PORT_ENV => '587',
            WebAdminMailConfiguration::SMTP_ENCRYPTION_ENV => 'starttls',
            WebAdminMailConfiguration::SMTP_USERNAME_ENV =>
                'mailer@example.test',
            WebAdminMailConfiguration::SMTP_PASSWORD_ENV =>
                self::SMTP_PASSWORD,
            WebAdminMailConfiguration::FROM_ADDRESS_ENV =>
                'no-reply@example.test',
            WebAdminMailConfiguration::FROM_NAME_ENV =>
                'WebAdmin integration',
        ];
        if ($withBootstrapEnvironment) {
            $values[WebAdminConfig::BOOTSTRAP_EMAIL_ENV[
                'system_superadmin'
            ]] = self::SUPERADMIN;
            $values[WebAdminConfig::BOOTSTRAP_EMAIL_ENV[
                'site_admin'
            ]] = self::SITE_ADMIN;
        }

        $lines = [];
        foreach ($values as $key => $value) {
            $lines[] = $key . '=' . json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }
        $this->filesystem->dumpFile(
            $project . '/.env',
            implode("\n", $lines) . "\n"
        );
    }

    private function applyWebAdminSchema(PDO $pdo, string $project): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $registry = ModuleRegistry::forProject($project, $coreRoot);
        $catalog = MigrationCatalog::fromRegistry($registry);
        $scopes = MigrationScopeCollection::fromTablePrefixes([
            'webadmin' => self::PREFIX,
        ]);
        $planner = new MigrationDatabasePlanner();
        $preview = $planner->plan($pdo, $catalog, $scopes);
        (new MigrationRunner())->apply(
            $pdo,
            $catalog,
            $scopes,
            new MigrationApplyOptions(
                expectedPlanHash: $preview->hash(),
                allowDestructive: true,
                backupConfirmed: true
            )
        );
    }

    private function queueOlderEditorInvite(PDO $pdo): int
    {
        $timestamp = '2000-01-01 00:00:00.000000';
        $pdo->prepare(
            'INSERT INTO "' . self::PREFIX . 'users" '
            . '(public_id, email_canonical, status, auth_version, invited_at, '
            . 'created_at, updated_at) VALUES (?, ?, \'invited\', 1, ?, ?, ?)'
        )->execute([
            '00000000-0000-4000-8000-000000000010',
            self::EDITOR,
            $timestamp,
            $timestamp,
            $timestamp,
        ]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO "' . self::PREFIX . 'credentials" '
            . '(user_id, password_hash, password_set_at, created_at, '
            . 'updated_at) VALUES (?, NULL, NULL, ?, ?)'
        )->execute([$userId, $timestamp, $timestamp]);
        $editorRoleId = (int) $pdo->query(
            'SELECT id FROM "' . self::PREFIX
                . 'roles" WHERE code = \'editor\''
        )->fetchColumn();
        self::assertGreaterThan(0, $editorRoleId);
        $pdo->prepare(
            'INSERT INTO "' . self::PREFIX . 'user_roles" '
            . '(user_id, role_id, assigned_by_user_id, source, created_at) '
            . 'VALUES (?, ?, NULL, \'manual\', ?)'
        )->execute([$userId, $editorRoleId, $timestamp]);
        $pdo->prepare(
            'INSERT INTO "' . self::PREFIX . 'outbox" '
            . '(kind, user_id, locale, status, attempts, available_at, '
            . 'created_at) VALUES (\'invite\', ?, \'es\', \'pending\', 0, ?, ?)'
        )->execute([$userId, $timestamp, $timestamp]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @return array{status: int, display: string, payload: array<string, mixed>}
     */
    private function executeOnboarding(
        string $project,
        PDO $pdo,
        OnboardRuntimeIntegrationTransport $transport
    ): array {
        $connectionFactory = new OnboardRuntimeIntegrationPdoFactory($pdo);
        $connectionResolver = static fn (
            array $_environment,
            string $_connection
        ): PdoConnectionFactoryInterface => $connectionFactory;
        $mailFactory = new WebAdminMailDispatchCommandRuntimeFactory(
            connectionFactoryResolver: $connectionResolver,
            transportResolver: static fn (
                WebAdminMailConfiguration $_configuration
            ): WebAdminMailTransportInterface => $transport
        );
        $bootstrapFactory = new WebAdminBootstrapCommandRuntimeFactory(
            connectionFactoryResolver: $connectionResolver
        );
        $runtimeFactory = new WebAdminOnboardCommandRuntimeFactory(
            $mailFactory,
            $bootstrapFactory
        );
        $command = new WebAdminOnboardCommand(
            $project,
            dirname(__DIR__, 2),
            $runtimeFactory
        );
        $application = new ComposerApplication();
        $application->setAutoExit(false);
        $application->add($command);
        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--yes' => true,
            '--format' => 'json',
        ]);
        $display = $tester->getDisplay();

        return [
            'status' => $status,
            'display' => $display,
            'payload' => json_decode(
                $display,
                true,
                512,
                JSON_THROW_ON_ERROR
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function outboxRow(PDO $pdo, int $id): array
    {
        $statement = $pdo->prepare(
            'SELECT * FROM "' . self::PREFIX . 'outbox" WHERE id = ?'
        );
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function countRows(PDO $pdo, string $suffix): int
    {
        self::assertMatchesRegularExpression('/\A[a-z_]+\z/', $suffix);

        return $this->scalarCount(
            $pdo,
            'SELECT COUNT(*) FROM "' . self::PREFIX . $suffix . '"'
        );
    }

    private function scalarCount(PDO $pdo, string $sql): int
    {
        $value = $pdo->query($sql)->fetchColumn();
        self::assertIsNumeric($value);

        return (int) $value;
    }

    /**
     * @return array<string, int>
     */
    private function dispatchCounters(
        int $examined,
        int $claimed,
        int $sent,
        int $retryScheduled,
        int $permanentlyFailed,
        int $fenced
    ): array {
        return [
            'examined' => $examined,
            'claimed' => $claimed,
            'sent' => $sent,
            'retry_scheduled' => $retryScheduled,
            'permanently_failed' => $permanentlyFailed,
            'fenced' => $fenced,
        ];
    }

    /**
     * @return array<string, int|bool>
     */
    private function readinessCounters(
        bool $ready,
        int $active,
        int $delivered,
        int $incomplete
    ): array {
        return [
            'ready' => $ready,
            'expected_identities' => 2,
            'active_identities' => $active,
            'delivered_invitations' => $delivered,
            'incomplete_identities' => $incomplete,
        ];
    }

    /** @param list<WebAdminMailMessage> $messages */
    private function assertSafeOutput(string $display, array $messages): void
    {
        foreach ([
            self::SUPERADMIN,
            self::SITE_ADMIN,
            self::EDITOR,
            self::SMTP_PASSWORD,
            ...$this->rawTokens($messages),
        ] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $display);
        }
        self::assertStringNotContainsString('@', $display);
    }

    /**
     * @param list<WebAdminMailMessage> $messages
     * @return list<string>
     */
    private function rawTokens(array $messages): array
    {
        $tokens = [];
        foreach ($messages as $message) {
            self::assertSame(
                1,
                preg_match(
                    '/[?&]token=([A-Za-z0-9_-]{43})/',
                    $message->textBody(),
                    $matches
                )
            );
            $tokens[] = $matches[1];
        }

        return $tokens;
    }
}

final class OnboardRuntimeIntegrationPdoFactory implements
    PdoConnectionFactoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function connect(): PDO
    {
        return $this->pdo;
    }
}

final class OnboardRuntimeIntegrationTransport implements
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
