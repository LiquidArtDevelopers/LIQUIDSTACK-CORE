<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\WebAdmin\Outbox\WebAdminOutboxDispatcher;
use App\Core\WebAdmin\Outbox\WebAdminOutboxDispatchReport;

final class WebAdminMailDispatchCommandRuntime implements
    WebAdminMailDispatchCommandRuntimeInterface
{
    public function __construct(
        private readonly WebAdminOutboxDispatcher $dispatcher
    ) {
    }

    public function dispatch(int $limit): WebAdminOutboxDispatchReport
    {
        return $this->dispatcher->dispatchBatch($limit);
    }
}
