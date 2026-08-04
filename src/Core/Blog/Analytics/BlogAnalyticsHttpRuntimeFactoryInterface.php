<?php

declare(strict_types=1);

namespace App\Core\Blog\Analytics;

use App\Core\Modules\ModuleRuntimeContext;

interface BlogAnalyticsHttpRuntimeFactoryInterface
{
    public function create(ModuleRuntimeContext $context): BlogAnalyticsHttpRuntime;
}
