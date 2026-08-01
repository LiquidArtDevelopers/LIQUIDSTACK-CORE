<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Modules\ModuleRuntimeContext;

interface BlogPublicHttpRuntimeFactoryInterface
{
    public function create(
        ModuleRuntimeContext $context
    ): BlogPublicHttpRuntime;
}
