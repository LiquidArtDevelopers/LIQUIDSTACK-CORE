<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Bootstrap;

use RuntimeException;

final class BootstrapException extends RuntimeException
{
    public function __construct(private readonly string $issueCode)
    {
        parent::__construct(
            'No se pudo completar el bootstrap de WebAdmin de forma segura.'
        );
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
