<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

/**
 * Verifies the durable database contract produced by one migration.
 *
 * Implementations must be read-only, deterministic for a given schema/data
 * state and must not expose database details through thrown exceptions.
 */
interface MigrationPostconditionVerifierInterface extends
    MigrationConditionVerifierInterface
{
}
