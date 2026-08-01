<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Http\Request;
use App\Core\Http\Response;
use Closure;
use RuntimeException;

final class ModuleRoute
{
    private readonly Closure $handler;

    /**
     * @param callable(Request): Response $handler
     */
    public function __construct(
        private readonly string $module,
        private readonly string $method,
        private readonly string $path,
        callable $handler
    ) {
        $this->handler = Closure::fromCallable($handler);
    }

    public function module(): string
    {
        return $this->module;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function handle(Request $request): Response
    {
        $response = ($this->handler)($request);
        if (!$response instanceof Response) {
            throw new RuntimeException(sprintf(
                'La ruta %s %s del módulo %s no devolvió una respuesta HTTP.',
                $this->method,
                $this->path,
                $this->module
            ));
        }

        return $response;
    }
}
