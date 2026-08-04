<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Blog\Analytics\BlogAnalyticsHttpController;
use App\Core\Blog\Analytics\BlogAnalyticsHttpRuntimeFactory;
use App\Core\Blog\Analytics\BlogAnalyticsHttpRuntimeFactoryInterface;
use App\Core\Blog\Analytics\BlogAnalyticsPageGrantCodec;
use App\Core\Blog\Analytics\BlogAnalyticsRequestPolicy;
use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Environment\ProjectRuntimeProfile;
use App\Core\Http\PrivateRouteTransportPolicy;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\ModuleRouteProviderInterface;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Routing\ModuleRouteCollection;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Security\SecurityKey;
use Throwable;

final class BlogAnalyticsRouteProvider implements ModuleRouteProviderInterface
{
    public const PREFIX = '/_liquidstack/blog-analytics';
    public const START_PATH = self::PREFIX . '/start';
    public const ENGAGEMENT_PATH = self::PREFIX . '/engagement';
    public const REVOKE_PATH = self::PREFIX . '/revoke';

    private ?ModuleRuntimeContext $context = null;
    private ?BlogAnalyticsHttpController $controller = null;
    private ?BlogAnalyticsPageGrantCodec $pageGrantCodec = null;

    public function __construct(
        private readonly BlogAnalyticsHttpRuntimeFactoryInterface
            $runtimeFactory = new BlogAnalyticsHttpRuntimeFactory(),
        private readonly BlogConfigLoader $configLoader =
            new BlogConfigLoader(),
        private readonly PrivateRouteTransportPolicy $transportPolicy =
            new PrivateRouteTransportPolicy(),
        private readonly BlogAnalyticsRequestPolicy $requestPolicy =
            new BlogAnalyticsRequestPolicy()
    ) {
    }

    public static function moduleId(): string
    {
        return 'blog';
    }

    public function registerRoutes(
        ModuleRouteCollection $routes,
        ModuleRuntimeContext $context
    ): void {
        try {
            $config = $this->configLoader->load(
                $context->projectRoot(),
                $context->languages()
            );
            if (!$config->analytics()->enabled()) {
                return;
            }
            $profile = ProjectRuntimeProfile::fromEnvironment(
                $context->environment()
            );
            if (
                $profile->isDevelopmentLoopbackHttp()
                && !$config->analytics()->collectInDevelopment()
            ) {
                return;
            }
            $routes->claimPrefix(
                self::moduleId(),
                self::PREFIX,
                [$this, 'notFound'],
                [$this, 'methodNotAllowed']
            );
            $routes->add(
                self::moduleId(),
                'POST',
                self::START_PATH,
                [$this, 'start']
            );
            $routes->add(
                self::moduleId(),
                'POST',
                self::ENGAGEMENT_PATH,
                [$this, 'engagement']
            );
            $routes->add(
                self::moduleId(),
                'POST',
                self::REVOKE_PATH,
                [$this, 'revoke']
            );
            $this->context = $context;
            $this->pageGrantCodec = null;
        } catch (Throwable) {
            return;
        }
    }

    public function start(Request $request): Response
    {
        return $this->handle('start', $request);
    }

    public function engagement(Request $request): Response
    {
        return $this->handle('engagement', $request);
    }

    public function revoke(Request $request): Response
    {
        $context = $this->context;
        if (
            !$context instanceof ModuleRuntimeContext
            || !$this->transportPolicy->accepts(
                $request,
                $context->environment()
            )
            || $request->header('origin') !== $this->origin($context)
        ) {
            return $this->plain(400, 'Bad request');
        }
        $secure = str_starts_with($this->origin($context), 'https://');
        $suffix = '; Path=/; Max-Age=0; SameSite=Lax'
            . ($secure ? '; Secure' : '');

        return new Response(204, '', [
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' =>
                "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'Set-Cookie' => [
                BlogAnalyticsHttpController::VISITOR_COOKIE . '=' . $suffix,
                BlogAnalyticsHttpController::SESSION_COOKIE . '=' . $suffix,
            ],
        ]);
    }

    public function notFound(Request $request): Response
    {
        return $this->plain(404, 'Not found');
    }

    /** @param list<string> $allowed */
    public function methodNotAllowed(Request $request, array $allowed): Response
    {
        $response = $this->plain(405, 'Method not allowed');

        return $response->withAddedHeader('Allow', implode(', ', $allowed));
    }

    private function handle(string $method, Request $request): Response
    {
        $context = $this->context;
        if (
            !$context instanceof ModuleRuntimeContext
            || !$this->transportPolicy->accepts(
                $request,
                $context->environment()
            )
            || !$this->requestPolicy->acceptsJsonPost(
                $request,
                $this->origin($context)
            )
        ) {
            return $this->plain(400, 'Bad request');
        }
        $payload = $method === 'start'
            ? $this->requestPolicy->startPayload($request)
            : $this->requestPolicy->engagementPayload($request);
        if ($payload === null) {
            return $this->plain(400, 'Bad request');
        }
        try {
            if ($this->pageGrantCodec($context)->verify(
                $payload['page_grant']
            ) === null) {
                return $this->plain(400, 'Bad request');
            }
            $controller = $this->controller();

            return $method === 'start'
                ? $controller->start($request)
                : $controller->engagement($request);
        } catch (Throwable) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    private function controller(): BlogAnalyticsHttpController
    {
        if ($this->controller instanceof BlogAnalyticsHttpController) {
            return $this->controller;
        }
        if (!$this->context instanceof ModuleRuntimeContext) {
            throw new \RuntimeException('Blog analytics unavailable.');
        }

        return $this->controller = new BlogAnalyticsHttpController(
            $this->runtimeFactory->create($this->context),
            $this->context->environment()
        );
    }

    private function origin(ModuleRuntimeContext $context): string
    {
        return BlogPublicOrigin::fromEnvironment(
            $context->environment()
        )->value();
    }

    private function pageGrantCodec(
        ModuleRuntimeContext $context
    ): BlogAnalyticsPageGrantCodec {
        if ($this->pageGrantCodec instanceof BlogAnalyticsPageGrantCodec) {
            return $this->pageGrantCodec;
        }
        $encoded = $context->environment()[
            WebAdminConfig::SECURITY_KEY_ENV
        ] ?? null;
        if (!is_string($encoded) || $encoded === '') {
            throw new \RuntimeException('Blog analytics unavailable.');
        }

        return $this->pageGrantCodec = new BlogAnalyticsPageGrantCodec(
            SecurityKey::fromBase64Url($encoded),
            BlogPublicOrigin::fromEnvironment($context->environment())
        );
    }

    private function plain(int $status, string $body): Response
    {
        return new Response($status, $body, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' =>
                "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
