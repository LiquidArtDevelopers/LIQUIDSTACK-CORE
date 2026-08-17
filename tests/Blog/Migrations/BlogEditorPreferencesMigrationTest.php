<?php

declare(strict_types=1);

namespace Tests\Blog\Migrations;

use App\Core\Blog\EditorPreferences\BlogEditorPreferences;
use App\Core\Blog\EditorPreferences\Persistence\PdoBlogEditorPreferencesRepository;
use App\Core\Modules\Blog\BlogEditorPreferencesMigrationPostconditionVerifier;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Blog\BlogMigrationRequirements;
use App\Core\Modules\Blog\BlogSettingsCapabilitySeedPostcondition;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class BlogEditorPreferencesMigrationTest extends TestCase
{
    private const ACTOR = '42000000-0000-4000-8000-000000000001';

    public function testOptionalSchemaIsExactEmptyAndAcceptsCanonicalRow(): void
    {
        $pdo = $this->sqlite();
        $scope = MigrationScope::forTablePrefix('blog', 'settings_blog_');
        $migrations = $this->migrations();
        foreach ([
            '0001_blog_posts',
            '0003_blog_categories',
            '0005_blog_structured_content',
            '0006_blog_sitemap_publication_state',
            '0007_blog_post_tombstones',
            '0009_blog_analytics',
            '0011_blog_layout_editor_v2',
            '0012_blog_editor_preferences',
        ] as $id) {
            $this->apply($pdo, $migrations[$id], $scope);
        }
        $migration = $migrations['0012_blog_editor_preferences'];
        self::assertInstanceOf(
            BlogEditorPreferencesMigrationPostconditionVerifier::class,
            $migration->postconditionVerifier()
        );
        self::assertTrue($migration->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
        self::assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM settings_blog_editor_preferences'
        )->fetchColumn(), 'Migration must not seed a project preference row.');

        $repository = new PdoBlogEditorPreferencesRepository($pdo, $scope);
        $repository->transactional(
            static function (PDO $transaction) use ($repository): void {
                $repository->insertGlobal(
                    BlogEditorPreferences::defaults(),
                    self::ACTOR,
                    new DateTimeImmutable(
                        '2026-08-05 12:00:00.000000',
                        new DateTimeZone('UTC')
                    )
                );
            }
        );
        self::assertTrue($migration->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));

        $pdo->exec(
            "UPDATE settings_blog_editor_preferences SET "
                . "preferences_sha256 = '"
                . str_repeat('0', 64) . "' WHERE scope_key = 'global'"
        );
        self::assertFalse($migration->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
    }

    public function testProtectedCapabilityIsExactAndIdempotent(): void
    {
        $pdo = $this->sqlite();
        $scope = MigrationScope::forTablePrefix(
            'webadmin',
            'settings_admin_'
        );
        $webAdmin = iterator_to_array(
            WebAdminMigrationProvider::migrations(),
            false
        )[0];
        $this->apply($pdo, $webAdmin, $scope);
        $migrations = $this->migrations();
        foreach ([
            '0002_blog_capabilities',
            '0004_blog_category_capabilities',
            '0008_blog_article_delete_capability',
            '0010_blog_analytics_view_capability',
            '0013_blog_settings_manage_capability',
        ] as $id) {
            $this->apply($pdo, $migrations[$id], $scope);
        }
        $capability = $migrations['0013_blog_settings_manage_capability'];
        $this->apply($pdo, $capability, $scope);
        self::assertInstanceOf(
            BlogSettingsCapabilitySeedPostcondition::class,
            $capability->postconditionVerifier()
        );
        self::assertTrue($capability->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
        self::assertSame([
            'module_id' => 'blog',
            'code' => 'blog.settings.manage',
            'label_key' => 'blog.capabilities.settings_manage',
            'is_delegable' => 0,
        ], $pdo->query(
            'SELECT module_id, code, label_key, is_delegable FROM '
                . "settings_admin_capabilities WHERE code = 'blog.settings.manage'"
        )->fetch(PDO::FETCH_ASSOC));
        self::assertSame(
            ['site_admin', 'system_superadmin'],
            $pdo->query(
                'SELECT r.code FROM settings_admin_role_capabilities rc '
                    . 'JOIN settings_admin_roles r ON r.id = rc.role_id '
                    . 'JOIN settings_admin_capabilities c '
                    . 'ON c.id = rc.capability_id WHERE c.code = '
                    . "'blog.settings.manage' ORDER BY r.code"
            )->fetchAll(PDO::FETCH_COLUMN)
        );

        $pdo->exec(
            'INSERT INTO settings_admin_role_capabilities '
                . '(role_id, capability_id) SELECT r.id, c.id FROM '
                . 'settings_admin_roles r CROSS JOIN '
                . 'settings_admin_capabilities c WHERE r.code = '
                . "'editor' AND c.code = 'blog.settings.manage'"
        );
        self::assertFalse($capability->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
        $pdo->exec(
            'DELETE FROM settings_admin_role_capabilities WHERE role_id = '
                . "(SELECT id FROM settings_admin_roles WHERE code = 'editor') "
                . 'AND capability_id = (SELECT id FROM '
                . 'settings_admin_capabilities WHERE code = '
                . "'blog.settings.manage')"
        );
        self::assertTrue($capability->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));

        $pdo->exec(
            'UPDATE settings_admin_capabilities SET is_delegable = 1 '
                . "WHERE code = 'blog.settings.manage'"
        );
        self::assertFalse($capability->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
    }

    public function testRequirementDoesNotAlterExistingEditorBoundary(): void
    {
        $preferences = BlogMigrationRequirements::editorPreferences();
        self::assertSame('blog.editor_preferences', $preferences->featureId());
        self::assertSame([
            '0012_blog_editor_preferences',
            '0013_blog_settings_manage_capability',
        ], $preferences->migrationIds());
        self::assertFalse(
            BlogMigrationRequirements::layoutEditor()->requires(
                '0012_blog_editor_preferences'
            )
        );
        self::assertTrue(
            BlogMigrationRequirements::administration()->requires(
                '0012_blog_editor_preferences'
            )
        );
    }

    /** @return array<string, MigrationDefinition> */
    private function migrations(): array
    {
        $migrations = [];
        foreach (BlogMigrationProvider::migrations() as $migration) {
            $migrations[$migration->id()] = $migration;
        }

        return $migrations;
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
