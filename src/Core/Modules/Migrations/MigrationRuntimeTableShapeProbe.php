<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Bounded request-time proof that repository-facing tables and columns exist.
 *
 * Migration postconditions remain responsible for exact DDL, constraints,
 * indexes, foreign keys, triggers and stored-row audits. This probe performs
 * only zero-row SELECTs, so HTTP readiness never walks database metadata.
 */
final class MigrationRuntimeTableShapeProbe
{
    /**
     * @param array<string, list<string>> $tables
     */
    public function hasColumns(
        PDO $pdo,
        MigrationScope $scope,
        array $tables
    ): bool {
        try {
            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            foreach ($tables as $suffix => $columns) {
                if (
                    preg_match('/\A[a-z][a-z0-9_]*\z/', $suffix) !== 1
                    || $columns === []
                ) {
                    return false;
                }

                $quotedColumns = [];
                foreach ($columns as $column) {
                    if (preg_match('/\A[a-z][a-z0-9_]*\z/', $column) !== 1) {
                        return false;
                    }
                    // Qualifying the identifier is significant on SQLite:
                    // an unknown bare double-quoted token can be interpreted
                    // as a string literal when DQS compatibility is enabled.
                    $quotedColumns[] = $driver === 'mysql'
                        ? 'runtime_shape.`' . $column . '`'
                        : 'runtime_shape."' . $column . '"';
                }

                $statement = $pdo->query(
                    'SELECT ' . implode(', ', $quotedColumns) . ' FROM '
                        . $scope->quotedTable($suffix, $driver)
                        . ' AS runtime_shape'
                        . ' WHERE 1 = 0'
                );
                if (!$statement instanceof PDOStatement) {
                    return false;
                }
                $statement->closeCursor();
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
