<?php

declare(strict_types=1);

namespace Tests\Blog\Categories;

use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Modules\Blog\BlogCategoryCapabilitySeedPostcondition;
use App\Core\Modules\Blog\BlogCategoryMigrationPostconditionVerifier;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use PDO;
use PHPUnit\Framework\TestCase;

final class BlogCategoryMigrationTest extends TestCase
{
    public function testSchemaAndCapabilitiesAreAdditiveAndIdempotent(): void
    {
        $pdo = $this->pdo();
        $blog = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $admin = MigrationScope::forTablePrefix('webadmin', 'ls_webadmin_');
        $blogMigrations = iterator_to_array(
            BlogMigrationProvider::migrations(),
            false
        );
        $webAdminMigrations = iterator_to_array(
            WebAdminMigrationProvider::migrations(),
            false
        );
        $this->apply($pdo, $webAdminMigrations[0], $admin);
        $this->apply($pdo, $blogMigrations[0], $blog);
        $this->apply($pdo, $blogMigrations[1], $admin);
        $this->apply($pdo, $blogMigrations[2], $blog);
        $this->apply($pdo, $blogMigrations[2], $blog);
        $this->apply($pdo, $blogMigrations[3], $admin);
        $this->apply($pdo, $blogMigrations[3], $admin);

        self::assertTrue((new BlogCategoryMigrationPostconditionVerifier())
            ->verify($pdo, $blog));
        self::assertTrue((new BlogCategoryCapabilitySeedPostcondition())
            ->verify($pdo, $admin));
        self::assertSame(
            ['0001_blog_posts'],
            $blogMigrations[2]->supersededPostconditionIds()
        );
        self::assertSame(
            ['0002_blog_capabilities'],
            $blogMigrations[3]->supersededPostconditionIds()
        );
    }

    public function testExactVerifierRejectsIndexCheckForeignKeyAndTriggerDrift(): void
    {
        $scope = MigrationScope::forTablePrefix('blog', 'drift_blog_');
        foreach (['index', 'check', 'foreign_key', 'trigger'] as $drift) {
            $pdo = $this->pdo();
            $migrations = iterator_to_array(
                BlogMigrationProvider::migrations(),
                false
            );
            $this->apply($pdo, $migrations[0], $scope);
            $this->apply($pdo, $migrations[2], $scope);
            self::assertTrue((new BlogCategoryMigrationPostconditionVerifier())
                ->verify($pdo, $scope), $drift);

            if ($drift === 'index') {
                $pdo->exec('DROP INDEX "drift_blog_ux_cl_locale_slug"');
            } elseif ($drift === 'trigger') {
                $pdo->exec(
                    'CREATE TRIGGER drift_blog_forbidden AFTER INSERT ON '
                    . '"drift_blog_categories" BEGIN SELECT 1; END'
                );
            } elseif ($drift === 'foreign_key') {
                $pdo->exec('PRAGMA foreign_keys = OFF');
            } else {
                $pdo->exec('PRAGMA ignore_check_constraints = ON');
            }
            self::assertFalse((new BlogCategoryMigrationPostconditionVerifier())
                ->verify($pdo, $scope), $drift);
        }
    }

    public function testPendingOrPartialCategorySchemaNeverSupersedesBase(): void
    {
        $pdo = $this->pdo();
        $scope = MigrationScope::forTablePrefix('blog', 'pending_blog_');
        $migrations = iterator_to_array(
            BlogMigrationProvider::migrations(),
            false
        );
        $this->apply($pdo, $migrations[0], $scope);

        self::assertTrue($migrations[0]->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
        self::assertFalse($migrations[2]->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));

        $pdo->exec(
            'CREATE TABLE "pending_blog_categories" '
            . '(id INTEGER PRIMARY KEY AUTOINCREMENT)'
        );
        self::assertFalse($migrations[0]->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
        self::assertFalse($migrations[2]->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
    }

    public function testMaximumSupportedPrefixKeepsEveryIdentifierPortable(): void
    {
        $prefix = 'b' . str_repeat(
            'x',
            BlogConfig::MAX_TABLE_PREFIX_LENGTH - 2
        ) . '_';
        $scope = MigrationScope::forTablePrefix('blog', $prefix);
        $migration = iterator_to_array(
            BlogMigrationProvider::migrations(),
            false
        )[2];

        foreach (['mysql', 'sqlite'] as $driver) {
            $sql = implode("\n", $migration->statementsFor($driver, $scope));
            preg_match_all('/[`"]([A-Za-z][A-Za-z0-9_]*)[`"]/', $sql, $matches);
            foreach ($matches[1] as $identifier) {
                self::assertLessThanOrEqual(64, strlen($identifier), $identifier);
            }
        }
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function apply(PDO $pdo, $migration, MigrationScope $scope): void
    {
        foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
            self::assertNotFalse($pdo->exec($sql));
        }
    }
}
