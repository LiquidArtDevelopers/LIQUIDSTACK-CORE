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
        self::assertSame(1800, $config->idleTtlSeconds());
        self::assertSame(28800, $config->absoluteTtlSeconds());

        $safe = $config->toSafeArray();
        self::assertTrue($safe['session']['secure']);
        self::assertTrue($safe['session']['http_only']);
        self::assertTrue($safe['session']['host_only']);
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
        self::assertSame(1800, $config->idleTtlSeconds());
        self::assertSame(28800, $config->absoluteTtlSeconds());
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
}
