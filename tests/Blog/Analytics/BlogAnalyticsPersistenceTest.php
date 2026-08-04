<?php

declare(strict_types=1);

namespace Tests\Blog\Analytics;

use App\Core\Blog\Analytics\BlogAnalyticsCollector;
use App\Core\Blog\Analytics\BlogAnalyticsHttpController;
use App\Core\Blog\Analytics\BlogAnalyticsHttpRuntime;
use App\Core\Blog\Analytics\BlogAnalyticsPageGrantCodec;
use App\Core\Blog\Analytics\PdoBlogAnalyticsRepository;
use App\Core\Blog\Configuration\BlogAnalyticsConfig;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Http\Request;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use App\Core\WebAdmin\Security\SecurityKey;

final class BlogAnalyticsPersistenceTest extends TestCase
{
    private PDO $pdo;
    private MigrationScopeCollection $scopes;
    private MigrationScope $blogScope;
    private string $localizationPublicId =
        '33333333-3333-4333-8333-333333333333';

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite no disponible.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->scopes = MigrationScopeCollection::fromTablePrefixes([
            'webadmin' => 'ls_webadmin_',
            'blog' => 'ls_blog_',
        ]);
        $this->blogScope = $this->scopes->get('blog')
            ?? throw new \RuntimeException('Missing Blog scope.');
        foreach (WebAdminMigrationProvider::migrations() as $migration) {
            $scope = $migration->targetScope('webadmin', $this->scopes)
                ?? throw new \RuntimeException('Missing WebAdmin scope.');
            $this->apply($migration, $scope);
        }
        foreach (BlogMigrationProvider::migrations() as $migration) {
            $scope = $migration->targetScope('blog', $this->scopes)
                ?? throw new \RuntimeException('Missing migration scope.');
            $this->apply($migration, $scope);
            self::assertTrue(
                $migration->postconditionVerifier()?->verify(
                    $this->pdo,
                    $scope
                )
            );
        }
        $this->insertPublishedLocalization();
    }

    public function testCollectionIsIdempotentAndReportsConsentSafeMetrics(): void
    {
        $repository = new PdoBlogAnalyticsRepository(
            $this->pdo,
            $this->blogScope
        );
        $time = new DateTimeImmutable('2026-08-04 12:00:00 UTC');
        $view = '44444444-4444-4444-8444-444444444444';
        $visitor = str_repeat('a', 64);
        $session = str_repeat('b', 64);

        self::assertTrue($repository->recordView(
            $this->localizationPublicId,
            $view,
            $visitor,
            $session,
            $time
        ));
        self::assertFalse($repository->recordView(
            $this->localizationPublicId,
            $view,
            $visitor,
            $session,
            $time
        ));
        self::assertTrue($repository->recordEngagement(
            $view,
            $session,
            1,
            11_000,
            $time->modify('+12 seconds')
        ));
        self::assertFalse($repository->recordEngagement(
            $view,
            $session,
            1,
            20_000,
            $time->modify('+20 seconds')
        ));

        $summary = $repository->summariesForLocalizations(
            [$this->localizationPublicId],
            $time->modify('-1 day'),
            $time->modify('+1 day')
        )[$this->localizationPublicId];

        self::assertSame(1, $summary->pageViews());
        self::assertSame(1, $summary->uniqueVisitors());
        self::assertSame(0, $summary->returningVisitors());
        self::assertSame(11_000, $summary->averageEngagementMilliseconds());
        self::assertSame(1, $summary->landingSessions());
        self::assertSame(1, $summary->engagedLandingSessions());
        self::assertSame(0.0, $summary->bounceRatePercentage());

        $columns = $this->pdo->query(
            'PRAGMA table_info("ls_blog_analytics_sessions")'
        )->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($columns, 'name');
        self::assertNotContains('ip', $names);
        self::assertNotContains('user_agent', $names);
        self::assertNotContains('referrer', $names);
    }

    public function testRetentionPurgeDeletesOnlyExpiredSessionsAndViews(): void
    {
        $repository = new PdoBlogAnalyticsRepository(
            $this->pdo,
            $this->blogScope
        );
        $old = new DateTimeImmutable('2026-01-01 00:00:00 UTC');
        $recent = new DateTimeImmutable('2026-08-01 00:00:00 UTC');
        self::assertTrue($repository->recordView(
            $this->localizationPublicId,
            '44444444-4444-4444-8444-444444444444',
            str_repeat('a', 64),
            str_repeat('b', 64),
            $old
        ));
        self::assertTrue($repository->recordView(
            $this->localizationPublicId,
            '55555555-5555-4555-8555-555555555555',
            str_repeat('c', 64),
            str_repeat('d', 64),
            $recent
        ));

        $result = $repository->purgeBefore(
            new DateTimeImmutable('2026-05-01 00:00:00 UTC')
        );

        self::assertSame(1, $result->deletedSessions());
        self::assertSame(1, $result->deletedViews());
        self::assertSame(
            1,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ls_blog_analytics_sessions'
            )->fetchColumn()
        );
        self::assertSame(
            1,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ls_blog_analytics_views'
            )->fetchColumn()
        );
    }

    public function testBouncePeriodUsesSessionStartInsteadOfALaterView(): void
    {
        $repository = new PdoBlogAnalyticsRepository(
            $this->pdo,
            $this->blogScope
        );
        $beforePeriod = new DateTimeImmutable('2026-07-01 12:00:00 UTC');
        $insidePeriod = new DateTimeImmutable('2026-08-02 12:00:00 UTC');
        $visitor = str_repeat('a', 64);
        $session = str_repeat('b', 64);

        self::assertTrue($repository->recordView(
            $this->localizationPublicId,
            '44444444-4444-4444-8444-444444444444',
            $visitor,
            $session,
            $beforePeriod
        ));
        self::assertTrue($repository->recordView(
            $this->localizationPublicId,
            '55555555-5555-4555-8555-555555555555',
            $visitor,
            $session,
            $insidePeriod
        ));

        $summary = $repository->summariesForLocalizations(
            [$this->localizationPublicId],
            new DateTimeImmutable('2026-08-01 00:00:00 UTC'),
            new DateTimeImmutable('2026-08-04 00:00:00 UTC')
        )[$this->localizationPublicId];

        self::assertSame(1, $summary->pageViews());
        self::assertSame(1, $summary->uniqueVisitors());
        self::assertSame(0, $summary->landingSessions());
        self::assertSame(0, $summary->engagedLandingSessions());
    }

    public function testRetentionPurgesAnOldViewFromARecentSession(): void
    {
        $repository = new PdoBlogAnalyticsRepository(
            $this->pdo,
            $this->blogScope
        );
        $visitor = str_repeat('a', 64);
        $session = str_repeat('b', 64);
        self::assertTrue($repository->recordView(
            $this->localizationPublicId,
            '44444444-4444-4444-8444-444444444444',
            $visitor,
            $session,
            new DateTimeImmutable('2026-01-01 00:00:00 UTC')
        ));
        self::assertTrue($repository->recordView(
            $this->localizationPublicId,
            '55555555-5555-4555-8555-555555555555',
            $visitor,
            $session,
            new DateTimeImmutable('2026-08-01 00:00:00 UTC')
        ));

        $result = $repository->purgeBefore(
            new DateTimeImmutable('2026-05-01 00:00:00 UTC')
        );

        self::assertSame(0, $result->deletedSessions());
        self::assertSame(1, $result->deletedViews());
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_analytics_sessions'
        )->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_analytics_views'
        )->fetchColumn());
    }

    public function testConsentEndpointCollectsPublishedRouteEndToEnd(): void
    {
        $repository = new PdoBlogAnalyticsRepository(
            $this->pdo,
            $this->blogScope
        );
        $config = new BlogConfig(
            ['es' => '/noticias'],
            '/blog-sitemap.xml',
            'ls_blog_',
            'test',
            analytics: new BlogAnalyticsConfig(true)
        );
        $environment = [
            'RAIZ' => 'https://example.test',
            'DEV_MODE' => '0',
        ];
        $securityKey = SecurityKey::fromRawBytes(str_repeat('s', 32));
        $pageGrantCodec = new BlogAnalyticsPageGrantCodec(
            $securityKey,
            BlogPublicOrigin::fromEnvironment($environment)
        );
        $pageGrant = $pageGrantCodec->issue(
            $this->localizationPublicId,
            '/noticias/prueba'
        );
        $controller = new BlogAnalyticsHttpController(
            new BlogAnalyticsHttpRuntime(
                $config->analytics(),
                BlogPublicOrigin::fromEnvironment($environment),
                new BlogAnalyticsCollector(
                    $repository,
                    $securityKey
                ),
                $pageGrantCodec,
                'LS_WEBADMIN_SID'
            ),
            $environment
        );
        $response = $controller->start(Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/_liquidstack/blog-analytics/start',
                'HTTPS' => 'on',
                'REMOTE_ADDR' => '198.51.100.20',
                'HTTP_HOST' => 'example.test',
            ],
            [],
            [],
            [
                'cookie_analytics' => 'true',
                'LS_BLOG_AV' => '66666666-6666-4666-8666-666666666666',
                'LS_BLOG_AS' => '77777777-7777-4777-8777-777777777777',
            ],
            [
                'Origin' => 'https://example.test',
                'Content-Type' => 'application/json',
                'Sec-Fetch-Site' => 'same-origin',
            ],
            (string) json_encode(['page_grant' => $pageGrant])
        ));

        self::assertSame(204, $response->status());
        $replay = $controller->start(Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/_liquidstack/blog-analytics/start',
                'HTTPS' => 'on',
                'REMOTE_ADDR' => '198.51.100.21',
                'HTTP_HOST' => 'example.test',
            ],
            [],
            [],
            [
                'cookie_analytics' => 'true',
                'LS_BLOG_AV' => '99999999-9999-4999-8999-999999999999',
                'LS_BLOG_AS' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            ],
            [
                'Origin' => 'https://example.test',
                'Content-Type' => 'application/json',
                'Sec-Fetch-Site' => 'same-origin',
            ],
            (string) json_encode(['page_grant' => $pageGrant])
        ));
        self::assertSame(204, $replay->status());
        self::assertSame(
            1,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ls_blog_analytics_views'
            )->fetchColumn()
        );
    }

    private function apply(
        MigrationDefinition $migration,
        MigrationScope $scope
    ): void {
        foreach ($migration->statementsFor('sqlite', $scope) as $statement) {
            $this->pdo->exec($statement);
        }
    }

    private function insertPublishedLocalization(): void
    {
        $actor = '22222222-2222-4222-8222-222222222222';
        $post = '11111111-1111-4111-8111-111111111111';
        $timestamp = '2026-08-04 12:00:00.000000';
        $query = $this->pdo->prepare(
            'INSERT INTO ls_blog_posts (public_id, '
                . 'created_by_user_public_id, created_at, updated_at) '
                . 'VALUES (:public_id, :actor, :created_at, :updated_at)'
        );
        $query->execute([
            'public_id' => $post,
            'actor' => $actor,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $postId = (int) $this->pdo->lastInsertId();
        $query = $this->pdo->prepare(
            'INSERT INTO ls_blog_post_localizations (public_id, post_id, '
                . 'locale, slug, h1, seo_title, meta_description, excerpt, '
                . 'body_text, status, published_at, lock_version, '
                . 'created_by_user_public_id, updated_by_user_public_id, '
                . 'created_at, updated_at) VALUES (:public_id, :post_id, '
                . "'es', 'prueba', 'Prueba', 'SEO prueba', "
                . "'Descripcion valida para la prueba de analitica', "
                . "'Extracto', 'Cuerpo', 'published', :published_at, 1, "
                . ':actor, :actor, :created_at, :updated_at)'
        );
        $query->execute([
            'public_id' => $this->localizationPublicId,
            'post_id' => $postId,
            'published_at' => $timestamp,
            'actor' => $actor,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}
