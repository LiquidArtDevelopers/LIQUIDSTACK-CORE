<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use InvalidArgumentException;

final class MigrationApplyOptions
{
    public function __construct(
        private readonly ?string $expectedPlanHash = null,
        private readonly bool $allowDestructive = false,
        private readonly bool $backupConfirmed = false,
        private readonly int $lockTimeoutSeconds = 10
    ) {
        if (
            $expectedPlanHash !== null
            && preg_match('/\A[a-f0-9]{64}\z/', $expectedPlanHash) !== 1
        ) {
            throw new InvalidArgumentException('Hash de plan inválido.');
        }
        if ($lockTimeoutSeconds < 0 || $lockTimeoutSeconds > 300) {
            throw new InvalidArgumentException(
                'El timeout del lock debe estar entre 0 y 300 segundos.'
            );
        }
    }

    public function expectedPlanHash(): ?string
    {
        return $this->expectedPlanHash;
    }

    public function allowDestructive(): bool
    {
        return $this->allowDestructive;
    }

    public function backupConfirmed(): bool
    {
        return $this->backupConfirmed;
    }

    public function lockTimeoutSeconds(): int
    {
        return $this->lockTimeoutSeconds;
    }
}
