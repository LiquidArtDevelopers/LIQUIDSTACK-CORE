<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Navigation;

use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleWebAdminNavigationProviderInterface;
use RuntimeException;

/** Builds the canonical navigation catalog from active module providers. */
final class WebAdminNavigationCatalogFactory
{
    public static function fromRegistry(
        ModuleRegistry $registry
    ): WebAdminNavigationCatalog {
        $items = [];

        foreach ($registry->webAdminNavigationProviders() as $registered) {
            $className = $registered['class'];
            $provider = new $className();
            if (!$provider instanceof ModuleWebAdminNavigationProviderInterface) {
                throw new RuntimeException(
                    'Invalid WebAdmin navigation provider.'
                );
            }

            $item = $provider->webAdminNavigationItem();
            if ($item->module() !== $registered['module']) {
                throw new RuntimeException(
                    'WebAdmin navigation provider module mismatch.'
                );
            }

            $items[] = $item;
        }

        return new WebAdminNavigationCatalog($items);
    }
}
