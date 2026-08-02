<?php

declare(strict_types=1);

namespace App\Core\Modules\WebAdmin;

use App\Core\Http\PrivateRouteTransportPolicy;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\ModuleRouteProviderInterface;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Routing\ModuleRouteCollection;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Configuration\WebAdminConfigException;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use App\Core\WebAdmin\Http\PhpErrorLogWebAdminRuntimeIssueReporter;
use App\Core\WebAdmin\Http\WebAdminRuntimeIssueReporterInterface;
use App\Core\WebAdmin\Media\Http\WebAdminMediaHttpController;
use App\Core\WebAdmin\Media\Http\WebAdminMediaHttpRequestPolicy;
use App\Core\WebAdmin\Media\Http\WebAdminMediaHttpRuntimeException;
use App\Core\WebAdmin\Media\Http\WebAdminMediaHttpRuntimeFactory;
use App\Core\WebAdmin\Media\Http\WebAdminMediaHttpRuntimeFactoryInterface;
use App\Core\WebAdmin\Routing\WebAdminRoutePolicy;
use RuntimeException;
use Throwable;

final class WebAdminMediaRouteProvider implements ModuleRouteProviderInterface
{
    private readonly WebAdminMediaHttpRuntimeFactoryInterface $runtimeFactory;
    private readonly WebAdminMediaHttpRequestPolicy $requestPolicy;
    private readonly WebAdminRuntimeIssueReporterInterface $issueReporter;
    private readonly WebAdminConfigLoader $configLoader;
    private readonly WebAdminRoutePolicy $routePolicy;
    private readonly PrivateRouteTransportPolicy $transportPolicy;
    private ?ModuleRuntimeContext $context = null;
    private ?WebAdminConfig $config = null;
    private ?WebAdminMediaHttpController $controller = null;
    private ?string $startupIssueCode = null;
    private bool $startupBlocked = false;

    public function __construct(
        ?WebAdminMediaHttpRuntimeFactoryInterface $runtimeFactory = null,
        ?WebAdminMediaHttpRequestPolicy $requestPolicy = null,
        ?WebAdminRuntimeIssueReporterInterface $issueReporter = null,
        ?WebAdminConfigLoader $configLoader = null,
        ?WebAdminRoutePolicy $routePolicy = null,
        ?PrivateRouteTransportPolicy $transportPolicy = null
    ) {
        $this->runtimeFactory = $runtimeFactory
            ?? new WebAdminMediaHttpRuntimeFactory();
        $this->requestPolicy = $requestPolicy
            ?? new WebAdminMediaHttpRequestPolicy();
        $this->issueReporter = $issueReporter
            ?? new PhpErrorLogWebAdminRuntimeIssueReporter();
        $this->configLoader = $configLoader ?? new WebAdminConfigLoader();
        $this->routePolicy = $routePolicy ?? new WebAdminRoutePolicy();
        $this->transportPolicy = $transportPolicy
            ?? new PrivateRouteTransportPolicy();
    }

    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public function registerRoutes(
        ModuleRouteCollection $routes,
        ModuleRuntimeContext $context
    ): void {
        $config = WebAdminConfig::defaults();
        try {
            $config = $this->configLoader->load($context->projectRoot());
        } catch (WebAdminConfigException) {
            $this->startupIssueCode = 'webadmin.media.configuration_invalid';
            $this->startupBlocked = true;
        }
        try {
            $languages = $context->languages();
        } catch (RuntimeException) {
            $languages = [];
            $config = WebAdminConfig::defaults();
            $this->startupIssueCode ??= 'webadmin.media.languages_invalid';
            $this->startupBlocked = true;
        }
        try {
            $resolution = $this->routePolicy->resolve(
                $context->projectRoot(),
                $config->basePath(),
                $languages
            );
        } catch (Throwable) {
            return;
        }
        $basePath = $resolution->registeredPath();
        if ($basePath === null) {
            return;
        }
        if ($config->basePath() !== $basePath) {
            $config = new WebAdminConfig(
                $basePath,
                $config->tablePrefix(),
                $config->cookieName(),
                $config->idleTtlSeconds(),
                $config->absoluteTtlSeconds(),
                $config->source(),
                $config->databaseConnection()
            );
        }
        $prefix = $basePath . '/media';
        try {
            $routes->claimChildPrefix(
                self::moduleId(),
                WebAdminRouteProvider::moduleId(),
                $basePath,
                $prefix,
                [$this, 'notFound'],
                [$this, 'methodNotAllowed']
            );
        } catch (RuntimeException) {
            return;
        }
        foreach ([
            ['GET', $prefix, 'index'],
            ['POST', $prefix . '/upload', 'upload'],
            ['GET', $prefix . '/updated', 'updated'],
            ['GET', $prefix . '/file', 'file'],
        ] as [$method, $path, $handler]) {
            $routes->add(self::moduleId(), $method, $path, [$this, $handler]);
        }
        $this->context = $context;
        $this->config = $config;
    }

    public function index(Request $request): Response { return $this->handle('index', $request); }
    public function upload(Request $request): Response { return $this->handle('upload', $request); }
    public function updated(Request $request): Response { return $this->handle('updated', $request); }
    public function file(Request $request): Response { return $this->handle('file', $request); }

    private function handle(string $operation, Request $request): Response
    {
        if ($this->context === null || $this->config === null) {
            $this->reportStartupIssue();
            return $this->unavailable();
        }
        if (!$this->requestAllowed($operation, $request)) {
            return $this->plain(400, 'Bad request');
        }
        if (!$this->transportPolicy->accepts(
            $request,
            $this->context->environment()
        )) {
            return $this->plain(400, 'Bad request');
        }
        if ($request->cookie($this->config->cookieName()) === null) {
            return new Response(
                303,
                '',
                ['Location' => $this->config->basePath() . '/login']
                    + $this->privateHeaders()
            );
        }
        if (!$this->context->environmentIsUsable() || $this->startupBlocked) {
            $this->reportStartupIssue();
            return $this->unavailable();
        }
        try {
            $this->controller ??= new WebAdminMediaHttpController(
                $this->runtimeFactory->create($this->context, $this->config)
            );

            return $this->controller->{$operation}($request);
        } catch (Throwable $exception) {
            $this->issueReporter->report(
                $exception instanceof WebAdminMediaHttpRuntimeException
                    ? $exception->issueCode()
                    : 'webadmin.media.runtime_unavailable'
            );
            return $this->unavailable();
        }
    }

    private function requestAllowed(string $operation, Request $request): bool
    {
        return match ($operation) {
            'index' => $this->requestPolicy->acceptsIndex($request),
            'upload' => $this->requestPolicy->acceptsUpload($request),
            'updated' => $this->requestPolicy->acceptsUpdated($request),
            'file' => $this->requestPolicy->acceptsFile($request),
            default => false,
        };
    }

    private function reportStartupIssue(): void
    {
        if ($this->startupIssueCode !== null) {
            $issue = $this->startupIssueCode;
            $this->startupIssueCode = null;
            $this->issueReporter->report($issue);
        }
    }

    public function notFound(Request $request): Response
    {
        return $this->plain(404, 'Not found');
    }

    /** @param list<string> $allowed */
    public function methodNotAllowed(Request $request, array $allowed): Response
    {
        return new Response(405, 'Method not allowed', [
            'Allow' => implode(', ', $allowed),
        ] + $this->privateHeaders());
    }

    private function unavailable(): Response
    {
        return $this->plain(503, 'Service unavailable');
    }

    private function plain(int $status, string $body): Response
    {
        return new Response($status, $body, $this->privateHeaders());
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
    }
}
