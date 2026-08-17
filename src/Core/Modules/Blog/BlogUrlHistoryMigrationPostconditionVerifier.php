<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationConditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use PDOStatement;
use Throwable;

final class BlogUrlHistoryMigrationPostconditionVerifier implements
    MigrationPostconditionVerifierInterface
{
    private readonly MigrationConditionVerifierInterface $baseVerifier;

    public function __construct(
        ?MigrationConditionVerifierInterface $baseVerifier = null
    ) {
        $this->baseVerifier = $baseVerifier
            ?? new BlogRobotsPreferencesMigrationPostconditionVerifier(
                expectUrlHistoryExtension: true
            );
    }

    public function contractVersion(): string
    {
        return 'blog-url-history-schema-v2-with-prior-frontier';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        if ($scope->moduleId() !== 'blog') {
            return false;
        }
        try {
            if (!$this->baseVerifier->verify($pdo, $scope)) {
                return false;
            }
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (!is_string($driver) || !in_array($driver, ['mysql', 'sqlite'], true)) {
                return false;
            }
            $statement = $pdo->query(
                'SELECT localization_id, locale, slug, state, '
                    . 'replacement_localization_id, created_at, updated_at FROM '
                    . $scope->quotedTable('url_history', $driver)
                    . ' WHERE 1 = 0'
            );
            if (!$statement instanceof PDOStatement) {
                return false;
            }
            $statement->closeCursor();
            if (!$this->schemaContractIsPresent($pdo, $scope, $driver)) {
                return false;
            }
            $invalid = $pdo->query(
                'SELECT COUNT(*) FROM ' . $scope->quotedTable('url_history', $driver)
                    . ' h JOIN ' . $scope->quotedTable('post_localizations', $driver)
                    . ' owner ON owner.id = h.localization_id LEFT JOIN '
                    . $scope->quotedTable('post_localizations', $driver)
                    . ' target ON target.id = h.replacement_localization_id'
                    . " WHERE h.state NOT IN ('active','temporary_not_found','gone','redirect')"
                    . " OR (h.state = 'redirect') <> (h.replacement_localization_id IS NOT NULL)"
                    . ' OR h.locale <> owner.locale OR (h.state = \'redirect\' '
                    . 'AND (target.id IS NULL OR target.locale <> h.locale))'
            );

            return $invalid instanceof PDOStatement
                && (int) $invalid->fetchColumn() === 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function schemaContractIsPresent(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $table = $scope->tableName('url_history');
        if ($driver === 'sqlite') {
            $query = $pdo->prepare(
                "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :name"
            );
            $query->execute(['name' => $table]);
            $definition = $query->fetchColumn();
            $columns = $pdo->query(
                'PRAGMA table_info(' . $scope->quotedTable('url_history', 'sqlite') . ')'
            )->fetchAll(PDO::FETCH_ASSOC);
            $foreignKeys = $pdo->query(
                'PRAGMA foreign_key_list('
                    . $scope->quotedTable('url_history', 'sqlite') . ')'
            )->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $row = $pdo->query(
                'SHOW CREATE TABLE ' . $scope->quotedTable('url_history', 'mysql')
            )->fetch(PDO::FETCH_NUM);
            $definition = is_array($row) ? ($row[1] ?? null) : null;
            $query = $pdo->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE '
                    . 'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                    . 'ORDER BY ORDINAL_POSITION'
            );
            $query->execute(['name' => $table]);
            $columns = array_map(
                static fn (array $row): array => ['name' => $row['COLUMN_NAME'] ?? null],
                $query->fetchAll(PDO::FETCH_ASSOC)
            );
            $query = $pdo->prepare(
                'SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE '
                    . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                    . 'AND REFERENCED_TABLE_NAME IS NOT NULL'
            );
            $query->execute(['name' => $table]);
            $foreignKeys = $query->fetchAll(PDO::FETCH_ASSOC);
        }
        $names = array_map(
            static fn (array $row): string => strtolower((string) ($row['name'] ?? '')),
            $columns
        );
        if ($names !== [
            'localization_id',
            'locale',
            'slug',
            'state',
            'replacement_localization_id',
            'created_at',
            'updated_at',
        ] || count($foreignKeys) !== 2 || !is_string($definition)) {
            return false;
        }
        $normalized = strtolower(
            preg_replace('/[\s`"]+/', '', $definition) ?? ''
        );

        return str_contains($normalized, 'primarykey(locale,slug)')
            && str_contains($normalized, 'temporary_not_found')
            && str_contains($normalized, "state='redirect'")
            && str_contains($normalized, 'replacement_localization_idisnotnull');
    }
}
