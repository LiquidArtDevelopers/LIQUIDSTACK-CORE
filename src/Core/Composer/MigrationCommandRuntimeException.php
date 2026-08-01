<?php

declare(strict_types=1);

namespace App\Core\Composer;

use RuntimeException;

final class MigrationCommandRuntimeException extends RuntimeException
{
    public function __construct(private readonly string $issueCode)
    {
        parent::__construct(
            'No se pudo preparar el runtime de migraciones de forma segura.'
        );
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
