<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\ModulePublicRouteProviderInterface;
use App\Core\Modules\ModulePreBootstrapPublicRouteProviderInterface;
use App\Core\Modules\ModulePreBootstrapPublicRoutePrefixProviderInterface;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use RuntimeException;
use Throwable;

final class ModulePublicRouteDispatcher
{
    /**
     * @param list<array{module: string, class: class-string<ModulePublicRouteProviderInterface>}> $providers
     */
    private function __construct(
        private readonly array $providers,
        private readonly ModuleRuntimeContext $context
    ) {
    }

    /**
     * @param array<string, mixed> $environment
     */
    public static function forProject(
        string $projectRoot,
        array $environment = [],
        ?string $coreRoot = null,
        bool $environmentUsable = true
    ): self {
        $registry = ModuleRegistry::forProject($projectRoot, $coreRoot);

        return new self(
            $registry->publicRouteProviders(),
            new ModuleRuntimeContext(
                $projectRoot,
                $environment,
                $environmentUsable
            )
        );
    }

    public function dispatch(Request $request): ?Response
    {
        if (
            !$request->hasValidMethod()
            || !$request->hasValidPath()
            || $this->projectPublicPathExists($request->path())
        ) {
            return null;
        }

        try {
            $matched = $this->matchingProvider($request->path());
        } catch (Throwable) {
            return $this->unavailableResponse($request);
        }
        if ($matched === null) {
            return null;
        }

        return $this->dispatchMatched($request, $matched);
    }

