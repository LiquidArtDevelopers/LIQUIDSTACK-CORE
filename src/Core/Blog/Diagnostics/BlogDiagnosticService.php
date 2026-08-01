<?php

declare(strict_types=1);

namespace App\Core\Blog\Diagnostics;

use App\Core\Blog\Configuration\BlogConfigException;
use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Routing\BlogRoutePolicy;
use App\Core\Modules\Migrations\MigrationDatabasePlan;
use App\Core\WebAdmin\Configuration\WebAdminConfigException;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use Throwable;

final class BlogDiagnosticService
{
    private const REQUIRED_MIGRATIONS = [
        '0001_blog_posts',
        '0002_blog_capabilities',
    ];

    public function __construct(
        private readonly BlogConfigLoader $configLoader =
            new BlogConfigLoader(),
        private readonly BlogRoutePolicy $routePolicy =
            new BlogRoutePolicy(),
        private readonly WebAdminConfigLoader $webAdminConfigLoader =
            new WebAdminConfigLoader()
    ) {
    }

    /**
     * @param list<string> $languages
     * @param array<string, mixed> $environment
     */
    public function inspect(
        string $projectRoot,
        array $languages,
        #[\SensitiveParameter] array $environment,
        ?string $webAdminPrefix,
        ?bool $webAdminRuntimeReady,
        ?MigrationDatabasePlan $databasePlan = null,
        bool $inspectDatabase = true
    ): BlogDiagnosticReport {
        $configurationReady = false;
        $configurationIssues = [];
        $effective = null;
        $routing = [
            'ready' => false,
            'issues' => [[
                'code' => 'configuration.unavailable',
                'key' => 'blog',
            ]],
            'collisions' => [],
        ];

        try {
            $config = $this->configLoader->load($projectRoot, $languages);
            $configurationReady = true;
            $effective = [
                'source' => $config->source(),
                'public_paths' => $config->publicPaths(),
                'sitemap_path' => $config->sitemapPath(),
                'database' => [
                    'connection' => $config->databaseConnection(),
                ],
            ];
            $routing = $this->routePolicy->resolve(
                $projectRoot,
                $config,
                $webAdminPrefix
            )->toArray();

            try {
                $webAdminConfig = $this->webAdminConfigLoader->load(
                    $projectRoot
                );
                if (
                    $webAdminConfig->databaseConnection()
                        !== $config->databaseConnection()
                ) {
                    $configurationReady = false;
                    $configurationIssues[] = [
                        'code' => 'database.connection_mismatch',
                        'key' => 'database.connection',
                    ];
                }
            } catch (WebAdminConfigException) {
                // WebAdmin reports its own configuration error. Blog keeps
                // that state represented through dependency readiness.
            }
        } catch (BlogConfigException $exception) {
            $configurationIssues[] = [
                'code' => $exception->issueCode(),
                'key' => $exception->configKey(),
            ];
        } catch (Throwable) {
            $configurationIssues[] = [
                'code' => 'configuration.unavailable',
                'key' => null,
            ];
        }

        $originReady = false;
        $originIssue = null;
        try {
            BlogPublicOrigin::fromEnvironment($environment);
            $originReady = true;
        } catch (BlogConfigException $exception) {
            $originIssue = [
                'code' => $exception->issueCode(),
                'key' => $exception->configKey(),
            ];
        } catch (Throwable) {
            $originIssue = [
                'code' => 'environment.public_origin_invalid',
                'key' => BlogPublicOrigin::ENV,
            ];
        }

        $database = $this->databaseStatus(
            $databasePlan,
            $inspectDatabase
        );
        $blockers = [];
        if (!$configurationReady) {
            $blockers[] = 'configuration.invalid';
        }
        if (($routing['ready'] ?? false) !== true) {
            $blockers[] = 'routing.invalid';
        }
        if (!$originReady) {
            $blockers[] = 'environment.public_origin_invalid';
        }
        if ($webAdminRuntimeReady === false) {
            $blockers[] = 'dependency.webadmin_not_ready';
        } elseif ($webAdminRuntimeReady === null) {
            $blockers[] = 'dependency.webadmin_not_checked';
        }
        if (($database['ready'] ?? false) !== true) {
            $blockers[] = $inspectDatabase
                ? 'database.migrations_not_ready'
                : 'database.not_checked';
        }

        return new BlogDiagnosticReport([
            'configuration' => [
                'ready' => $configurationReady,
                'effective' => $effective,
                'issues' => $configurationIssues,
            ],
            'environment' => [
                'public_origin' => [
                    'ready' => $originReady,
                    'required_name' => BlogPublicOrigin::ENV,
                    'issue' => $originIssue,
                ],
            ],
            'routing' => $routing,
            'dependency' => [
                'webadmin_runtime_ready' =>
                    $webAdminRuntimeReady === true,
                'status' => $webAdminRuntimeReady === true
                    ? 'ready'
                    : ($webAdminRuntimeReady === false
                        ? 'not_ready'
                        : 'not_checked'),
            ],
            'database' => $database,
            'readiness' => [
                'blog_ready' => $blockers === [],
                'blockers' => array_values(array_unique($blockers)),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function databaseStatus(
        ?MigrationDatabasePlan $plan,
        bool $inspectDatabase
    ): array {
        if (!$inspectDatabase) {
            return [
                'ready' => false,
                'status' => 'not_checked',
                'required_migrations' => self::REQUIRED_MIGRATIONS,
                'pending' => [],
            ];
        }
        if (!$plan instanceof MigrationDatabasePlan) {
            return [
                'ready' => false,
                'status' => 'not_checked',
                'required_migrations' => self::REQUIRED_MIGRATIONS,
                'pending' => [],
            ];
        }

        $states = [];
        foreach ($plan->entries() as $entry) {
            if (
                ($entry['module'] ?? null) === 'blog'
                && is_string($entry['id'] ?? null)
                && is_string($entry['status'] ?? null)
            ) {
                $states[$entry['id']] = $entry['status'];
            }
        }
        $pending = [];
        foreach (self::REQUIRED_MIGRATIONS as $migration) {
            if (($states[$migration] ?? null) !== 'applied') {
                $pending[] = $migration;
            }
        }
        $blocked = !$plan->isApplicable();

        return [
            'ready' => $pending === [] && !$blocked,
            'status' => $blocked
                ? 'blocked'
                : ($pending === [] ? 'applied' : 'pending'),
            'required_migrations' => self::REQUIRED_MIGRATIONS,
            'pending' => $pending,
        ];
    }
}
