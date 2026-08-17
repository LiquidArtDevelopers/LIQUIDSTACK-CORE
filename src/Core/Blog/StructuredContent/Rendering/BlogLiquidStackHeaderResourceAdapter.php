<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use App\Core\Support\Paths;

/** Uses consumer resources only when their controller and template are present. */
final class BlogLiquidStackHeaderResourceAdapter implements
    BlogHeaderResourceAdapterInterface
{
    private const RESOURCE_PATTERN = '/\A[A-Za-z][A-Za-z0-9]*\z/';

    public function supports(array $resources): bool
    {
        if (!function_exists('controller') || !array_is_list($resources)) {
            return false;
        }
        foreach ($resources as $resource) {
            if (!is_string($resource) || !$this->resourceExists($resource)) {
                return false;
            }
        }

        return $resources !== [];
    }

    public function render(string $resource, array $parameters): string
    {
        if (
            preg_match(self::RESOURCE_PATTERN, $resource) !== 1
            || !$this->resourceExists($resource)
            || !function_exists('controller')
        ) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        return \controller($resource, 0, $parameters);
    }

    private function resourceExists(string $resource): bool
    {
        if (preg_match(self::RESOURCE_PATTERN, $resource) !== 1) {
            return false;
        }
        $app = Paths::appPath();

        return is_file($app . '/controllers/' . $resource . '.php')
            && is_readable($app . '/controllers/' . $resource . '.php')
            && is_file($app . '/templates/_' . $resource . '.html')
            && is_readable($app . '/templates/_' . $resource . '.html');
    }
}
