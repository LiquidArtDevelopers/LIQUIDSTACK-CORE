<?php

declare(strict_types=1);

namespace Tests\Blog\QaFixtures;

use App\Core\Blog\Audit\BlogMutationAuditEvent;
use App\Core\Blog\Audit\BlogMutationAuditPortInterface;
use App\Core\Blog\Audit\WebAdminBlogMutationAuditAdapter;
use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Http\BlogPublicHttpController;
use App\Core\Blog\Http\BlogPublicHttpRuntime;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\PublicFeed\BlogPublicCatalogQuery;
use App\Core\Blog\PublicFeed\PdoBlogPublicCatalogRepository;
use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureCatalog;
use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureException;
use App\Core\Blog\QaFixtures\PdoBlogQaMatrixFixturePersistence;
use App\Core\Blog\QaFixtures\PdoBlogQaMatrixFixtureReadPort;
use App\Core\Blog\Seo\BlogUrlResolution;
use App\Core\Blog\Seo\PdoBlogUrlHistoryRepository;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Support\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FailingBlogQaMatrixAudit implements BlogMutationAuditPortInterface
{
    public int $calls = 0;

    public function __construct(private readonly string $failingPostPublicId)
    {
    }

    public function record(PDO $pdo, BlogMutationAuditEvent $event): void
    {
        ++$this->calls;
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Audit must be transactional.');
        }
        if ($event->postPublicId() === $this->failingPostPublicId) {
            throw new RuntimeException('Deliberate QA audit failure.');
        }
    }
}

final class PdoBlogQaMatrixFixturePersistenceTest extends TestCase
{
    private const MEDIA = '11111111-1111-4111-8111-111111111111';
    private const ACTOR = '22222222-2222-4222-8222-222222222222';

