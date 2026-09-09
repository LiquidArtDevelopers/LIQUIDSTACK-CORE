<?php

declare(strict_types=1);

namespace App\Core\Blog\Diagnostics;

use App\Core\Blog\Configuration\BlogConfigException;
use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\PublicShell\BlogPublicShellDefaultSecurityPolicy;
use App\Core\Blog\PublicShell\BlogPublicShellSecurityConfig;
use App\Core\Blog\Routing\BlogRoutePolicy;
use App\Core\Blog\Sitemap\Cache\PrivateBlogSitemapCacheStorage;
use App\Core\Blog\Sitemap\Persistence\PdoBlogSitemapStateRepository;
use App\Core\Environment\ProjectRuntimeProfile;
use App\Core\Modules\Migrations\MigrationDatabasePlan;
use App\Core\Modules\Migrations\MigrationFeatureReadiness;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\Blog\BlogMigrationRequirements;
use App\Core\Modules\Blog\BlogTagCapabilitySeedPostcondition;
use App\Core\Modules\Blog\BlogTagSchemaMigrationPostconditionVerifier;
use App\Core\Modules\Diagnostics\ProjectAssetInspector;
use App\Core\WebAdmin\Configuration\WebAdminConfigException;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use App\Core\WebAdmin\Security\InvalidSecurityKey;
use App\Core\WebAdmin\Security\SecurityKey;
use DOMDocument;
use PDO;
use Throwable;

final class BlogDiagnosticService
{
    private const PROJECT_ARTICLE_VIEW = 'App/views/blog-article.php';
    private const PROJECT_ARTICLE_HOOK =
        'App/app/_moduleBlogPublicArticle.php';
    private const PROJECT_ARTICLE_SOURCE_ENTRY = 'src/js/blogArticle.js';
    private const PRODUCTION_MANIFEST = 'public/.vite/manifest.json';
    private const COOKIE_LAD_ORIGIN = 'https://webda.eus';
    private const MAX_PRODUCTION_MANIFEST_NODES = 256;
    private const MAX_PRODUCTION_MANIFEST_REFERENCES = 1024;
    private const MAX_PRODUCTION_MANIFEST_ASSETS = 1024;
    private const PROJECT_SHELL_DEPENDENCIES = [
        'src/js/_global.js',
        'src/js/resources/_languagePreference.mjs',
        'src/scss/blogArticle.scss',
    ];
    private const CANONICAL_SHELL_DEPENDENCIES = [
        'App/includes/_globalHead.php',
        'App/includes/_globalBody.php',
        'App/includes/_nav.php',
        'App/includes/_footer.php',
        'App/controllers/_moduleBlogResources.php',
        'App/controllers/sectionBlogRelated01.php',
        'App/templates/_sectionBlogRelated01.html',
        'App/controllers/moduleButtonType04.php',
        'App/templates/_moduleButtonType04.html',
        'src/scss/_config.scss',
        'src/scss/_global.scss',
        'src/scss/resources/_hero00.scss',
        'src/scss/resources/_hero06.scss',
        'src/scss/resources/_hero07.scss',
        'src/scss/resources/_moduleH1Type01.scss',
        'src/scss/resources/_moduleH1Type03.scss',
        'src/scss/resources/_moduleH1Type04.scss',
        'src/scss/resources/_artBlogArticle01.scss',
        'src/scss/resources/_moduleButtonType04.scss',
        'src/scss/resources/_sectionBlogRelated01.scss',
    ];
    private const PAGE_META_KEYS = [
        'title',
        'headline',
        'description',
        'canonical',
        'alternates',
        'x_default',
        'type',
        'image',
        'published_at',
        'updated_at',
    ];

