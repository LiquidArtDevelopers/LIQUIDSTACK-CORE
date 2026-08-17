<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PDO;
use PDOStatement;
use Throwable;

/** Optional boundary: false keeps code defaults and the existing editor. */
final class BlogEditorPreferencesSchemaGate
{
    public function __construct(
        private readonly MigrationFeatureGate $migrationGate =
            new MigrationFeatureGate(),
        private readonly BlogSettingsCapabilitySeedPostcondition
            $capabilityVerifier =
                new BlogSettingsCapabilitySeedPostcondition()
    ) {
    }

    public function isReady(
        PDO $pdo,
        ModuleRegistry $registry,
        MigrationScopeCollection $scopes
    ): bool {
        try {
            $blogScope = $scopes->get('blog');
            $webAdminScope = $scopes->get('webadmin');
            if (
                $blogScope === null
                || $webAdminScope === null
                || !$this->migrationGate->isReady(
                    $pdo,
                    $registry,
                    $scopes,
                    BlogMigrationRequirements::editorPreferences()
                )
            ) {
                return false;
            }

            return $this->hasRuntimeShape($pdo, $blogScope)
                && $this->capabilityVerifier->verify($pdo, $webAdminScope);
        } catch (Throwable) {
            return false;
        }
    }

    private function hasRuntimeShape(
        PDO $pdo,
        MigrationScope $scope
    ): bool {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!is_string($driver) || !in_array($driver, ['mysql', 'sqlite'], true)) {
            return false;
        }
        $statement = $pdo->query(
            'SELECT scope_key, schema_version, preferences_json, '
                . 'preferences_bytes, preferences_sha256, lock_version, '
                . 'updated_by_user_public_id, created_at, updated_at FROM '
                . $scope->quotedTable('editor_preferences', $driver)
                . ' WHERE 1 = 0'
        );
        if (!$statement instanceof PDOStatement) {
            return false;
        }
        $statement->closeCursor();

        return true;
    }
}
