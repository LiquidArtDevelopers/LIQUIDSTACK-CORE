<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

use RuntimeException;

final class WebAdminHttpRuntimeException extends RuntimeException
{
    public function __construct(private readonly string $issueCode)
    {
        parent::__construct('WebAdmin runtime is unavailable.');
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
