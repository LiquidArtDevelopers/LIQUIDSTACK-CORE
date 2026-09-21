<?php

declare(strict_types=1);

namespace Tests\Commerce;

use App\Core\Commerce\Configuration\CommerceConfig;
use App\Core\Commerce\Configuration\CommerceConfigException;
use App\Core\Commerce\Configuration\CommerceConfigLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CommerceConfigTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-commerce-config-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testMissingConfigUsesSafeDeterministicDefaults(): void
    {
        $config = (new CommerceConfigLoader())->load(
            $this->root,
            ['es', 'eu', 'en']
        );

        self::assertFalse($config->publicEnabled());
        self::assertSame([
            'es' => '/commerce',
            'eu' => '/eu/commerce',
            'en' => '/en/commerce',
        ], $config->publicPaths());
        self::assertSame([
            'es' => '/commerce/inquiry',
            'eu' => '/eu/commerce/inquiry',
            'en' => '/en/commerce/inquiry',
        ], $config->inquiryPaths());
        self::assertSame('es', $config->defaultLocale());
        self::assertSame('inquiry', $config->transactionMode());
        self::assertSame('shared', $config->databaseConnection());
        self::assertSame('ls_commerce_', $config->tablePrefix());
        self::assertSame(20, $config->basketMaxItems());
        self::assertSame(2592000, $config->basketTtlSeconds());
        self::assertFalse($config->socialProofEnabled());
        self::assertSame(5, $config->socialProofMinimumCount());
        self::assertLessThanOrEqual(
            CommerceConfig::MYSQL_IDENTIFIER_MAX_LENGTH,
            strlen(
                $config->tablePrefix()
                . CommerceConfig::LONGEST_TABLE_SUFFIX
            )
        );
    }

    public function testProjectCanConfigurePublicInquiryWithoutSecrets(): void
    {
        $this->writeConfig(<<<'PHP'
<?php
return [
    'public' => ['enabled' => true],
    'public_paths' => [
        'es' => '/vehiculos',
        'en' => '/en/vehicles',
    ],
    'inquiry_paths' => [
        'es' => '/vehiculos/solicitud',
        'en' => '/en/vehicles/inquiry',
    ],
    'sitemap_path' => '/vehicles-sitemap.xml',
    'transaction_mode' => 'inquiry',
    'database' => [
        'connection' => 'liquidstack',
        'table_prefix' => 'client_commerce_',
    ],
    'basket' => ['max_items' => 12, 'ttl_seconds' => 86400],
    'social_proof' => ['enabled' => true, 'minimum_count' => 3],
];
PHP);

        $config = (new CommerceConfigLoader())->load(
            $this->root,
            ['es', 'en']
        );

        self::assertTrue($config->publicEnabled());
        self::assertSame('/vehiculos', $config->publicPath('ES'));
        self::assertSame('/en/vehicles', $config->publicPath('en'));
        self::assertSame('/vehiculos/solicitud', $config->inquiryPath('es'));
        self::assertSame('/vehicles-sitemap.xml', $config->sitemapPath());
        self::assertSame('liquidstack', $config->databaseConnection());
        self::assertSame('client_commerce_', $config->tablePrefix());
        self::assertSame(12, $config->basketMaxItems());
        self::assertSame(86400, $config->basketTtlSeconds());
        self::assertTrue($config->socialProofEnabled());
        self::assertSame(3, $config->socialProofMinimumCount());
        self::assertSame(
            'liquidstack',
            (new CommerceConfigLoader())->databaseConnection($this->root)
        );
    }

    /** @dataProvider invalidConfigProvider */
    public function testInvalidConfigFailsClosed(
        array $value,
        string $issue,
        string $key
    ): void {
        $this->writeConfig(
            "<?php\nreturn " . var_export($value, true) . ";\n"
        );

        try {
            (new CommerceConfigLoader())->load($this->root, ['es', 'en']);
            self::fail('Invalid Commerce config must fail closed.');
        } catch (CommerceConfigException $exception) {
            self::assertSame($issue, $exception->issueCode());
            self::assertSame($key, $exception->configKey());
        }
    }

    public static function invalidConfigProvider(): iterable
    {
        yield 'unknown key' => [
            ['secret' => 'no'],
            'config.unknown_key',
            'config.secret',
        ];
        yield 'sale is not implemented' => [
            ['transaction_mode' => 'sale'],
            'config.transaction_mode_unsupported',
            'transaction_mode',
        ];
        yield 'all locales are required' => [
            ['public_paths' => ['es' => '/vehiculos']],
            'config.language_route_missing',
            'public_paths.en',
        ];
        yield 'inquiry stays below its localized catalog' => [
            [
                'public_paths' => [
                    'es' => '/vehiculos',
                    'en' => '/en/vehicles',
                ],
                'inquiry_paths' => [
                    'es' => '/consulta',
                    'en' => '/en/vehicles/inquiry',
                ],
            ],
            'config.inquiry_path_outside_public_path',
            'inquiry_paths.es',
        ];
        yield 'public toggle is typed' => [
            ['public' => ['enabled' => 1]],
            'config.public_enabled_invalid',
            'public.enabled',
        ];
        yield 'prefix is bounded' => [
            ['database' => ['table_prefix' => 'Commerce_']],
            'config.invalid_table_prefix',
            'database.table_prefix',
        ];
    }

    private function writeConfig(string $contents): void
    {
        $path = $this->root . '/' . CommerceConfig::PROJECT_CONFIG_PATH;
        $this->filesystem->mkdir(dirname($path));
        $this->filesystem->dumpFile($path, $contents);
    }
}
