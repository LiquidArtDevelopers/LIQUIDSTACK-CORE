<?php

declare(strict_types=1);

namespace App\Core\Composer;

use RuntimeException;

final class MediaInitCommandRuntimeException extends RuntimeException
{
    public function __construct(private readonly string $issueCode)
    {
        parent::__construct('WebAdmin media initialization runtime failed.');
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
