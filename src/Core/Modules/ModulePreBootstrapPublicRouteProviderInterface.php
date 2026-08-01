<?php

declare(strict_types=1);

namespace App\Core\Modules;

/**
 * Optional contract for exact public infrastructure endpoints that must run
 * before the legacy language router and PHP session.
 *
 * The paths are cheap routing metadata and must also be covered by
 * publicRoutePrefixes(). Project-owned GET routes, public files and showroom
 * subroutes retain priority. Unclaimed paths continue through the established
 * late public-routing phase.
 */
interface ModulePreBootstrapPublicRouteProviderInterface extends
    ModulePublicRouteProviderInterface
{
    /** @return list<string> */
    public static function preBootstrapPublicRoutePaths(
        ModuleRuntimeContext $context
    ): array;
}
