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

/** Optional boundary: false means callers must keep using editor v1. */
final class BlogLayoutEditorSchemaGate
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
                    BlogMigrationRequirements::layoutEditor()
                )
            ) {
                return false;
            }

            return $this->hasRuntimeShape($pdo, $scope);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Keep request-time readiness bounded. The migration runner owns the
     * exhaustive postcondition (constraints, hashes and historical rows);
     * runtime only proves that the two optional companions expose every
     * column used by the repositories. WHERE 1 = 0 never scans content.
     */
    private function hasRuntimeShape(PDO $pdo, MigrationScope $scope): bool
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (
            !is_string($driver)
            || !in_array($driver, ['mysql', 'sqlite'], true)
        ) {
            return false;
        }

        foreach (
            ['content_layout_docs', 'content_layout_revisions'] as $suffix
        ) {
            $statement = $pdo->query(
                'SELECT '
                    . ($suffix === 'content_layout_docs'
                        ? 'document_id' : 'revision_id')
                    . ', schema_version, template_key, document_json, '
                    . 'document_bytes, document_sha256, snapshot_sha256 FROM '
                    . $scope->quotedTable($suffix, $driver)
                    . ' WHERE 1 = 0'
            );
            if (!$statement instanceof PDOStatement) {
                return false;
            }
            $statement->closeCursor();
        }

        return true;
    }
}
