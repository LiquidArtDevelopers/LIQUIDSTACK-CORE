<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use RuntimeException;

final class CommerceAdminHttpRuntimeException extends RuntimeException
{
    public function __construct(private readonly string $issueCode)
    {
        parent::__construct('Commerce administration is unavailable.');
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
