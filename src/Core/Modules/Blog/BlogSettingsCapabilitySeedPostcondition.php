<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Blog\EditorPreferences\BlogSettingsCapabilities;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Composite verifier for the protected settings grant appended by 0013. */
final class BlogSettingsCapabilitySeedPostcondition implements
    MigrationPostconditionVerifierInterface
{
    public function __construct(
        private readonly BlogAnalyticsCapabilitySeedPostcondition
            $baseVerifier = new BlogAnalyticsCapabilitySeedPostcondition()
    ) {
    }

    public function contractVersion(): string
    {
        return 'blog-settings-manage-capability-seed-v1';
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
            $mappings = $scope->quotedTable('role_capabilities', $driver);
            $statement = $pdo->prepare(
                'SELECT id, module_id, code, label_key, is_delegable FROM '
                    . $capabilities . ' WHERE code = :code'
            );
            $statement->execute([
                'code' => BlogSettingsCapabilities::MANAGE,
            ]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1) {
                return false;
            }
            $row = $rows[0];
            $capabilityId = (int) ($row['id'] ?? 0);
            if (
                $capabilityId < 1
                || ($row['module_id'] ?? null) !== 'blog'
                || ($row['code'] ?? null)
                    !== BlogSettingsCapabilities::MANAGE
                || ($row['label_key'] ?? null)
                    !== BlogSettingsCapabilities::MANAGE_LABEL
                || (int) ($row['is_delegable'] ?? -1) !== 0
            ) {
                return false;
            }

            $statement = $pdo->prepare(
                'SELECT r.code AS role_code, r.is_protected, '
                    . 'r.is_delegable FROM ' . $mappings . ' rc JOIN '
                    . $roles . ' r ON r.id = rc.role_id WHERE '
                    . 'rc.capability_id = :capability_id '
                    . 'ORDER BY r.code'
            );
            $statement->execute(['capability_id' => $capabilityId]);
            $actual = array_map(
                static fn (array $mapping): array => [
                    'role_code' => (string) ($mapping['role_code'] ?? ''),
                    'is_protected' => (int) ($mapping['is_protected'] ?? -1),
                    'is_delegable' => (int) ($mapping['is_delegable'] ?? -1),
                ],
                $statement->fetchAll(PDO::FETCH_ASSOC)
            );

            return $actual === [
                [
                    'role_code' => 'site_admin',
                    'is_protected' => 1,
                    'is_delegable' => 0,
                ],
                [
                    'role_code' => 'system_superadmin',
                    'is_protected' => 1,
                    'is_delegable' => 0,
                ],
            ];
        } catch (Throwable) {
            return false;
        }
    }
}
