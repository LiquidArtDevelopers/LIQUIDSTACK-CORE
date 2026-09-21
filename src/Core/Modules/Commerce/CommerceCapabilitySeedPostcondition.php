<?php

declare(strict_types=1);

namespace App\Core\Modules\Commerce;

use App\Core\Commerce\CommerceCapabilities;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

final class CommerceCapabilitySeedPostcondition implements
    MigrationPostconditionVerifierInterface
{
    /** @var array<string, array{string, int}> */
    private const EXPECTED = [
        CommerceCapabilities::PRODUCTS_VIEW =>
            ['commerce.capabilities.products_view', 1],
        CommerceCapabilities::PRODUCTS_EDIT =>
            ['commerce.capabilities.products_edit', 1],
        CommerceCapabilities::PRODUCTS_PUBLISH =>
            ['commerce.capabilities.products_publish', 1],
        CommerceCapabilities::PRODUCTS_ARCHIVE =>
            ['commerce.capabilities.products_archive', 1],
        CommerceCapabilities::TAXONOMIES_VIEW =>
            ['commerce.capabilities.taxonomies_view', 1],
        CommerceCapabilities::TAXONOMIES_EDIT =>
            ['commerce.capabilities.taxonomies_edit', 1],
        CommerceCapabilities::INQUIRIES_VIEW =>
            ['commerce.capabilities.inquiries_view', 1],
        CommerceCapabilities::INQUIRIES_MANAGE =>
            ['commerce.capabilities.inquiries_manage', 1],
        CommerceCapabilities::SETTINGS_MANAGE =>
            ['commerce.capabilities.settings_manage', 0],
    ];

    public function contractVersion(): string
    {
        return 'commerce-capability-seeds-v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        if ($scope->moduleId() !== 'webadmin') {
            return false;
        }

        try {
            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            $capabilities = $scope->quotedTable('capabilities', $driver);
            $roles = $scope->quotedTable('roles', $driver);
            $roleCapabilities = $scope->quotedTable(
                'role_capabilities',
                $driver
            );
            [$in, $params] = $this->inClause(
                array_keys(self::EXPECTED),
                'capability'
            );
            $statement = $pdo->prepare(
                'SELECT code, module_id, label_key, is_delegable FROM '
                . $capabilities . ' WHERE code IN (' . $in . ')'
            );
            $statement->execute($params);
            $actual = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $actual[(string) $row['code']] = [
                    (string) $row['label_key'],
                    (int) $row['is_delegable'],
                    (string) $row['module_id'],
                ];
            }
            foreach (self::EXPECTED as $code => [$label, $delegable]) {
                if (($actual[$code] ?? null) !== [
                    $label,
                    $delegable,
                    'commerce',
                ]) {
                    return false;
                }
            }

            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM ' . $roleCapabilities . ' AS rc '
                . 'JOIN ' . $roles . ' AS r ON r.id = rc.role_id '
                . 'JOIN ' . $capabilities . ' AS c '
                . 'ON c.id = rc.capability_id '
                . "WHERE r.code IN ('system_superadmin', 'site_admin') "
                . 'AND r.is_protected = 1 AND c.code IN (' . $in . ')'
            );
            $statement->execute($params);

            return (int) $statement->fetchColumn()
                === count(self::EXPECTED) * 2;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<string> $values @return array{string, array<string, string>} */
    private function inClause(array $values, string $prefix): array
    {
        $params = [];
        $placeholders = [];
        foreach (array_values($values) as $index => $value) {
            $key = $prefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }

        return [implode(', ', $placeholders), $params];
    }
}
