<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\Migrations\MigrationDatabaseConnectionContract;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationRuntimeTableShapeProbe;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PDO;
use Throwable;

/** Bounded fail-closed readiness gate for Blog HTTP requests. */
final class BlogHttpSchemaGate
{
    /** @var array<string, list<string>> */
    private const PUBLIC_RUNTIME_TABLES = [
        'posts' => [
            'id', 'public_id', 'created_by_user_public_id', 'created_at',
            'updated_at',
        ],
        'post_localizations' => [
            'id', 'public_id', 'post_id', 'locale', 'slug', 'h1',
            'seo_title', 'meta_description', 'excerpt', 'body_text', 'status',
            'published_at', 'lock_version', 'created_by_user_public_id',
            'updated_by_user_public_id', 'created_at', 'updated_at',
        ],
    ];

    /** @var array<string, list<string>> */
    private const ADMIN_RUNTIME_TABLES = [
        'posts' => [
            'id', 'public_id', 'created_by_user_public_id', 'created_at',
            'updated_at',
        ],
        'post_localizations' => [
            'id', 'public_id', 'post_id', 'locale', 'slug', 'h1',
            'seo_title', 'meta_description', 'excerpt', 'body_text', 'status',
            'published_at', 'lock_version', 'created_by_user_public_id',
            'updated_by_user_public_id', 'created_at', 'updated_at',
        ],
        'copy_operations' => [
            'request_public_id', 'payload_sha256', 'actor_public_id',
            'operation', 'source_post_public_id', 'source_locale',
            'destination_locale', 'expected_lock_version',
            'result_post_public_id', 'result_locale', 'created_at',
            'completed_at',
        ],
    ];

    private readonly MigrationFeatureGate $migrationGate;

    public function __construct(
        MigrationRegistry $migrationRegistry = new MigrationRegistry(),
        MigrationDatabaseConnectionContract $connectionContract =
            new MigrationDatabaseConnectionContract(),
        private readonly BlogCapabilitySeedPostcondition $capabilityVerifier =
            new BlogCapabilitySeedPostcondition(),
        private readonly BlogDummyCategoryRuntimeReadiness
            $dummyRuntimeReadiness =
                new BlogDummyCategoryRuntimeReadiness(),
        ?MigrationFeatureGate $migrationGate = null,
        private readonly MigrationRuntimeTableShapeProbe $shapeProbe =
            new MigrationRuntimeTableShapeProbe()
    ) {
        $this->migrationGate = $migrationGate
            ?? new MigrationFeatureGate(
                $migrationRegistry,
                $connectionContract
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
                BlogMigrationRequirements::publicContent()
            )) {
                return false;
            }

            return $this->shapeProbe->hasColumns(
                $pdo,
                $blogScope,
                self::PUBLIC_RUNTIME_TABLES
            )
                && $this->dummyRuntimeReadiness->isReady(
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
            $blogScope = $scopes->get('blog');
            $webAdminScope = $scopes->get('webadmin');
            if ($blogScope === null || $webAdminScope === null) {
                return false;
            }
            if (!$this->migrationGate->isReady(
                $pdo,
                $registry,
                $scopes,
                BlogMigrationRequirements::administration()
            )) {
                return false;
            }

            return $this->shapeProbe->hasColumns(
                $pdo,
                $blogScope,
                self::ADMIN_RUNTIME_TABLES
            )
                && $this->dummyRuntimeReadiness->isReady(
                    $pdo,
                    $blogScope
                )
                && $this->capabilityVerifier->verify($pdo, $webAdminScope);
        } catch (Throwable) {
            return false;
        }
    }

}
