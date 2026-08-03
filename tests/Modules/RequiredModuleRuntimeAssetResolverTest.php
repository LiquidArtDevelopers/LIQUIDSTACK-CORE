<?php

declare(strict_types=1);

namespace App\Core\Modules\Diagnostics {
    final class RequiredModuleRuntimeAssetResolverLinkProbe
    {
        public static ?string $path = null;

        public static function matches(string $path): bool
        {
            if (self::$path === null) {
                return false;
            }

            return strtolower(str_replace('\\', '/', self::$path))
                === strtolower(str_replace('\\', '/', $path));
        }
    }

    function is_link(string $path): bool
    {
        return RequiredModuleRuntimeAssetResolverLinkProbe::matches($path)
            || \is_link($path);
    }
}

namespace {
    use App\Core\Modules\Diagnostics\RequiredModuleRuntimeAssetResolver;
    use App\Core\Modules\Diagnostics\RequiredModuleRuntimeAssetResolverLinkProbe;
    use App\Core\Modules\ModuleDefinition;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Filesystem\Filesystem;

final class RequiredModuleRuntimeAssetResolverTest extends TestCase
{
    private string $fixtureRoot;
    private string $moduleRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-required-runtime-assets-'
            . bin2hex(random_bytes(8));
        $this->moduleRoot = $this->fixtureRoot . '/webadmin';
        $this->filesystem->mkdir($this->moduleRoot);
    }

    protected function tearDown(): void
    {
        RequiredModuleRuntimeAssetResolverLinkProbe::$path = null;
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testDeclaredRuntimeDirectoryCannotBeEmpty(): void
    {
        $this->writeManifest('published/assets');
        $this->filesystem->mkdir($this->moduleRoot . '/published/assets');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no contiene ficheros');

        (new RequiredModuleRuntimeAssetResolver())->resolve(
            $this->definition()
        );
    }

    public function testRuntimeSourceCannotTraverseParentSymlink(): void
    {
        $this->filesystem->dumpFile(
            $this->moduleRoot . '/published/assets/app.js',
            'runtime'
        );
        $this->writeManifest('published/assets');
        RequiredModuleRuntimeAssetResolverLinkProbe::$path =
            $this->moduleRoot . '/published';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('atraviesa un enlace');

        (new RequiredModuleRuntimeAssetResolver())->resolve(
            $this->definition()
        );
    }

    private function definition(): ModuleDefinition
    {
        return ModuleDefinition::fromManifest(
            $this->moduleRoot . '/module.json'
        );
    }

    private function writeManifest(string $source): void
    {
        $this->filesystem->dumpFile(
            $this->moduleRoot . '/module.json',
            json_encode([
                'schema' => 1,
                'id' => 'webadmin',
                'package' => 'liquidstack/webadmin',
                'requires' => [],
                'providers' => [],
                'project_files' => [[
                    'source' => $source,
                    'target' => 'public/assets/modules/webadmin',
                    'type' => 'dir',
                    'policy' => 'managed_hash',
                ]],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
    }
}

}
