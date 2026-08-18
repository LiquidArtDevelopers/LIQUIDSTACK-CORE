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

/** Optional, fail-closed runtime gate for localized Blog tags. */
final class BlogTagSchemaGate
{
    /** @var array<string, list<string>> */
    private const RUNTIME_TABLES = [
        'tags' => [
            'id', 'public_id', 'locale', 'slug', 'name',
            'normalized_sha256', 'lock_version',
            'created_by_user_public_id', 'updated_by_user_public_id',
            'created_at', 'updated_at',
        ],
        'localization_tags' => [
            'localization_id', 'tag_id', 'assigned_by_user_public_id',
            'created_at',
        ],
        'tag_assignment_heads' => [
            'localization_id', 'assignment_version',
            'updated_by_user_public_id', 'updated_at',
        ],
        'tag_assignment_workspaces' => [
            'localization_id', 'base_assignment_version',
            'workspace_version', 'created_by_user_public_id',
            'updated_by_user_public_id', 'created_at', 'updated_at',
        ],
        'tag_assignment_workspace_items' => [
            'localization_id', 'tag_id', 'assigned_by_user_public_id',
            'created_at',
        ],
    ];

    public function __construct(
        private readonly MigrationFeatureGate $migrationGate =
            new MigrationFeatureGate(),
        private readonly MigrationRuntimeTableShapeProbe $shapeProbe =
            new MigrationRuntimeTableShapeProbe(),
        private readonly BlogTagCapabilitySeedPostcondition
            $capabilityVerifier = new BlogTagCapabilitySeedPostcondition()
    ) {
    }

    public function isPublicReady(
        PDO $pdo,
        ModuleRegistry $registry,
        MigrationScopeCollection $scopes
    ): bool {
        try {
            $scope = $scopes->get('blog');
            return $scope !== null
                && $this->migrationGate->isReady(
                    $pdo,
                    $registry,
                    $scopes,
                    BlogMigrationRequirements::tagsPublic()
                )
                && $this->runtimeStateIsReady($pdo, $scope);
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
            $scope = $scopes->get('blog');
            $webAdminScope = $scopes->get('webadmin');
            return $scope !== null
                && $webAdminScope !== null
                && $this->migrationGate->isReady(
                    $pdo,
                    $registry,
                    $scopes,
                    BlogMigrationRequirements::tagsAdministration()
                )
                && $this->runtimeStateIsReady($pdo, $scope)
                && $this->capabilityVerifier->verify($pdo, $webAdminScope);
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
        );
    }
}