    public function __construct(
        private readonly BlogConfigLoader $configLoader =
            new BlogConfigLoader(),
        private readonly BlogRoutePolicy $routePolicy =
            new BlogRoutePolicy(),
        private readonly WebAdminConfigLoader $webAdminConfigLoader =
            new WebAdminConfigLoader(),
        private readonly ProjectAssetInspector $assetInspector =
            new ProjectAssetInspector(),
        private readonly ?bool $domExtensionAvailable = null
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
        array $requiredAssets = [],
        ?PDO $databaseConnection = null
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
        $sitemapCacheEnabled = false;
        $analyticsEnabled = false;
        $analyticsCollectInDevelopment = false;
        $sitemapTablePrefix = null;
        $webAdminTablePrefix = null;
        $publicArticleView = null;
        $publicArticleViewInvalid = false;

        try {
            $config = $this->configLoader->load($projectRoot, $languages);
            $configurationReady = true;
            $sitemapCacheEnabled = $config->sitemapCache()->enabled();
            $analyticsEnabled = $config->analytics()->enabled();
            $analyticsCollectInDevelopment = $config->analytics()
                ->collectInDevelopment();
            $sitemapTablePrefix = $config->tablePrefix();
            $publicArticleView = $config->publicArticleView();
            $effective = [
                'source' => $config->source(),
                'public_paths' => $config->publicPaths(),
                'sitemap_path' => $config->sitemapPath(),
                'database' => [
                    'connection' => $config->databaseConnection(),
                ],
                'sitemap_cache' => $config->sitemapCache()->toSafeArray(),
                'analytics' => $config->analytics()->toSafeArray(),
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
                $webAdminTablePrefix = $webAdminConfig->tablePrefix();
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
            $publicArticleViewInvalid =
                $exception->configKey() === 'public_article_view';
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
        $tags = $this->tagStatus(
            $databasePlan,
            $inspectDatabase,
            $databaseConnection,
            $sitemapTablePrefix,
            $webAdminTablePrefix
        );
        $sitemapCache = $this->sitemapCacheStatus(
            $projectRoot,
            $environment,
            $sitemapCacheEnabled,
            $databasePlan,
            $inspectDatabase,
            $sitemapTablePrefix,
            $databaseConnection
        );
        $analytics = $this->analyticsStatus(
            $analyticsEnabled,
            $analyticsCollectInDevelopment,
            $environment,
            $databasePlan,
            $inspectDatabase
        );
        $assets = $this->assetInspector->inspect(
            $projectRoot,
            $requiredAssets
        );
        $publicShell = $this->publicShellStatus(
            $projectRoot,
            $languages,
            $environment,
            $publicArticleView,
            $publicArticleViewInvalid,
            is_array($effective)
        );
        $domExtensionReady = $this->domExtensionAvailable
            ?? (
                extension_loaded('dom')
                && class_exists(DOMDocument::class, false)
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
        if (is_string($tags['blocker'] ?? null)) {
            $blockers[] = $tags['blocker'];
        }
        if (
            ($sitemapCache['enabled'] ?? false) === true
            && (
                ($sitemapCache['ready'] ?? false) !== true
                || ($sitemapCache['status'] ?? null) === 'blocked'
            )
        ) {
            $blockers[] = 'sitemap_cache.not_ready';
        }
        if (
            ($analytics['enabled'] ?? false) === true
            && ($analytics['ready'] ?? false) !== true
        ) {
            $blockers[] = 'analytics.not_ready';
        }
        if (!$assets['ready']) {
            $blockers[] = 'assets.missing_or_invalid';
        }
        if (!$domExtensionReady) {
            $blockers[] = 'runtime.dom_extension_missing';
        }
        if (
            ($publicShell['mode'] ?? null) === 'project'
            && ($publicShell['ready'] ?? false) !== true
        ) {
            $blockers[] = 'public_shell.project_not_ready';
        }
        if (
            ($publicShell['security_config']['ready'] ?? false) !== true
            && in_array(
                $publicShell['mode'] ?? null,
                ['standalone', 'project'],
                true
            )
        ) {
            $blockers[] = 'public_shell.security_not_ready';
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
            'public_shell' => $publicShell,
            'runtime' => [
                'dom_extension' => [
                    'extension' => 'dom',
                    'ready' => $domExtensionReady,
                    'status' => $domExtensionReady ? 'ready' : 'missing',
                ],
            ],
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
            'sitemap_cache' => $sitemapCache,
            'analytics' => $analytics,
            'tags' => $tags,
            'readiness' => [
                'blog_ready' => $blockers === [],
                'blockers' => array_values(array_unique($blockers)),
            ],
        ]);
    }

    /**
     * @param list<string> $languages
     * @param array<string, mixed> $environment
     * @return array<string, mixed>
     */
    private function publicShellStatus(
        string $projectRoot,
        array $languages,
        #[\SensitiveParameter] array $environment,
        ?string $articleView,
        bool $articleViewInvalid,
        bool $configurationAvailable
    ): array {
        $viewPresent = !$articleViewInvalid
            && is_string($articleView)
            && $this->projectFilePresent($projectRoot, $articleView);
        $cookieLadDetected = $viewPresent
            && is_string($articleView)
            && $this->projectShellUsesCookieLad(
                $projectRoot,
                $articleView
            );
        $security = $this->publicShellSecurityStatus(
            $projectRoot,
            $environment,
            $cookieLadDetected
        );
        $hookPresent = $this->projectFilePresent(
            $projectRoot,
            self::PROJECT_ARTICLE_HOOK
        );
        $sourcePresent = $this->projectFilePresent(
            $projectRoot,
            self::PROJECT_ARTICLE_SOURCE_ENTRY
        );

        if (!$configurationAvailable && !$articleViewInvalid) {
            return [
                'mode' => 'unavailable',
                'ready' => false,
                'complete' => false,
                'status' => 'configuration_unavailable',
                'view' => $this->shellComponent(null, false, 'not_checked'),
                'hook' => $this->shellComponent(
                    self::PROJECT_ARTICLE_HOOK,
                    false,
                    'not_checked'
                ),
                'source_entry' => $this->shellComponent(
                    self::PROJECT_ARTICLE_SOURCE_ENTRY,
                    false,
                    'not_checked'
                ),
                'dependencies' =>
                    $this->inactiveShellDependencies('not_checked'),
                'metadata_head' =>
                    $this->shellComponent(null, false, 'not_checked'),
                'security_config' => $security,
                'production_manifest' =>
                    $this->inactiveProductionManifest('not_checked', null),
                'recommendation' => null,
                'issues' => [[
                    'code' => 'public_shell.configuration_unavailable',
                    'blocking' => true,
                ]],
            ];
        }

        if ($articleView === null && !$articleViewInvalid) {
            $issues = [[
                'code' => 'public_shell.project_view_recommended',
                'blocking' => false,
            ]];
            if (($security['ready'] ?? false) !== true) {
                $issues[] = [
                    'code' => 'public_shell.security_config_invalid',
                    'blocking' => true,
                ];
            }

            return [
                'mode' => 'standalone',
                'ready' => ($security['ready'] ?? false) === true,
                'complete' => false,
                'status' => ($security['ready'] ?? false) === true
                    ? 'standalone' : 'incomplete',
                'view' => $this->shellComponent(
                    null,
                    false,
                    'not_configured'
                ),
                'hook' => $this->shellComponent(
                    self::PROJECT_ARTICLE_HOOK,
                    $hookPresent,
                    $hookPresent ? 'available' : 'not_required'
                ),
                'source_entry' => $this->shellComponent(
                    self::PROJECT_ARTICLE_SOURCE_ENTRY,
                    $sourcePresent,
                    $sourcePresent ? 'available' : 'not_required'
                ),
                'dependencies' =>
                    $this->inactiveShellDependencies('not_applicable'),
                'metadata_head' =>
                    $this->shellComponent(null, true, 'not_applicable'),
                'security_config' => $security,
                'production_manifest' =>
                    $this->inactiveProductionManifest(
                        'not_applicable',
                        false
                    ),
                'recommendation' =>
                    'public_shell.configure_project_view',
                'issues' => $issues,
            ];
        }

        $viewReady = $viewPresent
            && is_string($articleView)
            && $this->validArticleViewContract($projectRoot, $articleView);
        $hookReady = $hookPresent
            && $this->validArticleHookContract($projectRoot);
        $sourceReady = $sourcePresent
            && $this->validArticleEntryContract($projectRoot);
        $runtimeEnvironment = 'unknown';
        try {
            $profile = ProjectRuntimeProfile::fromEnvironment($environment);
            $runtimeEnvironment = $profile->isDevelopmentLoopbackHttp()
                ? 'development' : 'production';
        } catch (Throwable) {
            // The issue is represented below without exposing environment.
        }
        $manifest = $this->productionManifestStatus(
            $projectRoot,
            $runtimeEnvironment
        );
        $dependencies = is_string($articleView) && $viewPresent
            ? $this->projectShellDependencies(
                $projectRoot,
                $languages,
                $articleView
            )
            : $this->inactiveShellDependencies('not_checked');
        $metadataHead = $dependencies['metadata_head']
            ?? $this->shellComponent(null, false, 'not_checked');
        $issues = [];
        foreach ([
            'public_shell.view_missing_or_invalid' => $viewReady,
            'public_shell.hook_missing_or_invalid' => $hookReady,
            'public_shell.source_entry_missing_or_invalid' => $sourceReady,
            'public_shell.dependencies_missing_or_invalid' =>
                ($dependencies['ready'] ?? false) === true,
            'public_shell.metadata_head_incompatible' =>
                ($metadataHead['ready'] ?? false) === true,
            'public_shell.runtime_profile_unavailable' =>
                $runtimeEnvironment !== 'unknown',
            'public_shell.production_bundle_not_ready' =>
                ($manifest['ready'] ?? false) === true,
        ] as $code => $componentReady) {
            if (!$componentReady) {
                $issues[] = ['code' => $code, 'blocking' => true];
            }
        }
        if (($security['status'] ?? null) === 'invalid') {
            $issues[] = [
                'code' => 'public_shell.security_config_invalid',
                'blocking' => true,
            ];
        } elseif (
            ($security['cookie_lad']['detected'] ?? false) === true
            && ($security['cookie_lad']['ready'] ?? false) !== true
        ) {
            $issues[] = [
                'code' => 'public_shell.cookielad_csp_not_ready',
                'blocking' => true,
            ];
        }
        $ready = $issues === [];

        return [
            'mode' => 'project',
            'ready' => $ready,
            'complete' => $ready,
            'status' => $ready ? 'ready' : 'incomplete',
            'view' => $this->shellComponent(
                $articleView,
                $viewReady,
                $viewReady
                    ? 'ready'
                    : ($viewPresent ? 'contract_invalid' : 'missing_or_invalid')
            ),
            'hook' => $this->shellComponent(
                self::PROJECT_ARTICLE_HOOK,
                $hookReady,
                $hookReady
                    ? 'ready'
                    : ($hookPresent ? 'contract_invalid' : 'missing_or_invalid')
            ),
            'source_entry' => $this->shellComponent(
                self::PROJECT_ARTICLE_SOURCE_ENTRY,
                $sourceReady,
                $sourceReady
                    ? 'ready'
                    : ($sourcePresent ? 'contract_invalid' : 'missing_or_invalid')
            ),
            'dependencies' => $dependencies,
            'metadata_head' => $metadataHead,
            'security_config' => $security,
            'production_manifest' => $manifest,
            'recommendation' => $ready
                ? null : 'public_shell.complete_project_view',
            'issues' => $issues,
        ];
    }

    /**
     * @return array{path: ?string, ready: bool, status: string}
     */
    private function shellComponent(
        ?string $path,
        bool $ready,
        string $status
    ): array {
        return ['path' => $path, 'ready' => $ready, 'status' => $status];
    }

    /** @return array<string, mixed> */
    private function inactiveShellDependencies(string $status): array
    {
        return [
            'profile' => $status,
            'ready' => $status === 'not_applicable',
            'required' => [],
            'missing' => [],
            'invalid' => [],
        ];
    }

    /**
     * @param list<string> $languages
     * @return array<string, mixed>
     */
    private function projectShellDependencies(
        string $projectRoot,
        array $languages,
        string $articleView
    ): array {
        $canonical = $articleView === self::PROJECT_ARTICLE_VIEW;
        $required = self::PROJECT_SHELL_DEPENDENCIES;
        if ($canonical) {
            $required = array_merge(
                $required,
                self::CANONICAL_SHELL_DEPENDENCIES
            );
        }
        $catalogs = [];
        foreach ($languages as $language) {
            if (
                !is_string($language)
                || preg_match('/\A[A-Za-z0-9_-]{1,32}\z/D', $language) !== 1
            ) {
                continue;
            }
            $catalogs[] = 'App/config/languages/global/'
                . $language . '.json';
        }
        $required = array_values(array_unique(array_merge(
            $required,
            $catalogs
        )));
        $inspection = $this->assetInspector->inspect(
            $projectRoot,
            $required
        );
        $invalid = $inspection['invalid'];
        foreach ($catalogs as $catalog) {
            if (
                in_array($catalog, $inspection['missing'], true)
                || !$this->validProjectJsonObject($projectRoot, $catalog)
            ) {
                if (!in_array($catalog, $inspection['missing'], true)) {
                    $invalid[] = $catalog;
                }
            }
        }

        $metadataHead = $canonical || $this->viewUsesGlobalHead(
            $projectRoot,
            $articleView
        )
            ? $this->metadataHeadStatus($projectRoot)
            : $this->shellComponent(null, true, 'project_owned');
        $invalid = array_values(array_unique($invalid));
        $ready = $inspection['missing'] === []
            && $invalid === []
            && ($metadataHead['ready'] ?? false) === true;

        return [
            'profile' => $canonical ? 'canonical' : 'project_owned',
            'ready' => $ready,
            'required' => $required,
            'missing' => $inspection['missing'],
            'invalid' => $invalid,
            'metadata_head' => $metadataHead,
        ];
    }

    /** @return array{path: ?string, ready: bool, status: string} */
    private function metadataHeadStatus(string $projectRoot): array
    {
        $path = 'App/includes/_globalHead.php';
        $source = $this->projectFileContents($projectRoot, $path);
        if ($source === null) {
            return $this->shellComponent($path, false, 'missing_or_invalid');
        }
        $tokens = $this->phpTokens($source);
        $ready = is_array($tokens)
            && $this->globalHeadConsumesPageMeta($tokens)
            && $this->hasCspNonceScriptContract($tokens);

        return $this->shellComponent(
            $path,
            $ready,
            $ready ? 'ready' : 'contract_missing'
        );
    }

    /**
     * @param list<array{id: int|null, text: string, start: int, end: int,
     *     interpolated: bool}> $tokens
     */
    private function globalHeadConsumesPageMeta(array $tokens): bool
    {
        $keys = $this->pageMetaKeys($tokens, false);
        foreach (self::PAGE_META_KEYS as $key) {
            if (!isset($keys[$key])) {
                return false;
            }
        }

        return true;
    }

    private function validArticleViewContract(
        string $projectRoot,
        string $articleView
    ): bool {
        $source = $this->projectFileContents($projectRoot, $articleView);
        $tokens = is_string($source) ? $this->phpTokens($source) : null;
        if (!is_array($tokens)) {
            return false;
        }
        $html = $this->htmlLandmarks($tokens);
        $hook = $this->staticDirIncludeOffset(
            $tokens,
            '/../app/_moduleBlogPublicArticle.php',
            [T_REQUIRE, T_REQUIRE_ONCE]
        );
        $doctype = $html['doctype'] ?? null;
        if (
            !is_int($hook)
            || !is_int($doctype)
            || $hook >= $doctype
            || !isset($html['head_open'], $html['head_close'])
            || !isset($html['body_open'], $html['body_close'])
            || !(
                $doctype < $html['head_open']
                && $html['head_open'] < $html['head_close']
                && $html['head_close'] < $html['body_open']
                && $html['body_open'] < $html['body_close']
            )
        ) {
            return false;
        }
        $componentOffsets = [];
        foreach ([
            'hero' => ['$articleHero', '->headerHtml()'],
            'main' => ['$articleMain', '->mainHtml()'],
            'custom_css' => ['$articleCustomCss', '->customCss()'],
            'runtime' => ['$blogPublicRuntimeUrl', '->publicRuntimeUrl()'],
        ] as $name => $alternatives) {
            $offset = $this->echoedSemanticOffset($tokens, $alternatives);
            if (!is_int($offset)) {
                return false;
            }
            $componentOffsets[$name] = $offset;
        }
        $taxonomies = $this->echoedSemanticOffset(
            $tokens,
            ['$articleTaxonomiesHtml']
        );
        if (!is_int($taxonomies)) {
            return false;
        }
        foreach ([
            'data-blog-analytics-enabled',
            'data-blog-analytics-retention-days',
            'data-blog-analytics-session-timeout',
            'data-blog-analytics-page-grant',
        ] as $required) {
            $offset = $this->inlineHtmlPatternOffset(
                $tokens,
                '/\b' . preg_quote($required, '/') . '\s*=/'
            );
            if (!is_int($offset)
                || !($doctype < $offset && $offset < $html['head_open'])) {
                return false;
            }
        }
        $smoothWrapper = $this->inlineHtmlPatternOffset(
            $tokens,
            '/\bid\s*=\s*([\'"])smooth-wrapper\1/i'
        );
        $smoothContent = $this->inlineHtmlPatternOffset(
            $tokens,
            '/\bid\s*=\s*([\'"])smooth-content\1/i'
        );
        if (
            !is_int($smoothWrapper)
            || !is_int($smoothContent)
            || !(
                $html['head_open'] < $componentOffsets['custom_css']
                && $componentOffsets['custom_css'] < $html['head_close']
                && $html['head_open'] < $componentOffsets['runtime']
                && $componentOffsets['runtime'] < $html['head_close']
                && $html['body_open'] < $smoothWrapper
                && $smoothWrapper < $smoothContent
                && $smoothContent < $componentOffsets['hero']
                && $componentOffsets['hero'] < $taxonomies
                && $taxonomies < $componentOffsets['main']
                && $componentOffsets['main'] < $html['body_close']
            )
        ) {
            return false;
        }

        if ($articleView === self::PROJECT_ARTICLE_VIEW) {
            $headOpen = $html['head_open'];
            $globalHead = $this->staticDirIncludeOffset(
                $tokens,
                '/../includes/_globalHead.php'
            );
            $headClose = $html['head_close'];
            $bodyOpen = $html['body_open'];
            $globalBody = $this->staticDirIncludeOffset(
                $tokens,
                '/../includes/_globalBody.php'
            );
            $navigation = $this->staticDirIncludeOffset(
                $tokens,
                '/../includes/_nav.php'
            );
            $footer = $this->staticDirIncludeOffset(
                $tokens,
                '/../includes/_footer.php'
            );
            $bodyClose = $html['body_close'];
            foreach ([
                $headOpen,
                $globalHead,
                $headClose,
                $bodyOpen,
                $globalBody,
                $navigation,
                $footer,
                $bodyClose,
            ] as $landmark) {
                if (!is_int($landmark)) {
                    return false;
                }
            }
            if (
                !($headOpen < $globalHead && $globalHead < $headClose)
                || !(
                    $bodyOpen < $globalBody
                    && $globalBody < $navigation
                    && $navigation < $smoothWrapper
                    && $componentOffsets['main'] < $footer
                    && $footer < $bodyClose
                )
            ) {
                return false;
            }
        }

        return $this->hasCspNonceScriptContract($tokens);
    }

    private function validArticleHookContract(string $projectRoot): bool
    {
        $source = $this->projectFileContents(
            $projectRoot,
            self::PROJECT_ARTICLE_HOOK
        );
        $tokens = is_string($source) ? $this->phpTokens($source) : null;
        if (!is_array($tokens)) {
            return false;
        }
        $semantic = $this->semanticPhpSource($tokens);
        foreach ([
            'BlogPublicArticleViewModel',
            'BlogPublicArticleShellContext',
        ] as $class) {
            if (preg_match(
                '/\buse\s+[^;]*\b' . preg_quote($class, '/') . '\s*;/s',
                $semantic
            ) !== 1) {
                return false;
            }
        }
        foreach ([
            '$cspNonce' => 'nonce',
            '$blogPublicRuntimeUrl' => 'publicRuntimeUrl',
            '$articleHero' => 'headerHtml',
            '$articleMain' => 'mainHtml',
            '$articleCustomCss' => 'customCss',
        ] as $target => $method) {
            if (!$this->hasPhpAssignment($tokens, $target, $method)) {
                return false;
            }
        }
        foreach (['$articleTaxonomiesHtml', '$relatedArticles'] as $target) {
            if (!$this->hasPhpAssignment($tokens, $target)) {
                return false;
            }
        }

        return isset($this->pageMetaKeys($tokens)['headline']);
    }

    private function validArticleEntryContract(string $projectRoot): bool
    {
        $source = $this->projectFileContents(
            $projectRoot,
            self::PROJECT_ARTICLE_SOURCE_ENTRY
        );
        if ($source === null) {
            return false;
        }
        $lexicalSource = $this->javascriptLexicalSource($source);
        $languageBinding = is_array($lexicalSource)
            ? $this->articleEntryLanguageBinding($source, '')
            : null;
        if ($languageBinding === null || $lexicalSource === null) {
            return false;
        }
        $source = preg_replace('#/\*.*?\*/#s', ' ', $source) ?? '';
        $source = preg_replace('#^\s*//.*$#m', ' ', $source) ?? '';
        foreach ([
            '../scss/blogArticle.scss',
            './_global.js',
        ] as $import) {
            if (
                preg_match(
                    '/^\s*import\s*([\'"])'
                        . preg_quote($import, '/')
                        . '\1\s*;?\s*$/m',
                    $source
                ) !== 1
            ) {
                return false;
            }
        }
        return preg_match(
            '/^\s*(?:(?:const|let|var)\s+[A-Za-z_$][A-Za-z0-9_$]*'
                . '\s*=\s*)?' . preg_quote($languageBinding, '/') . '\s*\('
                . '\s*window\s*,\s*document\s*\)\s*;?\s*$/m',
            $lexicalSource['code']
        ) === 1;
    }

    private function articleEntryLanguageBinding(
        string $source,
        string $activeSource
    ): ?string
    {
        $lexicalSource = $this->javascriptLexicalSource($source);
        if ($lexicalSource === null) {
            return null;
        }
        $tokens = $this->javascriptTokens(
            $lexicalSource['code'],
            $lexicalSource['strings']
        );
        $binding = null;
        $curlyDepth = 0;
        $parenthesisDepth = 0;
        $bracketDepth = 0;
        foreach ($tokens as $index => $token) {
            if (
                $token['type'] === 'identifier'
                && $token['text'] === 'import'
                && $token['line_start']
                && $curlyDepth === 0
                && $parenthesisDepth === 0
                && $bracketDepth === 0
            ) {
                $import = $this->staticJavascriptImport($tokens, $index);
                if (
                    is_array($import)
                    && $import['module']
                        === './resources/_languagePreference.mjs'
                ) {
                    if (
                        !$import['named_only']
                        || !$import['valid']
                        || $import['local'] === null
                        || $binding !== null
                    ) {
                        return null;
                    }
                    $binding = $import['local'];
                }
            }
            if ($token['text'] === '{') {
                ++$curlyDepth;
            } elseif ($token['text'] === '}') {
                $curlyDepth = max(0, $curlyDepth - 1);
            } elseif ($token['text'] === '(') {
                ++$parenthesisDepth;
            } elseif ($token['text'] === ')') {
                $parenthesisDepth = max(0, $parenthesisDepth - 1);
            } elseif ($token['text'] === '[') {
                ++$bracketDepth;
            } elseif ($token['text'] === ']') {
                $bracketDepth = max(0, $bracketDepth - 1);
            }
        }

        return $binding;
    }

    /**
     * @return array{code: string, strings: array<string, string|null>}|null
     */
    private function javascriptLexicalSource(string $source): ?array
    {
        $code = '';
        $strings = [];
        $length = strlen($source);
        for ($index = 0; $index < $length; ++$index) {
            $character = $source[$index];
            $next = $source[$index + 1] ?? null;
            if ($character === '/' && $next === '/') {
                $code .= '  ';
                ++$index;
                while ($index + 1 < $length
                    && $source[$index + 1] !== "\n") {
                    ++$index;
                    $code .= $source[$index] === "\r" ? "\r" : ' ';
                }
                continue;
            }
            if ($character === '/' && $next === '*') {
                $code .= '  ';
                $index += 2;
                $closed = false;
                for (; $index < $length; ++$index) {
                    if (
                        $source[$index] === '*'
                        && ($source[$index + 1] ?? null) === '/'
                    ) {
                        $code .= '  ';
                        ++$index;
                        $closed = true;
                        break;
                    }
                    $code .= in_array(
                        $source[$index],
                        ["\r", "\n"],
                        true
                    ) ? $source[$index] : ' ';
                }
                if (!$closed) {
                    return null;
                }
                continue;
            }
            if ($character === '`') {
                $code .= ' ';
                $closed = false;
                for (++$index; $index < $length; ++$index) {
                    $candidate = $source[$index];
                    if ($candidate === '\\') {
                        $code .= ' ';
                        if (++$index >= $length) {
                            return null;
                        }
                        $code .= in_array(
                            $source[$index],
                            ["\r", "\n"],
                            true
                        ) ? $source[$index] : ' ';
                        continue;
                    }
                    $code .= in_array(
                        $candidate,
                        ["\r", "\n"],
                        true
                    ) ? $candidate : ' ';
                    if ($candidate === '`') {
                        $closed = true;
                        break;
                    }
                }
                if (!$closed) {
                    return null;
                }
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                $value = '';
                $escaped = false;
                $closed = false;
                for (++$index; $index < $length; ++$index) {
                    $candidate = $source[$index];
                    if ($candidate === '\\') {
                        $escaped = true;
                        if (++$index >= $length) {
                            return null;
                        }
                        if ($source[$index] === "\r") {
                            $code .= "\r";
                            if (($source[$index + 1] ?? null) === "\n") {
                                ++$index;
                                $code .= "\n";
                            }
                        } elseif ($source[$index] === "\n") {
                            $code .= "\n";
                        }
                        continue;
                    }
                    if ($candidate === $quote) {
                        $closed = true;
                        break;
                    }
                    if ($candidate === "\r" || $candidate === "\n") {
                        return null;
                    }
                    $value .= $candidate;
                }
                if (!$closed) {
                    return null;
                }
                $placeholder = '__LIQUIDSTACK_JS_STRING_'
                    . count($strings) . '__';
                $strings[$placeholder] = $escaped ? null : $value;
                $code .= ' ' . $placeholder . ' ';
                continue;
            }
            $code .= $character;
        }

        return ['code' => $code, 'strings' => $strings];
    }

    /**
     * @param array<string, string|null> $strings
     * @return list<array{
     *     type: 'identifier'|'string'|'punctuator',
     *     text: string,
     *     literal: string|null,
     *     line: int,
     *     line_start: bool
     * }>
     */
    private function javascriptTokens(string $code, array $strings): array
    {
        $tokens = [];
        $length = strlen($code);
        $line = 1;
        $lineHasToken = false;
        for ($index = 0; $index < $length;) {
            $character = $code[$index];
            if (ctype_space($character)) {
                if ($character === "\n") {
                    ++$line;
                    $lineHasToken = false;
                }
                ++$index;
                continue;
            }
            $lineStart = !$lineHasToken;
            if (
                preg_match('/[A-Za-z_$]/', $character) === 1
            ) {
                $start = $index++;
                while ($index < $length
                    && preg_match('/[A-Za-z0-9_$]/', $code[$index]) === 1) {
                    ++$index;
                }
                $text = substr($code, $start, $index - $start);
                $isString = array_key_exists($text, $strings);
                $tokens[] = [
                    'type' => $isString ? 'string' : 'identifier',
                    'text' => $text,
                    'literal' => $isString ? $strings[$text] : null,
                    'line' => $line,
                    'line_start' => $lineStart,
                ];
                $lineHasToken = true;
                continue;
            }
            $tokens[] = [
                'type' => 'punctuator',
                'text' => $character,
                'literal' => null,
                'line' => $line,
                'line_start' => $lineStart,
            ];
            $lineHasToken = true;
            ++$index;
        }

        return $tokens;
    }

    /**
     * @param list<array{
     *     type: 'identifier'|'string'|'punctuator',
     *     text: string,
     *     literal: string|null,
     *     line: int,
     *     line_start: bool
     * }> $tokens
     * @return array{
     *     module: string|null,
     *     named_only: bool,
     *     valid: bool,
     *     local: string|null
     * }|null
     */
    private function staticJavascriptImport(array $tokens, int $index): ?array
    {
        $cursor = $index + 1;
        if (!isset($tokens[$cursor])) {
            return null;
        }
        if ($tokens[$cursor]['type'] === 'string') {
            return [
                'module' => $tokens[$cursor]['literal'],
                'named_only' => false,
                'valid' => false,
                'local' => null,
            ];
        }
        if ($tokens[$cursor]['text'] !== '{') {
            return $this->nonNamedJavascriptImport($tokens, $cursor);
        }

        ++$cursor;
        $valid = true;
        $local = null;
        $expectsSpecifier = true;
        while (isset($tokens[$cursor]) && $tokens[$cursor]['text'] !== '}') {
            if (!$expectsSpecifier
                || $tokens[$cursor]['type'] !== 'identifier') {
                $valid = false;
                break;
            }
            $imported = $tokens[$cursor]['text'];
            $candidateLocal = $imported;
            ++$cursor;
            if (($tokens[$cursor]['text'] ?? null) === 'as') {
                ++$cursor;
                if (($tokens[$cursor]['type'] ?? null) !== 'identifier') {
                    $valid = false;
                    break;
                }
                $candidateLocal = $tokens[$cursor]['text'];
                ++$cursor;
            }
            if ($imported === 'bindLanguageNavigation') {
                if ($local !== null) {
                    $valid = false;
                    break;
                }
                $local = $candidateLocal;
            }
            if (($tokens[$cursor]['text'] ?? null) === ',') {
                ++$cursor;
                $expectsSpecifier = true;
                if (($tokens[$cursor]['text'] ?? null) === '}') {
                    break;
                }
                continue;
            }
            $expectsSpecifier = false;
            if (($tokens[$cursor]['text'] ?? null) !== '}') {
                $valid = false;
            }
            break;
        }
        if (($tokens[$cursor]['text'] ?? null) !== '}') {
            return [
                'module' => null,
                'named_only' => true,
                'valid' => false,
                'local' => null,
            ];
        }
        ++$cursor;
        if (($tokens[$cursor]['text'] ?? null) !== 'from') {
            return [
                'module' => null,
                'named_only' => true,
                'valid' => false,
                'local' => null,
            ];
        }
        ++$cursor;
        $module = ($tokens[$cursor]['type'] ?? null) === 'string'
            ? $tokens[$cursor]['literal'] : null;
        if ($module === null) {
            $valid = false;
        }
        if ($module !== null
            && !$this->javascriptImportEndsAtStatement($tokens, $cursor)) {
            $valid = false;
        }

        return [
            'module' => $module,
            'named_only' => true,
            'valid' => $valid && $local !== null,
            'local' => $local,
        ];
    }

    /**
     * @param list<array<string, mixed>> $tokens
     * @return array{
     *     module: string|null,
     *     named_only: false,
     *     valid: false,
     *     local: null
     * }
     */
    private function nonNamedJavascriptImport(array $tokens, int $cursor): array
    {
        $module = null;
        $limit = min(count($tokens), $cursor + 64);
        for (; $cursor < $limit; ++$cursor) {
            if ($tokens[$cursor]['text'] === ';') {
                break;
            }
            if (
                $tokens[$cursor]['text'] === 'from'
                && ($tokens[$cursor + 1]['type'] ?? null) === 'string'
            ) {
                $module = $tokens[$cursor + 1]['literal'];
                break;
            }
        }

        return [
            'module' => $module,
            'named_only' => false,
            'valid' => false,
            'local' => null,
        ];
    }

    /** @param list<array<string, mixed>> $tokens */
    private function javascriptImportEndsAtStatement(
        array $tokens,
        int $moduleIndex
    ): bool {
        $next = $tokens[$moduleIndex + 1] ?? null;
        if ($next === null) {
            return true;
        }
        if ($next['text'] === ';') {
            $afterSemicolon = $tokens[$moduleIndex + 2] ?? null;

            return $afterSemicolon === null
                || $afterSemicolon['line'] > $next['line'];
        }

        return $next['line'] > $tokens[$moduleIndex]['line'];
    }

    private function viewUsesGlobalHead(
        string $projectRoot,
        string $articleView
    ): bool {
        $source = $this->projectFileContents($projectRoot, $articleView);
        $tokens = is_string($source) ? $this->phpTokens($source) : null;

        return is_array($tokens)
            && is_int($this->staticDirIncludeOffset(
                $tokens,
                '/../includes/_globalHead.php'
            ));
    }

    private function projectShellUsesCookieLad(
        string $projectRoot,
        string $articleView
    ): bool {
        $view = $this->projectFileContents($projectRoot, $articleView);
        if (!is_string($view)) {
            return false;
        }
        $sources = [$view];
        foreach ([
            'App/includes/_globalHead.php',
            'App/includes/_globalBody.php',
        ] as $include) {
            if (!str_contains($view, basename($include))) {
                continue;
            }
            $includedSource = $this->projectFileContents(
                $projectRoot,
                $include
            );
            if (is_string($includedSource)) {
                $sources[] = $includedSource;
            }
        }
        foreach ($sources as $source) {
            $source = $this->activePhpHtmlSource($source);
            if (
                preg_match(
                    '#https://webda\.eus/apis/cookielad/loader\.js(?:\?|[\'"\s<])#i',
                    $source
                ) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    private function activePhpHtmlSource(string $source): string
    {
        $active = '';
        foreach (token_get_all($source) as $token) {
            if (
                is_array($token)
                && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
            ) {
                continue;
            }
            $active .= is_array($token) ? $token[1] : $token;
        }

        return preg_replace('/<!--.*?-->/s', ' ', $active) ?? '';
    }

    /**
     * @return list<array{id: int|null, text: string, start: int, end: int,
     *     interpolated: bool}>|null
     */
    private function phpTokens(string $source): ?array
    {
        try {
            $rawTokens = token_get_all($source, TOKEN_PARSE);
        } catch (Throwable) {
            return null;
        }
        $tokens = [];
        $offset = 0;
        $interpolatedDelimiter = null;
        foreach ($rawTokens as $rawToken) {
            $id = is_array($rawToken) ? $rawToken[0] : null;
            $text = is_array($rawToken) ? $rawToken[1] : $rawToken;
            $interpolated = $interpolatedDelimiter !== null;
            if ($id === T_START_HEREDOC) {
                $interpolated = true;
                $interpolatedDelimiter = 'heredoc';
            } elseif ($id === T_END_HEREDOC) {
                $interpolated = true;
                $interpolatedDelimiter = null;
            } elseif ($id === null && ($text === '"' || $text === '`')) {
                $interpolated = true;
                $interpolatedDelimiter = $interpolatedDelimiter === null
                    ? $text : null;
            }
            $tokens[] = [
                'id' => $id,
                'text' => $text,
                'start' => $offset,
                'end' => $offset + strlen($text),
                'interpolated' => $interpolated,
            ];
            $offset += strlen($text);
        }

        return $tokens;
    }

    /**
     * @param list<array{id: int|null, text: string, start: int, end: int,
     *     interpolated: bool}> $tokens
     */
    private function semanticPhpSource(array $tokens): string
    {
        $semantic = '';
        foreach ($tokens as $token) {
            if (
                $token['interpolated']
                || in_array($token['id'], [
                    T_COMMENT,
                    T_DOC_COMMENT,
                    T_CONSTANT_ENCAPSED_STRING,
                    T_ENCAPSED_AND_WHITESPACE,
                    T_INLINE_HTML,
                    T_START_HEREDOC,
                    T_END_HEREDOC,
                ], true)
            ) {
                $semantic .= str_repeat(' ', strlen($token['text']));
                continue;
            }
            $semantic .= $token['text'];
        }

        return $semantic;
    }

    /**
     * @param list<array{id: int|null, text: string, start: int, end: int,
     *     interpolated: bool}> $tokens
     * @param list<string> $alternatives
     */
    private function echoedSemanticOffset(
        array $tokens,
        array $alternatives
    ): ?int {
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            if (!in_array($tokens[$index]['id'], [
                T_OPEN_TAG_WITH_ECHO,
                T_ECHO,
            ], true)) {
                continue;
            }
            $start = $index + 1;
            $cursor = $start;
            while (
                $cursor < $count
                && $tokens[$cursor]['id'] !== T_CLOSE_TAG
                && (
                    $tokens[$index]['id'] === T_OPEN_TAG_WITH_ECHO
                    || $tokens[$cursor]['text'] !== ';'
                )
            ) {
                ++$cursor;
            }
            $semantic = $this->semanticPhpSource(
                array_slice($tokens, $start, $cursor - $start)
            );
            foreach ($alternatives as $alternative) {
                if (str_contains($semantic, $alternative)) {
                    return $tokens[$index]['start'];
                }
            }
            $index = max($index, $cursor - 1);
        }

        return null;
    }

    /**
     * @param list<array{id: int|null, text: string, start: int, end: int,
     *     interpolated: bool}> $tokens
     */
    private function hasPhpAssignment(
        array $tokens,
        string $target,
        ?string $method = null
    ): bool {
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            if (
                $tokens[$index]['interpolated']
                || $tokens[$index]['id'] !== T_VARIABLE
                || $tokens[$index]['text'] !== $target
            ) {
                continue;
            }
            $operator = $this->nextActivePhpToken($tokens, $index + 1);
            if (!isset($tokens[$operator]) || $tokens[$operator]['text'] !== '=') {
                continue;
            }
            if ($method === null) {
                return true;
            }
            $depth = 0;
            for ($cursor = $operator + 1; $cursor < $count; ++$cursor) {
                $candidate = $tokens[$cursor];
                if (!$candidate['interpolated']) {
                    if (in_array($candidate['text'], ['(', '[', '{'], true)) {
                        ++$depth;
                    } elseif (in_array($candidate['text'], [')', ']', '}'], true)) {
                        $depth = max(0, $depth - 1);
                    } elseif (($candidate['text'] === ';'
                        || $candidate['id'] === T_CLOSE_TAG) && $depth === 0) {
                        break;
                    }
                }
                if ($candidate['id'] !== T_OBJECT_OPERATOR) {
                    continue;
                }
                $name = $this->nextActivePhpToken($tokens, $cursor + 1);
                $open = $this->nextActivePhpToken($tokens, $name + 1);
                if (
                    isset($tokens[$name], $tokens[$open])
                    && $tokens[$name]['id'] === T_STRING
                    && $tokens[$name]['text'] === $method
                    && $tokens[$open]['text'] === '('
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array{id: int|null, text: string, start: int, end: int,
     *     interpolated: bool}> $tokens
     * @return array<string, true>
     */
    private function pageMetaKeys(
        array $tokens,
        bool $includeAssignedKeys = true
    ): array
    {
        $significant = array_values(array_filter(
            $tokens,
            static fn (array $token): bool => !$token['interpolated']
                && !in_array($token['id'], [
                    T_OPEN_TAG,
                    T_OPEN_TAG_WITH_ECHO,
                    T_CLOSE_TAG,
                    T_WHITESPACE,
                    T_COMMENT,
                    T_DOC_COMMENT,
                    T_INLINE_HTML,
                ], true)
        ));
        $keys = [];
        $count = count($significant);
        for ($index = 0; $index + 3 < $count; ++$index) {
            if (
                $significant[$index]['id'] !== T_VARIABLE
                || $significant[$index]['text'] !== '$pageMeta'
            ) {
                continue;
            }
            if (
                $significant[$index + 1]['text'] === '['
                && $significant[$index + 2]['id']
                    === T_CONSTANT_ENCAPSED_STRING
                && $significant[$index + 3]['text'] === ']'
            ) {
                $key = $this->decodePhpLiteralString(
                    $significant[$index + 2]['text']
                );
                if (is_string($key)) {
                    $keys[$key] = true;
                }
                continue;
            }
            if (
                !$includeAssignedKeys
                || $significant[$index + 1]['text'] !== '='
                || $significant[$index + 2]['text'] !== '['
            ) {
                continue;
            }
            $depth = 0;
            for ($cursor = $index + 2; $cursor < $count; ++$cursor) {
                if ($significant[$cursor]['text'] === '[') {
                    ++$depth;
                    continue;
                }
                if ($significant[$cursor]['text'] === ']') {
                    --$depth;
                    if ($depth === 0) {
                        break;
                    }
                    continue;
                }
                if (
                    $depth === 1
                    && $significant[$cursor]['id']
                        === T_CONSTANT_ENCAPSED_STRING
                    && ($significant[$cursor + 1]['id'] ?? null)
                        === T_DOUBLE_ARROW
                ) {
                    $key = $this->decodePhpLiteralString(
                        $significant[$cursor]['text']
                    );
                    if (is_string($key)) {
                        $keys[$key] = true;
                    }
                }
            }
        }

        return $keys;
    }

    private function decodePhpLiteralString(string $literal): ?string
    {
        if (strlen($literal) < 2) {
            return null;
        }
        $quote = $literal[0];
        if ($literal[strlen($literal) - 1] !== $quote) {
            return null;
        }
        $inner = substr($literal, 1, -1);
        if ($quote === '"') {
            return strpbrk($inner, '\\$') === false ? $inner : null;
        }
        if ($quote !== "'") {
            return null;
        }

        return str_replace(["\\\\", "\\'"], ["\\", "'"], $inner);
    }

    /**
     * @param list<array{id: int|null, text: string, start: int, end: int,
     *     interpolated: bool}> $tokens
     */
    private function staticDirIncludeOffset(
        array $tokens,
        string $expectedPath,
        ?array $kinds = null
    ): ?int {
        $matches = [];
        $braceDepth = 0;
        foreach ($tokens as $index => $token) {
            if ($token['interpolated']) {
                continue;
            }
            if ($token['text'] === '{') {
                ++$braceDepth;
                continue;
            }
            if ($token['text'] === '}') {
                $braceDepth = max(0, $braceDepth - 1);
                continue;
            }
            if (!in_array($token['id'], [
                T_REQUIRE,
                T_REQUIRE_ONCE,
                T_INCLUDE,
                T_INCLUDE_ONCE,
            ], true) || ($kinds !== null
                && !in_array($token['id'], $kinds, true))) {
                continue;
            }
            $cursor = $this->nextActivePhpToken($tokens, $index + 1);
            $parenthesized = isset($tokens[$cursor])
                && $tokens[$cursor]['text'] === '(';
            if ($parenthesized) {
                $cursor = $this->nextActivePhpToken($tokens, $cursor + 1);
            }
            if (!isset($tokens[$cursor]) || $tokens[$cursor]['id'] !== T_DIR) {
                continue;
            }
            $cursor = $this->nextActivePhpToken($tokens, $cursor + 1);
            if (!isset($tokens[$cursor]) || $tokens[$cursor]['text'] !== '.') {
                continue;
            }
            $cursor = $this->nextActivePhpToken($tokens, $cursor + 1);
            if (!isset($tokens[$cursor])
                || $tokens[$cursor]['id'] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $path = $this->decodePhpLiteralString($tokens[$cursor]['text']);
            $cursor = $this->nextActivePhpToken($tokens, $cursor + 1);
            if ($parenthesized) {
                if (!isset($tokens[$cursor]) || $tokens[$cursor]['text'] !== ')') {
                    continue;
                }
                $cursor = $this->nextActivePhpToken($tokens, $cursor + 1);
            }
            if (
                $braceDepth !== 0
                || str_replace('\\', '/', (string) $path) !== $expectedPath
                || !isset($tokens[$cursor])
                || ($tokens[$cursor]['text'] !== ';'
                    && $tokens[$cursor]['id'] !== T_CLOSE_TAG)
            ) {
                continue;
            }
            $matches[] = $token['start'];
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @param list<array<string, mixed>> $tokens */
    private function nextActivePhpToken(array $tokens, int $index): int
    {
        while (isset($tokens[$index]) && in_array($tokens[$index]['id'], [
            T_WHITESPACE,
            T_COMMENT,
            T_DOC_COMMENT,
        ], true)) {
            ++$index;
        }

        return $index;
    }

    /**
     * @param list<array{id: int|null, text: string, start: int, end: int,
     *     interpolated: bool}> $tokens
     * @return array<string, int>
     */
    private function htmlLandmarks(array $tokens): array
    {
        $patterns = [
            'doctype' => '/<!doctype\s+html\b[^>]*>/i',
            'head_open' => '/<head\b[^>]*>/i',
            'head_close' => '/<\/head\s*>/i',
            'body_open' => '/<body\b[^>]*>/i',
            'body_close' => '/<\/body\s*>/i',
        ];
        $positions = [];
        foreach ($tokens as $token) {
            if ($token['id'] !== T_INLINE_HTML) {
                continue;
            }
            $visible = preg_replace_callback(
                '/<!--.*?-->/s',
                static fn (array $match): string => str_repeat(
                    ' ',
                    strlen($match[0])
                ),
                $token['text']
            ) ?? '';
            foreach ($patterns as $name => $pattern) {
                if (isset($positions[$name]) || preg_match(
                    $pattern,
                    $visible,
                    $match,
                    PREG_OFFSET_CAPTURE
                ) !== 1) {
                    continue;
                }
                $positions[$name] = $token['start'] + $match[0][1];
            }
        }

        return $positions;
    }

    /**
     * @param list<array{id: int|null, text: string, start: int, end: int,
     *     interpolated: bool}> $tokens
     */
    private function inlineHtmlPatternOffset(
        array $tokens,
        string $pattern
    ): ?int
    {
        foreach ($tokens as $token) {
            if ($token['id'] !== T_INLINE_HTML) {
                continue;
            }
            $visible = preg_replace_callback(
                '/<!--.*?-->/s',
                static fn (array $match): string => str_repeat(
                    ' ',
                    strlen($match[0])
                ),
                $token['text']
            ) ?? '';
            if (preg_match(
                $pattern,
                $visible,
                $match,
                PREG_OFFSET_CAPTURE
            ) === 1) {
                return $token['start'] + $match[0][1];
            }
        }

        return null;
    }

    /**
     * @param list<array{id: int|null, text: string, start: int, end: int,
     *     interpolated: bool}> $tokens
     */
    private function hasCspNonceScriptContract(array $tokens): bool
    {
        $assignments = [];
        $nonceAssignments = [];
        $count = count($tokens);
        for ($index = 0; $index + 2 < $count; ++$index) {
            if ($tokens[$index]['interpolated']
                || $tokens[$index]['id'] !== T_VARIABLE) {
                continue;
            }
            $operator = $this->nextActivePhpToken($tokens, $index + 1);
            if (!isset($tokens[$operator]) || $tokens[$operator]['text'] !== '=') {
                continue;
            }
            $dependencies = [];
            $buildsNonceAttribute = false;
            $depth = 0;
            for ($cursor = $operator + 1; $cursor < $count; ++$cursor) {
                $candidate = $tokens[$cursor];
                if (!$candidate['interpolated']) {
                    if (in_array($candidate['text'], ['(', '[', '{'], true)) {
                        ++$depth;
                    } elseif (in_array($candidate['text'], [')', ']', '}'], true)) {
                        $depth = max(0, $depth - 1);
                    } elseif (($candidate['text'] === ';'
                        || $candidate['id'] === T_CLOSE_TAG) && $depth === 0) {
                        break;
                    }
                }
                if ($candidate['id'] === T_VARIABLE) {
                    $dependencies[$candidate['text']] = true;
                }
                if (in_array($candidate['id'], [
                    T_CONSTANT_ENCAPSED_STRING,
                    T_ENCAPSED_AND_WHITESPACE,
                ], true) && preg_match(
                    '/\bnonce\s*=/i',
                    $candidate['text']
                ) === 1) {
                    $buildsNonceAttribute = true;
                }
            }
            $target = $tokens[$index]['text'];
            $dependencyList = array_keys($dependencies);
            $assignments[$target][] = $dependencyList;
            if ($buildsNonceAttribute) {
                $nonceAssignments[$target][] = $dependencyList;
            }
            if (count($assignments) > 256) {
                return false;
            }
        }

        $dependsOnNonce = ['$cspNonce' => true];
        for ($pass = 0; $pass <= count($assignments); ++$pass) {
            $changed = false;
            foreach ($assignments as $target => $variants) {
                if (isset($dependsOnNonce[$target])) {
                    continue;
                }
                foreach ($variants as $dependencies) {
                    if (array_intersect_key(
                        array_fill_keys($dependencies, true),
                        $dependsOnNonce
                    ) === []) {
                        continue;
                    }
                    $dependsOnNonce[$target] = true;
                    $changed = true;
                    break;
                }
            }
            if (!$changed) {
                break;
            }
        }
        $nonceAttributeVariables = [];
        foreach ($nonceAssignments as $target => $variants) {
            foreach ($variants as $dependencies) {
                if (array_intersect_key(
                    array_fill_keys($dependencies, true),
                    $dependsOnNonce
                ) !== []) {
                    $nonceAttributeVariables[$target] = true;
                    break;
                }
            }
        }

        $projection = $this->templateOutputProjection(
            $tokens,
            $dependsOnNonce,
            $nonceAttributeVariables
        );
        $matches = [];
        if (preg_match_all(
            '/<script\b(?<attributes>(?:"[^"]*"|\'[^\']*\'|[^>])*)>/i',
            $projection,
            $matches
        ) < 1) {
            return false;
        }
        foreach ($matches['attributes'] as $attributes) {
            if (!$this->scriptAttributesUseCspNonce($attributes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array{id: int|null, text: string, start: int, end: int,
     *     interpolated: bool}> $tokens
     * @param array<string, true> $dependsOnNonce
     * @param array<string, true> $nonceAttributeVariables
     */
    private function templateOutputProjection(
        array $tokens,
        array $dependsOnNonce,
        array $nonceAttributeVariables
    ): string {
        $projection = '';
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            if ($tokens[$index]['id'] === T_INLINE_HTML) {
                $projection .= preg_replace(
                    '/<!--.*?-->/s',
                    ' ',
                    $tokens[$index]['text']
                ) ?? '';
                continue;
            }
            if ($tokens[$index]['id'] === T_OPEN_TAG_WITH_ECHO) {
                $start = $index + 1;
                while ($index < $count
                    && $tokens[$index]['id'] !== T_CLOSE_TAG) {
                    ++$index;
                }
                $projection .= $this->echoProjection(
                    array_slice($tokens, $start, $index - $start),
                    $dependsOnNonce,
                    $nonceAttributeVariables
                );
                continue;
            }
            if ($tokens[$index]['id'] !== T_ECHO) {
                continue;
            }
            $start = $index + 1;
            while ($index < $count
                && $tokens[$index]['text'] !== ';'
                && $tokens[$index]['id'] !== T_CLOSE_TAG) {
                ++$index;
            }
            $projection .= $this->echoProjection(
                array_slice($tokens, $start, $index - $start),
                $dependsOnNonce,
                $nonceAttributeVariables
            );
        }

        return $projection;
    }

    /**
     * @param list<array<string, mixed>> $tokens
     * @param array<string, true> $dependsOnNonce
     * @param array<string, true> $nonceAttributeVariables
     */
    private function echoProjection(
        array $tokens,
        array $dependsOnNonce,
        array $nonceAttributeVariables
    ): string {
        $usesNonce = false;
        foreach ($tokens as $token) {
            if ($token['id'] !== T_VARIABLE) {
                continue;
            }
            if (isset($nonceAttributeVariables[$token['text']])) {
                return ' nonce="__LIQUIDSTACK_CSP_VALUE__" ';
            }
            $usesNonce = $usesNonce || isset($dependsOnNonce[$token['text']]);
        }

        return $usesNonce
            ? '__LIQUIDSTACK_CSP_VALUE__'
            : '__LIQUIDSTACK_PHP_ECHO__';
    }

    private function scriptAttributesUseCspNonce(string $attributes): bool
    {
        $length = strlen($attributes);
        $quote = null;
        for ($index = 0; $index < $length; ++$index) {
            $character = $attributes[$index];
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
                continue;
            }
            if (strtolower(substr($attributes, $index, 5)) !== 'nonce') {
                continue;
            }
            $before = $index === 0 ? ' ' : $attributes[$index - 1];
            $after = $attributes[$index + 5] ?? ' ';
            if (preg_match('/[A-Za-z0-9_-]/', $before . $after) === 1) {
                continue;
            }
            $cursor = $index + 5;
            while ($cursor < $length && ctype_space($attributes[$cursor])) {
                ++$cursor;
            }
            if (($attributes[$cursor] ?? null) !== '=') {
                continue;
            }
            do {
                ++$cursor;
            } while ($cursor < $length && ctype_space($attributes[$cursor]));
            $valueQuote = $attributes[$cursor] ?? null;
            if ($valueQuote === '"' || $valueQuote === "'") {
                $end = strpos($attributes, $valueQuote, $cursor + 1);
                $value = $end === false
                    ? '' : substr($attributes, $cursor + 1, $end - $cursor - 1);
            } else {
                $end = $cursor;
                while ($end < $length && !ctype_space($attributes[$end])) {
                    ++$end;
                }
                $value = substr($attributes, $cursor, $end - $cursor);
            }
            if (str_contains($value, '__LIQUIDSTACK_CSP_VALUE__')) {
                return true;
            }
        }

        return false;
    }

    private function projectFileContents(
        string $projectRoot,
        string $relativePath
    ): ?string {
        if (!$this->projectFilePresent($projectRoot, $relativePath)) {
            return null;
        }
        $contents = file_get_contents(
            rtrim($projectRoot, '/\\') . '/' . $relativePath
        );

        return is_string($contents) ? $contents : null;
    }

    private function validProjectJsonObject(
        string $projectRoot,
        string $relativePath
    ): bool {
        $path = rtrim($projectRoot, '/\\') . '/' . $relativePath;
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            return false;
        }
        try {
            return is_object(json_decode(
                (string) file_get_contents($path),
                false,
                512,
                JSON_THROW_ON_ERROR
            ));
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $environment */
    private function publicShellSecurityStatus(
        string $projectRoot,
        #[\SensitiveParameter] array $environment,
        bool $cookieLadDetected
    ): array {
        $relativePath = BlogPublicShellSecurityConfig::PROJECT_FILE;
        $path = rtrim($projectRoot, '/\\') . '/' . $relativePath;
        $present = file_exists($path) || is_link($path);
        try {
            $config = BlogPublicShellSecurityConfig::fromProject(
                $projectRoot,
                BlogPublicShellDefaultSecurityPolicy::fromEnvironment(
                    $environment
                )
            );
            $context = $config->securityPolicy()->context();
            $cookieLadReady = !$cookieLadDetected
                || $this->cspAllowsCookieLad($context->headers());

            return [
                'path' => $relativePath,
                'present' => $present,
                'configured' => $config->isConfigured(),
                'ready' => $cookieLadReady,
                'status' => $cookieLadReady
                    ? ($config->isConfigured() ? 'configured' : 'default')
                    : 'cookielad_sources_missing',
                'cookie_lad' => [
                    'detected' => $cookieLadDetected,
                    'ready' => $cookieLadReady,
                    'status' => !$cookieLadDetected
                        ? 'not_detected'
                        : ($cookieLadReady ? 'authorized' : 'not_authorized'),
                ],
            ];
        } catch (Throwable) {
            return [
                'path' => $relativePath,
                'present' => $present,
                'configured' => false,
                'ready' => false,
                'status' => 'invalid',
                'cookie_lad' => [
                    'detected' => $cookieLadDetected,
                    'ready' => false,
                    'status' => 'not_checked',
                ],
            ];
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function cspAllowsCookieLad(array $headers): bool
    {
        $value = $headers['Content-Security-Policy'] ?? null;
        if (!is_string($value)) {
            return false;
        }
        $directives = [];
        foreach (explode(';', $value) as $directive) {
            $tokens = preg_split('/\s+/', trim($directive));
            if (!is_array($tokens) || $tokens === []) {
                continue;
            }
            $name = strtolower((string) array_shift($tokens));
            $directives[$name] = $tokens;
        }
        foreach (['script-src', 'style-src', 'img-src', 'connect-src'] as $name) {
            if (!in_array(
                self::COOKIE_LAD_ORIGIN,
                $directives[$name] ?? [],
                true
            )) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function inactiveProductionManifest(
        string $status,
        ?bool $required
    ): array {
        return [
            'path' => self::PRODUCTION_MANIFEST,
            'entry' => self::PROJECT_ARTICLE_SOURCE_ENTRY,
            'environment' => $status,
            'required' => $required,
            'ready' => $required === false,
            'status' => $status,
        ];
    }

    /** @return array<string, mixed> */
    private function productionManifestStatus(
        string $projectRoot,
        string $runtimeEnvironment
    ): array {
        $base = [
            'path' => self::PRODUCTION_MANIFEST,
            'entry' => self::PROJECT_ARTICLE_SOURCE_ENTRY,
            'environment' => $runtimeEnvironment,
        ];
        if ($runtimeEnvironment === 'development') {
            return $base + [
                'required' => false,
                'ready' => true,
                'status' => 'not_required_in_development',
            ];
        }
        if ($runtimeEnvironment !== 'production') {
            return $base + [
                'required' => true,
                'ready' => false,
                'status' => 'runtime_profile_unavailable',
            ];
        }

        $manifestPath = rtrim($projectRoot, '/\\')
            . '/public/.vite/manifest.json';
        if (
            !is_file($manifestPath)
            || is_link($manifestPath)
            || !is_readable($manifestPath)
        ) {
            return $base + [
                'required' => true,
                'ready' => false,
                'status' => 'missing_or_invalid',
            ];
        }
        try {
            $manifest = json_decode(
                (string) file_get_contents($manifestPath),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable) {
            $manifest = null;
        }
        if (!is_array($manifest)) {
            return $base + [
                'required' => true,
                'ready' => false,
                'status' => 'invalid',
            ];
        }

        $entry = $manifest[self::PROJECT_ARTICLE_SOURCE_ENTRY] ?? null;
        $assets = is_array($entry)
            ? $this->productionBundleAssets($manifest)
            : null;
        if ($assets === null) {
            return $base + [
                'required' => true,
                'ready' => false,
                'status' => is_array($entry)
                    ? 'assets_missing_or_invalid' : 'entry_missing',
            ];
        }
        $ready = $this->assetInspector->inspect(
            $projectRoot,
            $assets
        )['ready'];

        return $base + [
            'required' => true,
            'ready' => $ready,
            'status' => $ready ? 'ready' : 'assets_missing_or_invalid',
        ];
    }

    /**
     * Walk only the graph reachable from the Blog entry. Vite may attach CSS
     * and static assets to imported chunks instead of the root entry.
     *
     * @param array<string, mixed> $manifest
     * @return list<string>|null
     */
    private function productionBundleAssets(array $manifest): ?array
    {
        $queue = [self::PROJECT_ARTICLE_SOURCE_ENTRY];
        $visited = [];
        $assets = [];
        $referenceCount = 0;
        while ($queue !== []) {
            $key = array_shift($queue);
            if (!is_string($key) || isset($visited[$key])) {
                continue;
            }
            if (
                count($visited) >= self::MAX_PRODUCTION_MANIFEST_NODES
                || !$this->validManifestReference($key)
            ) {
                return null;
            }
            $entry = $manifest[$key] ?? null;
            if (!is_array($entry) || array_is_list($entry)) {
                return null;
            }
            $visited[$key] = true;

            $file = $entry['file'] ?? null;
            $path = is_string($file)
                ? $this->productionAssetPath($file, 'javascript') : null;
            if ($path === null) {
                return null;
            }
            $assets[$path] = true;

            $css = $entry['css'] ?? [];
            if (
                !is_array($css)
                || !array_is_list($css)
                || ($key === self::PROJECT_ARTICLE_SOURCE_ENTRY && $css === [])
            ) {
                return null;
            }
            $entryCss = [];
            foreach ($css as $asset) {
                $path = is_string($asset)
                    ? $this->productionAssetPath($asset, 'stylesheet') : null;
                if ($path === null || isset($entryCss[$path])) {
                    return null;
                }
                $entryCss[$path] = true;
                $assets[$path] = true;
            }

            $staticAssets = $entry['assets'] ?? [];
            if (!is_array($staticAssets) || !array_is_list($staticAssets)) {
                return null;
            }
            $entryAssets = [];
            foreach ($staticAssets as $asset) {
                $path = is_string($asset)
                    ? $this->productionAssetPath($asset, 'static') : null;
                if ($path === null || isset($entryAssets[$path])) {
                    return null;
                }
                $entryAssets[$path] = true;
                $assets[$path] = true;
            }
            if (count($assets) > self::MAX_PRODUCTION_MANIFEST_ASSETS) {
                return null;
            }

            foreach (['imports', 'dynamicImports'] as $field) {
                $references = $entry[$field] ?? [];
                if (!is_array($references) || !array_is_list($references)) {
                    return null;
                }
                foreach ($references as $reference) {
                    ++$referenceCount;
                    if (
                        $referenceCount
                            > self::MAX_PRODUCTION_MANIFEST_REFERENCES
                        || !is_string($reference)
                        || !$this->validManifestReference($reference)
                    ) {
                        return null;
                    }
                    if (!isset($visited[$reference])) {
                        $queue[] = $reference;
                    }
                }
            }
        }

        return array_keys($assets);
    }

    private function validManifestReference(string $reference): bool
    {
        return $reference !== ''
            && strlen($reference) <= 2048
            && !str_contains($reference, "\0")
            && preg_match('/[\x00-\x1F\x7F]/', $reference) !== 1;
    }

    private function productionAssetPath(string $asset, string $kind): ?string
    {
        $pattern = match ($kind) {
            'javascript' => '#\Aassets/js/[A-Za-z0-9._-]+\.js\z#D',
            'stylesheet' => '#\Aassets/css/[A-Za-z0-9._-]+\.css\z#D',
            default => '#\Aassets/(?:[A-Za-z0-9@._-]+/)*'
                . '[A-Za-z0-9@_-][A-Za-z0-9@._-]*'
                . '\.[A-Za-z0-9]{1,16}\z#D',
        };
        if (
            preg_match($pattern, $asset) !== 1
            || str_contains($asset, '/./')
            || str_contains($asset, '/../')
        ) {
            return null;
        }

        return 'public/' . $asset;
    }

    private function projectFilePresent(
        string $projectRoot,
        string $relativePath
    ): bool {
        return $this->assetInspector->inspect(
            $projectRoot,
            [$relativePath]
        )['ready'];
    }
    /** @return array<string, mixed> */
    private function tagStatus(
        ?MigrationDatabasePlan $plan,
        bool $inspectDatabase,
        ?PDO $pdo,
        ?string $blogTablePrefix,
        ?string $webAdminTablePrefix
    ): array {
        if (!$inspectDatabase || !$plan instanceof MigrationDatabasePlan) {
            return [
                'ready' => false,
                'status' => 'not_checked',
                'public' => 'not_checked',
                'administration' => 'not_checked',
                'schema' => 'not_checked',
                'capabilities' => 'not_checked',
                'blocker' => null,
            ];
        }
        $public = MigrationFeatureReadiness::fromPlan(
            $plan,
            BlogMigrationRequirements::tagsPublic()
        );
        $administration = MigrationFeatureReadiness::fromPlan(
            $plan,
            BlogMigrationRequirements::tagsAdministration()
        );
        if (!$public->baseReady()) {
            $blocked = $public->baseStatus() === 'blocked';
            return [
                'ready' => false,
                'status' => $blocked ? 'schema_not_ready' : 'pending',
                'public' => $public->baseStatus(),
                'administration' => $administration->baseStatus(),
                'schema' => $blocked ? 'not_ready' : 'not_applicable',
                'capabilities' => 'not_applicable',
                'blocker' => $blocked ? 'tags.schema_not_ready' : null,
            ];
        }
        if (
            !$pdo instanceof PDO
            || !is_string($blogTablePrefix)
            || $blogTablePrefix === ''
        ) {
            return [
                'ready' => false,
                'status' => 'not_checked',
                'public' => $public->baseStatus(),
                'administration' => $administration->baseStatus(),
                'schema' => 'not_checked',
                'capabilities' => 'not_checked',
                'blocker' => null,
            ];
        }
        $schemaReady = (new BlogTagSchemaMigrationPostconditionVerifier(5))
            ->verify(
                $pdo,
                MigrationScope::forTablePrefix('blog', $blogTablePrefix)
            );
        if (!$schemaReady) {
            return [
                'ready' => false,
                'status' => 'schema_not_ready',
                'public' => $public->baseStatus(),
                'administration' => $administration->baseStatus(),
                'schema' => 'not_ready',
                'capabilities' => 'not_checked',
                'blocker' => 'tags.schema_not_ready',
            ];
        }
        if (!$administration->baseReady()) {
            $blocked = $administration->baseStatus() === 'blocked';
            return [
                'ready' => false,
                'status' => $blocked
                    ? 'administration_not_ready' : 'public_ready',
                'public' => $public->baseStatus(),
                'administration' => $administration->baseStatus(),
                'schema' => 'ready',
                'capabilities' => $blocked ? 'not_ready' : 'pending',
                'blocker' => $blocked
                    ? 'tags.administration_not_ready' : null,
            ];
        }
        $capabilitiesReady = is_string($webAdminTablePrefix)
            && $webAdminTablePrefix !== ''
            && (new BlogTagCapabilitySeedPostcondition())->verify(
                $pdo,
                MigrationScope::forTablePrefix(
                    'webadmin',
                    $webAdminTablePrefix
                )
            );

        return [
            'ready' => $capabilitiesReady,
            'status' => $capabilitiesReady
                ? 'ready' : 'administration_not_ready',
            'public' => $public->baseStatus(),
            'administration' => $administration->baseStatus(),
            'schema' => 'ready',
            'capabilities' => $capabilitiesReady ? 'ready' : 'not_ready',
            'blocker' => $capabilitiesReady
                ? null : 'tags.administration_not_ready',
        ];
    }

    /**
     * @param array<string, mixed> $environment
     * @return array<string, mixed>
     */
    private function analyticsStatus(
        bool $enabled,
        bool $collectInDevelopment,
        #[\SensitiveParameter] array $environment,
        ?MigrationDatabasePlan $plan,
        bool $inspectDatabase
    ): array {
        if (!$enabled) {
            return [
                'enabled' => false,
                'ready' => true,
                'status' => 'disabled',
                'collection' => 'not_applicable',
                'administration' => 'not_applicable',
                'security_key' => 'not_applicable',
                'environment_collection' => 'not_applicable',
            ];
        }
        if (!$inspectDatabase || !$plan instanceof MigrationDatabasePlan) {
            return [
                'enabled' => true,
                'ready' => false,
                'status' => 'not_checked',
                'collection' => 'not_checked',
                'administration' => 'not_checked',
                'security_key' => 'not_checked',
                'environment_collection' => 'not_checked',
            ];
        }
        $collection = MigrationFeatureReadiness::fromPlan(
            $plan,
            BlogMigrationRequirements::analyticsCollection()
        );
        $administration = MigrationFeatureReadiness::fromPlan(
            $plan,
            BlogMigrationRequirements::analyticsAdministration()
        );
        $securityKeyReady = $this->analyticsSecurityKeyReady($environment);
        try {
            $profile = ProjectRuntimeProfile::fromEnvironment($environment);
            $environmentAllowsCollection =
                !$profile->isDevelopmentLoopbackHttp()
                || $collectInDevelopment;
        } catch (Throwable) {
            $environmentAllowsCollection = false;
        }
        $migrationsReady = $collection->baseReady()
            && $administration->baseReady();
        $ready = $migrationsReady
            && $securityKeyReady
            && $environmentAllowsCollection;
        $status = !$migrationsReady
            ? 'migration_not_ready'
            : (!$securityKeyReady
                ? 'security_key_not_ready'
                : (!$environmentAllowsCollection
                    ? 'disabled_in_environment'
                    : 'ready'));

        return [
            'enabled' => true,
            'ready' => $ready,
            'status' => $status,
            'collection' => $collection->baseStatus(),
            'administration' => $administration->baseStatus(),
            'security_key' => $securityKeyReady ? 'ready' : 'not_ready',
            'environment_collection' => $environmentAllowsCollection
                ? 'enabled'
                : 'disabled',
        ];
    }

    /** @param array<string, mixed> $environment */
    private function analyticsSecurityKeyReady(array $environment): bool
    {
        $encoded = $environment[WebAdminConfig::SECURITY_KEY_ENV] ?? null;
        if (!is_string($encoded) || $encoded === '') {
            return false;
        }
        try {
            SecurityKey::fromBase64Url($encoded);

            return true;
        } catch (InvalidSecurityKey) {
            return false;
        }
    }

    /** @param array<string, mixed> $environment @return array<string, mixed> */
    private function sitemapCacheStatus(
        string $projectRoot,
        array $environment,
        bool $enabled,
        ?MigrationDatabasePlan $plan,
        bool $inspectDatabase,
        ?string $tablePrefix,
        ?PDO $databaseConnection
    ): array {
        if (!$enabled) {
            return [
                'enabled' => false,
                'ready' => true,
                'status' => 'disabled',
                'migration' => 'not_applicable',
                'storage' => 'not_applicable',
                'generation' => 'not_applicable',
            ];
        }
        $migration = 'not_checked';
        $migrationReady = false;
        if ($inspectDatabase && $plan instanceof MigrationDatabasePlan) {
            $readiness = MigrationFeatureReadiness::fromPlan(
                $plan,
                BlogMigrationRequirements::sitemapCache()
            );
            $migrationReady = $readiness->baseReady();
            $migration = $readiness->baseStatus();
        }
        $storageReady = false;
        $storageStatus = 'invalid';
        $storage = null;
        try {
            $storage = PrivateBlogSitemapCacheStorage::forProject(
                $projectRoot,
                $environment
            );
            $diagnostic = $storage->diagnostic();
            $storageReady = ($diagnostic['ready'] ?? false) === true;
            $storageStatus = is_string($diagnostic['status'] ?? null)
                ? $diagnostic['status'] : 'invalid';
        } catch (Throwable) {
            $storageReady = false;
        }
        $generationStatus = 'not_checked';
        $generationReady = false;
        if (
            $migrationReady
            && $storageReady
            && $storage instanceof PrivateBlogSitemapCacheStorage
            && is_string($tablePrefix)
            && $tablePrefix !== ''
            && $databaseConnection instanceof PDO
        ) {
            try {
                $state = (new PdoBlogSitemapStateRepository(
                    $databaseConnection,
                    MigrationScope::forTablePrefix('blog', $tablePrefix)
                ))->current();
                $generation = $state->cacheGeneration();
                $generationReady = $generation !== null
                    && hash_equals(
                        $generation,
                        $storage->markerGeneration()
                    );
                $generationStatus = $generationReady
                    ? 'matched' : 'mismatch';
            } catch (Throwable) {
                $generationStatus = 'invalid';
            }
        }
        $ready = $migrationReady
            && $storageReady
            && $storageStatus !== 'blocked'
            && $generationReady;
        $status = $ready
            ? 'ready'
            : (!$migrationReady
                ? 'migration_not_ready'
                : (!$storageReady
                    ? 'storage_not_ready'
                    : ($storageStatus === 'blocked'
                        ? 'blocked'
                        : 'generation_' . $generationStatus)));

        return [
            'enabled' => true,
            'ready' => $ready,
            'status' => $status,
            'migration' => $migration,
            'storage' => $storageStatus,
            'generation' => $generationStatus,
        ];
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
