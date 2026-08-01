<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Modules\ModuleRuntimeContext;
use App\Core\WebAdmin\Configuration\WebAdminConfig;

interface BlogAdminHttpRuntimeFactoryInterface
{
    public function create(
        ModuleRuntimeContext $context,
        WebAdminConfig $webAdminConfig
    ): BlogAdminHttpRuntimeInterface;
}
