<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\CredentialAction;

/**
 * Deliberately constant public contract. It cannot disclose whether an
 * identity exists, is eligible, was rate-limited or already had queued work.
 */
final class PasswordResetRequestResult
{
    public const PUBLIC_MESSAGE = 'webadmin.password_reset.request_accepted';

    public function accepted(): bool
    {
        return true;
    }

    public function publicMessageCode(): string
    {
        return self::PUBLIC_MESSAGE;
    }
}
