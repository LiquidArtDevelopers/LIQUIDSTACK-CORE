<?php

declare(strict_types=1);

namespace App\Core\Blog\Analytics;

use App\Core\Http\PrivateRouteTransportPolicy;
use App\Core\Http\Request;
use App\Core\Http\Response;
use Throwable;

final class BlogAnalyticsHttpController
{
    public const VISITOR_COOKIE = 'LS_BLOG_AV';
    public const SESSION_COOKIE = 'LS_BLOG_AS';
    public const CONSENT_COOKIE = 'cookie_analytics';

    /** @param array<string, mixed> $environment */
    public function __construct(
        private readonly BlogAnalyticsHttpRuntime $runtime,
        #[\SensitiveParameter] private readonly array $environment,
        private readonly BlogAnalyticsRequestPolicy $requestPolicy =
            new BlogAnalyticsRequestPolicy(),
        private readonly PrivateRouteTransportPolicy $transportPolicy =
            new PrivateRouteTransportPolicy()
    ) {
    }

    public function start(Request $request): Response
    {
        if (!$this->accepts($request)) {
            return $this->response(400);
        }
        if ($this->isWebAdminBrowser($request)) {
            return $this->response(204);
        }
        if (!$this->hasConsent($request)) {
            return $this->response(204);
        }
        $payload = $this->requestPolicy->startPayload($request);
        if ($payload === null) {
            return $this->response(400);
        }
        $pageGrant = $this->runtime->pageGrantCodec()->verify(
            $payload['page_grant']
        );
        if ($pageGrant === null) {
            return $this->response(400);
        }
        $visitor = $request->cookie(self::VISITOR_COOKIE);
        $session = $request->cookie(self::SESSION_COOKIE);
        if (!is_string($visitor) || !is_string($session)) {
            return $this->response(204);
        }
        try {
            $this->runtime->collector()->start(
                $pageGrant,
                $visitor,
                $session
            );
        } catch (Throwable) {
            // Collection is best effort and never changes public navigation.
        }

        return $this->response(204);
    }

    public function engagement(Request $request): Response
    {
        if (!$this->accepts($request)) {
            return $this->response(400);
        }
        if ($this->isWebAdminBrowser($request)) {
            return $this->response(204);
        }
        if (!$this->hasConsent($request)) {
            return $this->response(204);
        }
        $payload = $this->requestPolicy->engagementPayload($request);
        if ($payload === null) {
            return $this->response(400);
        }
        $pageGrant = $this->runtime->pageGrantCodec()->verify(
            $payload['page_grant']
        );
        if ($pageGrant === null) {
            return $this->response(400);
        }
        $session = $request->cookie(self::SESSION_COOKIE);
        if (!is_string($session)) {
            return $this->response(204);
        }
        try {
            $this->runtime->collector()->engage(
                $pageGrant,
                $session,
                $payload['sequence'],
                $payload['engagement_msec']
            );
        } catch (Throwable) {
            // Collection is best effort and never changes public navigation.
        }

        return $this->response(204);
    }

    private function accepts(Request $request): bool
    {
        return $this->transportPolicy->accepts($request, $this->environment)
            && $this->requestPolicy->acceptsJsonPost(
                $request,
                $this->runtime->origin()->value()
            );
    }

    private function hasConsent(Request $request): bool
    {
        return $request->cookie(self::CONSENT_COOKIE) === 'true';
    }

    private function isWebAdminBrowser(Request $request): bool
    {
        return $request->cookie(
            $this->runtime->webAdminCookieName()
        ) !== null;
    }

    private function response(int $status): Response
    {
        return new Response($status, '', [
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' =>
                "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ]);
    }

}
