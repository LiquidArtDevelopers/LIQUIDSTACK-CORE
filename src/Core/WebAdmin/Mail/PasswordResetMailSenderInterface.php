<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Mail;

use App\Core\WebAdmin\CredentialAction\PasswordResetDelivery;

interface PasswordResetMailSenderInterface
{
    public function send(PasswordResetDelivery $delivery): void;
}
