<?php

declare(strict_types=1);

namespace Tests\Blog\Tags;

use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Blog\BlogTagCapabilitySeedPostcondition;
use App\Core\Modules\Blog\BlogTagSchemaMigrationPostconditionVerifier;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use PDO;
use PHPUnit\Framework\TestCase;

final class BlogTagMigrationTest extends TestCase
{
    public function testAppendOnlySchemaStagesAreExactAndIdempotent(): void
    {
        $pdo = $this->pdo();
        $scope = $this->blogScope();
        $migrations = $this->migrations();
        $this->applyPriorBlogSchema($pdo, $scope, $migrations);

        foreach (range(19, 23) as $index) {
            $this->apply($pdo, $migrations[$index], $scope);
            $this->apply($pdo, $migrations[$index], $scope);
            self::assertTrue(
                $migrations[$index]->postconditionVerifier()?->verify(
                    $pdo,
                    $scope
                ),
                $migrations[$index]->id()
            );
        }

        self::assertSame(5, (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND "
                . "name IN ('tag_blog_tags', 'tag_blog_localization_tags', "
                . "'tag_blog_tag_assignment_heads', "
                . "'tag_blog_tag_assignment_workspaces', "
                . "'tag_blog_tag_assignment_workspace_items')"
        )->fetchColumn());
    }

    public function testVerifierRejectsExactCheckDefaultIndexAndForeignKeyDrift(): void
    {
        $migrations = $this->migrations();

        $pdo = $this->pdo();
        $scope = $this->blogScope();
        $this->applyPriorBlogSchema($pdo, $scope, $migrations);
        $tagStatements = $migrations[19]->statementsFor('sqlite', $scope);
        $tagStatements[0] = str_replace(
            'CHECK ("lock_version" > 0)',
            'CHECK ("lock_version" >= 0)',
            $tagStatements[0]
        );
        $this->execStatements($pdo, $tagStatements);
        self::assertFalse($migrations[19]->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));

