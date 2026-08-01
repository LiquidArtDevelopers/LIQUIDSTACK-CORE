<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Routing;

use App\Core\Routing\StaticRouteCollisionInspector;
use App\Core\WebAdmin\Configuration\WebAdminConfig;

final class WebAdminRoutePolicy
{
    public function __construct(
        private readonly StaticRouteCollisionInspector $inspector =
            new StaticRouteCollisionInspector()
    ) {
    }

    /**
     * @param list<string> $languages
     */
    public function resolve(
        string $projectRoot,
        string $requestedPath,
        array $languages
    ): WebAdminRouteResolution {
        $issues = [];
        $collisions = [];
        $candidate = $requestedPath;

        $firstSegment = strtolower(
            explode('/', ltrim($requestedPath, '/'))[0] ?? ''
        );
        if (in_array($firstSegment, $languages, true)) {
            $issues[] = [
                'code' => 'config.localized_base_path',
                'key' => 'path',
            ];
            $candidate = WebAdminConfig::DEFAULT_BASE_PATH;
        }

        $assessment = $this->inspector->inspect(
            $projectRoot,
            $candidate
        );
        if (!$assessment['complete']) {
            return new WebAdminRouteResolution(
                $requestedPath,
                null,
                $candidate !== $requestedPath,
                array_merge($issues, $assessment['issues']),
                []
            );
        }

        if ($assessment['collisions'] === []) {
            return new WebAdminRouteResolution(
                $requestedPath,
                $candidate,
                $candidate !== $requestedPath,
                $issues,
                []
            );
        }

        $collisions = $assessment['collisions'];
        $issues[] = [
            'code' => 'config.route_collision',
            'key' => 'path',
        ];

        if ($candidate === WebAdminConfig::DEFAULT_BASE_PATH) {
            return new WebAdminRouteResolution(
                $requestedPath,
                null,
                $candidate !== $requestedPath,
                $issues,
                $collisions
            );
        }

        $fallback = $this->inspector->inspect(
            $projectRoot,
            WebAdminConfig::DEFAULT_BASE_PATH
        );
        if (!$fallback['complete']) {
            return new WebAdminRouteResolution(
                $requestedPath,
                null,
                true,
                array_merge($issues, $fallback['issues']),
                $collisions
            );
        }

        $collisions = array_merge(
            $collisions,
            $fallback['collisions']
        );
        if ($fallback['collisions'] !== []) {
            return new WebAdminRouteResolution(
                $requestedPath,
                null,
                true,
                $issues,
                $collisions
            );
        }

        return new WebAdminRouteResolution(
            $requestedPath,
            WebAdminConfig::DEFAULT_BASE_PATH,
            true,
            $issues,
            $collisions
        );
    }
}
