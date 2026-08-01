<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Routing\ModulePublicRouteCollection;

/**
 * Late public routing contract.
 *
 * publicRoutePrefixes() is routing metadata: implementations must keep it
 * cheap and must not create a database connection or an operational runtime.
 * The provider itself is instantiated and registerPublicRoutes() is invoked
 * only after the request path matches one of those declared prefixes.
 */
interface ModulePublicRouteProviderInterface extends ModuleProviderInterface
{
    /**
     * @return list<string>
     */
    public static function publicRoutePrefixes(
        ModuleRuntimeContext $context
    ): array;

    public function registerPublicRoutes(
        ModulePublicRouteCollection $routes,
        ModuleRuntimeContext $context
    ): void;
}
