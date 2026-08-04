<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Blog\Analytics\BlogAnalyticsPurgeResult;

interface BlogAnalyticsPurgeCommandRuntimeInterface
{
    public function purge(): BlogAnalyticsPurgeResult;
}
