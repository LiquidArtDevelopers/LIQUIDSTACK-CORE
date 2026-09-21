<?php

declare(strict_types=1);

namespace App\Core\Composer;

use RuntimeException;

final class CommerceMailDispatchCommandRuntimeException extends RuntimeException
{
    public function __construct(private readonly string $issueCode)
    {
        parent::__construct('Commerce mail dispatch runtime is unavailable.');
    }

    public function issueCode(): string { return $this->issueCode; }
}
