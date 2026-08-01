<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\Migrations\AppliedMigration;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDatabaseConnectionContract;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PDO;
use Throwable;

/** Bounded fail-closed readiness gate for Blog HTTP requests. */
final class BlogHttpSchemaGate
{
    private const MODULE = 'blog';

    public function __construct(
        private readonly MigrationRegistry $migrationRegistry =
            new MigrationRegistry(),
        private readonly MigrationDatabaseConnectionContract $connectionContract =
            new MigrationDatabaseConnectionContract(),
        private readonly BlogCapabilitySeedPostcondition $capabilityVerifier =
            new BlogCapabilitySeedPostcondition()
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
                !$registry->isEnabled(self::MODULE)
                || $blogScope === null
                || $webAdminScope === null
            ) {
                return false;
            }
            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            if ($this->connectionContract->issueCodes($pdo, $driver) !== []) {
                return false;
            }
            $expected = $this->expectedMigrations(
                MigrationCatalog::fromRegistry($registry),
                $scopes,
                $driver
            );
            if ($expected === []) {
                return false;
            }
            $applied = $this->appliedMigrations($pdo);
            if (
                $applied === null
                || array_keys($applied) !== array_keys($expected)
            ) {
                return false;
            }
            foreach ($expected as $id => $entry) {
                $record = $applied[$id];
                if (
                    $record->checksum() !== $entry['migration']->checksum()
                    || $record->scopeHash() !== $entry['scope_hash']
                ) {
                    return false;
                }
            }

            return $this->tablesAreQueryable($pdo, $blogScope, $driver)
                && $this->capabilityVerifier->verify($pdo, $webAdminScope);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, array{migration: MigrationDefinition, scope_hash: string}>
     */
    private function expectedMigrations(
        MigrationCatalog $catalog,
        MigrationScopeCollection $scopes,
        string $driver
    ): array {
        $expected = [];
        foreach ($catalog->entries() as $entry) {
            if ($entry['module'] !== self::MODULE) {
                continue;
            }
            $migration = $entry['migration'];
            $scope = $migration->targetScope(self::MODULE, $scopes);
            if (
                $scope === null
                || !$migration->isExecutableFor($driver)
                || $migration->statementsFor($driver, $scope) === []
                || isset($expected[$migration->id()])
            ) {
                return [];
            }
            $expected[$migration->id()] = [
                'migration' => $migration,
                'scope_hash' => $scope->hash(),
            ];
        }
        ksort($expected, SORT_STRING);

        return $expected;
    }

    /** @return null|array<string, AppliedMigration> */
    private function appliedMigrations(PDO $pdo): ?array
    {
        $applied = [];
        foreach (
            $this->migrationRegistry->recordedForModule($pdo, self::MODULE)
            as $record
        ) {
            if (
                $record->moduleId() !== self::MODULE
                || isset($applied[$record->migrationId()])
            ) {
                return null;
            }
            $applied[$record->migrationId()] = $record;
        }
        ksort($applied, SORT_STRING);

        return $applied;
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
