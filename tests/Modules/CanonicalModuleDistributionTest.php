<?php

declare(strict_types=1);

use App\Core\Modules\ModuleCatalog;
use App\Core\Modules\ModulePublishedSourceFinder;
use PHPUnit\Framework\TestCase;

final class CanonicalModuleDistributionTest extends TestCase
{
    public function testOptionalModulesPublishOnlyTheirManagedAssetBundles(): void
    {
        $root = dirname(__DIR__, 2);
        $catalog = ModuleCatalog::fromCoreRoot($root);

        foreach (['webadmin', 'blog'] as $moduleId) {
            self::assertSame([[
                'source' => 'published/assets',
                'target' => 'public/assets/modules/' . $moduleId,
                'type' => 'dir',
                'policy' => 'managed_hash',
                'group' => 'module:' . $moduleId
                    . ':public/assets/modules/' . $moduleId,
                'track_state' => true,
            ]], $catalog->get($moduleId)->projectFiles());
        }

        self::assertSame([
            'modules/blog/published/assets/blog-admin.css',
            'modules/blog/published/assets/blog-editor.js',
            'modules/blog/published/assets/blog-public.css',
            'modules/webadmin/published/assets/webadmin.css',
            'modules/webadmin/published/assets/webadmin.js',
        ], array_keys(
            ModulePublishedSourceFinder::currentManagedFiles($catalog)
        ));
    }
}
