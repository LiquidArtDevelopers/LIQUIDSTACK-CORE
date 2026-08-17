<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\Migrations\MigrationRuntimeTableShapeProbe;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PDO;
use Throwable;

/** Optional bounded gate; false preserves the legacy draft-only workflow. */
final class BlogPrivateDraftPublicationSchemaGate
{
    /** @var array<string, list<string>> */
    private const RUNTIME_TABLES = [
        'editorial_workspaces' => [
            'localization_id', 'draft_revision_id',
            'base_publication_version', 'created_by_user_public_id',
            'updated_by_user_public_id', 'created_at', 'updated_at',
        ],
        'category_assignment_heads' => [
            'post_id', 'assignment_version', 'updated_by_user_public_id',
            'updated_at',
        ],
        'category_assignment_workspaces' => [
            'post_id', 'base_assignment_version', 'workspace_version',
            'created_by_user_public_id', 'updated_by_user_public_id',
            'created_at', 'updated_at',
        ],
        'category_assignment_workspace_items' => [
            'post_id', 'category_id', 'assigned_by_user_public_id',
            'created_at',
        ],
        'publication_heads' => [
            'localization_id', 'revision_id', 'publication_version',
            'published_by_user_public_id', 'published_at',
        ],
    ];

    public function __construct(
        private readonly MigrationFeatureGate $migrationGate =
            new MigrationFeatureGate(),
        private readonly MigrationRuntimeTableShapeProbe $shapeProbe =
            new MigrationRuntimeTableShapeProbe()
    ) {
    }

    public function isReady(
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
                    BlogMigrationRequirements::privateDraftPublication()
                )
                && $this->shapeProbe->hasColumns(
                    $pdo,
                    $scope,
                    self::RUNTIME_TABLES
                );
        } catch (Throwable) {
            return false;
        }
    }
}
