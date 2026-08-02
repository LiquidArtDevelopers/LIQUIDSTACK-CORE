<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Blog\Http\BlogAdminHttpRuntimeException;
use App\Core\Blog\Http\BlogAdminRuntimeIssueReporterInterface;
use App\Core\Blog\Http\BlogCategoryAdminHttpController;
use App\Core\Blog\Http\BlogCategoryAdminHttpRuntimeFactory;
use App\Core\Blog\Http\BlogCategoryAdminHttpRuntimeFactoryInterface;
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

/** Owns only /admin/blog/categories; Blog article routes stay independent. */
final class BlogCategoryRouteProvider implements ModuleRouteProviderInterface
{
    private ?ModuleRuntimeContext $context = null;
    private ?WebAdminConfig $config = null;
    private ?BlogCategoryAdminHttpController $controller = null;

    public function __construct(
        private readonly BlogCategoryAdminHttpRuntimeFactoryInterface
            $runtimeFactory = new BlogCategoryAdminHttpRuntimeFactory(),
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
            $config = $this->configLoader->load($context->projectRoot());
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
                $config = new WebAdminConfig(
                    $webAdminPrefix,
                    $config->tablePrefix(),
                    $config->cookieName(),
                    $config->idleTtlSeconds(),
                    $config->absoluteTtlSeconds(),
                    $config->source(),
                    $config->databaseConnection()
                );
            }
            $blogPrefix = $webAdminPrefix . '/blog';
            $prefix = $blogPrefix . '/categories';
            $routes->claimChildPrefix(
                self::moduleId(),
                self::moduleId(),
                $blogPrefix,
                $prefix,
                [$this, 'notFound'],
                [$this, 'methodNotAllowed']
            );
            $definitions = [
                ['GET', $prefix, 'index'],
                ['GET', $prefix . '/new', 'newCategory'],
                ['POST', $prefix . '/create', 'create'],
                ['GET', $prefix . '/edit', 'edit'],
                ['POST', $prefix . '/save', 'save'],
                ['GET', $prefix . '/assign', 'assignment'],
                ['POST', $prefix . '/assign', 'saveAssignment'],
                ['GET', $prefix . '/updated', 'updated'],
            ];
            foreach ($definitions as [$method, $path, $handler]) {
                $routes->add(
                    self::moduleId(),
                    $method,
                    $path,
                    [$this, $handler]
                );
            }
            $this->context = $context;
            $this->config = $config;
        } catch (RuntimeException) {
            // The provider cannot escape or replace the Blog parent claim.
        } catch (Throwable) {
            $this->issueReporter->report('blog.categories.startup_unavailable');
        }
    }

    public function index(Request $request): Response
    {
        return $this->handle('index', $request);
    }

    public function newCategory(Request $request): Response
    {
        return $this->handle('newCategory', $request);
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

    public function assignment(Request $request): Response
    {
        return $this->handle('assignment', $request);
    }

    public function saveAssignment(Request $request): Response
    {
        return $this->handle('saveAssignment', $request);
    }

    public function updated(Request $request): Response
    {
        return $this->handle('updated', $request);
    }

    private function handle(string $operation, Request $request): Response
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
            $this->controller ??= new BlogCategoryAdminHttpController(
                $this->runtimeFactory->create($this->context, $this->config),
                transportPolicy: $this->transportPolicy,
                environment: $this->context->environment()
            );

            return $this->controller->{$operation}($request);
        } catch (Throwable $exception) {
            $this->issueReporter->report(
                $exception instanceof BlogAdminHttpRuntimeException
                    ? $exception->issueCode()
                    : 'blog.categories.runtime_unavailable'
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
        return new Response(
            303,
            '',
            ['Location' => $path] + $this->headers()
        );
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow,noarchive',
            'Content-Security-Policy' =>
                "default-src 'none'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'",
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ];
    }
}
