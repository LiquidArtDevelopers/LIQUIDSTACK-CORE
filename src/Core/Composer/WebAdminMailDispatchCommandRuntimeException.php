<?php

declare(strict_types=1);

namespace App\Core\Composer;

use RuntimeException;

final class WebAdminMailDispatchCommandRuntimeException extends RuntimeException
{
    public function __construct(private readonly string $issueCode)
    {
        parent::__construct('WebAdmin mail dispatch runtime is unavailable.');
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
