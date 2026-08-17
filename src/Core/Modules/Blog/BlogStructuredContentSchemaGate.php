<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\Migrations\MigrationRuntimeTableShapeProbe;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PDO;
use Throwable;

/** Bounded request-time gate for structured Blog persistence. */
final class BlogStructuredContentSchemaGate
{
    /** @var array<string, list<string>> */
    private const RUNTIME_TABLES = [
        'content_docs' => [
            'id', 'public_id', 'localization_id', 'schema_version',
            'template_key', 'document_json', 'document_bytes',
            'document_sha256', 'body_text_sha256', 'snapshot_sha256',
            'created_by_user_public_id', 'updated_by_user_public_id',
            'created_at', 'updated_at',
        ],
        'content_revisions' => [
            'id', 'public_id', 'localization_id', 'revision_number',
            'variant_lock_version', 'schema_version', 'template_key',
            'document_json', 'document_bytes', 'document_sha256',
            'body_text_sha256', 'snapshot_sha256', 'h1', 'slug',
            'seo_title', 'meta_description', 'excerpt', 'body_text',
            'created_by_user_public_id', 'created_at',
        ],
        'content_media' => [
            'document_id', 'block_public_id', 'media_asset_public_id',
            'role', 'created_at',
        ],
        'revision_media' => [
            'revision_id', 'block_public_id', 'media_asset_public_id',
            'role', 'created_at',
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
                    BlogMigrationRequirements::structuredContent()
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
