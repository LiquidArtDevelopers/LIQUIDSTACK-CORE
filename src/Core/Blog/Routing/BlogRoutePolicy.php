<?php

declare(strict_types=1);

namespace App\Core\Blog\Routing;

use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Routing\StaticRouteCollisionInspector;
use Throwable;

final class BlogRoutePolicy
{
    public function __construct(
        private readonly StaticRouteCollisionInspector $inspector =
            new StaticRouteCollisionInspector()
    ) {
    }

    public function resolve(
        string $projectRoot,
        BlogConfig $configuration,
        ?string $webAdminPrefix
    ): BlogRouteResolution {
        $issues = [];
        $collisions = [];

        if ($webAdminPrefix === null) {
            $issues[] = [
                'code' => 'webadmin.route_unavailable',
                'key' => 'dependency.webadmin',
            ];
        } else {
            foreach (
                $configuration->publicPaths() as $locale => $publicPath
            ) {
                if ($this->pathsOverlap($publicPath, $webAdminPrefix)) {
                    $issues[] = [
                        'code' => 'config.webadmin_route_collision',
                        'key' => 'public_paths.' . $locale,
                    ];
                }
            }
            if ($this->pathsOverlap(
                $configuration->sitemapPath(),
                $webAdminPrefix
            )) {
                $issues[] = [
                    'code' => 'config.webadmin_route_collision',
                    'key' => 'sitemap_path',
                ];
            }
        }

        try {
            $assessment = $this->inspector->inspect(
                $projectRoot,
                $configuration->sitemapPath()
            );
            if (!$assessment['complete']) {
                foreach ($assessment['issues'] as $issue) {
                    $issues[] = [
                        'code' => $issue['code'],
                        'key' => $issue['source'],
                    ];
                }
            }
            foreach ($assessment['collisions'] as $collision) {
                if ($collision['route'] === $configuration->sitemapPath()) {
                    $collisions[] = $collision;
                }
            }
        } catch (Throwable) {
            $issues[] = [
                'code' => 'route_catalog.unavailable',
                'key' => 'sitemap_path',
            ];
        }

        $publicTarget = rtrim($projectRoot, '/\\')
            . '/public'
            . $configuration->sitemapPath();
        if (file_exists($publicTarget) || is_link($publicTarget)) {
            $issues[] = [
                'code' => 'public_file.route_collision',
                'key' => 'sitemap_path',
            ];
        }

        return new BlogRouteResolution(
            $this->uniqueIssues($issues),
            $collisions
        );
    }

    private function pathsOverlap(string $left, string $right): bool
    {
        return $left === $right
            || str_starts_with($left . '/', $right . '/')
            || str_starts_with($right . '/', $left . '/');
    }

    /**
     * @param list<array{code: string, key: string}> $issues
     * @return list<array{code: string, key: string}>
     */
    private function uniqueIssues(array $issues): array
    {
        $unique = [];
        foreach ($issues as $issue) {
            $unique[$issue['code'] . "\0" . $issue['key']] = $issue;
        }

        return array_values($unique);
    }
}
