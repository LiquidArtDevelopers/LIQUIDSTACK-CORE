<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

use App\Core\Modules\ModuleRuntimeContext;
use App\Core\WebAdmin\Configuration\WebAdminConfig;

interface WebAdminHttpRuntimeFactoryInterface
{
    public function create(
        ModuleRuntimeContext $context,
        WebAdminConfig $config
    ): WebAdminHttpRuntime;
}
