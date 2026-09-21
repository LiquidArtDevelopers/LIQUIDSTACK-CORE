<?php

declare(strict_types=1);

namespace App\Core\Commerce\Outbox;

use App\Core\WebAdmin\Mail\WebAdminMailMessage;

interface CommerceInquiryMailMessageFactoryInterface
{
    public function create(CommerceOutboxLease $lease): WebAdminMailMessage;
}