    /**
     * Cheap metadata-only probe used to decide whether the legacy PHP session
     * may be deferred while project-owned GET/HEAD routes are resolved. It
     * never constructs a route provider or its operational runtime.
     *
     * A metadata failure inside an otherwise affected namespace is treated as
     * claimed so dispatch() can return its closed 503 without first creating
     * PHPSESSID. A claim never grants dispatch priority: callers must still
     * resolve project-owned routes before invoking dispatch().
     */
    public function claimsPublicRead(Request $request): bool
    {
        if (
            !$request->hasValidMethod()
            || !$request->hasValidPath()
            || !in_array($request->method(), ['GET', 'HEAD'], true)
            || $this->projectPublicPathExists($request->path())
        ) {
            return false;
        }

        try {
            return $this->matchingProvider($request->path()) !== null;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Dispatches exact public infrastructure endpoints and selected public
     * namespaces before the legacy language resolver and session.
     * Project-owned routes and files keep priority; incomplete route metadata
     * falls back to legacy.
     */
    public function dispatchBeforeLegacy(Request $request): ?Response
    {
        if (
            !$request->hasValidMethod()
            || !$request->hasValidPath()
            || !in_array($request->method(), ['GET', 'HEAD'], true)
        ) {
            return null;
        }

        try {
            $claim = $this->matchingPreBootstrapClaim($request->path());
        } catch (Throwable) {
            return $this->projectPathIsAvailable($request->path())
                ? $this->unavailableResponse($request)
                : null;
        }
        if (
            $claim === null
            || !$this->projectPathIsAvailable($request->path())
        ) {
            return null;
        }

        try {
            $matched = $this->matchingProvider($request->path());
        } catch (Throwable) {
            return $this->unavailableResponse($request);
        }
        if (
            $matched === null
            || $matched['module'] !== $claim['module']
            || $matched['class'] !== $claim['class']
        ) {
            return $this->unavailableResponse($request);
        }

        return $this->dispatchMatched($request, $matched);
    }

    /**
     * @param array{
     *     module: string,
     *     class: class-string<ModulePublicRouteProviderInterface>,
     *     prefix: string,
     *     routes: ModulePublicRouteCollection
     * } $matched
     */
    private function dispatchMatched(Request $request, array $matched): ?Response
    {
        $routes = $matched['routes'];

        /*
         * A recognized write method never constructs the provider or its
         * operational runtime. The declaration itself is enough for 405.
         */
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $routes->dispatch($request);
        }

        try {
            $className = $matched['class'];
            $provider = new $className();
            if (!$provider instanceof ModulePublicRouteProviderInterface) {
                throw new RuntimeException(sprintf(
                    'No se pudo construir el provider de rutas publicas %s.',
                    $className
                ));
            }

            $provider->registerPublicRoutes($routes, $this->context);

            return $routes->dispatch($request);
        } catch (Throwable) {
            error_log(sprintf(
                'liquidstack.public_module_route_unavailable module=%s',
                $matched['module']
            ));

            return $this->unavailableResponse($request);
        }
    }

    /**
     * @return array{
     *     module: string,
     *     class: class-string<ModulePublicRouteProviderInterface>
     * }|null
     */
    private function matchingPreBootstrapClaim(string $path): ?array
    {
        $matched = null;

        foreach ($this->providers as $registered) {
            $className = $registered['class'];
            $declaresExactPaths = is_a(
                $className,
                ModulePreBootstrapPublicRouteProviderInterface::class,
                true
            );
            $declaresPrefixes = is_a(
                $className,
                ModulePreBootstrapPublicRoutePrefixProviderInterface::class,
                true
            );
            if (!$declaresExactPaths && !$declaresPrefixes) {
                continue;
            }

            try {
                $matches = false;
                if ($declaresExactPaths) {
                    $exactRoutes = new ModulePublicRouteCollection(
                        $registered['module'],
                        $className::preBootstrapPublicRoutePaths($this->context)
                    );
                    $matches = in_array(
                        $path,
                        $exactRoutes->prefixes(),
                        true
                    );
                }
                if ($declaresPrefixes) {
                    $prefixRoutes = new ModulePublicRouteCollection(
                        $registered['module'],
                        $className::preBootstrapPublicRoutePrefixes($this->context)
                    );
                    foreach ($prefixRoutes->prefixes() as $prefix) {
                        if (
                            $path === $prefix
                            || str_starts_with($path, $prefix . '/')
                        ) {
                            $matches = true;
                            break;
                        }
                    }
                }
            } catch (Throwable) {
                continue;
            }

            if (!$matches) {
                continue;
            }
            if ($matched !== null) {
                throw new RuntimeException(sprintf(
                    'La ruta publica pre-bootstrap %s tiene mas de un propietario.',
                    $path
                ));
            }

            $matched = [
                'module' => $registered['module'],
                'class' => $className,
            ];
        }

        return $matched;
    }

    private function projectPathIsAvailable(string $path): bool
    {
        if ($this->projectPublicPathExists($path)) {
            return false;
        }

        if (!$this->projectGetPathIsAvailable($path)) {
            return false;
        }

        return !$this->showroomParentIsOwned($path);
    }

    private function projectGetPathIsAvailable(string $path): bool
    {
        try {
            $assessment = (new StaticRouteCollisionInspector())->inspect(
                $this->context->projectRoot(),
                $path
            );
        } catch (Throwable) {
            return false;
        }

        foreach ($assessment['issues'] as $issue) {
            if ($issue['source'] === 'App/config/routes/get.php') {
                return false;
            }
        }
        foreach ($assessment['collisions'] as $collision) {
            if (
                $collision['method'] === 'GET'
                && $collision['route'] === $assessment['prefix']
            ) {
                return false;
            }
        }

        return true;
    }

    private function showroomParentIsOwned(string $path): bool
    {
        $normalizedPath = $path === '/' ? '/' : rtrim($path, '/');
        foreach (ShowroomCategoryRoute::CATEGORIES as $category) {
            $suffix = '/' . $category;
            if (!str_ends_with($normalizedPath, $suffix)) {
                continue;
            }

            $parent = substr($normalizedPath, 0, -strlen($suffix));
            return $parent !== ''
                && !$this->projectGetPathIsAvailable($parent);
        }

        return false;
    }

    private function projectPublicPathExists(string $path): bool
    {
        $publicTarget = rtrim($this->context->projectRoot(), '/\\')
            . '/public'
            . str_replace('/', DIRECTORY_SEPARATOR, $path);

        return file_exists($publicTarget) || is_link($publicTarget);
    }

    private function unavailableResponse(Request $request): Response
    {
        $response = new Response(
            503,
            'Service unavailable',
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' =>
                    'no-store, no-cache, must-revalidate, max-age=0',
                'X-Robots-Tag' => 'noindex, nofollow, noarchive',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );

        return $request->method() === 'HEAD'
            ? $response->withoutBody()
            : $response;
    }

    /**
     * @return array{
     *     module: string,
     *     class: class-string<ModulePublicRouteProviderInterface>,
     *     prefix: string,
     *     routes: ModulePublicRouteCollection
     * }|null
     */
    private function matchingProvider(string $path): ?array
    {
        $claims = [];
        $matched = null;

        foreach ($this->providers as $registered) {
            $className = $registered['class'];
            $routes = new ModulePublicRouteCollection(
                $registered['module'],
                $className::publicRoutePrefixes($this->context)
            );

            foreach ($routes->prefixes() as $prefix) {
                $pathMatches = $path === $prefix
                    || str_starts_with($path, $prefix . '/');
                if (isset($claims[$prefix]) && $pathMatches) {
                    throw new RuntimeException(sprintf(
                        'El prefijo publico %s ya pertenece al modulo %s.',
                        $prefix,
                        $claims[$prefix]
                    ));
                }
                $claims[$prefix] ??= $registered['module'];

                if (!$pathMatches) {
                    continue;
                }

                if (
                    $matched === null
                    || strlen($prefix) > strlen($matched['prefix'])
                ) {
                    $matched = [
                        'module' => $registered['module'],
                        'class' => $className,
                        'prefix' => $prefix,
                        'routes' => $routes,
                    ];
                }
            }
        }

        return $matched;
    }
}
