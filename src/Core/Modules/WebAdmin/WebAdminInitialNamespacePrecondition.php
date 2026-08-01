<?php

declare(strict_types=1);

namespace App\Core\Modules\WebAdmin;

use App\Core\Database\MySqlServerCapabilities;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPreconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/**
 * Requires an unused physical namespace before WebAdmin migration 0001.
 *
 * Existing or partial objects are never adopted, repaired or removed. The
 * operator must inspect and recover them manually before retrying.
 */
final class WebAdminInitialNamespacePrecondition implements
    MigrationPreconditionVerifierInterface
{
    public function contractVersion(): string
    {
        return 'webadmin-initial-namespace-empty-v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        try {
            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;

            return match ($driver) {
                'mysql' => $this->mysqlNamespaceIsEmpty($pdo, $scope),
                'sqlite' => $this->sqliteNamespaceIsEmpty($pdo, $scope),
                default => false,
            };
        } catch (Throwable) {
            return false;
        }
    }

    private function mysqlNamespaceIsEmpty(
        PDO $pdo,
        MigrationScope $scope
    ): bool {
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        if (
            !is_string($version)
            || !MySqlServerCapabilities::supportsReliableCheckMetadata(
                $version
            )
        ) {
            return false;
        }

        $prefix = strtolower($scope->tablePrefix());
        $tables = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() '
            . 'AND LOWER(LEFT(TABLE_NAME, :prefix_length)) = :prefix '
            . 'AND LOWER(TABLE_NAME) <> :registry_table'
        );
        $tables->execute([
            'prefix_length' => strlen($prefix),
            'prefix' => $prefix,
            'registry_table' => strtolower(MigrationRegistry::TABLE),
        ]);
        if (!$this->isZeroCount($tables->fetchColumn())) {
            return false;
        }

        $constraints = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA = DATABASE() '
            . 'AND LOWER(LEFT(CONSTRAINT_NAME, :prefix_length)) = :prefix'
        );
        $constraints->execute([
            'prefix_length' => strlen($prefix),
            'prefix' => $prefix,
        ]);

        return $this->isZeroCount($constraints->fetchColumn());
    }

    private function sqliteNamespaceIsEmpty(
        PDO $pdo,
        MigrationScope $scope
    ): bool {
        $prefix = strtolower($scope->tablePrefix());
        $objects = $pdo->prepare(
            'SELECT COUNT(*) FROM ('
            . 'SELECT name FROM sqlite_master '
            . "WHERE type IN ('table', 'view', 'index', 'trigger') "
            . 'AND lower(substr(name, 1, :main_length)) = :main_prefix '
            . 'AND lower(name) <> :registry_table '
            . 'UNION ALL '
            . 'SELECT name FROM sqlite_temp_master '
            . "WHERE type IN ('table', 'view', 'index', 'trigger') "
            . 'AND lower(substr(name, 1, :temp_length)) = :temp_prefix'
            . ') AS namespace_objects'
        );
        $objects->execute([
            'main_length' => strlen($prefix),
            'main_prefix' => $prefix,
            'registry_table' => strtolower(MigrationRegistry::TABLE),
            'temp_length' => strlen($prefix),
            'temp_prefix' => $prefix,
        ]);
        if (!$this->isZeroCount($objects->fetchColumn())) {
            return false;
        }

        foreach (['sqlite_master', 'sqlite_temp_master'] as $catalog) {
            $statement = $pdo->query(
                'SELECT sql FROM ' . $catalog
                . " WHERE type = 'table' AND sql IS NOT NULL"
            );
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $sql) {
                if (
                    is_string($sql)
                    && $this->containsConstraintPrefix($sql, $prefix)
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    private function isZeroCount(mixed $value): bool
    {
        return $value === 0 || $value === '0';
    }

    private function containsConstraintPrefix(
        string $sql,
        string $prefix
    ): bool {
        $length = strlen($sql);
        for ($index = 0; $index < $length;) {
            $this->skipSqlTrivia($sql, $index);
            if ($index >= $length) {
                break;
            }
            if (in_array($sql[$index], ["'", '"', '`', '['], true)) {
                $this->readSqlIdentifier($sql, $index);
                continue;
            }
            if (preg_match('/[a-z_]/i', $sql[$index]) !== 1) {
                $index++;
                continue;
            }
            $token = $this->readSqlIdentifier($sql, $index);
            if (strtolower((string) $token) !== 'constraint') {
                continue;
            }
            $this->skipSqlTrivia($sql, $index);
            $name = $this->readSqlIdentifier($sql, $index);
            if (
                is_string($name)
                && str_starts_with(strtolower($name), $prefix)
            ) {
                return true;
            }
        }

        return false;
    }

    private function skipSqlTrivia(string $sql, int &$index): void
    {
        $length = strlen($sql);
        while ($index < $length) {
            if (ctype_space($sql[$index])) {
                $index++;
                continue;
            }
            if ($sql[$index] === '-' && ($sql[$index + 1] ?? '') === '-') {
                $index += 2;
                while (
                    $index < $length
                    && !in_array($sql[$index], ["\r", "\n"], true)
                ) {
                    $index++;
                }
                continue;
            }
            if ($sql[$index] === '/' && ($sql[$index + 1] ?? '') === '*') {
                $index += 2;
                while (
                    $index + 1 < $length
                    && !(
                        $sql[$index] === '*'
                        && $sql[$index + 1] === '/'
                    )
                ) {
                    $index++;
                }
                $index = min($length, $index + 2);
                continue;
            }
            break;
        }
    }

    private function readSqlIdentifier(string $sql, int &$index): ?string
    {
        $length = strlen($sql);
        if ($index >= $length) {
            return null;
        }
        $opening = $sql[$index];
        if (in_array($opening, ["'", '"', '`', '['], true)) {
            $closing = $opening === '[' ? ']' : $opening;
            $index++;
            $value = '';
            while ($index < $length) {
                if ($sql[$index] !== $closing) {
                    $value .= $sql[$index++];
                    continue;
                }
                if (($sql[$index + 1] ?? '') === $closing) {
                    $value .= $closing;
                    $index += 2;
                    continue;
                }
                $index++;
                break;
            }

            return $value;
        }
        if (preg_match('/[a-z_]/i', $opening) !== 1) {
            return null;
        }
        $start = $index++;
        while (
            $index < $length
            && preg_match('/[a-z0-9_]/i', $sql[$index]) === 1
        ) {
            $index++;
        }

        return substr($sql, $start, $index - $start);
    }
}
