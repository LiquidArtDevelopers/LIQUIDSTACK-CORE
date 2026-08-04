<?php

declare(strict_types=1);

namespace Tests\Blog\Analytics;

use App\Core\Blog\Analytics\BlogAnalyticsPageGrantCodec;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BlogAnalyticsGrantClock implements ClockInterface
{
    public function __construct(public DateTimeImmutable $value)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

final class BlogAnalyticsPageGrantCodecTest extends TestCase
{
    private SecurityKey $key;
    private BlogPublicOrigin $origin;
    private BlogAnalyticsGrantClock $clock;
    private BlogAnalyticsPageGrantCodec $codec;

    protected function setUp(): void
    {
        $this->key = SecurityKey::fromRawBytes(str_repeat('g', 32));
        $this->origin = BlogPublicOrigin::fromEnvironment([
            'RAIZ' => 'https://example.test',
            'DEV_MODE' => '0',
        ]);
        $this->clock = new BlogAnalyticsGrantClock(
            new DateTimeImmutable('2026-08-04 12:00:00 UTC')
        );
        $this->codec = new BlogAnalyticsPageGrantCodec(
            $this->key,
            $this->origin,
            $this->clock
        );
    }

    public function testRoundTripBindsOneServerViewToLocalizationAndPath(): void
    {
        $token = $this->codec->issue(
            '33333333-3333-4333-8333-333333333333',
            '/noticias/matrix'
        );
        $grant = $this->codec->verify($token);

        self::assertNotNull($grant);
        self::assertSame(
            '33333333-3333-4333-8333-333333333333',
            $grant->localizationPublicId()
        );
        self::assertSame('/noticias/matrix', $grant->canonicalPath());
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9-]{36}\z/D',
            $grant->viewPublicId()
        );
        self::assertSame(86_400, $grant->expiresAt()->getTimestamp()
            - $grant->issuedAt()->getTimestamp());
        self::assertLessThanOrEqual(
            BlogAnalyticsPageGrantCodec::MAX_TOKEN_BYTES,
            strlen($token)
        );
    }

    public function testTamperForeignKeyAndForeignOriginFailClosed(): void
    {
        $token = $this->codec->issue(
            '33333333-3333-4333-8333-333333333333',
            '/noticias/matrix'
        );
        $tampered = substr($token, 0, -1)
            . (str_ends_with($token, 'a') ? 'b' : 'a');
        self::assertNull($this->codec->verify($tampered));

        $foreignKey = new BlogAnalyticsPageGrantCodec(
            SecurityKey::fromRawBytes(str_repeat('x', 32)),
            $this->origin,
            $this->clock
        );
        self::assertNull($foreignKey->verify($token));

        $foreignOrigin = new BlogAnalyticsPageGrantCodec(
            $this->key,
            BlogPublicOrigin::fromEnvironment([
                'RAIZ' => 'https://other.test',
                'DEV_MODE' => '0',
            ]),
            $this->clock
        );
        self::assertNull($foreignOrigin->verify($token));
    }

    public function testExpiryAndFutureIssueTimeFailClosed(): void
    {
        $token = $this->codec->issue(
            '33333333-3333-4333-8333-333333333333',
            '/noticias/matrix'
        );
        $this->clock->value = $this->clock->value->modify('+24 hours');
        self::assertNull($this->codec->verify($token));

        $futureClock = new BlogAnalyticsGrantClock(
            new DateTimeImmutable('2026-08-04 12:02:00 UTC')
        );
        $futureToken = (new BlogAnalyticsPageGrantCodec(
            $this->key,
            $this->origin,
            $futureClock
        ))->issue(
            '33333333-3333-4333-8333-333333333333',
            '/noticias/matrix'
        );
        $this->clock->value = new DateTimeImmutable(
            '2026-08-04 12:00:00 UTC'
        );
        self::assertNull($this->codec->verify($futureToken));
        self::assertNull($this->codec->verify(str_repeat('a', 1025)));
    }
}
