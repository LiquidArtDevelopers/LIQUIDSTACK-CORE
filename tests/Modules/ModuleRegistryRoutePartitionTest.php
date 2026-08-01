<?php

declare(strict_types=1);

use App\Core\Modules\ModulePublicRouteProviderInterface;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRouteProviderInterface;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Routing\ModulePublicRouteCollection;
use App\Core\Routing\ModuleRouteCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class PartitionedPrivateRouteProviderFixture implements ModuleRouteProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public function registerRoutes(
        ModuleRouteCollection $routes,
        ModuleRuntimeContext $context
    ): void {
    }
}

final class PartitionedPublicRouteProviderFixture implements ModulePublicRouteProviderInterface
{
    public static function moduleId(): string
    {
        return 'blog';
    }

    public static function publicRoutePrefixes(
        ModuleRuntimeContext $context
    ): array {
        return ['/news'];
    }

    public function registerPublicRoutes(
        ModulePublicRouteCollection $routes,
        ModuleRuntimeContext $context
    ): void {
    }
}

final class RequiredConstructorPublicRouteProviderFixture implements ModulePublicRouteProviderInterface
{
    public function __construct(private readonly string $runtime)
    {
    }

    public static function moduleId(): string
    {
        return 'blog';
    }

    public static function publicRoutePrefixes(
        ModuleRuntimeContext $context
    ): array {
        return ['/news'];
    }

    public function registerPublicRoutes(
        ModulePublicRouteCollection $routes,
        ModuleRuntimeContext $context
    ): void {
    }
}

final class ModuleRegistryRoutePartitionTest extends TestCase
{
    private string $fixtureRoot;
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-route-partition-'
            . bin2hex(random_bytes(8));
        $this->projectRoot = $this->fixtureRoot . '/project';
        $this->filesystem->mkdir([
            $this->projectRoot,
            $this->fixtureRoot . '/modules/webadmin',
            $this->fixtureRoot . '/modules/blog',
        ]);

        $this->writeManifest(
            'webadmin',
            'liquidstack/webadmin',
            [],
            PartitionedPrivateRouteProviderFixture::class
        );
        $this->writeManifest(
            'blog',
            'liquidstack/blog',
            ['webadmin'],
            PartitionedPublicRouteProviderFixture::class
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode(
                ['require' => ['liquidstack/blog' => '*']],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testRegistrySeparatesEarlyPrivateAndLatePublicProviders(): void
    {
        $registry = ModuleRegistry::forProject(
            $this->projectRoot,
            $this->fixtureRoot
        );

        self::assertSame([[
            'module' => 'webadmin',
            'class' => PartitionedPrivateRouteProviderFixture::class,
        ]], $registry->routeProviders());
        self::assertSame([[
            'module' => 'blog',
            'class' => PartitionedPublicRouteProviderFixture::class,
        ]], $registry->publicRouteProviders());
        self::assertCount(2, $registry->providers('routes'));
    }

    public function testLateProviderConstructionCheckDoesNotPolluteEarlyRegistry(): void
    {
        $this->writeManifest(
            'blog',
            'liquidstack/blog',
            ['webadmin'],
            RequiredConstructorPublicRouteProviderFixture::class
        );
        $registry = ModuleRegistry::forProject(
            $this->projectRoot,
            $this->fixtureRoot
        );

        self::assertSame([[
            'module' => 'webadmin',
            'class' => PartitionedPrivateRouteProviderFixture::class,
        ]], $registry->routeProviders());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sin argumentos');
        $registry->publicRouteProviders();
    }

    /** @param list<string> $requires */
    private function writeManifest(
        string $id,
        string $package,
        array $requires,
        string $provider
    ): void {
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/modules/' . $id . '/module.json',
            json_encode([
                'schema' => 1,
                'id' => $id,
                'package' => $package,
                'requires' => $requires,
                'providers' => ['routes' => [$provider]],
                'project_files' => [],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
    }
}
