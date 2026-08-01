<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;

/** Declarative, side-effect-free navigation contribution for WebAdmin. */
interface ModuleWebAdminNavigationProviderInterface extends
    ModuleProviderInterface
{
    public function webAdminNavigationItem(): WebAdminNavigationItem;
}
