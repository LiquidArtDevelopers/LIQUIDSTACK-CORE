<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\Migrations\MigrationRuntimeTableShapeProbe;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PDO;
use Throwable;

/** Optional request-time tombstone gate; older Blog reads remain usable. */
final class BlogPostTombstoneSchemaGate
{
    /** @var array<string, list<string>> */
    private const RUNTIME_TABLES = [
        'post_tombstones' => [
            'post_localization_id', 'trashed_by_user_public_id', 'trashed_at',
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
                    BlogMigrationRequirements::postTombstones()
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
