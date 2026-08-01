<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Outbox;

use App\Core\WebAdmin\Mail\WebAdminMailMessage;
use SensitiveParameter;

interface WebAdminOutboxMessageFactoryInterface
{
    public function create(
        string $kind,
        string $recipientEmail,
        string $locale,
        #[SensitiveParameter] string $rawToken
    ): WebAdminMailMessage;
}
