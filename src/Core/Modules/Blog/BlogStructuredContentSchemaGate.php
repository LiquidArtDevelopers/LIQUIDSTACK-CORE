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
    private readonly BlogStructuredContentMigrationPostconditionVerifier
        $sitemapExtendedSchemaVerifier;
    private readonly BlogStructuredContentMigrationPostconditionVerifier
        $tombstoneExtendedSchemaVerifier;
    private readonly BlogStructuredContentMigrationPostconditionVerifier
        $analyticsExtendedSchemaVerifier;

    public function __construct(
        private readonly MigrationFeatureGate $migrationGate =
            new MigrationFeatureGate(),
        private readonly BlogStructuredContentMigrationPostconditionVerifier
            $schemaVerifier =
                new BlogStructuredContentMigrationPostconditionVerifier(),
        ?BlogStructuredContentMigrationPostconditionVerifier
            $sitemapExtendedSchemaVerifier = null,
        ?BlogStructuredContentMigrationPostconditionVerifier
            $tombstoneExtendedSchemaVerifier = null,
        ?BlogStructuredContentMigrationPostconditionVerifier
            $analyticsExtendedSchemaVerifier = null
    ) {
        $this->sitemapExtendedSchemaVerifier =
            $sitemapExtendedSchemaVerifier
            ?? new BlogStructuredContentMigrationPostconditionVerifier(
                expectSitemapStateExtension: true
            );
        $this->tombstoneExtendedSchemaVerifier =
            $tombstoneExtendedSchemaVerifier
            ?? new BlogStructuredContentMigrationPostconditionVerifier(
                expectSitemapStateExtension: true,
                expectPostTombstoneExtension: true
            );
        $this->analyticsExtendedSchemaVerifier =
            $analyticsExtendedSchemaVerifier
            ?? new BlogStructuredContentMigrationPostconditionVerifier(
                expectSitemapStateExtension: true,
                expectPostTombstoneExtension: true,
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
                    BlogMigrationRequirements::structuredContent()
                )
            ) {
                return false;
            }

            return $this->schemaVerifier->verify($pdo, $scope)
                || $this->sitemapExtendedSchemaVerifier->verify($pdo, $scope)
                || $this->tombstoneExtendedSchemaVerifier->verify(
                    $pdo,
                    $scope
                )
                || $this->analyticsExtendedSchemaVerifier->verify(
                    $pdo,
                    $scope
                );
        } catch (Throwable) {
            return false;
        }
    }
}
