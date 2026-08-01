<?php

declare(strict_types=1);

namespace App\Core\Database;

use RuntimeException;

final class DatabaseConnectionException extends RuntimeException
{
    public function __construct(private readonly string $issueCode)
    {
        parent::__construct(
            'No se pudo preparar la conexión de base de datos de forma segura.'
        );
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
