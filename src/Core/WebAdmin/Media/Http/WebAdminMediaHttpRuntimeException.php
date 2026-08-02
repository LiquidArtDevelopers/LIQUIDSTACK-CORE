<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Http;

use RuntimeException;

final class WebAdminMediaHttpRuntimeException extends RuntimeException
{
    public function __construct(private readonly string $issueCode)
    {
        parent::__construct('WebAdmin media runtime unavailable.');
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
