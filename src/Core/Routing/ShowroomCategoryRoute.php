<?php

declare(strict_types=1);

namespace App\Core\Routing;

final class ShowroomCategoryRoute
{
    /**
     * @var list<string>
     */
    public const CATEGORIES = [
        'heroes',
        'particles',
        'gsap-specials',
        'common',
        'cards-grids',
        'media',
        'forms-interactive',
        'modules-sections',
    ];

    /**
     * Resuelve una subruta únicamente cuando cuelga de una ruta de catálogo
     * ya registrada por el proyecto. No convierte ninguna otra ruta en
     * dinámica ni acepta nombres de fichero arbitrarios.
     *
     * @param array<string, array<string, mixed>> $routes
     * @return array<string, mixed>|null
     */
    public static function resolve(string $requestedUrl, array $routes): ?array
    {
        $path = parse_url($requestedUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path === '/') {
            return null;
        }

        $path = rtrim($path, '/');

        foreach (self::CATEGORIES as $category) {
            $suffix = '/' . $category;
            if (!str_ends_with($path, $suffix)) {
                continue;
            }

            $parentPath = substr($path, 0, -strlen($suffix));
            if (
                $parentPath === ''
                || !isset($routes[$parentPath])
                || !is_array($routes[$parentPath])
            ) {
                return null;
            }

            $parent = $routes[$parentPath];
            if (($parent['resources'] ?? null) !== 'templates') {
                return null;
            }

            $view = str_replace('\\', '/', (string) ($parent['view'] ?? ''));
            if (!in_array(basename($view), ['_showroom.php', '_templates.php'], true)) {
                return null;
            }

            $parent['showroom_category'] = $category;
            $parent['showroom_base_path'] = $parentPath;

            return $parent;
        }

        return null;
    }
}
