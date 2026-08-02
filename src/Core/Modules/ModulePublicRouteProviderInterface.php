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
 * A GET/HEAD response owned by the provider must not start the project's
 * legacy PHP session; returning null delegates to Application, which restores
 * that session before the legacy 404.
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
