<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Composite verifier for the article trash capability appended by 0008. */
final class BlogArticleDeleteCapabilitySeedPostcondition implements
    MigrationPostconditionVerifierInterface
{
    private const CODE = 'blog.articles.delete';
    private const LABEL = 'blog.capabilities.articles_delete';

    public function __construct(
        private readonly BlogCategoryCapabilitySeedPostcondition $baseVerifier =
            new BlogCategoryCapabilitySeedPostcondition()
    ) {
    }

    public function contractVersion(): string
    {
        return 'blog-article-delete-capability-seed-v1';
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

            $capability = $pdo->prepare(
                'SELECT id, module_id, code, label_key, is_delegable FROM '
                    . $capabilities . ' WHERE code = :code'
            );
            $capability->execute(['code' => self::CODE]);
            $rows = $capability->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1) {
                return false;
            }
            $row = $rows[0];
            $capabilityId = (int) ($row['id'] ?? 0);
            if (
                $capabilityId < 1
                || ($row['module_id'] ?? null) !== 'blog'
                || ($row['code'] ?? null) !== self::CODE
                || ($row['label_key'] ?? null) !== self::LABEL
                || (int) ($row['is_delegable'] ?? -1) !== 1
            ) {
                return false;
            }

            $mappings = $pdo->prepare(
                'SELECT r.code AS role_code FROM ' . $roleCapabilities
                    . ' rc JOIN ' . $roles . ' r ON r.id = rc.role_id '
                    . 'WHERE rc.capability_id = :capability_id '
                    . "AND r.code IN ('site_admin', 'system_superadmin') "
                    . 'AND r.is_protected = 1 AND r.is_delegable = 0 '
                    . 'ORDER BY r.code'
            );
            $mappings->execute(['capability_id' => $capabilityId]);
            $actual = array_map(
                static fn (array $mapping): string =>
                    (string) ($mapping['role_code'] ?? ''),
                $mappings->fetchAll(PDO::FETCH_ASSOC)
            );

            return $actual === ['site_admin', 'system_superadmin'];
        } catch (Throwable) {
            return false;
        }
    }
}
