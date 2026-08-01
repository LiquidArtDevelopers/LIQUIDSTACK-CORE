<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Mail;

interface WebAdminMailTransportInterface
{
    public function send(WebAdminMailMessage $message): void;
}
