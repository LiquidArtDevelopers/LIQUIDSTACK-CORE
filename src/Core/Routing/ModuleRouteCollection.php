<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Http\Request;
use App\Core\Http\Response;
use Closure;
use InvalidArgumentException;
use RuntimeException;

final class ModuleRouteCollection
{
    /** @var array<string, array{module: string, not_found: Closure, method_not_allowed: Closure}> */
    private array $claims = [];

    /** @var array<string, ModuleRoute> */
    private array $routes = [];

    /**
     * @param callable(Request): Response $notFound
     * @param callable(Request, list<string>): Response $methodNotAllowed
     */
    public function claimPrefix(
        string $module,
        string $prefix,
        callable $notFound,
        callable $methodNotAllowed
    ): void {
        $this->assertModule($module);
        $prefix = $this->validatePath($prefix, true);

        if (isset($this->claims[$prefix])) {
            throw new RuntimeException(sprintf(
                'El prefijo neutral %s ya pertenece al módulo %s.',
                $prefix,
                $this->claims[$prefix]['module']
            ));
        }

        $this->claims[$prefix] = [
            'module' => $module,
            'not_found' => Closure::fromCallable($notFound),
            'method_not_allowed' => Closure::fromCallable($methodNotAllowed),
        ];
    }

    /**
     * Claims a strict child of a prefix whose effective owner is the module
     * depended on by the caller. The existing claimPrefix() API remains
     * available for top-level and backwards-compatible registrations.
     *
     * @param callable(Request): Response $notFound
     * @param callable(Request, list<string>): Response $methodNotAllowed
     */
    public function claimChildPrefix(
        string $module,
        string $parentModule,
        string $parentPrefix,
        string $childPrefix,
        callable $notFound,
        callable $methodNotAllowed
    ): void {
        $this->assertModule($module);
        $this->assertModule($parentModule);
        $parentPrefix = $this->validatePath($parentPrefix, true);
        $childPrefix = $this->validatePath($childPrefix, true);

        if (!str_starts_with($childPrefix, $parentPrefix . '/')) {
            throw new InvalidArgumentException(sprintf(
                'El prefijo hijo %s debe descender estrictamente de %s.',
                $childPrefix,
                $parentPrefix
            ));
        }

        $declaredParent = $this->claimForPath($parentPrefix);
        $effectiveParent = $this->claimForPath($childPrefix);
        if (
            $declaredParent === null
            || $declaredParent['claim']['module'] !== $parentModule
            || $effectiveParent === null
            || $effectiveParent['claim']['module'] !== $parentModule
        ) {
            throw new RuntimeException(sprintf(
                'El prefijo padre efectivo %s no pertenece al modulo requerido %s.',
                $parentPrefix,
                $parentModule
            ));
        }

        $this->claimPrefix(
            $module,
            $childPrefix,
            $notFound,
            $methodNotAllowed
        );
    }

    /**
     * @param callable(Request): Response $handler
     */
    public function add(
        string $module,
        string $method,
        string $path,
        callable $handler
    ): void {
        $this->assertModule($module);
        $method = strtoupper(trim($method));
        if (preg_match('/\A[A-Z]+\z/', $method) !== 1) {
            throw new InvalidArgumentException('El método de la ruta no es válido.');
        }

        $path = $this->validatePath($path, false);
        $claim = $this->claimForPath($path);
        if ($claim === null || $claim['claim']['module'] !== $module) {
            throw new RuntimeException(sprintf(
                'La ruta %s del módulo %s no pertenece a un prefijo reclamado.',
                $path,
                $module
            ));
        }

        $key = $method . ' ' . $path;
        if (isset($this->routes[$key])) {
            throw new RuntimeException(sprintf(
                'La ruta neutral %s ya está registrada.',
                $key
            ));
        }

        $this->routes[$key] = new ModuleRoute(
            $module,
            $method,
            $path,
            $handler
        );
    }

