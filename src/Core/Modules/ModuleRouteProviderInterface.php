<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Routing\ModuleRouteCollection;

interface ModuleRouteProviderInterface extends ModuleProviderInterface
{
    public function registerRoutes(
        ModuleRouteCollection $routes,
        ModuleRuntimeContext $context
    ): void;
}
