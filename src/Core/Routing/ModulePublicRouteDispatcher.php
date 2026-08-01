<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\ModulePublicRouteProviderInterface;
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
        if (!$request->hasValidMethod() || !$request->hasValidPath()) {
            return null;
        }

        $matched = $this->matchingProvider($request->path());
        if ($matched === null) {
            return null;
        }

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
                if (isset($claims[$prefix])) {
                    throw new RuntimeException(sprintf(
                        'El prefijo publico %s ya pertenece al modulo %s.',
                        $prefix,
                        $claims[$prefix]
                    ));
                }
                $claims[$prefix] = $registered['module'];

                if (
                    $path !== $prefix
                    && !str_starts_with($path, $prefix . '/')
                ) {
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
