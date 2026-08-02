<?php

declare(strict_types=1);

namespace App\Core\Blog\Diagnostics;

use App\Core\Blog\Configuration\BlogConfigException;
use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Routing\BlogRoutePolicy;
use App\Core\Modules\Migrations\MigrationDatabasePlan;
use App\Core\Modules\Migrations\MigrationFeatureReadiness;
use App\Core\Modules\Blog\BlogMigrationRequirements;
use App\Core\Modules\Diagnostics\ProjectAssetInspector;
use App\Core\WebAdmin\Configuration\WebAdminConfigException;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use Throwable;

final class BlogDiagnosticService
{
    public function __construct(
        private readonly BlogConfigLoader $configLoader =
            new BlogConfigLoader(),
        private readonly BlogRoutePolicy $routePolicy =
            new BlogRoutePolicy(),
        private readonly WebAdminConfigLoader $webAdminConfigLoader =
            new WebAdminConfigLoader(),
        private readonly ProjectAssetInspector $assetInspector =
            new ProjectAssetInspector()
    ) {
    }

    /**
     * @param list<string> $languages
     * @param array<string, mixed> $environment
     * @param list<string> $requiredAssets project-relative module assets
     */
    public function inspect(
        string $projectRoot,
        array $languages,
        #[\SensitiveParameter] array $environment,
        ?string $webAdminPrefix,
        ?bool $webAdminRuntimeReady,
        ?MigrationDatabasePlan $databasePlan = null,
        bool $inspectDatabase = true,
        array $requiredAssets = []
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
            if ($config->publicArticleView() !== null) {
                $effective['public_article_view'] =
                    $config->publicArticleView();
            }
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
        $originSource = null;
        $originUsesLegacyCompatibilityOverride = false;
        try {
            $origin = BlogPublicOrigin::fromEnvironment($environment);
            $originReady = true;
            $originSource = $origin->source();
            $originUsesLegacyCompatibilityOverride =
                $origin->usesLegacyCompatibilityOverride();
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
        $assets = $this->assetInspector->inspect(
            $projectRoot,
            $requiredAssets
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
        if (!$assets['ready']) {
            $blockers[] = 'assets.missing_or_invalid';
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
                    'required_name' =>
                        BlogPublicOrigin::PROJECT_ORIGIN_ENV,
                    'issue' => $originIssue,
                    'source' => $originSource,
                    'legacy_compatibility_override' =>
                        $originUsesLegacyCompatibilityOverride,
                ],
            ],
            'routing' => $routing,
            'assets' => $assets,
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
            return $this->uncheckedDatabaseStatus();
        }
        if (!$plan instanceof MigrationDatabasePlan) {
            return $this->uncheckedDatabaseStatus();
        }

        $publicContent = MigrationFeatureReadiness::fromPlan(
            $plan,
            BlogMigrationRequirements::publicContent()
        );
        $administration = MigrationFeatureReadiness::fromPlan(
            $plan,
            BlogMigrationRequirements::administration()
        );
        $administrationBase = $administration->base();

        return [
            'ready' => $administration->baseReady(),
            'status' => $administration->baseStatus(),
            'required_migrations' =>
                $administrationBase['required'],
            'pending' => $administrationBase['pending'],
            'public_content' => $publicContent->base(),
            'administration' => $administrationBase,
            'features' => $administration->features(),
        ];
    }

    /** @return array<string, mixed> */
    private function uncheckedDatabaseStatus(): array
    {
        $publicRequirement = BlogMigrationRequirements::publicContent();
        $adminRequirement = BlogMigrationRequirements::administration();
        $base = static fn (array $required): array => [
            'ready' => false,
            'status' => 'not_checked',
            'required' => $required,
            'pending' => [],
            'missing' => [],
            'blockers' => [],
        ];

        return [
            'ready' => false,
            'status' => 'not_checked',
            'required_migrations' => $adminRequirement->migrationIds(),
            'pending' => [],
            'public_content' => $base(
                $publicRequirement->migrationIds()
            ),
            'administration' => $base(
                $adminRequirement->migrationIds()
            ),
            'features' => [
                'ready' => false,
                'status' => 'not_checked',
                'known' => [],
                'pending' => [],
                'blockers' => [],
            ],
        ];
    }
}
