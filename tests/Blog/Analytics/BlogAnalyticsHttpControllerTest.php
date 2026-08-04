<?php

declare(strict_types=1);

namespace Tests\Blog\Analytics;

use App\Core\Blog\Analytics\BlogAnalyticsCollector;
use App\Core\Blog\Analytics\BlogAnalyticsHttpController;
use App\Core\Blog\Analytics\BlogAnalyticsHttpRuntime;
use App\Core\Blog\Analytics\BlogAnalyticsIngestionInterface;
use App\Core\Blog\Analytics\BlogAnalyticsPageGrantCodec;
use App\Core\Blog\Configuration\BlogAnalyticsConfig;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Http\Request;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BlogAnalyticsHttpIngestionFixture implements
    BlogAnalyticsIngestionInterface
{
    public int $viewCalls = 0;
    public int $engagementCalls = 0;

    public function recordView(
        string $localizationPublicId,
        string $viewPublicId,
        string $visitorHash,
        string $sessionHash,
        DateTimeImmutable $occurredAt
    ): bool {
        ++$this->viewCalls;
        return true;
    }

    public function recordEngagement(
        string $viewPublicId,
        string $sessionHash,
        int $sequence,
        int $engagementMilliseconds,
        DateTimeImmutable $occurredAt
    ): bool {
        ++$this->engagementCalls;
        return true;
    }
}

final class BlogAnalyticsHttpClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-04 12:00:00 UTC');
    }
}

final class BlogAnalyticsHttpControllerTest extends TestCase
{
    private BlogAnalyticsHttpIngestionFixture $ingestion;
    private BlogAnalyticsHttpController $controller;
    private string $pageGrant;

    protected function setUp(): void
    {
        $this->ingestion = new BlogAnalyticsHttpIngestionFixture();
        $environment = [
            'RAIZ' => 'https://example.test',
            'DEV_MODE' => '0',
        ];
        $origin = BlogPublicOrigin::fromEnvironment($environment);
        $config = new BlogConfig(
            ['es' => '/noticias'],
            '/blog-sitemap.xml',
            'ls_blog_',
            'test',
            analytics: new BlogAnalyticsConfig(true)
        );
        $key = SecurityKey::fromRawBytes(str_repeat('k', 32));
        $clock = new BlogAnalyticsHttpClock();
        $pageGrantCodec = new BlogAnalyticsPageGrantCodec(
            $key,
            $origin,
            $clock
        );
        $this->pageGrant = $pageGrantCodec->issue(
            '33333333-3333-4333-8333-333333333333',
            '/noticias/prueba'
        );
        $collector = new BlogAnalyticsCollector(
            $this->ingestion,
            $key,
            $clock
        );
        $this->controller = new BlogAnalyticsHttpController(
            new BlogAnalyticsHttpRuntime(
                $config->analytics(),
                $origin,
                $collector,
                $pageGrantCodec,
                'PROJECT_ADMIN_SESSION'
            ),
            $environment
        );
    }

    public function testAdminBrowserIsExcludedWithoutReadingItsValue(): void
    {
        $response = $this->controller->start($this->request([
            'cookie_analytics' => 'true',
            'LS_BLOG_AV' => '11111111-1111-4111-8111-111111111111',
            'LS_BLOG_AS' => '22222222-2222-4222-8222-222222222222',
            'PROJECT_ADMIN_SESSION' => 'sensitive-session-value',
        ]));

        self::assertSame(204, $response->status());
        self::assertSame(0, $this->ingestion->viewCalls);
        self::assertStringNotContainsString(
            'sensitive-session-value',
            $response->body()
        );
    }

    public function testMissingConsentIsAWriteFreeNoOp(): void
    {
        $response = $this->controller->start($this->request([
            'LS_BLOG_AV' => '11111111-1111-4111-8111-111111111111',
            'LS_BLOG_AS' => '22222222-2222-4222-8222-222222222222',
        ]));

        self::assertSame(204, $response->status());
        self::assertSame(0, $this->ingestion->viewCalls);
    }

    public function testCrossOriginAndNonJsonRequestsFailBeforeCollection(): void
    {
        $request = Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/_liquidstack/blog-analytics/start',
                'HTTPS' => 'on',
                'REMOTE_ADDR' => '192.0.2.55',
                'HTTP_HOST' => 'example.test',
            ],
            [],
            [],
            ['cookie_analytics' => 'true'],
            [
                'Origin' => 'https://attacker.test',
                'Content-Type' => 'application/json',
            ],
            (string) json_encode(['page_grant' => $this->pageGrant])
        );

        self::assertSame(400, $this->controller->start($request)->status());
        self::assertSame(0, $this->ingestion->viewCalls);
    }

    /** @param array<string, string> $cookies */
    private function request(array $cookies): Request
    {
        return Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/_liquidstack/blog-analytics/start',
                'HTTPS' => 'on',
                'REMOTE_ADDR' => '192.0.2.55',
                'HTTP_HOST' => 'example.test',
            ],
            [],
            [],
            $cookies,
            [
                'Origin' => 'https://example.test',
                'Content-Type' => 'application/json',
                'Sec-Fetch-Site' => 'same-origin',
                'User-Agent' => 'must-not-be-persisted',
            ],
            (string) json_encode(['page_grant' => $this->pageGrant])
        );
    }
}
