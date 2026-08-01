<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Mail;

use RuntimeException;

/** Stable failure boundary: SMTP and PHPMailer diagnostics stay private. */
final class WebAdminMailTransportException extends RuntimeException
{
    public const ISSUE_CODE = 'mail.transport_failed';

    public function __construct()
    {
        parent::__construct('WebAdmin mail delivery failed.');
    }

    public function issueCode(): string
    {
        return self::ISSUE_CODE;
    }
}
