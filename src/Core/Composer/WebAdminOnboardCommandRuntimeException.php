<?php

declare(strict_types=1);

namespace App\Core\Composer;

use RuntimeException;

final class WebAdminOnboardCommandRuntimeException extends RuntimeException
{
    public function __construct(private readonly string $issueCode)
    {
        parent::__construct(
            'WebAdmin initial-access onboarding runtime is unavailable.'
        );
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
