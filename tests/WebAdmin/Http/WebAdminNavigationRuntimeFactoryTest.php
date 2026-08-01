<?php

declare(strict_types=1);

use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Modules\ModuleWebAdminNavigationProviderInterface;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Http\WebAdminHttpRuntimeException;
use App\Core\WebAdmin\Http\WebAdminHttpRuntimeFactory;
use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class NavigationRuntimePdoFactory implements
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

final class ActiveOnlyNavigationProviderFixture implements
    ModuleWebAdminNavigationProviderInterface
{
    public static int $constructions = 0;

    public function __construct()
    {
        self::$constructions++;
    }

    public static function moduleId(): string
    {
        return 'feature';
    }

    public function webAdminNavigationItem(): WebAdminNavigationItem
    {
        return new WebAdminNavigationItem(
            'feature',
            'Funcionalidad',
            '/feature',
            'feature.dashboard.view'
        );
    }
}

final class MismatchedNavigationProviderFixture implements
    ModuleWebAdminNavigationProviderInterface
{
    public static function moduleId(): string
    {
        return 'feature';
    }

    public function webAdminNavigationItem(): WebAdminNavigationItem
    {
        return new WebAdminNavigationItem(
            'other',
            'Destino ajeno',
            '/other',
            'other.dashboard.view'
        );
    }
}

final class WebAdminNavigationRuntimeFactoryTest extends TestCase
{
    private string $fixtureRoot;
    private string $projectRoot;
    private string $coreRoot;
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
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-navigation-runtime-'
            . bin2hex(random_bytes(8));
        $this->projectRoot = $this->fixtureRoot . '/project';
        $this->coreRoot = $this->fixtureRoot . '/core';
        $this->filesystem->mkdir([
            $this->projectRoot,
            $this->coreRoot . '/modules/webadmin',
            $this->coreRoot . '/modules/feature',
        ]);
        $this->writeManifest(
            'webadmin',
            'liquidstack/webadmin',
            [],
            [],
            [WebAdminMigrationProvider::class]
        );
        $this->writeFeatureManifest(
            ActiveOnlyNavigationProviderFixture::class
        );
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        ActiveOnlyNavigationProviderFixture::$constructions = 0;
    }

    protected function tearDown(): void
    {
        ini_set(
            'zend.exception_ignore_args',
            $this->previousExceptionTraceSetting
        );
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testFactoryNeverInstantiatesProviderOfDisabledModule(): void
    {
        $this->writeComposer(['liquidstack/webadmin' => '*']);
        $this->applyMigrations();

        $runtime = $this->factory()->create(
            $this->context(),
            WebAdminConfig::defaults()
        );

        self::assertSame([], $runtime->navigation()->items());
        self::assertSame(0, ActiveOnlyNavigationProviderFixture::$constructions);
    }

    public function testFactoryBuildsImmutableCatalogFromActiveProvider(): void
    {
        $this->writeComposer(['liquidstack/feature' => '*']);
        $this->applyMigrations();

        $runtime = $this->factory()->create(
            $this->context(),
            WebAdminConfig::defaults()
        );

        self::assertSame(1, ActiveOnlyNavigationProviderFixture::$constructions);
        self::assertCount(1, $runtime->navigation()->items());
        self::assertSame(
            '/feature',
            $runtime->navigation()->items()[0]->suffix()
        );
    }

    public function testFactoryRejectsItemOwnedByAnotherModule(): void
    {
        $this->writeFeatureManifest(
            MismatchedNavigationProviderFixture::class
        );
        $this->writeComposer(['liquidstack/feature' => '*']);
        $this->applyMigrations();

        try {
            $this->factory()->create(
                $this->context(),
                WebAdminConfig::defaults()
            );
            self::fail('A mismatched navigation item must fail closed.');
        } catch (WebAdminHttpRuntimeException $exception) {
            self::assertSame(
                'webadmin.runtime_unavailable',
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                'other',
                $exception->getMessage()
            );
        }
    }

    private function factory(): WebAdminHttpRuntimeFactory
    {
        return new WebAdminHttpRuntimeFactory(
            $this->coreRoot,
            fn (): PdoConnectionFactoryInterface =>
                new NavigationRuntimePdoFactory($this->pdo)
        );
    }

    private function context(): ModuleRuntimeContext
    {
        return new ModuleRuntimeContext(
            $this->projectRoot,
            [
                WebAdminHttpRuntimeFactory::SECURITY_KEY_ENV => rtrim(strtr(
                    base64_encode(str_repeat('N', 32)),
                    '+/',
                    '-_'
                ), '='),
            ]
        );
    }

    private function applyMigrations(): void
    {
        $registry = ModuleRegistry::forProject(
            $this->projectRoot,
            $this->coreRoot
        );
        (new MigrationRunner())->apply(
            $this->pdo,
            MigrationCatalog::fromRegistry($registry),
            MigrationScopeCollection::fromTablePrefixes([
                'webadmin' => WebAdminConfig::DEFAULT_TABLE_PREFIX,
            ])
        );
    }

    /** @param array<string, string> $requirements */
    private function writeComposer(array $requirements): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode(
                ['require' => $requirements],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
    }

    private function writeFeatureManifest(string $provider): void
    {
        $this->writeManifest(
            'feature',
            'liquidstack/feature',
            ['webadmin'],
            [$provider],
            []
        );
    }

    /**
     * @param list<string> $requires
     * @param list<string> $navigation
     * @param list<string> $migrations
     */
    private function writeManifest(
        string $id,
        string $package,
        array $requires,
        array $navigation,
        array $migrations
    ): void {
        $this->filesystem->dumpFile(
            $this->coreRoot . '/modules/' . $id . '/module.json',
            json_encode([
                'schema' => 1,
                'id' => $id,
                'package' => $package,
                'requires' => $requires,
                'providers' => [
                    'navigation' => $navigation,
                    'migrations' => $migrations,
                ],
                'project_files' => [],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
    }
}
