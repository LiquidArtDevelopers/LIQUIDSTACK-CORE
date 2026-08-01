<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Blog\Http\BlogAdminHttpController;
use App\Core\Blog\Http\BlogAdminHttpRuntimeException;
use App\Core\Blog\Http\BlogAdminHttpRuntimeFactory;
use App\Core\Blog\Http\BlogAdminHttpRuntimeFactoryInterface;
use App\Core\Blog\Http\BlogAdminRequestPolicy;
use App\Core\Blog\Http\BlogAdminRuntimeIssueReporterInterface;
use App\Core\Blog\Http\PhpErrorLogBlogAdminRuntimeIssueReporter;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\ModuleRouteProviderInterface;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Modules\WebAdmin\WebAdminRouteProvider;
use App\Core\Routing\ModuleRouteCollection;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Configuration\WebAdminConfigException;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use App\Core\WebAdmin\Routing\WebAdminRoutePolicy;
use RuntimeException;
use Throwable;

final class BlogRouteProvider implements ModuleRouteProviderInterface
{
    private readonly BlogAdminHttpRuntimeFactoryInterface $runtimeFactory;
    private readonly BlogAdminRequestPolicy $requestPolicy;
    private readonly BlogAdminRuntimeIssueReporterInterface $issueReporter;
    private readonly WebAdminConfigLoader $webAdminConfigLoader;
    private readonly WebAdminRoutePolicy $webAdminRoutePolicy;
    private ?ModuleRuntimeContext $runtimeContext = null;
    private ?WebAdminConfig $webAdminConfig = null;
    private ?BlogAdminHttpController $controller = null;
    private ?string $startupIssueCode = null;
    private bool $startupBlocked = false;

    public function __construct(
        ?BlogAdminHttpRuntimeFactoryInterface $runtimeFactory = null,
        ?BlogAdminRequestPolicy $requestPolicy = null,
        ?BlogAdminRuntimeIssueReporterInterface $issueReporter = null,
        ?WebAdminConfigLoader $webAdminConfigLoader = null,
        ?WebAdminRoutePolicy $webAdminRoutePolicy = null
    ) {
        $this->runtimeFactory = $runtimeFactory
            ?? new BlogAdminHttpRuntimeFactory();
        $this->requestPolicy = $requestPolicy
            ?? new BlogAdminRequestPolicy();
        $this->issueReporter = $issueReporter
            ?? new PhpErrorLogBlogAdminRuntimeIssueReporter();
        $this->webAdminConfigLoader = $webAdminConfigLoader
            ?? new WebAdminConfigLoader();
        $this->webAdminRoutePolicy = $webAdminRoutePolicy
            ?? new WebAdminRoutePolicy();
    }

    public static function moduleId(): string
    {
        return 'blog';
    }

    public function registerRoutes(
        ModuleRouteCollection $routes,
        ModuleRuntimeContext $context
    ): void {
        $configuration = WebAdminConfig::defaults();
        try {
            $configuration = $this->webAdminConfigLoader->load(
                $context->projectRoot()
            );
        } catch (WebAdminConfigException) {
            $this->startupIssueCode =
                'blog.webadmin_configuration_invalid';
            $this->startupBlocked = true;
        }

        try {
            $languages = $context->languages();
        } catch (RuntimeException) {
            $languages = [];
            $configuration = WebAdminConfig::defaults();
            $this->startupIssueCode ??= 'blog.languages_invalid';
            $this->startupBlocked = true;
        }

        try {
            $resolution = $this->webAdminRoutePolicy->resolve(
                $context->projectRoot(),
                $configuration->basePath(),
                $languages
            );
        } catch (Throwable) {
            return;
        }
        $webAdminPrefix = $resolution->registeredPath();
        if ($webAdminPrefix === null) {
            return;
        }
        if ($configuration->basePath() !== $webAdminPrefix) {
            $configuration = new WebAdminConfig(
                $webAdminPrefix,
                $configuration->tablePrefix(),
                $configuration->cookieName(),
                $configuration->idleTtlSeconds(),
                $configuration->absoluteTtlSeconds(),
                $configuration->source()
            );
        }
        $prefix = $webAdminPrefix . '/blog';

        try {
            $routes->claimChildPrefix(
                self::moduleId(),
                WebAdminRouteProvider::moduleId(),
                $webAdminPrefix,
                $prefix,
                [$this, 'notFound'],
                [$this, 'methodNotAllowed']
            );
        } catch (RuntimeException) {
            // Blog never escapes or replaces the effective WebAdmin claim.
            return;
        }

        $definitions = [
            ['GET', $prefix, 'index'],
            ['GET', $prefix . '/posts/new', 'newPost'],
            ['POST', $prefix . '/posts/create', 'create'],
            ['GET', $prefix . '/posts/edit', 'edit'],
            ['POST', $prefix . '/posts/save', 'save'],
            ['POST', $prefix . '/posts/publish', 'publish'],
            ['POST', $prefix . '/posts/unpublish', 'unpublish'],
            ['GET', $prefix . '/posts/updated', 'updated'],
        ];
        foreach ($definitions as [$method, $path, $handler]) {
            $routes->add(
                self::moduleId(),
                $method,
                $path,
                [$this, $handler]
            );
        }

        $this->runtimeContext = $context;
        $this->webAdminConfig = $configuration;
    }

