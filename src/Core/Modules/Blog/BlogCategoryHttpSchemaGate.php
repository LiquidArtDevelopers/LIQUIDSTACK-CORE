<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\Migrations\MigrationRuntimeTableShapeProbe;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PDO;
use Throwable;

/** Category-only readiness gate; it never repeats migration DDL audits. */
final class BlogCategoryHttpSchemaGate
{
    /** @var array<string, list<string>> */
    private const RUNTIME_TABLES = [
        'categories' => [
            'id', 'public_id', 'created_by_user_public_id', 'created_at',
            'updated_at',
        ],
        'category_locales' => [
            'id', 'public_id', 'category_id', 'locale', 'slug', 'name',
            'lock_version', 'created_by_user_public_id',
            'updated_by_user_public_id', 'created_at', 'updated_at',
        ],
        'post_categories' => [
            'id', 'public_id', 'post_id', 'category_id',
            'assigned_by_user_public_id', 'created_at', 'updated_at',
        ],
    ];

    public function __construct(
        private readonly MigrationFeatureGate $migrationGate =
            new MigrationFeatureGate(),
        private readonly BlogCategoryCapabilitySeedPostcondition
            $capabilityVerifier =
                new BlogCategoryCapabilitySeedPostcondition(),
        private readonly BlogDummyCategoryRuntimeReadiness
            $dummyRuntimeReadiness =
                new BlogDummyCategoryRuntimeReadiness(),
        private readonly MigrationRuntimeTableShapeProbe $shapeProbe =
            new MigrationRuntimeTableShapeProbe()
    ) {
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
            return $blogScope !== null
                && $this->migrationGate->isReady(
                    $pdo,
                    $registry,
                    $scopes,
                    BlogMigrationRequirements::categoriesPublic()
                )
                && $this->runtimeStateIsReady($pdo, $blogScope);
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
            $blogScope = $scopes->get('blog');
            $webAdminScope = $scopes->get('webadmin');
            return $blogScope !== null
                && $webAdminScope !== null
                && $this->migrationGate->isReady(
                    $pdo,
                    $registry,
                    $scopes,
                    BlogMigrationRequirements::categoriesAdministration()
                )
                && $this->runtimeStateIsReady($pdo, $blogScope)
                && $this->capabilityVerifier->verify(
                    $pdo,
                    $webAdminScope
                );
        } catch (Throwable) {
            return false;
        }
    }

    private function runtimeStateIsReady(
        PDO $pdo,
        MigrationScope $scope
    ): bool {
        return $this->shapeProbe->hasColumns(
            $pdo,
            $scope,
            self::RUNTIME_TABLES
        ) && $this->dummyRuntimeReadiness->isReady($pdo, $scope);
    }
}
