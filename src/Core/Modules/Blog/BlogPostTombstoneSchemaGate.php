<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PDO;
use Throwable;

/** Optional feature gate that keeps pre-0007 Blog reads operational. */
final class BlogPostTombstoneSchemaGate
{
    private readonly BlogPostTombstoneMigrationPostconditionVerifier
        $analyticsExtendedSchemaVerifier;

    public function __construct(
        private readonly MigrationFeatureGate $migrationGate =
            new MigrationFeatureGate(),
        private readonly BlogPostTombstoneMigrationPostconditionVerifier
            $schemaVerifier =
                new BlogPostTombstoneMigrationPostconditionVerifier(),
        ?BlogPostTombstoneMigrationPostconditionVerifier
            $analyticsExtendedSchemaVerifier = null
    ) {
        $this->analyticsExtendedSchemaVerifier =
            $analyticsExtendedSchemaVerifier
            ?? new BlogPostTombstoneMigrationPostconditionVerifier(
                expectAnalyticsExtension: true
            );
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
                    BlogMigrationRequirements::postTombstones()
                )
            ) {
                return false;
            }

            return $this->schemaVerifier->verify($pdo, $scope)
                || $this->analyticsExtendedSchemaVerifier->verify(
                    $pdo,
                    $scope
                );
        } catch (Throwable) {
            return false;
        }
    }
}
