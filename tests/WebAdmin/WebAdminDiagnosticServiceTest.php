<?php

declare(strict_types=1);

use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\Modules\Migrations\MigrationDatabasePlan;
use App\Core\WebAdmin\Diagnostics\WebAdminDatabaseDiagnostic;
use App\Core\WebAdmin\Diagnostics\WebAdminDiagnosticService;
use App\Core\WebAdmin\Mail\WebAdminMailConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class WebAdminDiagnosticServiceTest extends TestCase
{
    private string $fixtureRoot;
    private Filesystem $filesystem;
    private string $previousExceptionTraceSetting;

    protected function setUp(): void
    {
        $this->previousExceptionTraceSetting = (string) ini_get(
            'zend.exception_ignore_args'
        );
        ini_set('zend.exception_ignore_args', '1');
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-webadmin-diagnostic-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->fixtureRoot);
    }

    protected function tearDown(): void
    {
        ini_set(
            'zend.exception_ignore_args',
            $this->previousExceptionTraceSetting
        );
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testReportListsNamesButNeverEnvironmentValues(): void
    {
        $environment = [
            'BBDD_SERVER' => 'db.internal.invalid',
            'BBDD_USER' => 'liquid-user',
            'BBDD_PASS' => 'super-secret-database-password',
            'BBDD_NAME' => 'liquid-db',
            'LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL'
                => 'superadmin@example.invalid',
            'LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL'
                => 'siteadmin@example.invalid',
        ];
        $environmentBefore = $environment;

        $report = $this->inspect(
            $this->fixtureRoot,
            $environment
        );
        $data = $report->toArray();
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);

        self::assertTrue($report->isReady());
        self::assertTrue($report->isBootstrapReady());
        self::assertSame([], $data['missing_env']);
        self::assertSame('connected', $data['readiness']['database_connection']);
        self::assertSame('applied', $data['readiness']['migrations']);
        self::assertSame(
            'argon2id-v1',
            $data['environment']['password_policy']['id']
        );
        self::assertSame(
            'argon2id',
            $data['environment']['password_policy']['algorithm']
        );
        self::assertTrue(
            $data['environment']['password_policy']['ready']
        );
        self::assertStringContainsString('BBDD_PASS', $encoded);
        self::assertStringContainsString(
            'LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL',
            $encoded
        );
        foreach ($environment as $value) {
            self::assertStringNotContainsString($value, $encoded);
        }
        self::assertSame(
            $environmentBefore,
            $environment,
            'The injected environment remains caller-owned.'
        );
        self::assertFileDoesNotExist(
            $this->fixtureRoot . '/' . WebAdminConfig::PROJECT_CONFIG_PATH
        );
    }

    public function testLiquidStackProfileRequiresOnlyItsDedicatedNamesEvenWhenSharedIsComplete(): void
    {
        $this->writeLiquidStackDatabaseConfig();
        $legacyEnvironment = [
            'BBDD_SERVER' => 'legacy-db.private.invalid',
            'BBDD_USER' => 'legacy-private-user',
            'BBDD_PASS' => 'legacy-private-password',
            'BBDD_NAME' => 'legacy_private_database',
        ];

        $report = $this->inspect(
            $this->fixtureRoot,
            $legacyEnvironment
        );
        $data = $report->toArray();
        $database = $data['environment']['database'];
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);

        self::assertFalse($report->isReady());
        self::assertSame('liquidstack', $database['connection']);
        self::assertSame(
            WebAdminConfig::LIQUIDSTACK_DATABASE_ENV,
            $database['required']
        );
        self::assertSame(
            WebAdminConfig::LIQUIDSTACK_DATABASE_ENV,
            $database['missing']
        );
        self::assertSame([], $database['invalid']);
        self::assertFalse($database['ready']);
        self::assertSame(
            'liquidstack',
            $data['configuration']['effective']['database']['connection']
        );
        self::assertSame(
            WebAdminConfig::LIQUIDSTACK_DATABASE_ENV,
            $data['configuration']['effective']['database']['environment_names']
        );
        self::assertContains(
            'environment.database_missing',
            $data['readiness']['blockers']
        );
        self::assertStringContainsString('LIQUIDSTACK_DB_PASSWORD', $encoded);
        self::assertStringNotContainsString('BBDD_PASS', $encoded);
        foreach ($legacyEnvironment as $value) {
            self::assertStringNotContainsString($value, $encoded);
        }
    }

    public function testLiquidStackProfileReportsNamesAndNeverEitherProfilesValues(): void
    {
        $this->writeLiquidStackDatabaseConfig();
        $dedicatedEnvironment = $this->liquidStackDatabaseEnvironment();
        $unusedLegacyEnvironment = [
            'BBDD_SERVER' => 'unused-legacy.private.invalid',
            'BBDD_USER' => 'unused-legacy-user',
            'BBDD_PASS' => 'unused-legacy-password',
            'BBDD_NAME' => 'unused_legacy_database',
        ];
        $environment = $dedicatedEnvironment + $unusedLegacyEnvironment;

        $report = $this->inspect($this->fixtureRoot, $environment);
        $data = $report->toArray();
        $database = $data['environment']['database'];
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);

        self::assertTrue($report->isReady());
        self::assertSame('liquidstack', $database['connection']);
        self::assertSame(
            WebAdminConfig::LIQUIDSTACK_DATABASE_ENV,
            $database['required']
        );
        self::assertSame([], $database['missing']);
        self::assertSame([], $database['invalid']);
        self::assertTrue($database['ready']);
        self::assertStringContainsString('LIQUIDSTACK_DB_HOST', $encoded);
        self::assertStringContainsString('LIQUIDSTACK_DB_PASSWORD', $encoded);
        self::assertStringNotContainsString('BBDD_SERVER', $encoded);
        self::assertStringNotContainsString('BBDD_PASS', $encoded);
        foreach ($environment as $value) {
            self::assertStringNotContainsString($value, $encoded);
        }
    }

    public function testDiagnosticDoesNotLoadOrRewriteDotenvFiles(): void
    {
        $dotenvPath = $this->fixtureRoot . '/.env';
        $dotenvContents = implode("\n", [
            'BBDD_SERVER=must-not-be-read',
            'BBDD_USER=must-not-be-read',
            'BBDD_PASS=must-not-be-read',
            'BBDD_NAME=must-not-be-read',
            'LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL=hidden@example.invalid',
            'LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL=hidden-admin@example.invalid',
            '',
        ]);
        $this->filesystem->dumpFile($dotenvPath, $dotenvContents);
        $beforeHash = hash_file('sha256', $dotenvPath);

        $report = $this->inspect(
            $this->fixtureRoot,
            []
        );
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);

        self::assertFalse($report->isReady());
        self::assertSame(
            array_merge(
                WebAdminConfig::SHARED_DATABASE_ENV,
                array_values(WebAdminConfig::BOOTSTRAP_EMAIL_ENV)
            ),
            $report->toArray()['missing_env']
        );
        self::assertStringNotContainsString('must-not-be-read', $encoded);
        self::assertStringNotContainsString('hidden@example.invalid', $encoded);
        self::assertSame($beforeHash, hash_file('sha256', $dotenvPath));
        self::assertSame($dotenvContents, file_get_contents($dotenvPath));
    }

    public function testMissingDatabaseEnvironmentBlocksConfigurationReadiness(): void
    {
        $report = $this->inspect(
            $this->fixtureRoot,
            []
        );
        $data = $report->toArray();

        self::assertFalse($report->isReady());
        self::assertFalse($report->isBootstrapReady());
        self::assertSame(
            WebAdminConfig::SHARED_DATABASE_ENV,
            $data['environment']['database']['missing']
        );
        self::assertContains(
            'environment.database_missing',
            $data['readiness']['blockers']
        );
        self::assertSame(
            array_merge(
                WebAdminConfig::SHARED_DATABASE_ENV,
                array_values(WebAdminConfig::BOOTSTRAP_EMAIL_ENV)
            ),
            $data['missing_env']
        );
    }

    public function testEmptyDatabaseValuesRemainMissingWithoutBeingExposed(): void
    {
        $environment = [
            'BBDD_SERVER' => 'localhost',
            'BBDD_USER' => '',
            'BBDD_PASS' => '   ',
            'BBDD_NAME' => null,
        ];

        $report = $this->inspect(
            $this->fixtureRoot,
            $environment
        );

        self::assertFalse($report->isReady());
        self::assertSame(
            ['BBDD_USER', 'BBDD_NAME'],
            $report->toArray()['environment']['database']['missing']
        );
        self::assertSame(
            [],
            $report->toArray()['environment']['database']['invalid']
        );
        self::assertContains(
            'environment.database_missing',
            $report->toArray()['readiness']['blockers']
        );
    }

    public function testInvalidDatabaseValuesAreNotMistakenForReadyOrMissing(): void
    {
        $environment = [
            'BBDD_SERVER' => 'localhost;password=must-not-leak',
            'BBDD_USER' => 'user',
            'BBDD_PASS' => 'must-not-leak',
            'BBDD_NAME' => 'database;charset=must-not-leak',
        ];

        $report = $this->inspect(
            $this->fixtureRoot,
            $environment
        );
        $data = $report->toArray();
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);

        self::assertFalse($report->isReady());
        self::assertSame([], $data['environment']['database']['missing']);
        self::assertSame(
            ['BBDD_SERVER', 'BBDD_NAME'],
            $data['environment']['database']['invalid']
        );
        self::assertSame(
            array_values(WebAdminConfig::BOOTSTRAP_EMAIL_ENV),
            $data['missing_env']
        );
        self::assertContains(
            'environment.database_invalid',
            $data['readiness']['blockers']
        );
        self::assertStringNotContainsString('must-not-leak', $encoded);
    }

    public function testDatabaseReportCanExposeBothStatesUsingNamesOnly(): void
    {
        $report = $this->inspect(
            $this->fixtureRoot,
            [
                'BBDD_SERVER' => 'localhost;must-not-leak',
                'BBDD_USER' => '',
                'BBDD_PASS' => '',
                'BBDD_NAME' => 'database',
            ]
        );
        $data = $report->toArray();

        self::assertSame(
            ['BBDD_USER'],
            $data['environment']['database']['missing']
        );
        self::assertSame(
            ['BBDD_SERVER'],
            $data['environment']['database']['invalid']
        );
        self::assertSame(
            [
                'environment.database_missing',
                'environment.database_invalid',
            ],
            array_values(array_intersect(
                $data['readiness']['blockers'],
                [
                    'environment.database_missing',
                    'environment.database_invalid',
                ]
            ))
        );
        self::assertStringNotContainsString(
            'must-not-leak',
            json_encode($report, JSON_THROW_ON_ERROR)
        );
    }

    public function testBootstrapEmailsAreSeparateFromRuntimePrerequisites(): void
    {
        $report = $this->inspect(
            $this->fixtureRoot,
            $this->databaseEnvironment()
        );
        $data = $report->toArray();

        self::assertTrue($report->isReady());
        self::assertFalse($report->isBootstrapReady());
        self::assertSame(
            array_values(WebAdminConfig::BOOTSTRAP_EMAIL_ENV),
            $data['environment']['bootstrap']['missing']
        );
        self::assertSame([], $data['readiness']['blockers']);
    }

    public function testInvalidBootstrapEmailReportsOnlyVariableName(): void
    {
        $environment = $this->databaseEnvironment() + [
            'LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL'
                => 'not-an-email-secret-value',
            'LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL'
                => 'admin@example.invalid',
        ];

        $report = $this->inspect(
            $this->fixtureRoot,
            $environment
        );
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);

        self::assertTrue($report->isReady());
        self::assertFalse($report->isBootstrapReady());
        self::assertSame(
            ['LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL'],
            $report->toArray()['environment']['bootstrap']['invalid']
        );
        self::assertStringNotContainsString(
            'not-an-email-secret-value',
            $encoded
        );
    }

    public function testCanonicalDuplicateBootstrapEmailsInvalidateBothRoles(): void
    {
        $environment = $this->databaseEnvironment() + [
            'LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL'
                => ' Admin@Example.invalid ',
            'LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL'
                => 'admin@example.invalid',
        ];

        $report = $this->inspect(
            $this->fixtureRoot,
            $environment
        );
        $bootstrap = $report->toArray()['environment']['bootstrap'];
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);

        self::assertTrue(
            $report->isReady(),
            'Bootstrap readiness does not alter normal runtime readiness.'
        );
        self::assertFalse($report->isBootstrapReady());
        self::assertSame([], $bootstrap['missing']);
        self::assertSame(
            array_values(WebAdminConfig::BOOTSTRAP_EMAIL_ENV),
            $bootstrap['invalid']
        );
        self::assertStringNotContainsString(
            'Admin@Example.invalid',
            $encoded
        );
        self::assertStringNotContainsString(
            'admin@example.invalid',
            $encoded
        );
    }

    public function testBootstrapEmailMissingAndInvalidStatesRemainSeparate(): void
    {
        $report = $this->inspect(
            $this->fixtureRoot,
            $this->databaseEnvironment() + [
                'LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL' => '',
                'LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL' => ['must-not-leak'],
            ]
        );
        $bootstrap = $report->toArray()['environment']['bootstrap'];

        self::assertSame(
            ['LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL'],
            $bootstrap['missing']
        );
        self::assertSame(
            ['LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL'],
            $bootstrap['invalid']
        );
        self::assertStringNotContainsString(
            'must-not-leak',
            json_encode($report, JSON_THROW_ON_ERROR)
        );
    }

    public function testAssetsAreCheckedPassivelyInsideProject(): void
    {
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/public/assets/modules/webadmin/app.css',
            '/* fixture */'
        );

        $report = $this->inspect(
            $this->fixtureRoot,
            $this->databaseEnvironment(),
            [
                'public/assets/modules/webadmin/app.css',
                'public/assets/modules/webadmin/app.js',
                '../outside-project.txt',
            ]
        );
        $assets = $report->toArray()['assets'];

        self::assertFalse($report->isReady());
        self::assertSame(
            ['public/assets/modules/webadmin/app.js'],
            $assets['missing']
        );
        self::assertSame(['../outside-project.txt'], $assets['invalid']);
        self::assertContains(
            'assets.missing_or_invalid',
            $report->toArray()['readiness']['blockers']
        );
    }

    public function testInvalidProjectConfigProducesStructuredSafeIssue(): void
    {
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/' . WebAdminConfig::PROJECT_CONFIG_PATH,
            <<<'PHP'
<?php

return ['database' => ['password' => 'never-report-this-value']];
PHP
        );

        $report = $this->inspect(
            $this->fixtureRoot,
            $this->databaseEnvironment()
        );
        $data = $report->toArray();
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);

        self::assertFalse($report->isReady());
        self::assertSame([
            'code' => 'config.unknown_key',
            'key' => 'database.password',
        ], $data['configuration']['issues'][0]);
        self::assertNull($data['configuration']['effective']);
        self::assertStringNotContainsString(
            'never-report-this-value',
            $encoded
        );
    }

    public function testUnprobedDatabaseCannotBeReportedAsRuntimeReady(): void
    {
        $environment = $this->databaseEnvironment() + [
            'LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL'
                => 'superadmin@example.invalid',
            'LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL'
                => 'siteadmin@example.invalid',
            WebAdminConfig::SECURITY_KEY_ENV => $this->securityKey(),
        ];

        $report = (new WebAdminDiagnosticService())->inspect(
            $this->fixtureRoot,
            $environment
        );
        $data = $report->toArray();

        self::assertFalse($report->isRuntimeReady());
        self::assertFalse($report->isBootstrapReady());
        self::assertSame('preflight', $data['readiness']['scope']);
        self::assertSame(
            'not_checked',
            $data['readiness']['database_connection']
        );
        self::assertContains(
            'database.connection_not_ready',
            $data['readiness']['blockers']
        );
    }

    public function testSecurityKeyFailureBlocksRuntimeButNotBootstrap(): void
    {
        $environment = $this->databaseEnvironment() + [
            'LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL'
                => 'superadmin@example.invalid',
            'LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL'
                => 'siteadmin@example.invalid',
            WebAdminConfig::SECURITY_KEY_ENV
                => 'invalid-security-key-must-not-leak',
        ];

        $report = (new WebAdminDiagnosticService())->inspect(
            $this->fixtureRoot,
            $environment,
            [],
            $this->readyDatabaseDiagnostic()
        );
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);

        self::assertFalse($report->isRuntimeReady());
        self::assertTrue($report->isBootstrapReady());
        self::assertSame(
            [WebAdminConfig::SECURITY_KEY_ENV],
            $report->toArray()['environment']['security_key']['invalid']
        );
        self::assertStringNotContainsString(
            'invalid-security-key-must-not-leak',
            $encoded
        );
    }

    public function testMissingSecurityKeyReportsOnlyItsName(): void
    {
        $report = (new WebAdminDiagnosticService())->inspect(
            $this->fixtureRoot,
            $this->databaseEnvironment() + [
                'LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL'
                    => 'superadmin@example.invalid',
                'LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL'
                    => 'siteadmin@example.invalid',
            ],
            [],
            $this->readyDatabaseDiagnostic()
        );

        self::assertFalse($report->isRuntimeReady());
        self::assertTrue($report->isBootstrapReady());
        self::assertSame(
            [WebAdminConfig::SECURITY_KEY_ENV],
            $report->toArray()['environment']['security_key']['missing']
        );
        self::assertContains(
            WebAdminConfig::SECURITY_KEY_ENV,
            $report->toArray()['missing_env']
        );
    }

    public function testUnsafeExceptionTraceBlocksRuntimeButNotBootstrap(): void
    {
        ini_set('zend.exception_ignore_args', '0');
        try {
            $report = $this->inspect(
                $this->fixtureRoot,
                $this->databaseEnvironment() + [
                    'LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL'
                        => 'superadmin@example.invalid',
                    'LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL'
                        => 'siteadmin@example.invalid',
                ]
            );
        } finally {
            ini_set('zend.exception_ignore_args', '1');
        }

        self::assertFalse($report->isRuntimeReady());
        self::assertTrue($report->isBootstrapReady());
        self::assertFalse(
            $report->toArray()['environment']['php_security']['ready']
        );
        self::assertContains(
            'runtime.exception_trace_unsafe',
            $report->toArray()['readiness']['blockers']
        );
    }

    public function testUnavailableDatabaseBlocksBothReadinessScopesSafely(): void
    {
        $report = (new WebAdminDiagnosticService())->inspect(
            $this->fixtureRoot,
            $this->databaseEnvironment() + [
                'LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL'
                    => 'superadmin@example.invalid',
                'LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL'
                    => 'siteadmin@example.invalid',
                WebAdminConfig::SECURITY_KEY_ENV => $this->securityKey(),
            ],
            [],
            WebAdminDatabaseDiagnostic::unavailable()
        );

        self::assertFalse($report->isRuntimeReady());
        self::assertFalse($report->isBootstrapReady());
        self::assertSame(
            'unavailable',
            $report->toArray()['readiness']['database_connection']
        );
        self::assertSame(
            'not_checked',
            $report->toArray()['readiness']['migrations']
        );
    }

    public function testMailReadinessIsSeparateFromLoginAndBootstrap(): void
    {
        $report = $this->inspect(
            $this->fixtureRoot,
            $this->databaseEnvironment() + [
                'LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL'
                    => 'superadmin@example.invalid',
                'LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL'
                    => 'siteadmin@example.invalid',
            ]
        );
        $data = $report->toArray();

        self::assertTrue($report->isRuntimeReady());
        self::assertTrue($report->isBootstrapReady());
        self::assertFalse($data['readiness']['mail_ready']);
        self::assertSame(
            ['environment.mail_missing'],
            $data['readiness']['mail_blockers']
        );
        self::assertSame(
            WebAdminMailConfiguration::REQUIRED_ENV,
            $data['environment']['mail']['missing']
        );
        self::assertSame([], $data['environment']['mail']['invalid']);
        foreach (WebAdminMailConfiguration::REQUIRED_ENV as $name) {
            self::assertNotContains($name, $data['missing_env']);
        }
    }

    public function testValidMailEnvironmentIsReportedWithoutAnyValue(): void
    {
        $environment = $this->databaseEnvironment() + [
            'LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL'
                => 'superadmin@example.invalid',
            'LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL'
                => 'siteadmin@example.invalid',
            WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV =>
                'https://private-host.example.test',
            WebAdminMailConfiguration::SMTP_HOST_ENV =>
                'smtp.private-host.example.test',
            WebAdminMailConfiguration::SMTP_PORT_ENV => '587',
            WebAdminMailConfiguration::SMTP_ENCRYPTION_ENV => 'starttls',
            WebAdminMailConfiguration::SMTP_USERNAME_ENV =>
                'private-smtp-user',
            WebAdminMailConfiguration::SMTP_PASSWORD_ENV =>
                'private-smtp-password',
            WebAdminMailConfiguration::FROM_ADDRESS_ENV =>
                'webadmin@example.test',
            WebAdminMailConfiguration::FROM_NAME_ENV => 'WebAdmin',
        ];
        $data = $this->inspect(
            $this->fixtureRoot,
            $environment
        )->toArray();

        self::assertTrue($data['environment']['mail']['ready']);
        self::assertTrue($data['readiness']['mail_ready']);
        self::assertSame([], $data['readiness']['mail_blockers']);
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(
            'private-host.example.test',
            $encoded
        );
        self::assertStringNotContainsString('private-smtp-user', $encoded);
        self::assertStringNotContainsString('private-smtp-password', $encoded);
    }

    /**
     * @param array<string, mixed> $environment
     * @param list<string> $requiredAssets
     */
    private function inspect(
        string $projectRoot,
        array $environment,
        array $requiredAssets = []
    ): \App\Core\WebAdmin\Diagnostics\WebAdminDiagnosticReport {
        $environmentWithKey = $environment + [
            WebAdminConfig::SECURITY_KEY_ENV => $this->securityKey(),
        ];

        return (new WebAdminDiagnosticService())->inspect(
            $projectRoot,
            $environmentWithKey,
            $requiredAssets,
            $this->readyDatabaseDiagnostic()
        );
    }

    private function readyDatabaseDiagnostic(): WebAdminDatabaseDiagnostic
    {
        return WebAdminDatabaseDiagnostic::fromPlan(
            new MigrationDatabasePlan(
                'sqlite',
                true,
                [[
                    'module' => 'webadmin',
                    'id' => '0001_webadmin_identity_and_access',
                    'description' => 'not exposed by diagnostic projection',
                    'checksum' => str_repeat('a', 64),
                    'scope_hash' => str_repeat('b', 64),
                    'destructive' => false,
                    'status' => 'applied',
                ]],
                []
            )
        );
    }

    private function securityKey(): string
    {
        return rtrim(strtr(
            base64_encode(str_repeat('D', 32)),
            '+/',
            '-_'
        ), '=');
    }

    /**
     * @return array<string, string>
     */
    private function databaseEnvironment(): array
    {
        return [
            'BBDD_SERVER' => 'db',
            'BBDD_USER' => 'user',
            'BBDD_PASS' => '',
            'BBDD_NAME' => 'database',
        ];
    }

    private function writeLiquidStackDatabaseConfig(): void
    {
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/' . WebAdminConfig::PROJECT_CONFIG_PATH,
            <<<'PHP'
<?php

return [
    'database' => [
        'connection' => 'liquidstack',
        'table_prefix' => 'private_webadmin_',
    ],
];
PHP
        );
    }

    /** @return array<string, string> */
    private function liquidStackDatabaseEnvironment(): array
    {
        return [
            'LIQUIDSTACK_DB_HOST' => 'dedicated-db.private.invalid',
            'LIQUIDSTACK_DB_PORT' => '47117',
            'LIQUIDSTACK_DB_NAME' => 'dedicated_private_database',
            'LIQUIDSTACK_DB_USER' => 'dedicated-private-user',
            'LIQUIDSTACK_DB_PASSWORD' => 'dedicated-private-password',
            'LIQUIDSTACK_DB_CHARSET' => 'utf8mb4',
        ];
    }
}
