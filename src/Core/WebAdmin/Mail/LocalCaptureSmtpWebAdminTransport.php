<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Mail;

use Closure;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

/**
 * Plain SMTP adapter reserved for a capture service on the same loopback host.
 *
 * The configuration loader is the primary environment gate. This adapter
 * repeats the invariant so an injected or manually-built configuration cannot
 * turn the development exception into a plaintext remote SMTP transport.
 */
final class LocalCaptureSmtpWebAdminTransport implements
    WebAdminMailTransportInterface
{
    /** @var Closure(): PHPMailer */
    private readonly Closure $mailerFactory;

    /** @param (Closure(): PHPMailer)|null $mailerFactory */
    public function __construct(
        private readonly WebAdminMailConfiguration $configuration,
        ?Closure $mailerFactory = null
    ) {
        if (
            !$configuration->isLocalCaptureSmtp()
            || $configuration->usesSmtpAuthentication()
            || $configuration->smtpEncryption()
                !== WebAdminMailConfiguration::ENCRYPTION_NONE
            || !in_array(
                $configuration->smtpHost(),
                ['127.0.0.1', '[::1]'],
                true
            )
        ) {
            throw new WebAdminMailTransportException();
        }

        $this->mailerFactory = $mailerFactory
            ?? static fn (): PHPMailer => new PHPMailer(true);
    }

    public function send(WebAdminMailMessage $message): void
    {
        $mailer = null;

        try {
            $candidate = ($this->mailerFactory)();
            if (!$candidate instanceof PHPMailer) {
                throw new WebAdminMailTransportException();
            }
            $mailer = $candidate;

            $this->clearMessageState($mailer);
            $this->configureTransport($mailer);
            $this->configureMessage($mailer, $message);

            if ($mailer->send() !== true) {
                throw new WebAdminMailTransportException();
            }
        } catch (Throwable) {
            throw new WebAdminMailTransportException();
        } finally {
            if ($mailer instanceof PHPMailer) {
                $this->clearMessageState($mailer);
                $mailer->Username = '';
                $mailer->Password = '';
            }
        }
    }

    private function configureTransport(PHPMailer $mailer): void
    {
        $mailer->isSMTP();
        $mailer->SMTPAuth = false;
        $mailer->Host = $this->configuration->smtpHost();
        $mailer->Port = $this->configuration->smtpPort();
        $mailer->Username = '';
        $mailer->Password = '';
        $mailer->SMTPSecure = '';
        $mailer->SMTPAutoTLS = false;
        $mailer->SMTPOptions = [];
        $mailer->SMTPKeepAlive = false;
        $mailer->SMTPDebug = 0;
        $mailer->Timeout = WebAdminMailConfiguration::SMTP_TIMEOUT_SECONDS;
        $smtp = $mailer->getSMTPInstance();
        $smtp->setTimeout(WebAdminMailConfiguration::SMTP_TIMEOUT_SECONDS);
        $smtp->Timelimit = WebAdminMailConfiguration::SMTP_TIMEOUT_SECONDS;
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        $mailer->XMailer = 'LiquidStack WebAdmin';
    }

    private function configureMessage(
        PHPMailer $mailer,
        WebAdminMailMessage $message
    ): void {
        if (
            $mailer->setFrom(
                $this->configuration->fromAddress(),
                $this->configuration->fromName(),
                false
            ) !== true
            || $mailer->addAddress(
                $message->recipientEmail(),
                $message->recipientName() ?? ''
            ) !== true
        ) {
            throw new WebAdminMailTransportException();
        }
        $mailer->isHTML(true);
        $mailer->Subject = $message->subject();
        $mailer->Body = $message->htmlBody();
        $mailer->AltBody = $message->textBody();
    }

    private function clearMessageState(PHPMailer $mailer): void
    {
        $mailer->clearAllRecipients();
        $mailer->clearReplyTos();
        $mailer->clearAttachments();
        $mailer->clearCustomHeaders();
        $mailer->Subject = '';
        $mailer->Body = '';
        $mailer->AltBody = '';
        $mailer->ErrorInfo = '';
    }
}
