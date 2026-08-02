<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\Migrations\MigrationDatabaseConnectionContract;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PDO;
use Throwable;

/** Bounded fail-closed readiness gate for Blog HTTP requests. */
final class BlogHttpSchemaGate
{
    private readonly MigrationFeatureGate $migrationGate;

    public function __construct(
        MigrationRegistry $migrationRegistry = new MigrationRegistry(),
        MigrationDatabaseConnectionContract $connectionContract =
            new MigrationDatabaseConnectionContract(),
        private readonly BlogCapabilitySeedPostcondition $capabilityVerifier =
            new BlogCapabilitySeedPostcondition(),
        ?MigrationFeatureGate $migrationGate = null
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
            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            if (!$this->migrationGate->isReady(
                $pdo,
                $registry,
                $scopes,
                BlogMigrationRequirements::publicContent()
            )) {
                return false;
            }

            return $this->tablesAreQueryable($pdo, $blogScope, $driver);
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
            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            if (!$this->migrationGate->isReady(
                $pdo,
                $registry,
                $scopes,
                BlogMigrationRequirements::administration()
            )) {
                return false;
            }

            return $this->tablesAreQueryable($pdo, $blogScope, $driver)
                && $this->capabilityVerifier->verify($pdo, $webAdminScope);
        } catch (Throwable) {
            return false;
        }
    }

    private function tablesAreQueryable(
        PDO $pdo,
        \App\Core\Modules\Migrations\MigrationScope $scope,
        string $driver
    ): bool {
        $posts = $scope->quotedTable('posts', $driver);
        $localizations = $scope->quotedTable(
            'post_localizations',
            $driver
        );
        $pdo->query(
            'SELECT id, public_id, created_by_user_public_id, created_at, '
            . 'updated_at FROM ' . $posts . ' WHERE 1 = 0'
        );
        $pdo->query(
            'SELECT id, public_id, post_id, locale, slug, h1, seo_title, '
            . 'meta_description, excerpt, body_text, status, published_at, '
            . 'lock_version, created_by_user_public_id, '
            . 'updated_by_user_public_id, created_at, updated_at FROM '
            . $localizations . ' WHERE 1 = 0'
        );

        return true;
    }
}
