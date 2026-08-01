<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use PDO;

interface MigrationLockInterface
{
    public function acquire(PDO $pdo, int $timeoutSeconds): void;

    /**
     * SQLite commits or rolls back its lock transaction here. MySQL only
     * releases the advisory lease.
     */
    public function release(PDO $pdo, bool $successful): void;

    public function ownsBatchTransaction(): bool;
}
