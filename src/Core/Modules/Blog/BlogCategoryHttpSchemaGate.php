<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PDO;
use Throwable;

/** Category-only readiness gate; it never disables Blog 0001 administration. */
final class BlogCategoryHttpSchemaGate
{
    private readonly BlogCategoryMigrationPostconditionVerifier
        $extendedSchemaVerifier;
    private readonly BlogCategoryMigrationPostconditionVerifier
        $sitemapExtendedSchemaVerifier;
    private readonly BlogCategoryMigrationPostconditionVerifier
        $tombstoneExtendedSchemaVerifier;
    private readonly BlogCategoryMigrationPostconditionVerifier
        $analyticsExtendedSchemaVerifier;

    public function __construct(
        private readonly MigrationFeatureGate $migrationGate =
            new MigrationFeatureGate(),
        private readonly BlogCategoryMigrationPostconditionVerifier
            $schemaVerifier =
                new BlogCategoryMigrationPostconditionVerifier(),
        private readonly BlogCategoryCapabilitySeedPostcondition
            $capabilityVerifier =
                new BlogCategoryCapabilitySeedPostcondition(),
        ?BlogCategoryMigrationPostconditionVerifier
            $extendedSchemaVerifier = null,
        ?BlogCategoryMigrationPostconditionVerifier
            $sitemapExtendedSchemaVerifier = null,
        ?BlogCategoryMigrationPostconditionVerifier
            $tombstoneExtendedSchemaVerifier = null,
        ?BlogCategoryMigrationPostconditionVerifier
            $analyticsExtendedSchemaVerifier = null
    ) {
        $this->extendedSchemaVerifier = $extendedSchemaVerifier
            ?? new BlogCategoryMigrationPostconditionVerifier(
                expectStructuredContentExtension: true
            );
        $this->sitemapExtendedSchemaVerifier =
            $sitemapExtendedSchemaVerifier
            ?? new BlogCategoryMigrationPostconditionVerifier(
                expectStructuredContentExtension: true,
                expectSitemapStateExtension: true
            );
        $this->tombstoneExtendedSchemaVerifier =
            $tombstoneExtendedSchemaVerifier
            ?? new BlogCategoryMigrationPostconditionVerifier(
                expectStructuredContentExtension: true,
                expectSitemapStateExtension: true,
                expectPostTombstoneExtension: true
            );
        $this->analyticsExtendedSchemaVerifier =
            $analyticsExtendedSchemaVerifier
            ?? new BlogCategoryMigrationPostconditionVerifier(
                expectStructuredContentExtension: true,
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
        return $this->isAdministrationReady($pdo, $registry, $scopes);
    }

    public function isPublicReady(
        PDO $pdo,
        ModuleRegistry $registry,
        MigrationScopeCollection $scopes
    ): bool {
        try {
            $blogScope = $scopes->get('blog');
            if ($blogScope === null) {
                return false;
            }
            if (!$this->migrationGate->isReady(
                $pdo,
                $registry,
                $scopes,
                BlogMigrationRequirements::categoriesPublic()
            )) {
                return false;
            }

            // Both namespaces are closed contracts. Accept the exact 0003
            // boundary before 0005 or its exact structured-content extension.
            return $this->schemaVerifier->verify($pdo, $blogScope)
                || $this->extendedSchemaVerifier->verify($pdo, $blogScope)
                || $this->sitemapExtendedSchemaVerifier->verify(
                    $pdo,
                    $blogScope
                )
                || $this->tombstoneExtendedSchemaVerifier->verify(
                    $pdo,
                    $blogScope
                )
                || $this->analyticsExtendedSchemaVerifier->verify(
                    $pdo,
                    $blogScope
                );
        } catch (Throwable) {
            return false;
        }
    }

    public function isAdministrationReady(
        PDO $pdo,
        ModuleRegistry $registry,
        MigrationScopeCollection $scopes
    ): bool {
        try {
            $webAdminScope = $scopes->get('webadmin');
            if (
                $webAdminScope === null
                || !$this->isPublicReady($pdo, $registry, $scopes)
                || !$this->migrationGate->isReady(
                    $pdo,
                    $registry,
                    $scopes,
                    BlogMigrationRequirements::categoriesAdministration()
                )
            ) {
                return false;
            }

            return $this->capabilityVerifier->verify($pdo, $webAdminScope);
        } catch (Throwable) {
            return false;
        }
    }
}
