<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\CredentialAction;

use DateTimeImmutable;
use InvalidArgumentException;

/** Safe metadata for a valid action already bound to a browser session. */
final class BoundCredentialAction
{
    public function __construct(
        private readonly string $purpose,
        private readonly DateTimeImmutable $expiresAt
    ) {
        if (!CredentialActionService::isSupportedPurpose($purpose)) {
            throw new InvalidArgumentException(
                'Invalid WebAdmin credential-action purpose.'
            );
        }
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
