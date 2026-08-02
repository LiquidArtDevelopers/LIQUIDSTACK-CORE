<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Http;

use App\Core\Modules\ModuleRuntimeContext;
use App\Core\WebAdmin\Configuration\WebAdminConfig;

interface WebAdminMediaHttpRuntimeFactoryInterface
{
    public function create(
        ModuleRuntimeContext $context,
        WebAdminConfig $config
    ): WebAdminMediaHttpRuntime;
}
