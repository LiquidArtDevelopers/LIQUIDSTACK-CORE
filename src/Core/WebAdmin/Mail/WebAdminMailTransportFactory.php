<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Mail;

/** Canonical transport selection shared by HTTP and CLI runtimes. */
final class WebAdminMailTransportFactory
{
    public function create(
        WebAdminMailConfiguration $configuration
    ): WebAdminMailTransportInterface {
        return $configuration->isLocalCaptureSmtp()
            ? new LocalCaptureSmtpWebAdminTransport($configuration)
            : new PhpMailerWebAdminTransport($configuration);
    }
}
