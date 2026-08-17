<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\Migrations\MigrationRuntimeTableShapeProbe;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PDO;
use Throwable;

final class BlogUrlHistorySchemaGate
{
    /** @var array<string, list<string>> */
    private const RUNTIME_TABLES = [
        'url_history' => [
            'localization_id', 'locale', 'slug', 'state',
            'replacement_localization_id', 'created_at', 'updated_at',
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
                    BlogMigrationRequirements::urlHistory()
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
