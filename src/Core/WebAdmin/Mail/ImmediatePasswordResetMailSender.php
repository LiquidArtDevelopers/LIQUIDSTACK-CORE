<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Mail;

use App\Core\WebAdmin\CredentialAction\PasswordResetDelivery;

/** Sends exactly one recovery message during the originating HTTP request. */
final class ImmediatePasswordResetMailSender implements
    PasswordResetMailSenderInterface
{
    public function __construct(
        private readonly WebAdminCredentialMailMessageFactory $messages,
        private readonly WebAdminMailTransportInterface $transport
    ) {
    }

    public function send(PasswordResetDelivery $delivery): void
    {
        $this->transport->send($this->messages->create(
            WebAdminCredentialMailMessageFactory::KIND_PASSWORD_RESET,
            $delivery->recipientEmail(),
            $delivery->locale(),
            $delivery->rawToken()
        ));
    }
}
