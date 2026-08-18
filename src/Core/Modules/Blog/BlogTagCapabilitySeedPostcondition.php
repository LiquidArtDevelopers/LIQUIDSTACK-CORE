<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Blog\Tags\BlogTagCapabilities;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Exact capability and protected-role grant contract for Blog tags. */
final class BlogTagCapabilitySeedPostcondition implements
    MigrationPostconditionVerifierInterface
{
    public function contractVersion(): string
    {
        return 'blog-tag-capability-seed-v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        if ($scope->moduleId() !== 'webadmin') {
            return false;
        }
        try {
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (!in_array($driver, ['mysql', 'sqlite'], true)) {
                return false;
            }
            $capabilities = $scope->quotedTable('capabilities', $driver);
            $roles = $scope->quotedTable('roles', $driver);
            $mappings = $scope->quotedTable('role_capabilities', $driver);
            $statement = $pdo->prepare(
                'SELECT id, module_id, code, label_key, is_delegable FROM '
                    . $capabilities . ' WHERE code IN (:view, :edit) '
                    . 'ORDER BY code'
            );
            $statement->execute([
                'view' => BlogTagCapabilities::VIEW,
                'edit' => BlogTagCapabilities::EDIT,
            ]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 2) {
                return false;
            }
            $expected = [
                BlogTagCapabilities::EDIT => BlogTagCapabilities::EDIT_LABEL,
                BlogTagCapabilities::VIEW => BlogTagCapabilities::VIEW_LABEL,
            ];
            $ids = [];
            foreach ($rows as $row) {
                $code = (string) ($row['code'] ?? '');
                $id = (int) ($row['id'] ?? 0);
                if (
                    $id < 1
                    || !isset($expected[$code])
                    || ($row['module_id'] ?? null) !== 'blog'
                    || ($row['label_key'] ?? null) !== $expected[$code]
                    || (int) ($row['is_delegable'] ?? -1) !== 1
                ) {
                    return false;
                }
                $ids[$id] = $code;
            }
            $statement = $pdo->prepare(
                'SELECT r.code AS role_code, r.is_protected, '
                    . 'r.is_delegable, c.code AS capability_code FROM '
                    . $mappings . ' rc JOIN ' . $roles
                    . ' r ON r.id = rc.role_id JOIN ' . $capabilities
                    . ' c ON c.id = rc.capability_id WHERE c.code IN '
                    . '(:view, :edit) AND r.code IN '
                    . "('site_admin', 'system_superadmin') "
                    . 'AND r.is_protected = 1 ORDER BY r.code, c.code'
            );
            $statement->execute([
                'view' => BlogTagCapabilities::VIEW,
                'edit' => BlogTagCapabilities::EDIT,
            ]);
            $actual = array_map(
                static fn (array $row): array => [
                    'role' => (string) ($row['role_code'] ?? ''),
                    'protected' => (int) ($row['is_protected'] ?? -1),
                    'delegable' => (int) ($row['is_delegable'] ?? -1),
                    'capability' =>
                        (string) ($row['capability_code'] ?? ''),
                ],
                $statement->fetchAll(PDO::FETCH_ASSOC)
            );
            $expectedMappings = [];
            foreach (['site_admin', 'system_superadmin'] as $role) {
                foreach ([
                    BlogTagCapabilities::EDIT,
                    BlogTagCapabilities::VIEW,
                ] as $capability) {
                    $expectedMappings[] = [
                        'role' => $role,
                        'protected' => 1,
                        'delegable' => 0,
                        'capability' => $capability,
                    ];
                }
            }

            return $actual === $expectedMappings;
        } catch (Throwable) {
            return false;
        }
    }
}