    private PDO $pdo;
    private MigrationScope $blogScope;
    private WebAdminTableNames $webAdminTables;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->blogScope = MigrationScope::forTablePrefix(
            'blog',
            'ls_blog_'
        );
        $webAdminScope = MigrationScope::forTablePrefix(
            'webadmin',
            'ls_webadmin_'
        );
        $this->applyWebAdminMigrations($webAdminScope, [
            '0001_webadmin_identity_and_access',
        ]);
        $this->applyBlogMigrations([
            '0001_blog_posts',
            '0003_blog_categories',
            '0005_blog_structured_content',
            '0011_blog_layout_editor_v2',
            '0014_blog_private_draft_publication',
            '0015_blog_robots_preferences',
            '0016_blog_url_history',
            '0017_blog_dummy_category',
        ]);
        $this->webAdminTables = WebAdminTableNames::fromPdo(
            $this->pdo,
            'ls_webadmin_'
        );
        $this->pdo->prepare(
            'INSERT INTO ls_webadmin_users '
                . '(public_id, email_canonical, display_name, status, '
                . 'activated_at, created_at, updated_at) VALUES '
                . '(:public_id, :email, :display_name, :status, '
                . ':activated_at, :created_at, :updated_at)'
        )->execute([
            'public_id' => self::ACTOR,
            'email' => 'qa-matrix@example.test',
            'display_name' => 'Matrix QA',
            'status' => 'active',
            'activated_at' => '2030-01-01 00:00:00.000000',
            'created_at' => '2030-01-01 00:00:00.000000',
            'updated_at' => '2030-01-01 00:00:00.000000',
        ]);
    }

    public function testDryRunApplyAndRerunAreSafeAndIdempotent(): void
    {
        $articles = (new BlogQaMatrixFixtureCatalog())->articles(self::MEDIA);
        $persistence = $this->productionPersistence();

        $dryRun = $persistence->run(
            $articles,
            BlogQaMatrixFixtureCatalog::LOCALES,
            self::ACTOR,
            false
        );
        self::assertSame(30, $dryRun->pendingVariantCount());
        self::assertSame(0, $this->rowCount('ls_blog_posts'));
        self::assertSame(0, $this->rowCount('ls_webadmin_audit_log'));

        $applied = $persistence->run(
            $articles,
            BlogQaMatrixFixtureCatalog::LOCALES,
            self::ACTOR,
            true
        );
        self::assertTrue($applied->applied());
        self::assertSame(30, $applied->pendingVariantCount());
        self::assertSame(10, $this->rowCount('ls_blog_posts'));
        self::assertSame(30, $this->rowCount('ls_blog_post_localizations'));
        self::assertSame(30, $this->rowCount('ls_blog_content_docs'));
        self::assertSame(30, $this->rowCount('ls_blog_content_revisions'));
        self::assertSame(30, $this->rowCount('ls_blog_content_layout_docs'));
        self::assertSame(30, $this->rowCount(
            'ls_blog_content_layout_revisions'
        ));
        self::assertSame(30, $this->rowCount('ls_blog_content_media'));
        self::assertSame(30, $this->rowCount('ls_blog_revision_media'));
        self::assertSame(30, $this->rowCount('ls_blog_publication_heads'));
        self::assertSame(30, $this->rowCount('ls_blog_url_history'));
        self::assertSame(10, $this->rowCount('ls_blog_post_categories'));
        self::assertSame(20, $this->rowCount('ls_webadmin_audit_log'));
        self::assertSame(30, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_robots_settings '
                . 'WHERE allow_index = 0 AND allow_follow = 0'
        )->fetchColumn());
        self::assertSame(30, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_revision_robots '
                . 'WHERE allow_index = 0 AND allow_follow = 0'
        )->fetchColumn());

        $rerun = $persistence->run(
            $articles,
            BlogQaMatrixFixtureCatalog::LOCALES,
            self::ACTOR,
            true
        );
        self::assertSame(0, $rerun->pendingVariantCount());
        self::assertSame(30, $rerun->existingVariantCount());
        self::assertSame(20, $this->rowCount('ls_webadmin_audit_log'));

        $ordinary = new PdoBlogRepository(
            $this->pdo,
            $this->blogScope,
            robotsSettingsEnabled: true,
            reservedCategoryPolicyEnabled: true
        );
        self::assertSame([], $ordinary->listPublishedCards('es', 100, 0));
        self::assertSame([], $ordinary->sitemapEntries(500));
        self::assertNull($ordinary->publishedVariant('es', 'qa-matrix-01'));
        self::assertSame([], (new PdoBlogPublicCatalogRepository(
            $this->pdo,
            $this->blogScope
        ))->search(new BlogPublicCatalogQuery('es', limit: 50)));

        $history = new PdoBlogUrlHistoryRepository(
            $this->pdo,
            $this->blogScope
        );
        self::assertSame(
            BlogUrlResolution::ACTIVE,
            $history->resolve('es', 'qa-matrix-01')?->state()
        );
        $public = new BlogPublicHttpController(new BlogPublicHttpRuntime(
            new BlogConfig(
                ['es' => '/es/noticias'],
                '/blog-sitemap.xml',
                'ls_blog_',
                'fixture'
            ),
            BlogPublicOrigin::fromEnvironment([
                BlogPublicOrigin::ENV => 'https://example.test',
            ]),
            new BlogService($ordinary),
            urlHistory: $history
        ));
        $direct = $public->article('es', 'qa-matrix-01');
        self::assertSame(404, $direct?->status());
        self::assertSame('', $direct?->body());
        self::assertSame(
            'noindex, nofollow',
            $direct?->headers()['X-Robots-Tag'] ?? null
        );

        $qaViews = (new PdoBlogQaMatrixFixtureReadPort(
            $this->pdo,
            $this->blogScope,
            ['DEV_MODE' => '1', 'RAIZ' => 'http://localhost']
        ))->publishedViews(
            $articles,
            BlogQaMatrixFixtureCatalog::LOCALES
        );
        self::assertCount(30, $qaViews);
        self::assertSame('es', $qaViews[0]->locale());
        self::assertSame(
            2,
            $qaViews[0]->publishedSnapshot()->document()->version()
        );
    }

    public function testLocaleSubsetAddsVariantsToTheSameTenAggregates(): void
    {
        $articles = (new BlogQaMatrixFixtureCatalog())->articles(self::MEDIA);
        $persistence = $this->productionPersistence();

        $first = $persistence->run($articles, ['es'], self::ACTOR, true);
        self::assertSame(10, $first->pendingVariantCount());
        self::assertSame(10, $this->rowCount('ls_blog_posts'));
        self::assertSame(10, $this->rowCount('ls_blog_post_localizations'));

        $second = $persistence->run(
            $articles,
            ['en', 'eu'],
            self::ACTOR,
            true
        );
        self::assertSame(20, $second->pendingVariantCount());
        self::assertSame(10, $this->rowCount('ls_blog_posts'));
        self::assertSame(30, $this->rowCount('ls_blog_post_localizations'));
        self::assertSame(10, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ls_blog_post_localizations WHERE locale = 'eu'"
        )->fetchColumn());
    }

    public function testLegacyDummyDoesNotShadowCanonicalFixtureCategory(): void
    {
        $this->pdo->prepare(
            'INSERT INTO ls_blog_categories '
                . '(public_id, created_by_user_public_id) VALUES (?, ?)'
        )->execute([
            '51111111-1111-4111-8111-111111111111',
            self::ACTOR,
        ]);
        $legacyId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO ls_blog_category_locales '
                . '(public_id, category_id, locale, slug, name, '
                . 'created_by_user_public_id, updated_by_user_public_id) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            '61111111-1111-4111-8111-111111111111',
            $legacyId,
            'es',
            'dummy',
            'Dummy legacy',
            self::ACTOR,
            self::ACTOR,
        ]);

        $result = $this->productionPersistence()->run(
            (new BlogQaMatrixFixtureCatalog())->articles(self::MEDIA),
            ['es'],
            self::ACTOR,
            false
        );

        self::assertSame(10, $result->pendingVariantCount());
        self::assertSame(0, $this->rowCount('ls_blog_posts'));
    }

    public function testAuditFailureRollsBackTheCurrentAggregateAndStops(): void
    {
        $articles = (new BlogQaMatrixFixtureCatalog())->articles(self::MEDIA);
        $audit = new FailingBlogQaMatrixAudit($articles[1]->postPublicId());
        $persistence = $this->persistence($audit);

        try {
            $persistence->run($articles, ['es'], self::ACTOR, true);
            self::fail('A failing aggregate audit was accepted.');
        } catch (BlogQaMatrixFixtureException $exception) {
            self::assertSame(
                'blog.qa_fixture.aggregate_apply_failed',
                $exception->issueCode()
            );
        }
        self::assertSame(1, $this->rowCount('ls_blog_posts'));
        self::assertSame(1, $this->rowCount('ls_blog_post_localizations'));
        self::assertSame(1, $this->rowCount('ls_blog_content_revisions'));
        self::assertSame(3, $audit->calls);
    }

    public function testSnapshotDriftFailsPreflightBeforeAddingAnyLocale(): void
    {
        $articles = (new BlogQaMatrixFixtureCatalog())->articles(self::MEDIA);
        $persistence = $this->productionPersistence();
        $persistence->run($articles, ['es'], self::ACTOR, true);
        $this->pdo->exec(
            "UPDATE ls_blog_post_localizations SET h1 = 'manual drift' "
                . "WHERE locale = 'es' AND id = (SELECT MIN(id) FROM "
                . 'ls_blog_post_localizations)'
        );

        try {
            $persistence->run($articles, ['en'], self::ACTOR, true);
            self::fail('Fixture drift was overwritten.');
        } catch (BlogQaMatrixFixtureException $exception) {
            self::assertStringStartsWith(
                'blog.qa_fixture.',
                $exception->issueCode()
            );
        }
        self::assertSame(10, $this->rowCount('ls_blog_post_localizations'));
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ls_blog_post_localizations WHERE locale = 'en'"
        )->fetchColumn());
    }

    public function testQaReadPortFailsClosedOutsideExactLoopbackDevMode(): void
    {
        foreach ([
            ['DEV_MODE' => '0', 'RAIZ' => 'https://example.test'],
            ['DEV_MODE' => 'true', 'RAIZ' => 'http://localhost'],
            ['DEV_MODE' => '1', 'RAIZ' => 'https://example.test'],
        ] as $environment) {
            try {
                new PdoBlogQaMatrixFixtureReadPort(
                    $this->pdo,
                    $this->blogScope,
                    $environment
                );
                self::fail('QA fixture reads escaped the development gate.');
            } catch (BlogQaMatrixFixtureException $exception) {
                self::assertSame(
                    'blog.qa_fixture.read_dev_mode_required',
                    $exception->issueCode()
                );
            }
        }
    }

    private function productionPersistence(): PdoBlogQaMatrixFixturePersistence
    {
        return $this->persistence(new WebAdminBlogMutationAuditAdapter(
            $this->pdo,
            $this->webAdminTables
        ));
    }

    private function persistence(
        BlogMutationAuditPortInterface $audit
    ): PdoBlogQaMatrixFixturePersistence {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable(
                    '2030-01-01T12:00:00.000000Z',
                    new DateTimeZone('UTC')
                );
            }
        };

        return new PdoBlogQaMatrixFixturePersistence(
            $this->pdo,
            $this->blogScope,
            $this->webAdminTables,
            $audit,
            $clock
        );
    }

    /** @param list<string> $ids */
    private function applyBlogMigrations(array $ids): void
    {
        $pending = array_fill_keys($ids, true);
        foreach (BlogMigrationProvider::migrations() as $migration) {
            if (!isset($pending[$migration->id()])) {
                continue;
            }
            foreach (
                $migration->statementsFor('sqlite', $this->blogScope)
                as $sql
            ) {
                self::assertNotFalse($this->pdo->exec($sql));
            }
            unset($pending[$migration->id()]);
        }
        self::assertSame([], array_keys($pending));
    }

    /** @param list<string> $ids */
    private function applyWebAdminMigrations(
        MigrationScope $scope,
        array $ids
    ): void {
        $pending = array_fill_keys($ids, true);
        foreach (WebAdminMigrationProvider::migrations() as $migration) {
            if (!isset($pending[$migration->id()])) {
                continue;
            }
            foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
                self::assertNotFalse($this->pdo->exec($sql));
            }
            unset($pending[$migration->id()]);
        }
        self::assertSame([], array_keys($pending));
    }

    private function rowCount(string $table): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ' . $table
        )->fetchColumn();
    }
}
