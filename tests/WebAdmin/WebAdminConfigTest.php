<?php

declare(strict_types=1);

use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Configuration\WebAdminConfigException;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class WebAdminConfigTest extends TestCase
{
    private string $fixtureRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-webadmin-config-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->fixtureRoot);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testMissingProjectFileUsesSafeDefaults(): void
    {
        $config = (new WebAdminConfigLoader())->load($this->fixtureRoot);

        self::assertSame('defaults', $config->source());
        self::assertSame('/admin', $config->basePath());
        self::assertSame('shared', $config->databaseConnection());
        self::assertSame('ls_webadmin_', $config->tablePrefix());
        self::assertLessThanOrEqual(
            WebAdminConfig::MYSQL_IDENTIFIER_MAX_LENGTH,
            strlen(
                $config->tablePrefix()
                . WebAdminConfig::LONGEST_TABLE_SUFFIX
            )
        );
        self::assertSame('LS_WEBADMIN_SID', $config->cookieName());
        self::assertSame(
            'LS_WEBADMIN_PREAUTH',
            $config->preAuthenticationCookieName()
        );
        self::assertSame('LS_WEBADMIN_ACTION', $config->actionCookieName());
        self::assertSame('/admin', $config->cookiePath());
        self::assertSame(2_592_000, $config->idleTtlSeconds());
        self::assertSame(2_592_000, $config->absoluteTtlSeconds());

        $safe = $config->toSafeArray();
        self::assertTrue($safe['session']['secure']);
        self::assertTrue($safe['session']['http_only']);
        self::assertTrue($safe['session']['host_only']);
        self::assertFalse($safe['session']['development_isolated']);
        self::assertSame(
            'LS_WEBADMIN_PREAUTH',
            $safe['session']['preauth_cookie_name']
        );
        self::assertSame(
            'Lax',
            $safe['session']['preauth_cookie_same_site']
        );
        self::assertSame(
            'LS_WEBADMIN_ACTION',
            $safe['session']['action_cookie_name']
        );
        self::assertSame(
            'Lax',
            $safe['session']['action_cookie_same_site']
        );
        self::assertSame('Strict', $safe['session']['same_site']);
        self::assertSame(
            ['BBDD_SERVER', 'BBDD_USER', 'BBDD_PASS', 'BBDD_NAME'],
            $safe['database']['environment_names']
        );
    }

    public function testEmptyProjectConfigurationUsesSafeDefaults(): void
    {
        $this->writeConfig("<?php\n\nreturn [];\n");

        $config = (new WebAdminConfigLoader())->load($this->fixtureRoot);

        self::assertSame('project', $config->source());
        self::assertSame('/admin', $config->basePath());
        self::assertSame('ls_webadmin_', $config->tablePrefix());
        self::assertSame('LS_WEBADMIN_SID', $config->cookieName());
        self::assertSame(2_592_000, $config->idleTtlSeconds());
        self::assertSame(2_592_000, $config->absoluteTtlSeconds());
    }

    public function testProjectCanOverrideOnlyNonSecretSettings(): void
    {
        $this->writeConfig(<<<'PHP'
<?php

return [
    'path' => '/gestion/interna',
    'database' => [
        'connection' => 'shared',
        'table_prefix' => 'client_webadmin_',
    ],
    'session' => [
        'cookie_name' => 'CLIENT_WEBADMIN_SID',
        'idle_ttl_seconds' => 900,
        'absolute_ttl_seconds' => 14400,
    ],
];
PHP);

        $config = (new WebAdminConfigLoader())->load($this->fixtureRoot);

        self::assertSame('project', $config->source());
        self::assertSame('/gestion/interna', $config->basePath());
        self::assertSame('client_webadmin_', $config->tablePrefix());
        self::assertSame('CLIENT_WEBADMIN_SID', $config->cookieName());
        self::assertSame(900, $config->idleTtlSeconds());
        self::assertSame(14400, $config->absoluteTtlSeconds());
    }

    public function testProjectCanSelectDedicatedLiquidStackConnectionSafely(): void
    {
        $this->writeConfig(<<<'PHP'
<?php

return [
    'database' => [
        'connection' => 'liquidstack',
        'table_prefix' => 'client_webadmin_',
    ],
];
PHP);

        $config = (new WebAdminConfigLoader())->load($this->fixtureRoot);
        $safe = $config->toSafeArray();

        self::assertSame('liquidstack', $config->databaseConnection());
        self::assertSame('liquidstack', $safe['database']['connection']);
        self::assertSame(
            [
                'LIQUIDSTACK_DB_HOST',
                'LIQUIDSTACK_DB_PORT',
                'LIQUIDSTACK_DB_NAME',
                'LIQUIDSTACK_DB_USER',
                'LIQUIDSTACK_DB_PASSWORD',
                'LIQUIDSTACK_DB_CHARSET',
            ],
            $safe['database']['environment_names']
        );

        $encoded = json_encode($safe, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('database-password', $encoded);
        self::assertStringNotContainsString('mysql:', $encoded);
    }

    public function testEachOptionalProjectBlockCanBeOmitted(): void
    {
        $this->writeConfig(<<<'PHP'
<?php

return ['path' => '/gestion-web'];
PHP);

        $config = (new WebAdminConfigLoader())->load($this->fixtureRoot);

        self::assertSame('/gestion-web', $config->basePath());
        self::assertSame('ls_webadmin_', $config->tablePrefix());
        self::assertSame('LS_WEBADMIN_SID', $config->cookieName());
    }

    public function testLoopbackDevelopmentNamespacesEveryCookieByProjectNotPort(): void
    {
        $projectId = '0123456789abcdef01234567';
        $at1309 = (new WebAdminConfigLoader())->load(
            $this->fixtureRoot,
            $this->developmentEnvironment($projectId, 1309)
        );
        $at1317 = (new WebAdminConfigLoader())->load(
            $this->fixtureRoot,
            $this->developmentEnvironment($projectId, 1317)
        );

        self::assertSame(
            'LS_WEBADMIN_SID_D_' . $projectId,
            $at1309->cookieName()
        );
        self::assertSame(
            'LS_WEBADMIN_PREAUTH_D_' . $projectId,
            $at1309->preAuthenticationCookieName()
        );
        self::assertSame(
            'LS_WEBADMIN_ACTION_D_' . $projectId,
            $at1309->actionCookieName()
        );
        self::assertSame($at1309->cookieName(), $at1317->cookieName());
        self::assertSame(
            $at1309->preAuthenticationCookieName(),
            $at1317->preAuthenticationCookieName()
        );
        self::assertSame(
            $at1309->actionCookieName(),
            $at1317->actionCookieName()
        );

        $otherProject = (new WebAdminConfigLoader())->load(
            $this->fixtureRoot,
            $this->developmentEnvironment(
                'fedcba9876543210fedcba98',
                1309
            )
        );
        self::assertNotSame(
            $at1309->cookieName(),
            $otherProject->cookieName()
        );

        $safe = $at1309->toSafeArray();
        self::assertTrue($safe['session']['development_isolated']);
        self::assertSame(
            $at1309->cookieName(),
            $safe['session']['cookie_name']
        );
        self::assertSame(
            $at1309->preAuthenticationCookieName(),
            $safe['session']['preauth_cookie_name']
        );
        self::assertSame(
            $at1309->actionCookieName(),
            $safe['session']['action_cookie_name']
        );
        self::assertArrayNotHasKey(
            'development_project_id',
            $safe['session']
        );

        $withEffectivePath = $at1309->withBasePath('/gestion/interna');
        self::assertSame('/gestion/interna', $withEffectivePath->basePath());
        self::assertSame('/gestion/interna', $withEffectivePath->cookiePath());
        self::assertSame(
            $at1309->cookieName(),
            $withEffectivePath->cookieName()
        );
        self::assertSame(
            $at1309->preAuthenticationCookieName(),
            $withEffectivePath->preAuthenticationCookieName()
        );
        self::assertSame(
            $at1309->actionCookieName(),
            $withEffectivePath->actionCookieName()
        );
        self::assertTrue(
            $withEffectivePath->toSafeArray()['session'][
                'development_isolated'
            ]
        );
    }

    public function testDevelopmentNamespaceTruncatesOnlyConfiguredSidToBudget(): void
    {
        $configuredSid = str_repeat('A', WebAdminConfig::COOKIE_NAME_MAX_LENGTH);
        $this->writeConfig(
            "<?php\n\nreturn " . var_export([
                'session' => ['cookie_name' => $configuredSid],
            ], true) . ";\n"
        );
        $projectId = '0123456789abcdef01234567';

        $config = (new WebAdminConfigLoader())->load(
            $this->fixtureRoot,
            $this->developmentEnvironment($projectId)
        );

        $suffix = '_D_' . $projectId;
        self::assertSame(
            substr(
                $configuredSid,
                0,
                WebAdminConfig::COOKIE_NAME_MAX_LENGTH - strlen($suffix)
            ) . $suffix,
            $config->cookieName()
        );
        self::assertSame(
            WebAdminConfig::COOKIE_NAME_MAX_LENGTH,
            strlen($config->cookieName())
        );
        self::assertSame(
            WebAdminConfig::PREAUTH_COOKIE_NAME . $suffix,
            $config->preAuthenticationCookieName()
        );
        self::assertSame(
            WebAdminConfig::ACTION_COOKIE_NAME . $suffix,
            $config->actionCookieName()
        );
        self::assertSame(
            $config->cookieName(),
            $config->toSafeArray()['session']['cookie_name']
        );
    }

    public function testEnvironmentWithoutProjectIdPreservesLegacyCookieNames(): void
    {
        foreach ([
            [],
            ['RAIZ' => 'https://example.test', 'DEV_MODE' => '0'],
            ['RAIZ' => 'http://localhost:1310', 'DEV_MODE' => '1'],
        ] as $environment) {
            $config = (new WebAdminConfigLoader())->load(
                $this->fixtureRoot,
                $environment
            );

            self::assertSame(
                WebAdminConfig::DEFAULT_COOKIE_NAME,
                $config->cookieName()
            );
            self::assertSame(
                WebAdminConfig::PREAUTH_COOKIE_NAME,
                $config->preAuthenticationCookieName()
            );
            self::assertSame(
                WebAdminConfig::ACTION_COOKIE_NAME,
                $config->actionCookieName()
            );
            self::assertFalse(
                $config->toSafeArray()['session']['development_isolated']
            );
        }
    }

    /** @dataProvider invalidDevelopmentProjectIdProvider */
    public function testInvalidDevelopmentProjectIdFailsClosed(mixed $projectId): void
    {
        $environment = $this->developmentEnvironment(
            '0123456789abcdef01234567'
        );
        $environment[WebAdminConfig::DEVELOPMENT_PROJECT_ID_ENV] = $projectId;

        $this->assertInvalidDevelopmentProjectEnvironment($environment);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDevelopmentProjectIdProvider(): iterable
    {
        yield 'null' => [null];
        yield 'integer' => [123456789012345678901234];
        yield 'empty' => [''];
        yield 'short' => ['0123456789abcdef0123456'];
        yield 'long' => ['0123456789abcdef012345678'];
        yield 'uppercase' => ['0123456789ABCDEF01234567'];
        yield 'non hexadecimal' => ['0123456789abcdef0123456g'];
        yield 'surrounding whitespace' => [' 0123456789abcdef01234567'];
    }

    /** @dataProvider invalidDevelopmentProfileProvider */
    public function testProjectIdOutsideLoopbackDevelopmentFailsClosed(
        array $environment
    ): void {
        $environment[WebAdminConfig::DEVELOPMENT_PROJECT_ID_ENV] =
            '0123456789abcdef01234567';

        $this->assertInvalidDevelopmentProjectEnvironment($environment);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidDevelopmentProfileProvider(): iterable
    {
        yield 'missing profile' => [[]];
        yield 'missing origin' => [['DEV_MODE' => '1']];
        yield 'missing development mode' => [[
            'RAIZ' => 'http://localhost:1309',
        ]];
        yield 'development disabled' => [[
            'RAIZ' => 'http://localhost:1309',
            'DEV_MODE' => '0',
        ]];
        yield 'production https' => [[
            'RAIZ' => 'https://example.test',
            'DEV_MODE' => '0',
        ]];
        yield 'https with development enabled' => [[
            'RAIZ' => 'https://localhost:1309',
            'DEV_MODE' => '1',
        ]];
        yield 'remote http host' => [[
            'RAIZ' => 'http://example.test:1309',
            'DEV_MODE' => '1',
        ]];
        yield 'malformed origin' => [[
            'RAIZ' => "http://localhost:1309\n",
            'DEV_MODE' => '1',
        ]];
    }

    public function testMaximumTablePrefixLeavesRoomForLongestTableName(): void
    {
        $prefix = str_repeat(
            'a',
            WebAdminConfig::MAX_TABLE_PREFIX_LENGTH - 1
        ) . '_';
        $this->writeConfig(
            "<?php\n\nreturn " . var_export([
                'database' => ['table_prefix' => $prefix],
            ], true) . ";\n"
        );

        $config = (new WebAdminConfigLoader())->load($this->fixtureRoot);

        self::assertSame(
            WebAdminConfig::MAX_TABLE_PREFIX_LENGTH,
            strlen($config->tablePrefix())
        );
        self::assertSame(
            WebAdminConfig::MYSQL_IDENTIFIER_MAX_LENGTH,
            strlen(
                $config->tablePrefix()
                . WebAdminConfig::LONGEST_TABLE_SUFFIX
            )
        );
    }

    public function testTablePrefixOneCharacterOverMysqlBudgetIsRejected(): void
    {
        $prefix = str_repeat(
            'a',
            WebAdminConfig::MAX_TABLE_PREFIX_LENGTH
        ) . '_';
        $this->writeConfig(
            "<?php\n\nreturn " . var_export([
                'database' => ['table_prefix' => $prefix],
            ], true) . ";\n"
        );

        try {
            (new WebAdminConfigLoader())->load($this->fixtureRoot);
            self::fail('An overlong table prefix must be rejected.');
        } catch (WebAdminConfigException $exception) {
            self::assertSame(
                'config.invalid_table_prefix',
                $exception->issueCode()
            );
            self::assertSame(
                'database.table_prefix',
                $exception->configKey()
            );
        }
    }

    public function testConfigRejectsSecretOrUnknownKeys(): void
    {
        $this->writeConfig(<<<'PHP'
<?php

return [
    'database' => [
        'password' => 'must-not-live-here',
    ],
];
PHP);

        try {
            (new WebAdminConfigLoader())->load($this->fixtureRoot);
            self::fail('The secret-bearing key should have been rejected.');
        } catch (WebAdminConfigException $exception) {
            self::assertSame('config.unknown_key', $exception->issueCode());
            self::assertSame('database.password', $exception->configKey());
            self::assertStringNotContainsString(
                'must-not-live-here',
                $exception->getMessage()
            );
        }
    }

    /**
     * @dataProvider invalidConfigurationProvider
     * @param array<string, mixed> $configuration
     */
    public function testInvalidConfigurationFailsClosed(
        array $configuration,
        string $issueCode,
        string $key
    ): void {
        $this->writeConfig(
            "<?php\n\nreturn " . var_export($configuration, true) . ";\n"
        );

        try {
            (new WebAdminConfigLoader())->load($this->fixtureRoot);
            self::fail('Invalid configuration should fail closed.');
        } catch (WebAdminConfigException $exception) {
            self::assertSame($issueCode, $exception->issueCode());
            self::assertSame($key, $exception->configKey());
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'uppercase path' => [
            ['path' => '/Admin'],
            'config.invalid_base_path',
            'path',
        ];
        yield 'trailing slash' => [
            ['path' => '/admin/'],
            'config.invalid_base_path',
            'path',
        ];
        yield 'legacy PHP session cookie' => [
            ['session' => ['cookie_name' => 'PHPSESSID']],
            'config.invalid_cookie_name',
            'session.cookie_name',
        ];
        yield 'authenticated cookie collides with action cookie' => [
            ['session' => ['cookie_name' => 'LS_WEBADMIN_ACTION']],
            'config.invalid_cookie_name',
            'session.cookie_name',
        ];
        yield 'authenticated cookie collides with preauth cookie' => [
            ['session' => ['cookie_name' => 'LS_WEBADMIN_PREAUTH']],
            'config.invalid_cookie_name',
            'session.cookie_name',
        ];
        yield 'absolute lifetime below idle lifetime' => [
            ['session' => [
                'idle_ttl_seconds' => 1800,
                'absolute_ttl_seconds' => 900,
            ]],
            'config.invalid_ttl',
            'session.absolute_ttl_seconds',
        ];
        yield 'idle lifetime above thirty days' => [
            ['session' => [
                'idle_ttl_seconds' => 2_592_001,
                'absolute_ttl_seconds' => 2_592_001,
            ]],
            'config.invalid_ttl',
            'session.idle_ttl_seconds',
        ];
        yield 'absolute lifetime above thirty days' => [
            ['session' => [
                'idle_ttl_seconds' => 2_592_000,
                'absolute_ttl_seconds' => 2_592_001,
            ]],
            'config.invalid_ttl',
            'session.absolute_ttl_seconds',
        ];
        yield 'dedicated database not in first contract' => [
            ['database' => ['connection' => 'dedicated']],
            'config.unsupported_database_connection',
            'database.connection',
        ];
    }

    public function testProjectConfigMustReturnAnArrayWithoutOutput(): void
    {
        $this->writeConfig(<<<'PHP'
<?php

echo 'unexpected-output';
return [];
PHP);

        try {
            (new WebAdminConfigLoader())->load($this->fixtureRoot);
            self::fail('Configuration output must be rejected.');
        } catch (WebAdminConfigException $exception) {
            self::assertSame(
                'config.project_file_emitted_output',
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                'unexpected-output',
                $exception->getMessage()
            );
        }
    }

    private function writeConfig(string $contents): void
    {
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/' . WebAdminConfig::PROJECT_CONFIG_PATH,
            $contents
        );
    }

    /** @return array<string, string> */
    private function developmentEnvironment(
        string $projectId,
        int $port = 1309
    ): array {
        return [
            'RAIZ' => 'http://localhost:' . $port,
            'DEV_MODE' => '1',
            WebAdminConfig::DEVELOPMENT_PROJECT_ID_ENV => $projectId,
        ];
    }

    /** @param array<string, mixed> $environment */
    private function assertInvalidDevelopmentProjectEnvironment(
        array $environment
    ): void {
        try {
            (new WebAdminConfigLoader())->load(
                $this->fixtureRoot,
                $environment
            );
            self::fail('Invalid development isolation must fail closed.');
        } catch (WebAdminConfigException $exception) {
            self::assertSame(
                'config.invalid_development_project_id',
                $exception->issueCode()
            );
            self::assertSame(
                WebAdminConfig::DEVELOPMENT_PROJECT_ID_ENV,
                $exception->configKey()
            );
            $rawProjectId = $environment[
                WebAdminConfig::DEVELOPMENT_PROJECT_ID_ENV
            ] ?? null;
            if (is_string($rawProjectId) && $rawProjectId !== '') {
                self::assertStringNotContainsString(
                    $rawProjectId,
                    $exception->getMessage()
                );
            }
        }
    }
}