    public function dispatch(Request $request): ?Response
    {
        $claimed = $this->claimForPath($request->path());
        if ($claimed === null) {
            return null;
        }

        $claim = $claimed['claim'];
        if (!$request->hasValidPath()) {
            return $this->withoutBodyForHead(
                $request,
                $this->invokeNotFound($claim, $request)
            );
        }

        $method = $request->method();
        $lookupMethod = $method === 'HEAD' ? 'GET' : $method;
        $route = $this->routes[$lookupMethod . ' ' . $request->path()] ?? null;

        if ($route !== null && $route->module() === $claim['module']) {
            $response = $route->handle($request);

            return $method === 'HEAD' ? $response->withoutBody() : $response;
        }

        $allowed = $this->allowedMethods($request->path(), $claim['module']);
        if ($allowed !== []) {
            return $this->invokeMethodNotAllowed(
                $claim,
                $request,
                $allowed
            );
        }

        return $this->withoutBodyForHead(
            $request,
            $this->invokeNotFound($claim, $request)
        );
    }

    /**
     * @param array{module: string, not_found: Closure, method_not_allowed: Closure} $claim
     */
    private function invokeNotFound(array $claim, Request $request): Response
    {
        $response = ($claim['not_found'])($request);
        if (!$response instanceof Response) {
            throw new RuntimeException('El handler 404 del módulo no devolvió una respuesta HTTP.');
        }

        return $response;
    }

    /**
     * @param array{module: string, not_found: Closure, method_not_allowed: Closure} $claim
     * @param list<string> $allowed
     */
    private function invokeMethodNotAllowed(
        array $claim,
        Request $request,
        array $allowed
    ): Response {
        $response = ($claim['method_not_allowed'])($request, $allowed);
        if (!$response instanceof Response) {
            throw new RuntimeException('El handler 405 del módulo no devolvió una respuesta HTTP.');
        }

        return $request->method() === 'HEAD'
            ? $response->withoutBody()
            : $response;
    }

    /** @return list<string> */
    private function allowedMethods(string $path, string $module): array
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if ($route->path() !== $path || $route->module() !== $module) {
                continue;
            }

            $allowed[] = $route->method();
            if ($route->method() === 'GET') {
                $allowed[] = 'HEAD';
            }
        }

        $allowed = array_values(array_unique($allowed));
        sort($allowed);

        return $allowed;
    }

    private function withoutBodyForHead(
        Request $request,
        Response $response
    ): Response {
        return $request->method() === 'HEAD'
            ? $response->withoutBody()
            : $response;
    }

    /**
     * @return array{prefix: string, claim: array{module: string, not_found: Closure, method_not_allowed: Closure}}|null
     */
    private function claimForPath(string $path): ?array
    {
        $matched = null;
        foreach ($this->claims as $prefix => $claim) {
            if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
                continue;
            }

            if ($matched === null || strlen($prefix) > strlen($matched['prefix'])) {
                $matched = ['prefix' => $prefix, 'claim' => $claim];
            }
        }

        return $matched;
    }

    private function assertModule(string $module): void
    {
        if (preg_match('/\A[a-z][a-z0-9-]*\z/', $module) !== 1) {
            throw new InvalidArgumentException('El identificador del módulo no es válido.');
        }
    }

    private function validatePath(string $path, bool $prefix): string
    {
        if (
            $path === ''
            || $path === '/'
            || !str_starts_with($path, '/')
            || str_contains($path, '?')
            || str_contains($path, '#')
            || str_contains($path, "\\")
            || str_contains($path, '//')
            || preg_match('/[\x00-\x20\x7F]/', $path) === 1
            || preg_match('#(?:^|/)\.\.?($|/)#', $path) === 1
            || ($prefix && str_ends_with($path, '/'))
        ) {
            throw new InvalidArgumentException('La ruta neutral no es válida.');
        }

        return $path;
    }
}
