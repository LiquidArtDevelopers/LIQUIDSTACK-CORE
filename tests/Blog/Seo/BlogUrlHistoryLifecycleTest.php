<?php

declare(strict_types=1);

namespace Tests\Blog\Seo;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Http\BlogPublicHttpController;
use App\Core\Blog\Http\BlogPublicHttpRuntime;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\Seo\BlogUrlResolution;
use App\Core\Blog\Seo\PdoBlogUrlHistoryRepository;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Blog\BlogUrlHistoryMigrationPostconditionVerifier;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use PDO;
use PHPUnit\Framework\TestCase;

final class BlogUrlHistoryLifecycleTest extends TestCase
{
    private PDO $pdo;
    private MigrationScope $scope;
    private BlogService $service;
    private BlogPublicHttpController $public;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->scope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        foreach ([
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
            '0017_blog_dummy_category',
        ] as $id) {
            $this->apply($id);
        }
        // Retry safety is contractual, not an installer side effect.
        $this->apply('0016_blog_url_history');
        self::assertTrue(
            (new BlogUrlHistoryMigrationPostconditionVerifier())
                ->verify($this->pdo, $this->scope)
        );

        $history = new PdoBlogUrlHistoryRepository($this->pdo, $this->scope);
        $repository = new PdoBlogRepository(
            $this->pdo,
            $this->scope,
            postTombstonesEnabled: true,
            reservedCategoryPolicyEnabled: true
        );
        $generator = new class implements UuidGeneratorInterface {
            private int $sequence = 1;

            public function generateV4(): string
            {
                return sprintf(
                    '00000000-0000-4000-8000-%012x',
                    $this->sequence++
                );
            }
        };
        $this->service = new BlogService(
            $repository,
            $generator,
            urlHistory: $history
        );
        $runtime = new BlogPublicHttpRuntime(
            new BlogConfig(
                ['es' => '/noticias'],
                '/blog-sitemap.xml',
                'ls_blog_',
                'fixture'
            ),
            BlogPublicOrigin::fromEnvironment([
                BlogPublicOrigin::ENV => 'https://example.test',
            ]),
            $this->service,
            urlHistory: $history
        );
        $this->public = new BlogPublicHttpController($runtime);
    }

    public function testTemporaryGoneAndExplicitEquivalentRedirectLifecycle(): void
    {
        $source = $this->service->createPost(
            $this->actorGate(),
            'es',
            $this->draft('origen', 'Origen')
        );
        $publishedSource = $this->service->publish(
            $this->actorGate(),
            $source->postPublicId(),
            'es',
            $source->lockVersion()
        );
        self::assertSame(200, $this->public->article('es', 'origen')?->status());

        $draftSource = $this->service->unpublish(
            $this->actorGate(),
            $source->postPublicId(),
            'es',
            $publishedSource->lockVersion()
        );
        self::assertSame(404, $this->public->article('es', 'origen')?->status());
        self::assertSame([], $this->service->sitemapEntries());

        $goneSource = $this->service->finalizeRetiredUrl(
            $this->actorGate(),
            $source->postPublicId(),
            'es',
            $draftSource->lockVersion(),
            'origen',
            BlogUrlResolution::GONE
        );
        self::assertSame(410, $this->public->article('es', 'origen')?->status());

        $target = $this->service->createPost(
            $this->actorGate(),
            'es',
            $this->draft('sustituto', 'Sustituto')
        );
        $publishedTarget = $this->service->publish(
            $this->actorGate(),
            $target->postPublicId(),
            'es',
            $target->lockVersion()
        );
        $this->pdo->exec(
            "INSERT INTO ls_blog_post_categories "
                . "(public_id, post_id, category_id, "
                . "assigned_by_user_public_id) SELECT "
                . "'99999999-9999-4999-8999-999999999999', p.id, c.id, "
                . "'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' FROM "
                . "ls_blog_posts p CROSS JOIN ls_blog_categories c WHERE "
                . "p.public_id = '" . $target->postPublicId() . "' AND "
                . "c.public_id = '00000000-0000-4000-8000-000000000017'"
        );
        try {
            $this->service->finalizeRetiredUrl(
                $this->actorGate(),
                $source->postPublicId(),
                'es',
                $goneSource->lockVersion(),
                'origen',
                BlogUrlResolution::REDIRECT,
                $target->postPublicId()
            );
            self::fail('An internal Dummy fixture cannot be a 301 target.');
        } catch (\App\Core\Blog\BlogException $exception) {
            self::assertSame(
                \App\Core\Blog\BlogException::INVALID_STATE,
                $exception->issueCode()
            );
        }
        $this->pdo->exec(
            "DELETE FROM ls_blog_post_categories WHERE public_id = "
                . "'99999999-9999-4999-8999-999999999999'"
        );
        $this->service->finalizeRetiredUrl(
            $this->actorGate(),
            $source->postPublicId(),
            'es',
            $goneSource->lockVersion(),
            'origen',
            BlogUrlResolution::REDIRECT,
            $target->postPublicId()
        );
        $redirect = $this->public->article('es', 'origen');
        self::assertSame(301, $redirect?->status());
        self::assertSame('/noticias/sustituto', $redirect?->headers()['Location'] ?? null);
        $this->pdo->exec(
            "INSERT INTO ls_blog_post_categories "
                . "(public_id, post_id, category_id, "
                . "assigned_by_user_public_id) SELECT "
                . "'99999999-9999-4999-8999-999999999999', p.id, c.id, "
                . "'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' FROM "
                . "ls_blog_posts p CROSS JOIN ls_blog_categories c WHERE "
                . "p.public_id = '" . $target->postPublicId() . "' AND "
                . "c.public_id = '00000000-0000-4000-8000-000000000017'"
        );
        self::assertSame(404, $this->public->article('es', 'origen')?->status());
        $this->pdo->exec(
            "DELETE FROM ls_blog_post_categories WHERE public_id = "
                . "'99999999-9999-4999-8999-999999999999'"
        );
        self::assertSame(301, $this->public->article('es', 'origen')?->status());
        $this->service->unpublish(
            $this->actorGate(),
            $target->postPublicId(),
            'es',
            $publishedTarget->lockVersion()
        );
        self::assertSame(404, $this->public->article('es', 'origen')?->status());
    }

    public function testLegacyDummySlugDoesNotHideAnOrdinaryPublication(): void
    {
        $created = $this->service->createPost(
            $this->actorGate(),
            'es',
            $this->draft('publicacion-normal', 'Publicación normal')
        );
        $published = $this->service->publish(
            $this->actorGate(),
            $created->postPublicId(),
            'es',
            $created->lockVersion()
        );
        $this->pdo->prepare(
            'INSERT INTO ls_blog_categories '
                . '(public_id, created_by_user_public_id) VALUES (?, ?)'
        )->execute([
            '71111111-1111-4111-8111-111111111111',
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        ]);
        $legacyCategoryId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO ls_blog_category_locales '
                . '(public_id, category_id, locale, slug, name, '
                . 'created_by_user_public_id, updated_by_user_public_id) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            '81111111-1111-4111-8111-111111111111',
            $legacyCategoryId,
            'es',
            'dummy',
            'Categoría ordinaria histórica',
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        ]);
        $this->pdo->prepare(
            'INSERT INTO ls_blog_post_categories '
                . '(public_id, post_id, category_id, '
                . 'assigned_by_user_public_id) SELECT ?, p.id, ?, ? FROM '
                . 'ls_blog_posts p WHERE p.public_id = ?'
        )->execute([
            '91111111-1111-4111-8111-111111111111',
            $legacyCategoryId,
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            $published->postPublicId(),
        ]);

        self::assertSame(
            200,
            $this->public->article('es', 'publicacion-normal')?->status()
        );
        $history = new PdoBlogUrlHistoryRepository(
            $this->pdo,
            $this->scope
        );
        self::assertSame(
            BlogUrlResolution::ACTIVE,
            $history->resolve('es', 'publicacion-normal')?->state()
        );
        self::assertTrue($history->isRedirectTargetEligible(
            $published->localizationPublicId()
        ));
    }

    public function testTemporaryUrlCanBeRepublishedWithoutChangingItsAddress(): void
    {
        $created = $this->service->createPost(
            $this->actorGate(),
            'es',
            $this->draft('republicable', 'Republicable')
        );
        $published = $this->service->publish(
            $this->actorGate(),
            $created->postPublicId(),
            'es',
            $created->lockVersion()
        );
        $draft = $this->service->unpublish(
            $this->actorGate(),
            $created->postPublicId(),
            'es',
            $published->lockVersion()
        );
        self::assertSame(404, $this->public->article('es', 'republicable')?->status());
        $this->service->publish(
            $this->actorGate(),
            $created->postPublicId(),
            'es',
            $draft->lockVersion()
        );
        self::assertSame(200, $this->public->article('es', 'republicable')?->status());
    }

    public function testExplicitRedirectCanTargetTheSameArticleAfterSlugChange(): void
    {
        $created = $this->service->createPost(
            $this->actorGate(),
            'es',
            $this->draft('slug-antiguo', 'Artículo')
        );
        $published = $this->service->publish(
            $this->actorGate(),
            $created->postPublicId(),
            'es',
            $created->lockVersion()
        );
        $draft = $this->service->unpublish(
            $this->actorGate(),
            $created->postPublicId(),
            'es',
            $published->lockVersion()
        );
        $saved = $this->service->saveDraft(
            $this->actorGate(),
            $created->postPublicId(),
            'es',
            $draft->lockVersion(),
            $this->draft('slug-nuevo', 'Artículo')
        );
        $republished = $this->service->publish(
            $this->actorGate(),
            $created->postPublicId(),
            'es',
            $saved->lockVersion()
        );
        $this->service->finalizeRetiredUrl(
            $this->actorGate(),
            $created->postPublicId(),
            'es',
            $republished->lockVersion(),
            'slug-antiguo',
            BlogUrlResolution::REDIRECT,
            $created->postPublicId()
        );
        $redirect = $this->public->article('es', 'slug-antiguo');
        self::assertSame(301, $redirect?->status());
        self::assertSame('/noticias/slug-nuevo', $redirect?->headers()['Location'] ?? null);
    }

    public function testMigrationShipsPortableMysqlContractWithoutApplyingIt(): void
    {
        $migration = $this->migration('0016_blog_url_history');
        self::assertTrue($migration->isRetrySafe());
        self::assertFalse($migration->isDestructive());
        $mysql = implode("\n", $migration->statementsFor('mysql', $this->scope));
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS', $mysql);
        self::assertStringContainsString("'temporary_not_found'", $mysql);
        self::assertStringContainsString("'redirect'", $mysql);
        self::assertStringContainsString('INSERT IGNORE', $mysql);
    }

    private function draft(string $slug, string $h1): BlogDraft
    {
        return new BlogDraft(
            $h1,
            'Contenido suficiente para publicar.',
            $slug,
            $h1 . ' | SEO',
            'Descripción SEO suficientemente explícita.',
            'Resumen editorial.'
        );
    }

    /** @return callable(PDO): string */
    private function actorGate(): callable
    {
        return static fn (PDO $pdo): string =>
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    }

    private function apply(string $id): void
    {
        foreach ($this->migration($id)->statementsFor('sqlite', $this->scope) as $sql) {
            $this->pdo->exec($sql);
        }
    }

    private function migration(string $id): MigrationDefinition
    {
        foreach (BlogMigrationProvider::migrations() as $migration) {
            if ($migration->id() === $id) {
                return $migration;
            }
        }

        self::fail('Missing migration ' . $id);
    }
}
