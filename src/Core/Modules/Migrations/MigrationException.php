<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use RuntimeException;

final class MigrationException extends RuntimeException
{
    public function __construct(
        private readonly string $issueCode,
        private readonly ?string $moduleId = null,
        private readonly ?string $migrationId = null
    ) {
        parent::__construct('La operación de migraciones no pudo completarse de forma segura.');
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }

    public function moduleId(): ?string
    {
        return $this->moduleId;
    }

    public function migrationId(): ?string
    {
        return $this->migrationId;
    }
}
