<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\WebAdmin\Outbox\WebAdminOutboxDispatchReport;

interface WebAdminMailDispatchCommandRuntimeInterface
{
    public function dispatch(int $limit): WebAdminOutboxDispatchReport;
}
