<?php

declare(strict_types=1);

use App\Core\Modules\ModuleProviderInterface;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleWebAdminNavigationProviderInterface;
use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ValidWebAdminNavigationProviderFixture implements
    ModuleWebAdminNavigationProviderInterface
{
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

final class UntypedWebAdminNavigationProviderFixture implements
    ModuleProviderInterface
{
    public static function moduleId(): string
    {
        return 'feature';
    }
}

final class RequiredConstructorWebAdminNavigationProviderFixture implements
    ModuleWebAdminNavigationProviderInterface
{
    public function __construct(private readonly string $runtime)
    {
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

final class ModuleWebAdminNavigationProviderTest extends TestCase
{
    private string $fixtureRoot;
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-navigation-provider-'
            . bin2hex(random_bytes(8));
        $this->projectRoot = $this->fixtureRoot . '/project';
        $this->filesystem->mkdir([
            $this->projectRoot,
            $this->fixtureRoot . '/modules/webadmin',
            $this->fixtureRoot . '/modules/feature',
        ]);
        $this->writeManifest(
            'webadmin',
            'liquidstack/webadmin',
            [],
            []
        );
        $this->writeFeatureProvider(
            ValidWebAdminNavigationProviderFixture::class
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testOnlyActiveTypedProvidersAreReturned(): void
    {
        $this->writeComposer(['liquidstack/webadmin' => '*']);
        self::assertSame(
            [],
            $this->registry()->webAdminNavigationProviders()
        );

        $this->writeComposer(['liquidstack/feature' => '*']);
        self::assertSame([[
            'module' => 'feature',
            'class' => ValidWebAdminNavigationProviderFixture::class,
        ]], $this->registry()->webAdminNavigationProviders());
    }

    public function testProviderMustImplementTheTypedContract(): void
    {
        $this->writeFeatureProvider(
            UntypedWebAdminNavigationProviderFixture::class
        );
        $this->writeComposer(['liquidstack/feature' => '*']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            ModuleWebAdminNavigationProviderInterface::class
        );
        $this->registry()->webAdminNavigationProviders();
    }

    public function testProviderMustBeConstructibleWithoutArguments(): void
    {
        $this->writeFeatureProvider(
            RequiredConstructorWebAdminNavigationProviderFixture::class
        );
        $this->writeComposer(['liquidstack/feature' => '*']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sin argumentos');
        $this->registry()->webAdminNavigationProviders();
    }

    private function registry(): ModuleRegistry
    {
        return ModuleRegistry::forProject(
            $this->projectRoot,
            $this->fixtureRoot
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

    private function writeFeatureProvider(string $provider): void
    {
        $this->writeManifest(
            'feature',
            'liquidstack/feature',
            ['webadmin'],
            [$provider]
        );
    }

    /**
     * @param list<string> $requires
     * @param list<string> $navigation
     */
    private function writeManifest(
        string $id,
        string $package,
        array $requires,
        array $navigation
    ): void {
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/modules/' . $id . '/module.json',
            json_encode([
                'schema' => 1,
                'id' => $id,
                'package' => $package,
                'requires' => $requires,
                'providers' => ['navigation' => $navigation],
                'project_files' => [],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
    }
}
