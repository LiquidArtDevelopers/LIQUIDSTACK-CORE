<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

/**
 * Read-only invariant for the initial migration of one module.
 *
 * It is evaluated against batch-start state in preview and again under lock,
 * before any migration writes. Later catalog entries cannot declare one; a
 * condition that depends on an earlier pending migration belongs in that
 * migration's postcondition instead.
 */
interface MigrationPreconditionVerifierInterface extends
    MigrationConditionVerifierInterface
{
}
