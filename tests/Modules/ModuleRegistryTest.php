<?php

declare(strict_types=1);

use App\Core\Modules\ModuleProviderInterface;
use App\Core\Modules\ModuleRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class WebadminRouteProviderFixture implements ModuleProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }
}

final class BlogRouteProviderFixture implements ModuleProviderInterface
{
    public static function moduleId(): string
    {
        return 'blog';
    }
}

final class ModuleRegistryTest extends TestCase
{
    private string $fixtureRoot;
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-module-registry-'
            . bin2hex(random_bytes(8));
        $this->projectRoot = $this->fixtureRoot . '/project';
        $this->filesystem->mkdir([
            $this->projectRoot,
            $this->fixtureRoot . '/modules',
        ]);

        $this->writeManifest(
            'webadmin',
            'liquidstack/webadmin',
            [],
            WebadminRouteProviderFixture::class
        );
        $this->writeManifest(
            'blog',
            'liquidstack/blog',
            ['webadmin'],
            BlogRouteProviderFixture::class
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testCoreOnlyReturnsNoProviders(): void
    {
        $this->writeComposer(['liquidstack/core' => '^1.8']);

        self::assertSame([], $this->registry()->providers('routes'));
    }

    public function testWebadminReturnsOnlyItsProvider(): void
    {
        $this->writeComposer([
            'liquidstack/core' => '^1.8',
            'liquidstack/webadmin' => '*',
        ]);

        self::assertSame([[
            'module' => 'webadmin',
            'class' => WebadminRouteProviderFixture::class,
        ]], $this->registry()->providers('routes'));
    }

    public function testBlogReturnsProvidersInDependencyOrder(): void
    {
        $this->writeComposer([
            'liquidstack/core' => '^1.8',
            'liquidstack/blog' => '*',
        ]);

        self::assertSame([
            [
                'module' => 'webadmin',
                'class' => WebadminRouteProviderFixture::class,
            ],
            [
                'module' => 'blog',
                'class' => BlogRouteProviderFixture::class,
            ],
        ], $this->registry()->providers('routes'));
    }

    public function testInvalidProviderIsOnlyRejectedWhenModuleIsActive(): void
    {
        $this->writeManifest(
            'blog',
            'liquidstack/blog',
            ['webadmin'],
            'MissingBlogProvider'
        );
        $this->writeComposer(['liquidstack/core' => '^1.8']);
        self::assertSame([], $this->registry()->providers('routes'));

        $this->writeComposer([
            'liquidstack/core' => '^1.8',
            'liquidstack/blog' => '*',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no implementa');
        $this->registry()->providers('routes');
    }

    private function registry(): ModuleRegistry
    {
        return ModuleRegistry::forProject(
            $this->projectRoot,
            $this->fixtureRoot
        );
    }

    /**
     * @param array<string, string> $requirements
     */
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

    /**
     * @param list<string> $requires
     */
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
