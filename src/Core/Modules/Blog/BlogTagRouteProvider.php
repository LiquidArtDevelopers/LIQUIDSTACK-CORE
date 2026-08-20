<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Blog\Http\BlogAdminHttpRuntimeException;
use App\Core\Blog\Http\BlogAdminHttpRuntimeFactory;
use App\Core\Blog\Http\BlogAdminHttpRuntimeFactoryInterface;
use App\Core\Blog\Http\BlogAdminRuntimeIssueReporterInterface;
use App\Core\Blog\Http\BlogTagAdminHttpController;
use App\Core\Blog\Http\BlogTagAdminHttpRuntimeInterface;
use App\Core\Blog\Http\PhpErrorLogBlogAdminRuntimeIssueReporter;
use App\Core\Http\PrivateRouteTransportPolicy;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\ModuleRouteProviderInterface;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Routing\ModuleRouteCollection;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use App\Core\WebAdmin\Routing\WebAdminRoutePolicy;
use RuntimeException;
use Throwable;

/** Owns the closed `/admin/blog/tags` assignment mutation only. */
final class BlogTagRouteProvider implements ModuleRouteProviderInterface
{
    private ?ModuleRuntimeContext $context = null;
    private ?WebAdminConfig $config = null;
    private ?BlogTagAdminHttpController $controller = null;

    public function __construct(
        private readonly BlogAdminHttpRuntimeFactoryInterface $runtimeFactory =
            new BlogAdminHttpRuntimeFactory(),
        private readonly BlogAdminRuntimeIssueReporterInterface $issueReporter =
            new PhpErrorLogBlogAdminRuntimeIssueReporter(),
        private readonly WebAdminConfigLoader $configLoader =
            new WebAdminConfigLoader(),
        private readonly WebAdminRoutePolicy $routePolicy =
            new WebAdminRoutePolicy(),
        private readonly PrivateRouteTransportPolicy $transportPolicy =
            new PrivateRouteTransportPolicy()
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
                $context->environment()
            );
            $resolution = $this->routePolicy->resolve(
                $context->projectRoot(),
                $config->basePath(),
                $context->languages()
            );
            $webAdminPrefix = $resolution->registeredPath();
            if ($webAdminPrefix === null) {
                return;
            }
            if ($config->basePath() !== $webAdminPrefix) {
                $config = $config->withBasePath($webAdminPrefix);
            }
            $blogPrefix = $webAdminPrefix . '/blog';
            $prefix = $blogPrefix . '/tags';
            $routes->claimChildPrefix(
                self::moduleId(),
                self::moduleId(),
                $blogPrefix,
                $prefix,
                [$this, 'notFound'],
                [$this, 'methodNotAllowed']
            );
            $routes->add(
                self::moduleId(),
                'POST',
                $prefix . '/assign',
                [$this, 'saveAssignment']
            );
            $this->context = $context;
            $this->config = $config;
        } catch (RuntimeException) {
            // The provider cannot escape or replace the Blog parent claim.
        } catch (Throwable) {
            $this->issueReporter->report('blog.tags.startup_unavailable');
        }
    }

    public function saveAssignment(Request $request): Response
    {
        return $this->handle($request);
    }

    private function handle(Request $request): Response
    {
        if ($this->context === null || $this->config === null) {
            return $this->unavailable();
        }
        if (!$this->transportPolicy->accepts(
            $request,
            $this->context->environment()
        )) {
            return $this->plain(400, 'Bad request');
        }
        if ($request->cookie($this->config->cookieName()) === null) {
            return $this->redirect($this->config->basePath() . '/login');
        }
        if (!$this->context->environmentIsUsable()) {
            $this->issueReporter->report('blog.environment_unusable');

            return $this->unavailable();
        }
        try {
            if ($this->controller === null) {
                $runtime = $this->runtimeFactory->create(
                    $this->context,
                    $this->config
                );
                if (!$runtime instanceof BlogTagAdminHttpRuntimeInterface) {
                    throw new BlogAdminHttpRuntimeException(
                        'blog.tags.runtime_unavailable'
                    );
                }
                $this->controller = new BlogTagAdminHttpController(
                    $runtime,
                    transportPolicy: $this->transportPolicy,
                    environment: $this->context->environment()
                );
            }

            return $this->controller->saveAssignment($request);
        } catch (Throwable $exception) {
            $this->issueReporter->report(
                $exception instanceof BlogAdminHttpRuntimeException
                    ? $exception->issueCode()
                    : 'blog.tags.runtime_unavailable'
            );

            return $this->unavailable();
        }
    }

    public function notFound(Request $request): Response
    {
        return $this->plain(404, 'Not found');
    }

    /** @param list<string> $allowed */
    public function methodNotAllowed(
        Request $request,
        array $allowed
    ): Response {
        return $this->plain(405, 'Method not allowed', [
            'Allow' => implode(', ', $allowed),
        ]);
    }

    private function unavailable(): Response
    {
        return $this->plain(503, 'Service unavailable');
    }

    /** @param array<string, string> $headers */
    private function plain(
        int $status,
        string $body,
        array $headers = []
    ): Response {
        return new Response($status, $body, $headers + $this->headers());
    }

    private function redirect(string $path): Response
    {
        return new Response(303, '', ['Location' => $path] + $this->headers());
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Content-Security-Policy' =>
                "default-src 'none'; form-action 'none'; "
                . "frame-ancestors 'none'; base-uri 'none'",
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ];
    }
}
