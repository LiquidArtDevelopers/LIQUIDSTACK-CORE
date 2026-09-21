<?php

declare(strict_types=1);

namespace Tests\Commerce;

use App\Core\Modules\ModuleCatalog;
use App\Core\Modules\ModuleSelection;
use PHPUnit\Framework\TestCase;

final class CommerceModuleContractTest extends TestCase
{
    public function testSelectorClosesOnlyItsWebAdminDependency(): void
    {
        $root = dirname(__DIR__, 2);
        $catalog = ModuleCatalog::fromCoreRoot($root);
        $commerce = $catalog->get('commerce');

        self::assertSame('liquidstack/commerce', $commerce->packageName());
        self::assertSame(['webadmin'], $commerce->dependencies());
        $selection = ModuleSelection::fromRequirementNames(
            $catalog,
            ['liquidstack/commerce']
        );
        self::assertSame(['commerce'], $selection->requestedIds());
        self::assertSame(
            ['webadmin', 'commerce'],
            $selection->enabledIds()
        );
        self::assertFalse($selection->isEnabled('blog'));

        $composer = json_decode(
            (string) file_get_contents($root . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'self.version',
            $composer['replace']['liquidstack/commerce'] ?? null
        );
    }
}
