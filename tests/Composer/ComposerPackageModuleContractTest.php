<?php

declare(strict_types=1);

use App\Core\Modules\ModuleCatalog;
use PHPUnit\Framework\TestCase;

final class ComposerPackageModuleContractTest extends TestCase
{
    public function testCorePhysicallyContainsBothLogicalModuleSelectors(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $composer = json_decode(
            (string) file_get_contents($coreRoot . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('liquidstack/core', $composer['name']);
        self::assertSame(
            'self.version',
            $composer['replace']['liquidstack/webadmin'] ?? null
        );
        self::assertSame(
            'self.version',
            $composer['replace']['liquidstack/blog'] ?? null
        );
        self::assertArrayNotHasKey(
            'liquidstack/webadmin',
            $composer['require']
        );
        self::assertArrayNotHasKey(
            'liquidstack/blog',
            $composer['require']
        );

        $catalog = ModuleCatalog::fromCoreRoot($coreRoot);
        $catalogSelectors = array_map(
            static fn ($definition): string => $definition->packageName(),
            array_values($catalog->all())
        );
        $replaceSelectors = array_keys($composer['replace']);
        sort($catalogSelectors, SORT_STRING);
        sort($replaceSelectors, SORT_STRING);
        self::assertSame($catalogSelectors, $replaceSelectors);
        self::assertSame(
            'webadmin',
            $catalog->forPackage('liquidstack/webadmin')?->id()
        );
        self::assertSame(
            'blog',
            $catalog->forPackage('liquidstack/blog')?->id()
        );
    }
}
