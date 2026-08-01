<?php

declare(strict_types=1);

namespace Tests\Blog\Migrations;

use App\Core\Modules\Blog\BlogCapabilitySeedPostcondition;
use App\Core\Modules\Blog\BlogInitialNamespacePrecondition;
use App\Core\Modules\Blog\BlogMigrationPostconditionVerifier;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BlogMigrationProviderTest extends TestCase
{
    public function testCatalogExposesSchemaThenCrossScopeCapabilities(): void
    {
        self::assertSame('blog', BlogMigrationProvider::moduleId());
        $migrations = $this->migrations();

        self::assertCount(2, $migrations);
        self::assertSame(
            ['0001_blog_posts', '0002_blog_capabilities'],
            array_map(
                static fn (MigrationDefinition $migration): string =>
                    $migration->id(),
                $migrations
            )
        );
        foreach ($migrations as $migration) {
            self::assertFalse($migration->isDestructive());
            self::assertTrue($migration->isRetrySafe());
            self::assertTrue($migration->isExecutableFor('mysql'));
            self::assertTrue($migration->isExecutableFor('sqlite'));
            self::assertFalse($migration->isTransactionalFor('mysql'));
            self::assertTrue($migration->isTransactionalFor('sqlite'));
            self::assertMatchesRegularExpression(
                '/\A[a-f0-9]{64}\z/',
                $migration->checksum()
            );
        }
        self::assertNotSame(
            $migrations[0]->checksum(),
            $migrations[1]->checksum()
        );
        self::assertNull($migrations[0]->targetScopeModuleId());
        self::assertSame('webadmin', $migrations[1]->targetScopeModuleId());
        $scopes = MigrationScopeCollection::fromTablePrefixes([
            'blog' => 'ls_blog_',
            'webadmin' => 'ls_webadmin_',
        ]);
        self::assertSame(
            'blog',
            $migrations[0]->targetScope('blog', $scopes)?->moduleId()
        );
        self::assertSame(
            'webadmin',
            $migrations[1]->targetScope('blog', $scopes)?->moduleId()
        );
        self::assertInstanceOf(
            BlogInitialNamespacePrecondition::class,
            $migrations[0]->preconditionVerifier()
        );
        self::assertInstanceOf(
            BlogMigrationPostconditionVerifier::class,
            $migrations[0]->postconditionVerifier()
        );
        self::assertNull($migrations[1]->preconditionVerifier());
        self::assertInstanceOf(
            BlogCapabilitySeedPostcondition::class,
            $migrations[1]->postconditionVerifier()
        );
    }

    public function testSchemaSqlIsExactAndContainsNoDeferredDomains(): void
    {
        $migration = $this->migrations()[0];
        $scope = MigrationScope::forTablePrefix('blog', 'tenant_blog_');
        $mysql = implode("\n", $migration->statementsFor('mysql', $scope));
        $sqlite = implode("\n", $migration->statementsFor('sqlite', $scope));

        foreach ([$mysql, $sqlite] as $sql) {
            self::assertStringContainsString('tenant_blog_posts', $sql);
            self::assertStringContainsString(
                'tenant_blog_post_localizations',
                $sql
            );
            self::assertStringContainsString('public_id', $sql);
            self::assertStringContainsString('locale', $sql);
            self::assertStringContainsString('slug', $sql);
            self::assertStringContainsString('h1', $sql);
            self::assertStringContainsString('seo_title', $sql);
            self::assertStringContainsString('meta_description', $sql);
            self::assertStringContainsString('excerpt', $sql);
            self::assertStringContainsString('body_text', $sql);
            self::assertStringContainsString('published_at', $sql);
            self::assertStringContainsString('lock_version', $sql);
            self::assertStringContainsString(
                'created_by_user_public_id',
                $sql
            );
            self::assertStringContainsString(
                'updated_by_user_public_id',
                $sql
            );
            self::assertStringNotContainsString('{{', $sql);
            self::assertDoesNotMatchRegularExpression(
                '/\b(?:categor|media|revision)[a-z_]*\b/i',
                $sql
            );
            self::assertStringNotContainsString('body_html', $sql);
        }
        self::assertStringContainsString(
            'UNIQUE KEY `uq_blog_locale_slug` (`locale`, `slug`)',
            $mysql
        );
        self::assertStringContainsString(
            'tenant_blog_ux_pl_locale_slug',
            $sqlite
        );
    }

    public function testSqliteSchemaIsIdempotentAndSupportsIndependentLocales(): void
    {
        $pdo = $this->sqlite();
        $scope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $migration = $this->migrations()[0];

        $this->apply($pdo, $migration, $scope);
        $this->apply($pdo, $migration, $scope);
        self::assertTrue($migration->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));

        $firstPost = $this->insertPost($pdo, $scope, 1);
        $secondPost = $this->insertPost($pdo, $scope, 2);
        $thirdPost = $this->insertPost($pdo, $scope, 3);
        $this->insertLocalization(
            $pdo,
            $scope,
            1,
            $firstPost,
            'es',
            null,
            'draft',
            null
        );
        $this->insertLocalization(
            $pdo,
            $scope,
            2,
            $secondPost,
            'es',
            null,
            'draft',
            null
        );
        $this->insertLocalization(
            $pdo,
            $scope,
            3,
            $thirdPost,
            'es',
            'asesoria-fiscal',
            'published',
            '2026-08-01 06:00:00.000000'
        );
        $this->insertLocalization(
            $pdo,
            $scope,
            4,
            $firstPost,
            'eu',
            'asesoria-fiscal',
            'draft',
            null
        );

        self::assertSame(4, (int) $pdo->query(
            'SELECT COUNT(*) FROM '
            . $scope->quotedTable('post_localizations', 'sqlite')
        )->fetchColumn());
        self::assertTrue($migration->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));

        $this->expectConstraintFailure(fn () => $this->insertLocalization(
            $pdo,
            $scope,
            5,
            $secondPost,
            'es',
            'asesoria-fiscal',
            'draft',
            null
        ));
        $this->expectConstraintFailure(fn () => $this->insertLocalization(
            $pdo,
            $scope,
            9,
            $secondPost,
            'en ',
            'english-padded-locale',
            'draft',
            null
        ));
        $this->expectConstraintFailure(fn () => $this->insertLocalization(
            $pdo,
            $scope,
            11,
            $secondPost,
            'en',
            ' padded-slug ',
            'draft',
            null
        ));
        $this->expectConstraintFailure(fn () => $this->insertLocalization(
            $pdo,
            $scope,
            6,
            $firstPost,
            'es',
            'otro-slug',
            'draft',
            null
        ));
        $this->expectConstraintFailure(fn () => $this->insertLocalization(
            $pdo,
            $scope,
            7,
            $secondPost,
            'EN',
            'english',
            'draft',
            null
        ));
        $this->expectConstraintFailure(fn () => $this->insertLocalization(
            $pdo,
            $scope,
            8,
            $secondPost,
            'en',
            'english',
            'published',
            null
        ));
        $localizations = $scope->quotedTable(
            'post_localizations',
            'sqlite'
        );
        $this->expectConstraintFailure(
            fn () => $pdo->exec(
                'UPDATE ' . $localizations . ' SET "lock_version" = 0 '
                . 'WHERE "id" = 1'
            )
        );
        $this->expectConstraintFailure(
            fn () => $pdo->exec(
                'UPDATE ' . $localizations . " SET \"h1\" = '   ' "
                . 'WHERE "id" = 1'
            )
        );
        $this->expectConstraintFailure(
            fn () => $pdo->exec(
                'UPDATE ' . $localizations . " SET \"slug\" = '' "
                . 'WHERE "id" = 1'
            )
        );
        $this->expectConstraintFailure(
            fn () => $pdo->exec(
                'UPDATE ' . $localizations
                . " SET \"updated_by_user_public_id\" = 'short' "
                . 'WHERE "id" = 1'
            )
        );
        $this->expectConstraintFailure(
            fn () => $pdo->exec(
                'UPDATE ' . $localizations
                . ' SET "meta_description" = NULL WHERE "id" = 3'
            )
        );
        $this->expectConstraintFailure(
            fn () => $pdo->exec(
                'UPDATE ' . $localizations
                . " SET \"body_text\" = '   ' WHERE \"id\" = 3"
            )
        );
    }

    public function testSqliteForeignKeyCascadesAndWholeBatchRollsBack(): void
    {
        $pdo = $this->sqlite();
        $scope = MigrationScope::forTablePrefix('blog', 'rollback_blog_');
        $migration = $this->migrations()[0];

        self::assertTrue($pdo->exec('BEGIN IMMEDIATE') !== false);
        try {
            $this->apply($pdo, $migration, $scope);
            throw new RuntimeException('rollback probe');
        } catch (RuntimeException) {
            self::assertTrue($pdo->exec('ROLLBACK') !== false);
        }
        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master "
            . "WHERE name LIKE 'rollback_blog_%'"
        )->fetchColumn());
        self::assertTrue($migration->preconditionVerifier()?->verify(
            $pdo,
            $scope
        ));

        $this->apply($pdo, $migration, $scope);
        $postId = $this->insertPost($pdo, $scope, 10);
        $this->insertLocalization(
            $pdo,
            $scope,
            10,
            $postId,
            'es',
            'borrado-en-cascada',
            'draft',
            null
        );
        $pdo->exec(
            'DELETE FROM ' . $scope->quotedTable('posts', 'sqlite')
            . ' WHERE "id" = ' . $postId
        );
        self::assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM '
            . $scope->quotedTable('post_localizations', 'sqlite')
        )->fetchColumn());
    }

    public function testCapabilityMigrationTargetsWebAdminAndIsIdempotent(): void
    {
        $pdo = $this->sqlite();
        $webAdminScope = MigrationScope::forTablePrefix(
            'webadmin',
            'tenant_admin_'
        );
        $webAdminMigration = iterator_to_array(
            WebAdminMigrationProvider::migrations(),
            false
        )[0];
        $this->apply($pdo, $webAdminMigration, $webAdminScope);

        $migration = $this->migrations()[1];
        $this->apply($pdo, $migration, $webAdminScope);
        $this->apply($pdo, $migration, $webAdminScope);
        self::assertTrue($migration->postconditionVerifier()?->verify(
            $pdo,
            $webAdminScope
        ));

        $capabilities = $pdo->query(
            'SELECT module_id, code, label_key, is_delegable FROM '
            . $webAdminScope->quotedTable('capabilities', 'sqlite')
            . " WHERE module_id = 'blog' ORDER BY code"
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([
            [
                'module_id' => 'blog',
                'code' => 'blog.articles.edit',
                'label_key' => 'blog.capabilities.articles_edit',
                'is_delegable' => 1,
            ],
            [
                'module_id' => 'blog',
                'code' => 'blog.articles.publish',
                'label_key' => 'blog.capabilities.articles_publish',
                'is_delegable' => 1,
            ],
            [
                'module_id' => 'blog',
                'code' => 'blog.articles.view',
                'label_key' => 'blog.capabilities.articles_view',
                'is_delegable' => 1,
            ],
        ], $capabilities);

        $mappings = $pdo->query(
            'SELECT r.code AS role_code, c.code AS capability_code FROM '
            . $webAdminScope->quotedTable('role_capabilities', 'sqlite')
            . ' AS rc JOIN '
            . $webAdminScope->quotedTable('roles', 'sqlite')
            . ' AS r ON r.id = rc.role_id JOIN '
            . $webAdminScope->quotedTable('capabilities', 'sqlite')
            . " AS c ON c.id = rc.capability_id WHERE c.module_id = 'blog' "
            . 'ORDER BY r.code, c.code'
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(6, $mappings);
        self::assertSame(
            ['site_admin', 'system_superadmin'],
            array_values(array_unique(array_column($mappings, 'role_code')))
        );

        $sqlite = implode(
            "\n",
            $migration->statementsFor('sqlite', $webAdminScope)
        );
        self::assertStringContainsString('tenant_admin_capabilities', $sqlite);
        self::assertStringNotContainsString('ls_blog_', $sqlite);
        self::assertStringNotContainsString('blog.posts.', $sqlite);
    }

    public function testCapabilityMigrationNeverAdoptsAnIncompatibleExistingCode(): void
    {
        $pdo = $this->sqlite();
        $scope = MigrationScope::forTablePrefix(
            'webadmin',
            'tenant_admin_'
        );
        $webAdminMigration = iterator_to_array(
            WebAdminMigrationProvider::migrations(),
            false
        )[0];
        $this->apply($pdo, $webAdminMigration, $scope);
        $pdo->exec(
            'INSERT INTO ' . $scope->quotedTable('capabilities', 'sqlite')
            . ' ("module_id", "code", "label_key", "is_delegable") '
            . "VALUES ('project', 'blog.articles.view', "
            . "'project.capability.must_survive', 0)"
        );

        $migration = $this->migrations()[1];
        $this->apply($pdo, $migration, $scope);

        self::assertSame([
            'module_id' => 'project',
            'label_key' => 'project.capability.must_survive',
            'is_delegable' => 0,
        ], $pdo->query(
            'SELECT module_id, label_key, is_delegable FROM '
            . $scope->quotedTable('capabilities', 'sqlite')
            . " WHERE code = 'blog.articles.view'"
        )->fetch(PDO::FETCH_ASSOC));
        self::assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM '
            . $scope->quotedTable('role_capabilities', 'sqlite')
            . ' rc JOIN ' . $scope->quotedTable('capabilities', 'sqlite')
            . " c ON c.id = rc.capability_id WHERE c.code = "
            . "'blog.articles.view'"
        )->fetchColumn());
        self::assertFalse($migration->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));

        $sqlite = implode("\n", $migration->statementsFor('sqlite', $scope));
        $mysql = implode("\n", $migration->statementsFor('mysql', $scope));
        self::assertStringContainsString(
            'ON CONFLICT("code") DO NOTHING',
            $sqlite
        );
        self::assertStringContainsString(
            'INSERT IGNORE INTO',
            $mysql
        );
        self::assertStringNotContainsString(
            '`module_id` = VALUES(`module_id`)',
            $mysql
        );
    }

    /** @return list<MigrationDefinition> */
    private function migrations(): array
    {
        return iterator_to_array(BlogMigrationProvider::migrations(), false);
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function apply(
        PDO $pdo,
        MigrationDefinition $migration,
        MigrationScope $scope
    ): void {
        foreach ($migration->statementsFor('sqlite', $scope) as $statement) {
            $pdo->exec($statement);
        }
    }

    private function insertPost(
        PDO $pdo,
        MigrationScope $scope,
        int $sequence
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO ' . $scope->quotedTable('posts', 'sqlite')
            . ' ("public_id", "created_by_user_public_id") '
            . 'VALUES (:public_id, :author)'
        );
        $statement->execute([
            'public_id' => sprintf(
                '10000000-0000-4000-8000-%012x',
                $sequence
            ),
            'author' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertLocalization(
        PDO $pdo,
        MigrationScope $scope,
        int $sequence,
        int $postId,
        string $locale,
        ?string $slug,
        string $status,
        ?string $publishedAt
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO '
            . $scope->quotedTable('post_localizations', 'sqlite')
            . ' ("public_id", "post_id", "locale", "slug", "h1", '
            . '"seo_title", "meta_description", "excerpt", "body_text", '
            . '"status", "published_at", "created_by_user_public_id", '
            . '"updated_by_user_public_id") VALUES '
            . '(:public_id, :post_id, :locale, :slug, :h1, :seo_title, '
            . ':meta_description, :excerpt, :body_text, :status, '
            . ':published_at, :created_by, :updated_by)'
        );
        $statement->execute([
            'public_id' => sprintf(
                '20000000-0000-4000-8000-%012x',
                $sequence
            ),
            'post_id' => $postId,
            'locale' => $locale,
            'slug' => $slug,
            'h1' => 'Encabezado del articulo',
            'seo_title' => 'Title SEO',
            'meta_description' => 'Descripcion SEO controlada.',
            'excerpt' => 'Resumen del articulo.',
            'body_text' => 'Contenido plano que se codificara al renderizar.',
            'status' => $status,
            'published_at' => $publishedAt,
            'created_by' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'updated_by' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        ]);
    }

    /** @param callable(): void $operation */
    private function expectConstraintFailure(callable $operation): void
    {
        try {
            $operation();
            self::fail('The database contract should reject this row.');
        } catch (PDOException) {
            self::addToAssertionCount(1);
        }
    }
}
