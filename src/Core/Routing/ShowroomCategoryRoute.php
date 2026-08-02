<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Modules\ModuleRegistry;
use App\Core\Support\Paths;
use Throwable;

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
        'blog',
    ];

    /**
     * @var array<string, string>
     */
    private const REQUIRED_MODULE_BY_CATEGORY = [
        'blog' => 'blog',
    ];

    /**
     * Devuelve únicamente las categorías disponibles para el consumidor.
     * Resolver la selección solo lee composer.json y los manifiestos; no
     * construye providers, sesiones ni conexiones de base de datos.
     *
     * @return list<string>
     */
    public static function availableCategories(
        ?string $projectRoot = null,
        ?string $coreRoot = null
    ): array {
        $available = [];
        $registry = null;

        foreach (self::CATEGORIES as $category) {
            $requiredModule = self::REQUIRED_MODULE_BY_CATEGORY[$category]
                ?? null;
            if ($requiredModule === null) {
                $available[] = $category;
                continue;
            }

            try {
                $registry ??= ModuleRegistry::forProject(
                    $projectRoot ?? Paths::projectRoot(),
                    $coreRoot
                );
                if ($registry->isEnabled($requiredModule)) {
                    $available[] = $category;
                }
            } catch (Throwable) {
                // Una selección inválida oculta únicamente la categoría
                // opcional y conserva operativo el catálogo base.
            }
        }

        return $available;
    }

    /**
     * Resuelve una subruta únicamente cuando cuelga de una ruta de catálogo
     * ya registrada por el proyecto. No convierte ninguna otra ruta en
     * dinámica ni acepta nombres de fichero arbitrarios.
     *
     * @param array<string, array<string, mixed>> $routes
     * @return array<string, mixed>|null
     */
    public static function resolve(
        string $requestedUrl,
        array $routes,
        ?string $projectRoot = null,
        ?string $coreRoot = null
    ): ?array {
        $path = parse_url($requestedUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path === '/') {
            return null;
        }

        $path = rtrim($path, '/');

        foreach (self::availableCategories($projectRoot, $coreRoot) as $category) {
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
