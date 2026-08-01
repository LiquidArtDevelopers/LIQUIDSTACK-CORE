<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Mail;

use Closure;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

/**
 * Production SMTP adapter.
 *
 * The optional factory is an explicit test seam. Production always creates a
 * fresh exception-enabled PHPMailer instance for every delivery.
 */
final class PhpMailerWebAdminTransport implements WebAdminMailTransportInterface
{
    /** @var Closure(): PHPMailer */
    private readonly Closure $mailerFactory;

    /** @param (Closure(): PHPMailer)|null $mailerFactory */
    public function __construct(
        private readonly WebAdminMailConfiguration $configuration,
        ?Closure $mailerFactory = null
    ) {
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

            // Never retain recipients or message material if a factory reuses
            // an instance. The finally block repeats this after every outcome.
            $this->clearMessageState($mailer);
            $this->configureTransport($mailer);
            $this->configureMessage($mailer, $message);

            if ($mailer->send() !== true) {
                throw new WebAdminMailTransportException();
            }
        } catch (Throwable) {
            // Do not chain the original exception: SMTP diagnostics can expose
            // server names, account identifiers, recipients or message data.
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
        $mailer->SMTPAuth = true;
        $mailer->Host = $this->configuration->smtpHost();
        $mailer->Port = $this->configuration->smtpPort();
        $mailer->Username = $this->configuration->smtpUsername();
        $mailer->Password = $this->configuration->smtpPassword();
        $mailer->SMTPSecure = $this->configuration->smtpEncryption()
            === WebAdminMailConfiguration::ENCRYPTION_SMTPS
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->SMTPAutoTLS = true;
        $mailer->SMTPOptions = [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ];
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
