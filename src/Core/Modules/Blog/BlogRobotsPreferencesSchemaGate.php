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

/** Optional boundary: absent settings retain index/follow defaults. */
final class BlogRobotsPreferencesSchemaGate
{
    public function __construct(
        private readonly MigrationFeatureGate $migrationGate =
            new MigrationFeatureGate()
    ) {
    }

    public function isReady(
        PDO $pdo,
        ModuleRegistry $registry,
        MigrationScopeCollection $scopes
    ): bool {
        try {
            $scope = $scopes->get('blog');
            if (
                $scope === null
                || !$this->migrationGate->isReady(
                    $pdo,
                    $registry,
                    $scopes,
                    BlogMigrationRequirements::robotsPreferences()
                )
            ) {
                return false;
            }

            return $this->hasRuntimeShape($pdo, $scope);
        } catch (Throwable) {
            return false;
        }
    }

    private function hasRuntimeShape(PDO $pdo, MigrationScope $scope): bool
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!is_string($driver) || !in_array($driver, ['mysql', 'sqlite'], true)) {
            return false;
        }
        foreach (['robots_settings', 'revision_robots'] as $suffix) {
            $statement = $pdo->query(
                'SELECT '
                    . ($suffix === 'robots_settings'
                        ? 'localization_id' : 'revision_id')
                    . ', allow_index, allow_follow, settings_sha256 FROM '
                    . $scope->quotedTable($suffix, $driver) . ' WHERE 1 = 0'
            );
            if (!$statement instanceof PDOStatement) {
                return false;
            }
            $statement->closeCursor();
        }

        return true;
    }
}
