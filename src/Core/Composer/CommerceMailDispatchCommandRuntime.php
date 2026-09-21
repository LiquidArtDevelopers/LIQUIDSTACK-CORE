<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Commerce\Outbox\CommerceInquiryOutboxDispatcher;
use App\Core\Commerce\Outbox\CommerceOutboxDispatchReport;

final class CommerceMailDispatchCommandRuntime implements
    CommerceMailDispatchCommandRuntimeInterface
{
    public function __construct(
        private readonly CommerceInquiryOutboxDispatcher $dispatcher
    ) {
    }

    public function dispatch(int $limit): CommerceOutboxDispatchReport
    {
        return $this->dispatcher->dispatchBatch($limit);
    }
}
