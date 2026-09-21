<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Commerce\Outbox\CommerceOutboxDispatchReport;

interface CommerceMailDispatchCommandRuntimeInterface
{
    public function dispatch(int $limit): CommerceOutboxDispatchReport;
}
