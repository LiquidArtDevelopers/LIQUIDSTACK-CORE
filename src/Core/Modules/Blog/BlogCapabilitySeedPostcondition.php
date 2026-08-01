<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Verifies only Blog-owned capability seeds inside the WebAdmin scope. */
final class BlogCapabilitySeedPostcondition implements
    MigrationPostconditionVerifierInterface
{
    /** @var array<string, string> */
    private const CAPABILITIES = [
        'blog.articles.edit' => 'blog.capabilities.articles_edit',
        'blog.articles.publish' => 'blog.capabilities.articles_publish',
        'blog.articles.view' => 'blog.capabilities.articles_view',
    ];

    /** @var list<string> */
    private const PROTECTED_ROLES = ['site_admin', 'system_superadmin'];

    public function contractVersion(): string
    {
        return 'blog-capability-seeds-v1';
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

            $codes = array_keys(self::CAPABILITIES);
            [$capabilityIn, $capabilityParams] = $this->inClause(
                $codes,
                'capability'
            );
            $statement = $pdo->prepare(
                'SELECT id, module_id, code, label_key, is_delegable FROM '
                . $capabilities . ' WHERE code IN (' . $capabilityIn . ')'
            );
            $statement->execute($capabilityParams);
            $capabilityIds = [];
            $actualCapabilities = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $code = (string) ($row['code'] ?? '');
                $capabilityIds[$code] = (int) ($row['id'] ?? 0);
                $actualCapabilities[$code] = [
                    'module_id' => (string) ($row['module_id'] ?? ''),
                    'label_key' => (string) ($row['label_key'] ?? ''),
                    'is_delegable' => (int) ($row['is_delegable'] ?? -1),
                ];
            }
            ksort($actualCapabilities, SORT_STRING);
            $expectedCapabilities = [];
            foreach (self::CAPABILITIES as $code => $labelKey) {
                $expectedCapabilities[$code] = [
                    'module_id' => 'blog',
                    'label_key' => $labelKey,
                    'is_delegable' => 1,
                ];
            }
            if (
                $actualCapabilities !== $expectedCapabilities
                || count(array_filter($capabilityIds)) !== 3
            ) {
                return false;
            }

            [$roleIn, $roleParams] = $this->inClause(
                self::PROTECTED_ROLES,
                'role'
            );
            $statement = $pdo->prepare(
                'SELECT id, code, is_protected, is_delegable FROM '
                . $roles . ' WHERE code IN (' . $roleIn . ')'
            );
            $statement->execute($roleParams);
            $roleIds = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (
                    (int) ($row['is_protected'] ?? 0) !== 1
                    || (int) ($row['is_delegable'] ?? 1) !== 0
                ) {
                    return false;
                }
                $roleIds[(string) $row['code']] = (int) $row['id'];
            }
            ksort($roleIds, SORT_STRING);
            if (array_keys($roleIds) !== self::PROTECTED_ROLES) {
                return false;
            }

            $statement = $pdo->prepare(
                'SELECT r.code AS role_code, c.code AS capability_code FROM '
                . $roleCapabilities . ' AS rc '
                . 'JOIN ' . $roles . ' AS r ON r.id = rc.role_id '
                . 'JOIN ' . $capabilities . ' AS c ON c.id = rc.capability_id '
                . 'WHERE r.code IN (' . $roleIn . ') '
                . 'AND c.code IN (' . $capabilityIn . ') '
                . 'ORDER BY r.code, c.code'
            );
            $statement->execute($roleParams + $capabilityParams);
            $actualMappings = array_map(
                static fn (array $row): string =>
                    (string) $row['role_code'] . ':'
                    . (string) $row['capability_code'],
                $statement->fetchAll(PDO::FETCH_ASSOC)
            );
            $expectedMappings = [];
            foreach (self::PROTECTED_ROLES as $roleCode) {
                foreach ($codes as $capabilityCode) {
                    $expectedMappings[] = $roleCode . ':' . $capabilityCode;
                }
            }
            sort($expectedMappings, SORT_STRING);

            return $actualMappings === $expectedMappings;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<string> $values @return array{string, array<string, string>} */
    private function inClause(array $values, string $prefix): array
    {
        $parameters = [];
        $placeholders = [];
        foreach ($values as $position => $value) {
            $key = $prefix . '_' . $position;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $value;
        }

        return [implode(', ', $placeholders), $parameters];
    }
}
