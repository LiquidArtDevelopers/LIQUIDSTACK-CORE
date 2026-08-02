<?php

declare(strict_types=1);

namespace App\Core\Modules;

/**
 * Optional contract for public namespaces that must be routed before the
 * legacy language resolver and PHP session.
 *
 * Unlike ModulePreBootstrapPublicRouteProviderInterface, every declared
 * prefix also claims its descendants. The metadata must remain cheap and the
 * same prefix must be present in publicRoutePrefixes(). Project-owned routes,
 * files and showroom subroutes retain priority.
 */
interface ModulePreBootstrapPublicRoutePrefixProviderInterface extends
    ModulePublicRouteProviderInterface
{
    /** @return list<string> */
    public static function preBootstrapPublicRoutePrefixes(
        ModuleRuntimeContext $context
    ): array;
}
