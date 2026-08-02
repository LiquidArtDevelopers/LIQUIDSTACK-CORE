<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PDO;
use Throwable;

/** Pure schema readiness gate for structured Blog persistence. */
final class BlogStructuredContentSchemaGate
{
    public function __construct(
        private readonly MigrationFeatureGate $migrationGate =
            new MigrationFeatureGate(),
        private readonly BlogStructuredContentMigrationPostconditionVerifier
            $schemaVerifier =
                new BlogStructuredContentMigrationPostconditionVerifier()
    ) {
    }

    public function isReady(
        PDO $pdo,
        ModuleRegistry $registry,
        MigrationScopeCollection $scopes
    ): bool {
        try {
            $scope = $scopes->get('blog');
            if (
                $scope === null
                || !$this->migrationGate->isReady(
                    $pdo,
                    $registry,
                    $scopes,
                    BlogMigrationRequirements::structuredContent()
                )
            ) {
                return false;
            }

            return $this->schemaVerifier->verify($pdo, $scope);
        } catch (Throwable) {
            return false;
        }
    }
}
