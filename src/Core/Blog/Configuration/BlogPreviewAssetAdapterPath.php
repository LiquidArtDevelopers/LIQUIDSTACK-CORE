<?php

declare(strict_types=1);

namespace App\Core\Blog\Configuration;

/** Validated project-owned adapter contained by App/config/modules. */
final class BlogPreviewAssetAdapterPath
{
    private function __construct(
        private readonly string $relativePath,
        private readonly string $absolutePath
    ) {
    }

    public static function fromProject(
        string $projectRoot,
        mixed $configuredPath
    ): self {
        if (
            !is_string($configuredPath)
            || strlen($configuredPath) > 512
            || !str_ends_with($configuredPath, '.php')
        ) {
            throw new BlogConfigException(
                'config.invalid_preview_asset_adapter',
                'preview_asset_adapter'
            );
        }

        $segments = explode('/', $configuredPath);
        if (
            count($segments) < 4
            || $segments[0] !== 'App'
            || $segments[1] !== 'config'
            || $segments[2] !== 'modules'
            || in_array('', $segments, true)
        ) {
            throw new BlogConfigException(
                'config.invalid_preview_asset_adapter',
                'preview_asset_adapter'
            );
        }
        foreach ($segments as $segment) {
            if (
                $segment === '.'
                || $segment === '..'
                || strlen($segment) > 128
                || preg_match(
                    '/\A[a-zA-Z0-9_][a-zA-Z0-9_.-]*\z/D',
                    $segment
                ) !== 1
            ) {
                throw new BlogConfigException(
                    'config.invalid_preview_asset_adapter',
                    'preview_asset_adapter'
                );
            }
        }

        $root = rtrim($projectRoot, '/\\');
        $adapterRoot = $root . DIRECTORY_SEPARATOR . 'App'
            . DIRECTORY_SEPARATOR . 'config'
            . DIRECTORY_SEPARATOR . 'modules';
        $cursor = $root;
        foreach ($segments as $segment) {
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor)) {
                throw new BlogConfigException(
                    'config.preview_asset_adapter_not_regular',
                    'preview_asset_adapter'
                );
            }
        }
        if (!is_file($cursor) || !is_readable($cursor)) {
            throw new BlogConfigException(
                'config.preview_asset_adapter_not_regular',
                'preview_asset_adapter'
            );
        }

        $resolvedRoot = realpath($adapterRoot);
        $resolvedAdapter = realpath($cursor);
        if (
            !is_string($resolvedRoot)
            || !is_string($resolvedAdapter)
            || !self::isWithin($resolvedRoot, $resolvedAdapter)
        ) {
            throw new BlogConfigException(
                'config.preview_asset_adapter_not_regular',
                'preview_asset_adapter'
            );
        }

        return new self($configuredPath, $resolvedAdapter);
    }

    public function relativePath(): string
    {
        return $this->relativePath;
    }

    public function absolutePath(): string
    {
        return $this->absolutePath;
    }

    private static function isWithin(string $root, string $path): bool
    {
        $normalize = static function (string $value): string {
            $value = rtrim(str_replace('\\', '/', $value), '/');

            return PHP_OS_FAMILY === 'Windows' ? strtolower($value) : $value;
        };
        $root = $normalize($root);
        $path = $normalize($path);

        return $path === $root || str_starts_with($path, $root . '/');
    }
}
