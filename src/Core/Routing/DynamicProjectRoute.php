<?php

declare(strict_types=1);

namespace App\Core\Routing;

use RuntimeException;

/**
 * Matches project-owned path routes whose dynamic values occupy one complete
 * segment, for example `/news/page/{page}`.
 *
 * Query strings stay outside this boundary. Values are exposed to the view as
 * data and must still be validated by the owning route before use.
 */
final class DynamicProjectRoute
{
    /**
     * @param list<mixed> $routes
     * @return array{route: string, params: array<string, string>}|null
     */
    public static function match(string $path, array $routes): ?array
    {
        if (
            $path === ''
            || !str_starts_with($path, '/')
            || str_contains($path, '?')
            || str_contains($path, '#')
            || str_contains($path, "\\")
            || str_contains($path, '//')
            || preg_match('/[\x00-\x20\x7F]/', $path) === 1
            || preg_match('//u', $path) !== 1
        ) {
            return null;
        }

        $pathSegments = explode('/', ltrim($path, '/'));
        $matched = null;

        foreach ($routes as $route) {
            if (
                !is_string($route)
                || !str_contains($route, '{')
                || str_contains($route, '?')
                || str_contains($route, '#')
            ) {
                continue;
            }

            $routeSegments = explode('/', ltrim($route, '/'));
            if (count($routeSegments) !== count($pathSegments)) {
                continue;
            }

            $params = [];
            $routeMatches = true;
            foreach ($routeSegments as $index => $routeSegment) {
                $pathSegment = $pathSegments[$index] ?? '';
                if (
                    preg_match(
                        '/\A\{([a-z][a-z0-9_]*)\}\z/D',
                        $routeSegment,
                        $parameter
                    ) === 1
                ) {
                    $name = (string) ($parameter[1] ?? '');
                    if (
                        $name === ''
                        || isset($params[$name])
                        || $pathSegment === ''
                        || strlen($pathSegment) > 190
                        || preg_match('/[\p{Cc}\p{Cf}]/u', $pathSegment) === 1
                    ) {
                        $routeMatches = false;
                        break;
                    }
                    $params[$name] = $pathSegment;
                    continue;
                }

                if ($routeSegment !== $pathSegment) {
                    $routeMatches = false;
                    break;
                }
            }

            if (!$routeMatches || $params === []) {
                continue;
            }
            if ($matched !== null) {
                throw new RuntimeException(sprintf(
                    'La ruta dinamica %s coincide con mas de un patron.',
                    $path
                ));
            }

            $matched = ['route' => $route, 'params' => $params];
        }

        return $matched;
    }
}
