<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use PDO;

/** Shared immutable contract for read-only migration state verifiers. */
interface MigrationConditionVerifierInterface
{
    /** Versioned as part of the migration's canonical checksum contract. */
    public function contractVersion(): string;

    public function verify(PDO $pdo, MigrationScope $scope): bool;
}
