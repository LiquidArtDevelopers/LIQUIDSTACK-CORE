<?php

declare(strict_types=1);

namespace App\Core\Modules\Commerce;

use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPreconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Rejects implicit adoption of a pre-existing Commerce namespace. */
final class CommerceInitialNamespacePrecondition implements
    MigrationPreconditionVerifierInterface
{
    public function contractVersion(): string
    {
        return 'commerce-initial-namespace-empty-v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        if ($scope->moduleId() !== 'commerce') {
            return false;
        }

        try {
            $prefix = strtolower($scope->tablePrefix());
            return match (MigrationDatabaseDriver::fromPdo($pdo)->value) {
                'mysql' => $this->mysqlIsEmpty($pdo, $prefix),
                'sqlite' => $this->sqliteIsEmpty($pdo, $prefix),
                default => false,
            };
        } catch (Throwable) {
            return false;
        }
    }

    private function mysqlIsEmpty(PDO $pdo, string $prefix): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() '
            . 'AND LOWER(LEFT(TABLE_NAME, :prefix_length)) = :prefix '
            . 'AND LOWER(TABLE_NAME) <> :registry_table'
        );
        $statement->execute([
            'prefix_length' => strlen($prefix),
            'prefix' => $prefix,
            'registry_table' => strtolower(MigrationRegistry::TABLE),
        ]);

        return (int) $statement->fetchColumn() === 0;
    }

    private function sqliteIsEmpty(PDO $pdo, string $prefix): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM sqlite_master '
            . "WHERE type IN ('table', 'view', 'index', 'trigger') "
            . 'AND lower(substr(name, 1, :prefix_length)) = :prefix '
            . 'AND lower(name) <> :registry_table'
        );
        $statement->execute([
            'prefix_length' => strlen($prefix),
            'prefix' => $prefix,
            'registry_table' => strtolower(MigrationRegistry::TABLE),
        ]);

        return (int) $statement->fetchColumn() === 0;
    }
}
