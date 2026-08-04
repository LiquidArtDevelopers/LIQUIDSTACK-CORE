<?php

declare(strict_types=1);

namespace App\Core\Blog\Analytics;

use App\Core\Blog\BlogInput;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\SystemClock;
use Throwable;

final class BlogAnalyticsCollector
{
    public function __construct(
        private readonly BlogAnalyticsIngestionInterface $ingestion,
        private readonly SecurityKey $securityKey,
        private readonly ClockInterface $clock = new SystemClock()
    ) {
    }

    public function start(
        BlogAnalyticsPageGrant $pageGrant,
        string $visitorToken,
        string $sessionToken
    ): bool {
        try {
            BlogInput::generatedPublicId($visitorToken);
            BlogInput::generatedPublicId($sessionToken);
            return $this->ingestion->recordView(
                $pageGrant->localizationPublicId(),
                $pageGrant->viewPublicId(),
                $this->securityKey->subjectHash(
                    'blog.analytics.visitor',
                    $visitorToken
                ),
                $this->securityKey->subjectHash(
                    'blog.analytics.session',
                    $sessionToken
                ),
                $this->clock->now()
            );
        } catch (Throwable) {
            return false;
        }
    }

    public function engage(
        BlogAnalyticsPageGrant $pageGrant,
        string $sessionToken,
        int $sequence,
        int $engagementMilliseconds
    ): bool {
        try {
            BlogInput::generatedPublicId($sessionToken);
            return $this->ingestion->recordEngagement(
                $pageGrant->viewPublicId(),
                $this->securityKey->subjectHash(
                    'blog.analytics.session',
                    $sessionToken
                ),
                $sequence,
                $engagementMilliseconds,
                $this->clock->now()
            );
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'ingestion' => '[redacted]',
            'security_key' => '[redacted]',
        ];
    }
}
