<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRouteProviderInterface;
use App\Core\Modules\ModuleRuntimeContext;
use RuntimeException;

final class ModuleRouteDispatcher
{
    private function __construct(
        private readonly ModuleRouteCollection $routes
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
        $context = new ModuleRuntimeContext(
            $projectRoot,
            $environment,
            $environmentUsable
        );
        $routes = new ModuleRouteCollection();

        foreach ($registry->routeProviders() as $registered) {
            $className = $registered['class'];
            $provider = new $className();
            if (!$provider instanceof ModuleRouteProviderInterface) {
                throw new RuntimeException(sprintf(
                    'No se pudo construir el provider de rutas %s.',
                    $className
                ));
            }

            $provider->registerRoutes($routes, $context);
        }

        return new self($routes);
    }

    public function dispatch(Request $request): ?Response
    {
        return $this->routes->dispatch($request);
    }
}
