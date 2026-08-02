<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Verifies only the category capabilities appended by migration 0004. */
final class BlogCategoryCapabilitySeedPostcondition implements
    MigrationPostconditionVerifierInterface
{
    /** @var array<string, string> */
    private const CAPABILITIES = [
        'blog.categories.edit' => 'blog.capabilities.categories_edit',
        'blog.categories.view' => 'blog.capabilities.categories_view',
    ];

    /** @var list<string> */
    private const ROLES = ['site_admin', 'system_superadmin'];

    public function __construct(
        private readonly BlogCapabilitySeedPostcondition $baseVerifier =
            new BlogCapabilitySeedPostcondition()
    ) {
    }

    public function contractVersion(): string
    {
        return 'blog-category-capability-seeds-v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        if ($scope->moduleId() !== 'webadmin') {
            return false;
        }

        try {
            if (!$this->baseVerifier->verify($pdo, $scope)) {
                return false;
            }
            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            $capabilities = $scope->quotedTable('capabilities', $driver);
            $roles = $scope->quotedTable('roles', $driver);
            $roleCapabilities = $scope->quotedTable(
                'role_capabilities',
                $driver
            );
            $statement = $pdo->query(
                'SELECT id, module_id, code, label_key, is_delegable FROM '
                . $capabilities . " WHERE code IN ('blog.categories.edit', "
                . "'blog.categories.view') ORDER BY code"
            );
            $ids = [];
            $actual = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $code = (string) $row['code'];
                $ids[$code] = (int) $row['id'];
                $actual[$code] = [
                    'module_id' => (string) $row['module_id'],
                    'label_key' => (string) $row['label_key'],
                    'is_delegable' => (int) $row['is_delegable'],
                ];
            }
            $expected = [];
            foreach (self::CAPABILITIES as $code => $label) {
                $expected[$code] = [
                    'module_id' => 'blog',
                    'label_key' => $label,
                    'is_delegable' => 1,
                ];
            }
            if ($actual !== $expected || count($ids) !== 2) {
                return false;
            }

            $rows = $pdo->query(
                'SELECT r.code AS role_code, c.code AS capability_code FROM '
                . $roleCapabilities . ' rc JOIN ' . $roles
                . ' r ON r.id = rc.role_id JOIN ' . $capabilities
                . " c ON c.id = rc.capability_id WHERE r.code IN "
                . "('site_admin', 'system_superadmin') AND r.is_protected = 1 "
                . "AND c.code IN ('blog.categories.edit', "
                . "'blog.categories.view') ORDER BY r.code, c.code"
            )->fetchAll(PDO::FETCH_ASSOC);
            $actualMappings = array_map(
                static fn (array $row): string =>
                    $row['role_code'] . ':' . $row['capability_code'],
                $rows
            );
            $expectedMappings = [];
            foreach (self::ROLES as $role) {
                foreach (array_keys(self::CAPABILITIES) as $capability) {
                    $expectedMappings[] = $role . ':' . $capability;
                }
            }

            return $actualMappings === $expectedMappings;
        } catch (Throwable) {
            return false;
        }
    }
}
