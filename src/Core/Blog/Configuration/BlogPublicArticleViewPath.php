<?php

declare(strict_types=1);

namespace App\Core\Blog\Configuration;

/** Validated project-owned PHP view contained by App/views. */
final class BlogPublicArticleViewPath
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
                'config.invalid_public_article_view',
                'public_article_view'
            );
        }

        $segments = explode('/', $configuredPath);
        if (
            count($segments) < 3
            || $segments[0] !== 'App'
            || $segments[1] !== 'views'
            || in_array('', $segments, true)
        ) {
            throw new BlogConfigException(
                'config.invalid_public_article_view',
                'public_article_view'
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
                    'config.invalid_public_article_view',
                    'public_article_view'
                );
            }
        }

        $root = rtrim($projectRoot, '/\\');
        $views = $root . DIRECTORY_SEPARATOR . 'App'
            . DIRECTORY_SEPARATOR . 'views';
        $cursor = $root;
        foreach ($segments as $segment) {
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor)) {
                throw new BlogConfigException(
                    'config.public_article_view_not_regular',
                    'public_article_view'
                );
            }
        }

        if (!is_file($cursor) || !is_readable($cursor)) {
            throw new BlogConfigException(
                'config.public_article_view_not_regular',
                'public_article_view'
            );
        }

        $resolvedViews = realpath($views);
        $resolvedView = realpath($cursor);
        if (
            !is_string($resolvedViews)
            || !is_string($resolvedView)
            || !self::isWithin($resolvedViews, $resolvedView)
        ) {
            throw new BlogConfigException(
                'config.public_article_view_not_regular',
                'public_article_view'
            );
        }

        return new self($configuredPath, $resolvedView);
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

            return PHP_OS_FAMILY === 'Windows'
                ? strtolower($value)
                : $value;
        };
        $root = $normalize($root);
        $path = $normalize($path);

        return $path === $root || str_starts_with($path, $root . '/');
    }
}
