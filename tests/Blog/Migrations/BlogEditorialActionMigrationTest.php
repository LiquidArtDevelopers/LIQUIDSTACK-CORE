<?php

declare(strict_types=1);

namespace Tests\Blog\Migrations;

use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use PDO;
use PHPUnit\Framework\TestCase;

final class BlogEditorialActionMigrationTest extends TestCase
{
    public function testTombstoneMigrationIsIdempotentAndDriftSensitive(): void
    {
        $pdo = $this->sqlite();
        $scope = MigrationScope::forTablePrefix('blog', 'editorial_blog_');
        $migrations = $this->blogMigrations();
        foreach ([
            '0001_blog_posts',
            '0003_blog_categories',
            '0005_blog_structured_content',
            '0006_blog_sitemap_publication_state',
            '0007_blog_post_tombstones',
        ] as $id) {
            $this->apply($pdo, $migrations[$id], $scope);
        }
        $tombstones = $migrations['0007_blog_post_tombstones'];
        $this->apply($pdo, $tombstones, $scope);

        self::assertTrue($tombstones->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
        $sql = implode("\n", $tombstones->statementsFor('sqlite', $scope));
        self::assertStringContainsString('editorial_blog_post_tombstones', $sql);
        self::assertStringNotContainsString('{{', $sql);

        $pdo->exec('DROP INDEX editorial_blog_ix_pt_time');
        self::assertFalse($tombstones->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
    }

    public function testDeleteCapabilityIsExactIdempotentAndGrantedToProtectedRoles(): void
    {
        $pdo = $this->sqlite();
        $scope = MigrationScope::forTablePrefix('webadmin', 'editorial_admin_');
        $webAdminBase = iterator_to_array(
            WebAdminMigrationProvider::migrations(),
            false
        )[0];
        $this->apply($pdo, $webAdminBase, $scope);
        $migrations = $this->blogMigrations();
        foreach ([
            '0002_blog_capabilities',
            '0004_blog_category_capabilities',
            '0008_blog_article_delete_capability',
        ] as $id) {
            $this->apply($pdo, $migrations[$id], $scope);
        }
        $capability = $migrations['0008_blog_article_delete_capability'];
        $this->apply($pdo, $capability, $scope);

        self::assertTrue($capability->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
        self::assertSame([
            'module_id' => 'blog',
            'code' => 'blog.articles.delete',
            'label_key' => 'blog.capabilities.articles_delete',
            'is_delegable' => 1,
        ], $pdo->query(
            'SELECT module_id, code, label_key, is_delegable FROM '
                . 'editorial_admin_capabilities WHERE code = '
                . "'blog.articles.delete'"
        )->fetch(PDO::FETCH_ASSOC));
        self::assertSame(
            ['site_admin', 'system_superadmin'],
            $pdo->query(
                'SELECT r.code FROM editorial_admin_role_capabilities rc '
                    . 'JOIN editorial_admin_roles r ON r.id = rc.role_id '
                    . 'JOIN editorial_admin_capabilities c '
                    . 'ON c.id = rc.capability_id WHERE c.code = '
                    . "'blog.articles.delete' ORDER BY r.code"
            )->fetchAll(PDO::FETCH_COLUMN)
        );

        $pdo->exec(
            "UPDATE editorial_admin_capabilities SET label_key = 'wrong' "
                . "WHERE code = 'blog.articles.delete'"
        );
        self::assertFalse($capability->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
    }

    /** @return array<string, MigrationDefinition> */
    private function blogMigrations(): array
    {
        $result = [];
        foreach (BlogMigrationProvider::migrations() as $migration) {
            $result[$migration->id()] = $migration;
        }

        return $result;
    }

    private function apply(
        PDO $pdo,
        MigrationDefinition $migration,
        MigrationScope $scope
    ): void {
        foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
            self::assertNotFalse($pdo->exec($sql));
        }
    }

    private function sqlite(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }
}
