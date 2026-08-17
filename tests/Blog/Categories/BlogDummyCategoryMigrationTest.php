<?php

declare(strict_types=1);

namespace Tests\Blog\Categories;

use App\Core\Blog\Admin\BlogAdminCatalogQuery;
use App\Core\Blog\Categories\BlogReservedCategoryPolicy;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\Seo\BlogPublicRobotsPolicy;
use App\Core\Blog\Seo\PdoBlogDummyCategoryRobotsOverride;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use PHPUnit\Framework\TestCase;

final class BlogDummyCategoryMigrationTest extends TestCase
{
    private const ACTOR = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private PDO $pdo;
    private MigrationScope $scope;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->scope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        foreach ($this->migrations([
            '0001_blog_posts',
            '0003_blog_categories',
            '0005_blog_structured_content',
            '0006_blog_sitemap_publication_state',
            '0007_blog_post_tombstones',
            '0009_blog_analytics',
            '0011_blog_layout_editor_v2',
            '0012_blog_editor_preferences',
            '0014_blog_private_draft_publication',
            '0015_blog_robots_preferences',
            '0016_blog_url_history',
        ]) as $migration) {
            $this->apply($migration);
        }
    }

    public function testCanonicalSeedCoexistsWithLegacyDummyAndRetriesExactly(): void
    {
        $legacyEs = $this->insertCategory(
            '11111111-1111-4111-8111-111111111111',
            '21111111-1111-4111-8111-111111111111',
            'es'
        );
        $this->insertCategory(
            '12222222-2222-4222-8222-222222222222',
            '22222222-2222-4222-8222-222222222222',
            'en'
        );
        $this->pdo->prepare(
            'INSERT INTO ls_blog_posts '
                . '(public_id, created_by_user_public_id) VALUES (?, ?)'
        )->execute([
            '33333333-3333-4333-8333-333333333333',
            self::ACTOR,
        ]);
        $postId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO ls_blog_post_categories '
                . '(public_id, post_id, category_id, '
                . 'assigned_by_user_public_id) VALUES (?, ?, ?, ?)'
        )->execute([
            '43333333-3333-4333-8333-333333333333',
            $postId,
            $legacyEs,
            self::ACTOR,
        ]);
        $seed = $this->migrations(['0017_blog_dummy_category'])[0];
        $verifier = $seed->postconditionVerifier();
        self::assertNotNull($verifier);
        self::assertSame('blog-dummy-category-seed-v2', $verifier->contractVersion());
        self::assertFalse($seed->isTransactionalFor('mysql'));
        $mysql = implode("\n", $seed->statementsFor('mysql', $this->scope));
        self::assertStringContainsString('INSERT IGNORE', $mysql);
        self::assertStringNotContainsString('NOT EXISTS', $mysql);
        self::assertFalse($verifier->verify($this->pdo, $this->scope));

        // Simulates the category-only state left by an older non-transactional
        // attempt. The new statements must complete it without touching legacy.
        $this->pdo->prepare(
            'INSERT INTO ls_blog_categories '
                . '(public_id, created_by_user_public_id) VALUES (?, ?)'
        )->execute([
            BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID,
            BlogReservedCategoryPolicy::SYSTEM_ACTOR_PUBLIC_ID,
        ]);
        self::assertFalse($verifier->verify($this->pdo, $this->scope));

        $this->apply($seed);
        $this->apply($seed);
        self::assertTrue($verifier->verify($this->pdo, $this->scope));
        self::assertSame(3, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ls_blog_category_locales WHERE slug = 'dummy'"
        )->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_post_categories pc JOIN '
                . 'ls_blog_categories c ON c.id = pc.category_id WHERE '
                . "c.public_id = '11111111-1111-4111-8111-111111111111'"
        )->fetchColumn());

        $this->pdo->exec(
            "UPDATE ls_blog_category_locales SET name = 'drift' WHERE "
                . "public_id = '00000000-0000-4000-8000-000000000117'"
        );
        self::assertFalse($verifier->verify($this->pdo, $this->scope));
    }

    public function testLegacyDummyAssignmentsBecomePrivateByCanonicalUuid(): void
    {
        $legacyCategoryId = $this->insertCategory(
            '11111111-1111-4111-8111-111111111111',
            '21111111-1111-4111-8111-111111111111',
            'es'
        );
        $this->pdo->prepare(
            'INSERT INTO ls_blog_posts '
                . '(public_id, created_by_user_public_id) VALUES (?, ?)'
        )->execute([
            '33333333-3333-4333-8333-333333333333',
            self::ACTOR,
        ]);
        $postId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO ls_blog_post_localizations '
                . '(public_id, post_id, locale, slug, h1, seo_title, '
                . 'meta_description, excerpt, body_text, status, '
                . 'published_at, created_by_user_public_id, '
                . 'updated_by_user_public_id) VALUES '
                . '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            '53333333-3333-4333-8333-333333333333',
            $postId,
            'es',
            'qa-pub-001',
            'QA PUB-001',
            'QA PUB-001',
            'Fixture heredado que nunca debe alcanzar el sitio publico.',
            'Fixture heredado.',
            'Contenido QA heredado.',
            'published',
            '2030-01-01 10:00:00.000000',
            self::ACTOR,
            self::ACTOR,
        ]);
        $this->pdo->prepare(
            'INSERT INTO ls_blog_post_categories '
                . '(public_id, post_id, category_id, '
                . 'assigned_by_user_public_id) VALUES (?, ?, ?, ?)'
        )->execute([
            '43333333-3333-4333-8333-333333333333',
            $postId,
            $legacyCategoryId,
            self::ACTOR,
        ]);
        $this->pdo->prepare(
            'INSERT INTO ls_blog_category_assignment_workspaces '
                . '(post_id, base_assignment_version, workspace_version, '
                . 'created_by_user_public_id, updated_by_user_public_id, '
                . 'created_at, updated_at) VALUES (?, 0, 2, ?, ?, ?, ?)'
        )->execute([
            $postId,
            self::ACTOR,
            self::ACTOR,
            '2030-01-01 09:00:00.000000',
            '2030-01-01 09:05:00.000000',
        ]);
        $this->pdo->prepare(
            'INSERT INTO ls_blog_category_assignment_workspace_items '
                . '(post_id, category_id, assigned_by_user_public_id, '
                . 'created_at) VALUES (?, ?, ?, ?)'
        )->execute([
            $postId,
            $legacyCategoryId,
            self::ACTOR,
            '2030-01-01 09:05:00.000000',
        ]);

        $seed = $this->migrations(['0017_blog_dummy_category'])[0];
        $normalization = $this->migrations([
            '0018_blog_dummy_category_normalization',
        ])[0];
        $this->apply($seed);
        $verifier = $normalization->postconditionVerifier();
        self::assertNotNull($verifier);
        self::assertSame(
            'blog-dummy-category-normalization-v1',
            $verifier->contractVersion()
        );
        self::assertFalse($verifier->verify($this->pdo, $this->scope));

        $repository = new PdoBlogRepository(
            $this->pdo,
            $this->scope,
            reservedCategoryPolicyEnabled: true
        );
        self::assertCount(1, $repository->searchSummaries(
            new BlogAdminCatalogQuery()
        ));
        self::assertNotNull($repository->publishedVariant('es', 'qa-pub-001'));

        $sqliteStatements = $normalization->statementsFor(
            'sqlite',
            $this->scope
        );
        self::assertCount(2, $sqliteStatements);
        self::assertStringContainsString(
            'ON CONFLICT("post_id", "category_id") DO NOTHING',
            implode("\n", $sqliteStatements)
        );
        self::assertNotFalse($this->pdo->exec($sqliteStatements[0]));
        self::assertNotFalse($this->pdo->exec($sqliteStatements[0]));
        self::assertFalse(
            $verifier->verify($this->pdo, $this->scope),
            'A partial MySQL-like retry cannot claim an uncovered workspace.'
        );
        self::assertNotFalse($this->pdo->exec($sqliteStatements[1]));
        $this->apply($normalization);
        $this->apply($normalization);
        self::assertTrue($verifier->verify($this->pdo, $this->scope));
        self::assertSame(2, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_post_categories WHERE post_id = '
                . $postId
        )->fetchColumn(), 'Legacy data is preserved beside the canonical link.');
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_post_categories pc JOIN '
                . 'ls_blog_categories c ON c.id = pc.category_id WHERE '
                . 'pc.post_id = ' . $postId . " AND c.public_id = '"
                . BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID . "'"
        )->fetchColumn());
        self::assertSame(
            'b3333333-3333-4333-8333-333333333333',
            $this->pdo->query(
                'SELECT pc.public_id FROM ls_blog_post_categories pc JOIN '
                    . 'ls_blog_categories c ON c.id = pc.category_id WHERE '
                    . 'pc.post_id = ' . $postId . " AND c.public_id = '"
                    . BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID
                    . "'"
            )->fetchColumn(),
            'The deterministic relation ID remains an RFC 4122 UUID v4.'
        );
        self::assertSame(2, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM '
                . 'ls_blog_category_assignment_workspace_items WHERE '
                . 'post_id = ' . $postId
        )->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM '
                . 'ls_blog_category_assignment_workspace_items wi JOIN '
                . 'ls_blog_categories c ON c.id = wi.category_id WHERE '
                . 'wi.post_id = ' . $postId . " AND c.public_id = '"
                . BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID . "'"
        )->fetchColumn());
        $mysql = implode("\n", $normalization->statementsFor(
            'mysql',
            $this->scope
        ));
        self::assertStringNotContainsString('UUID()', $mysql);
        self::assertStringNotContainsString('RANDOM_BYTES', $mysql);
        self::assertStringContainsString(
            'INSERT IGNORE INTO `ls_blog_post_categories`',
            $mysql
        );
        self::assertStringContainsString(
            'INSERT IGNORE INTO '
                . '`ls_blog_category_assignment_workspace_items`',
            $mysql
        );
        self::assertStringContainsString('LOCATE(', $mysql);
        self::assertStringContainsString(
            'ls_blog_category_assignment_workspace_items',
            $mysql
        );

        self::assertSame([], $repository->searchSummaries(
            new BlogAdminCatalogQuery()
        ));
        self::assertSame([], $repository->listPublishedCards('es', 10, 0));
        self::assertSame([], $repository->sitemapEntries(10));
        self::assertNull($repository->publishedVariant('es', 'qa-pub-001'));
        $editorVariant = $repository->variant(
            '33333333-3333-4333-8333-333333333333',
            'es'
        );
        self::assertNotNull($editorVariant, 'The editor read path remains intact.');
        self::assertSame(
            'noindex,nofollow',
            (new BlogPublicRobotsPolicy(
                new PdoBlogDummyCategoryRobotsOverride(
                    $this->pdo,
                    $this->scope
                )
            ))->effectiveFor($editorVariant)->directive()
        );
    }

    private function insertCategory(
        string $categoryPublicId,
        string $localizationPublicId,
        string $locale
    ): int {
        $this->pdo->prepare(
            'INSERT INTO ls_blog_categories '
                . '(public_id, created_by_user_public_id) VALUES (?, ?)'
        )->execute([$categoryPublicId, self::ACTOR]);
        $categoryId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO ls_blog_category_locales '
                . '(public_id, category_id, locale, slug, name, '
                . 'created_by_user_public_id, updated_by_user_public_id) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $localizationPublicId,
            $categoryId,
            $locale,
            'dummy',
            'dummy',
            self::ACTOR,
            self::ACTOR,
        ]);

        return $categoryId;
    }

    /** @param list<string> $ids @return list<MigrationDefinition> */
    private function migrations(array $ids): array
    {
        $wanted = array_fill_keys($ids, true);
        $result = [];
        foreach (BlogMigrationProvider::migrations() as $migration) {
            if (isset($wanted[$migration->id()])) {
                $result[] = $migration;
                unset($wanted[$migration->id()]);
            }
        }
        self::assertSame([], array_keys($wanted));

        return $result;
    }

    private function apply(MigrationDefinition $migration): void
    {
        foreach ($migration->statementsFor('sqlite', $this->scope) as $sql) {
            self::assertNotFalse($this->pdo->exec($sql));
        }
    }
}
