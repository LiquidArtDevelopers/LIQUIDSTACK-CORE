<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\CredentialAction;

/**
 * The public acceptance contract never discloses identity state. A separate
 * process-local flag lets the HTTP boundary show a generic retry result only
 * when an eligible request could not be handed to SMTP.
 */
final class PasswordResetRequestResult
{
    public const PUBLIC_MESSAGE = 'webadmin.password_reset.request_accepted';

    public function __construct(
        private readonly bool $deliveryFailed = false
    ) {
    }

    public function accepted(): bool
    {
        return true;
    }

    public function publicMessageCode(): string
    {
        return self::PUBLIC_MESSAGE;
    }

    public function deliveryFailed(): bool
    {
        return $this->deliveryFailed;
    }
}
