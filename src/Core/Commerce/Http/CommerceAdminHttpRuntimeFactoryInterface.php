<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use App\Core\Modules\ModuleRuntimeContext;
use App\Core\WebAdmin\Configuration\WebAdminConfig;

interface CommerceAdminHttpRuntimeFactoryInterface
{
    public function create(
        ModuleRuntimeContext $context,
        WebAdminConfig $webAdminConfig
    ): CommerceAdminHttpRuntimeInterface;
}
