<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Http\Request;
use App\Core\Http\Response;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Routes one public module. Normal public routes run after the project's
 * static router has missed; selected exact infrastructure endpoints may also
 * use the guarded pre-bootstrap phase.
 *
 * A GET handler owns its declared prefix and descendants, may return null to
 * continue to the project's existing 404, and is reused for HEAD with the
 * response body removed. Other methods are recognized without invoking it.
 */
final class ModulePublicRouteCollection
{
    /** @var array<string, true> */
    private array $allowedPrefixes = [];

    /** @var array<string, Closure> */
    private array $getHandlers = [];

    /**
     * @param list<string> $allowedPrefixes
     */
    public function __construct(
        private readonly string $module,
        array $allowedPrefixes
    ) {
        $this->assertModule($module);

        if (!array_is_list($allowedPrefixes)) {
            throw new InvalidArgumentException(
                'Los prefijos publicos deben declararse como una lista.'
            );
        }

        foreach ($allowedPrefixes as $prefix) {
            if (!is_string($prefix)) {
                throw new InvalidArgumentException(
                    'El prefijo publico no es valido.'
                );
            }

            $prefix = $this->validatePrefix($prefix);
            if (isset($this->allowedPrefixes[$prefix])) {
                throw new RuntimeException(sprintf(
                    'El prefijo publico %s esta duplicado para el modulo %s.',
                    $prefix,
                    $module
                ));
            }

            $this->allowedPrefixes[$prefix] = true;
        }
    }

    /** @return list<string> */
    public function prefixes(): array
    {
        return array_keys($this->allowedPrefixes);
    }

    /**
     * @param callable(Request): (Response|null) $handler
     */
    public function addGet(
        string $module,
        string $prefix,
        callable $handler
    ): void {
        $this->assertModule($module);
        $prefix = $this->validatePrefix($prefix);

        if ($module !== $this->module) {
            throw new RuntimeException(sprintf(
                'El modulo %s no puede registrar rutas publicas de %s.',
                $module,
                $this->module
            ));
        }
        if (!isset($this->allowedPrefixes[$prefix])) {
            throw new RuntimeException(sprintf(
                'El prefijo publico %s no fue declarado por el modulo %s.',
                $prefix,
                $module
            ));
        }
        if (isset($this->getHandlers[$prefix])) {
            throw new RuntimeException(sprintf(
                'La ruta publica GET/HEAD %s ya esta registrada.',
                $prefix
            ));
        }

        $this->getHandlers[$prefix] = Closure::fromCallable($handler);
    }

    public function dispatch(Request $request): ?Response
    {
        if (!$request->hasValidMethod() || !$request->hasValidPath()) {
            return null;
        }

        $declaredPrefix = $this->matchingPrefix(
            $request->path(),
            $this->allowedPrefixes
        );
        if ($declaredPrefix === null) {
            return null;
        }

        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return new Response(
                405,
                'Method not allowed',
                [
                    'Allow' => 'GET, HEAD',
                    'Content-Type' => 'text/plain; charset=utf-8',
                ]
            );
        }

        $handlerPrefix = $this->matchingPrefix(
            $request->path(),
            $this->getHandlers
        );
        if ($handlerPrefix === null) {
            return null;
        }

        $response = ($this->getHandlers[$handlerPrefix])($request);
        if ($response !== null && !$response instanceof Response) {
            throw new RuntimeException(sprintf(
                'La ruta publica GET/HEAD %s del modulo %s no devolvio una respuesta HTTP valida.',
                $handlerPrefix,
                $this->module
            ));
        }

        return $request->method() === 'HEAD' && $response !== null
            ? $response->withoutBody()
            : $response;
    }

    /**
     * @param array<string, mixed> $prefixes
     */
    private function matchingPrefix(string $path, array $prefixes): ?string
    {
        $matched = null;

        foreach (array_keys($prefixes) as $prefix) {
            if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
                continue;
            }

            if ($matched === null || strlen($prefix) > strlen($matched)) {
                $matched = $prefix;
            }
        }

        return $matched;
    }

    private function assertModule(string $module): void
    {
        if (preg_match('/\A[a-z][a-z0-9-]*\z/', $module) !== 1) {
            throw new InvalidArgumentException(
                'El identificador del modulo no es valido.'
            );
        }
    }

    private function validatePrefix(string $prefix): string
    {
        if (
            $prefix === ''
            || $prefix === '/'
            || !str_starts_with($prefix, '/')
            || str_ends_with($prefix, '/')
            || str_contains($prefix, '?')
            || str_contains($prefix, '#')
            || str_contains($prefix, "\\")
            || str_contains($prefix, '//')
            || preg_match('/[\x00-\x20\x7F]/', $prefix) === 1
            || preg_match('#(?:^|/)\.\.?($|/)#', $prefix) === 1
            || preg_match('//u', $prefix) !== 1
        ) {
            throw new InvalidArgumentException(
                'El prefijo publico no es valido.'
            );
        }

        return $prefix;
    }
}
