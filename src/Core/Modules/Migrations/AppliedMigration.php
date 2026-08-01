<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use InvalidArgumentException;

final class AppliedMigration
{
    public function __construct(
        private readonly string $moduleId,
        private readonly string $migrationId,
        private readonly string $checksum,
        private readonly string $scopeHash,
        private readonly int $batch,
        private readonly string $appliedAt
    ) {
        if (
            preg_match('/\A[a-z][a-z0-9-]{0,62}\z/', $moduleId) !== 1
            || strlen($migrationId) > 190
            || preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $migrationId) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $scopeHash) !== 1
            || $batch < 1
            || $appliedAt === ''
            || preg_match('/[\x00-\x1F\x7F]/', $appliedAt) === 1
        ) {
            throw new InvalidArgumentException(
                'Registro de migración aplicada inválido.'
            );
        }
    }

    public function moduleId(): string
    {
        return $this->moduleId;
    }

    public function migrationId(): string
    {
        return $this->migrationId;
    }

    public function checksum(): string
    {
        return $this->checksum;
    }

    public function scopeHash(): string
    {
        return $this->scopeHash;
    }

    public function batch(): int
    {
        return $this->batch;
    }

    public function appliedAt(): string
    {
        return $this->appliedAt;
    }
}