    public function index(Request $request): Response
    {
        return $this->handle('index', $request);
    }

    public function newPost(Request $request): Response
    {
        return $this->handle('newPost', $request);
    }

    public function create(Request $request): Response
    {
        return $this->handle('create', $request);
    }

    public function edit(Request $request): Response
    {
        return $this->handle('edit', $request);
    }

    public function save(Request $request): Response
    {
        return $this->handle('save', $request);
    }

    public function publish(Request $request): Response
    {
        return $this->handle('publish', $request);
    }

    public function unpublish(Request $request): Response
    {
        return $this->handle('unpublish', $request);
    }

    public function updated(Request $request): Response
    {
        return $this->handle('updated', $request);
    }

    private function handle(string $operation, Request $request): Response
    {
        if (
            $this->runtimeContext === null
            || $this->webAdminConfig === null
        ) {
            $this->reportStartupIssue();

            return $this->unavailable();
        }
        if (!$this->requestIsAllowed($operation, $request)) {
            return $this->response(400, 'Bad request');
        }
        if (!$request->isSecureTransport()) {
            return $this->response(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->html(200, '');
        }
        if ($request->cookie($this->webAdminConfig->cookieName()) === null) {
            return $this->response(303, '', [
                'Location' => $this->webAdminConfig->basePath() . '/login',
            ]);
        }
        if (!$this->runtimeContext->environmentIsUsable()) {
            $this->issueReporter->report('blog.environment_unusable');

            return $this->unavailable();
        }
        if ($this->startupBlocked) {
            $this->reportStartupIssue();

            return $this->unavailable();
        }

        try {
            $this->controller ??= new BlogAdminHttpController(
                $this->runtimeFactory->create(
                    $this->runtimeContext,
                    $this->webAdminConfig
                )
            );

            return $this->controller->{$operation}($request);
        } catch (Throwable $exception) {
            $this->issueReporter->report(
                $exception instanceof BlogAdminHttpRuntimeException
                    ? $exception->issueCode()
                    : 'blog.admin_runtime_unavailable'
            );

            return $this->unavailable();
        }
    }

    private function requestIsAllowed(
        string $operation,
        Request $request
    ): bool {
        return match ($operation) {
            'index' => $this->requestPolicy->acceptsIndex($request),
            'updated' => $this->requestPolicy->acceptsUpdated($request),
            'newPost' => $this->requestPolicy->acceptsNew($request),
            'create' => $this->requestPolicy->acceptsCreate($request),
            'edit' => $this->requestPolicy->acceptsEdit($request),
            'save' => $this->requestPolicy->acceptsSave($request),
            'publish', 'unpublish' =>
                $this->requestPolicy->acceptsTransition($request),
            default => false,
        };
    }

    private function reportStartupIssue(): void
    {
        if ($this->startupIssueCode === null) {
            return;
        }
        $issueCode = $this->startupIssueCode;
        $this->startupIssueCode = null;
        $this->issueReporter->report($issueCode);
    }

    public function notFound(Request $request): Response
    {
        return $this->response(404, 'Not found');
    }

    /** @param list<string> $allowed */
    public function methodNotAllowed(
        Request $request,
        array $allowed
    ): Response {
        return $this->response(405, 'Method not allowed', [
            'Allow' => implode(', ', $allowed),
        ]);
    }

    private function unavailable(): Response
    {
        return $this->response(503, 'Service unavailable');
    }

    /** @param array<string, string> $headers */
    private function html(
        int $status,
        string $body,
        array $headers = []
    ): Response {
        return new Response($status, $body, $headers + $this->headers(
            "default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"
        ) + [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Language' => 'es',
        ]);
    }

    /** @param array<string, string> $headers */
    private function response(
        int $status,
        string $body,
        array $headers = []
    ): Response {
        return new Response($status, $body, $headers + $this->headers(
            "default-src 'none'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'"
        ) + ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** @return array<string, string> */
    private function headers(string $csp): array
    {
        return [
            'Cache-Control' =>
                'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Content-Security-Policy' => $csp,
            'Permissions-Policy' =>
                'camera=(), microphone=(), geolocation=()',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
    }
}