        $pdo = $this->pdo();
        $this->applyPriorBlogSchema($pdo, $scope, $migrations);
        $this->apply($pdo, $migrations[19], $scope);
        $pdo->exec(
            'CREATE INDEX "tag_blog_ix_bt_extra" ON '
                . $scope->quotedTable('tags', 'sqlite') . ' ("slug")'
        );
        self::assertFalse($migrations[19]->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));

        $pdo = $this->pdo();
        $this->applyPriorBlogSchema($pdo, $scope, $migrations);
        foreach (range(19, 21) as $index) {
            $this->apply($pdo, $migrations[$index], $scope);
        }
        $workspaceStatements = $migrations[22]->statementsFor(
            'sqlite',
            $scope
        );
        $workspaceStatements[0] = str_replace(
            'DEFAULT 0 CHECK ("base_assignment_version" >= 0)',
            'DEFAULT 1 CHECK ("base_assignment_version" >= 0)',
            $workspaceStatements[0]
        );
        $this->execStatements($pdo, $workspaceStatements);
        self::assertFalse($migrations[22]->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));

        $pdo = $this->pdo();
        $this->applyPriorBlogSchema($pdo, $scope, $migrations);
        $this->apply($pdo, $migrations[19], $scope);
        $relationStatements = $migrations[20]->statementsFor(
            'sqlite',
            $scope
        );
        $relationStatements[0] = str_replace(
            '"created_at" TEXT NOT NULL,',
            '"created_at" TEXT NOT NULL REFERENCES '
                . $scope->quotedTable('posts', 'sqlite')
                . ' ("public_id") ON DELETE RESTRICT,',
            $relationStatements[0]
        );
        $this->execStatements($pdo, $relationStatements);
        self::assertFalse($migrations[20]->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
    }

    public function testVerifierRejectsOrphansInsertedWithForeignKeysOff(): void
    {
        $pdo = $this->pdo();
        $scope = $this->blogScope();
        $migrations = $this->migrations();
        $this->applyPriorBlogSchema($pdo, $scope, $migrations);
        $this->apply($pdo, $migrations[19], $scope);
        $this->apply($pdo, $migrations[20], $scope);
        $actor = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $pdo->exec(
            'INSERT INTO ' . $scope->quotedTable('tags', 'sqlite')
                . ' (public_id, locale, slug, name, normalized_sha256, '
                . 'created_by_user_public_id, updated_by_user_public_id, '
                . "created_at, updated_at) VALUES ("
                . "'10000000-0000-4000-8000-000000000001', 'es', "
                . "'fiscal', 'Fiscal', '" . str_repeat('a', 64) . "', '"
                . $actor . "', '" . $actor . "', "
                . "'2026-08-18 00:00:00.000000', "
                . "'2026-08-18 00:00:00.000000')"
        );
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec(
            'INSERT INTO '
                . $scope->quotedTable('localization_tags', 'sqlite')
                . ' (localization_id, tag_id, assigned_by_user_public_id, '
                . "created_at) VALUES (999, 1, '" . $actor . "', "
                . "'2026-08-18 00:00:00.000000')"
        );
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::assertFalse($migrations[20]->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
    }

    public function testCapabilitySeedRequiresNonDelegableProtectedRoles(): void
    {
        $pdo = $this->pdo();
        $scope = MigrationScope::forTablePrefix(
            'webadmin',
            'tag_admin_'
        );
        $webAdmin = iterator_to_array(
            WebAdminMigrationProvider::migrations(),
            false
        )[0];
        $this->apply($pdo, $webAdmin, $scope);
        $migration = $this->migrations()[24];
        self::assertInstanceOf(
            BlogTagCapabilitySeedPostcondition::class,
            $migration->postconditionVerifier()
        );
        $this->apply($pdo, $migration, $scope);
        $this->apply($pdo, $migration, $scope);
        self::assertTrue($migration->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));

        $roles = $scope->quotedTable('roles', 'sqlite');
        $capabilities = $scope->quotedTable('capabilities', 'sqlite');
        $mappings = $scope->quotedTable('role_capabilities', 'sqlite');
        $pdo->exec(
            'INSERT INTO ' . $roles
                . " (code, label_key, is_protected, is_delegable) VALUES "
                . "('content_editor', 'roles.content_editor', 0, 1)"
        );
        $pdo->exec(
            'INSERT INTO ' . $mappings . ' (role_id, capability_id) '
                . 'SELECT r.id, c.id FROM ' . $roles . ' r CROSS JOIN '
                . $capabilities . " c WHERE r.code = 'content_editor' "
                . "AND c.code IN ('blog.tags.view', 'blog.tags.edit')"
        );
        self::assertTrue(
            $migration->postconditionVerifier()?->verify($pdo, $scope),
            'Legitimate delegated grants must not disable the tag runtime.'
        );

        $pdo->exec(
            'UPDATE ' . $scope->quotedTable('roles', 'sqlite')
                . " SET is_delegable = 1 WHERE code = 'site_admin'"
        );
        self::assertFalse($migration->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
    }

    public function testTagVerifierContractIsStageBound(): void
    {
        foreach (range(1, 5) as $stage) {
            self::assertSame(
                'blog-tag-schema-v1-stage-' . $stage,
                (new BlogTagSchemaMigrationPostconditionVerifier($stage))
                    ->contractVersion()
            );
        }
    }

    /** @return list<MigrationDefinition> */
    private function migrations(): array
    {
        return iterator_to_array(BlogMigrationProvider::migrations(), false);
    }

    /** @param list<MigrationDefinition> $migrations */
    private function applyPriorBlogSchema(
        PDO $pdo,
        MigrationScope $scope,
        array $migrations
    ): void {
        foreach (array_slice($migrations, 0, 19) as $migration) {
            if ($migration->targetScopeModuleId() === null) {
                $this->apply($pdo, $migration, $scope);
            }
        }
    }

    private function apply(
        PDO $pdo,
        MigrationDefinition $migration,
        MigrationScope $scope
    ): void {
        $this->execStatements(
            $pdo,
            $migration->statementsFor('sqlite', $scope)
        );
    }

    /** @param list<string> $statements */
    private function execStatements(PDO $pdo, array $statements): void
    {
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    }

    private function blogScope(): MigrationScope
    {
        return MigrationScope::forTablePrefix('blog', 'tag_blog_');
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }
}
