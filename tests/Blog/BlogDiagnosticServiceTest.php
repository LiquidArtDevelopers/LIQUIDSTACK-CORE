<?php

declare(strict_types=1);

use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Diagnostics\BlogDiagnosticService;
use App\Core\Blog\Sitemap\Cache\PrivateBlogSitemapCacheStorage;
use App\Core\Modules\Migrations\MigrationDatabasePlan;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogDiagnosticServiceTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-blog-doctor-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir([
            $this->root . '/App/config/routes',
            $this->root . '/public',
        ]);
        $this->filesystem->dumpFile(
            $this->root . '/App/config/routes/get.php',
            "<?php\nreturn [];\n"
        );
        $this->filesystem->dumpFile(
            $this->root . '/App/config/routes/post.php',
            "<?php\nreturn [];\n"
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testCompleteRuntimeIsBlogReadyWithoutExposingOriginOrPrefix(): void
    {
        $report = $this->inspect($this->appliedPlan());
        $data = $report->toArray();

        self::assertTrue($report->isReady());
        self::assertTrue($data['readiness']['blog_ready']);
        self::assertSame([], $data['readiness']['blockers']);
        self::assertSame([
            'extension' => 'dom',
            'ready' => true,
            'status' => 'ready',
        ], $data['runtime']['dom_extension']);
        self::assertSame('applied', $data['database']['status']);
        self::assertSame('pending', $data['tags']['status']);
        self::assertNull($data['tags']['blocker']);
        self::assertSame(
            BlogPublicOrigin::SOURCE_LEGACY,
            $data['environment']['public_origin']['source']
        );
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('https://example.test', $encoded);
        self::assertStringNotContainsString('ls_blog_', $encoded);
    }

    public function testMissingDomExtensionBlocksBlogWithStableCode(): void
    {
        $report = (new BlogDiagnosticService(
            domExtensionAvailable: false
        ))->inspect(
            $this->root,
            ['es', 'en'],
            [BlogPublicOrigin::ENV => 'https://example.test'],
            '/admin',
            true,
            $this->appliedPlan(),
            true
        );
        $data = $report->toArray();

        self::assertFalse($report->isReady());
        self::assertSame([
            'extension' => 'dom',
            'ready' => false,
            'status' => 'missing',
        ], $data['runtime']['dom_extension']);
        self::assertSame(
            ['runtime.dom_extension_missing'],
            $data['readiness']['blockers']
        );
    }

    public function testAppliedTagSchemaAndCapabilityDriftAreVisibleBlockers(): void
    {
        $pdo = $this->tagDatabase();
        $plan = $this->tagPlan();
        $service = new BlogDiagnosticService();
        $ready = $service->inspect(
            $this->root,
            ['es', 'en'],
            [BlogPublicOrigin::ENV => 'https://example.test'],
            '/admin',
            true,
            $plan,
            true,
            [],
            $pdo
        )->toArray();
        self::assertSame('ready', $ready['tags']['status']);
        self::assertTrue($ready['readiness']['blog_ready']);

        $pdo->exec(
            'DELETE FROM ls_webadmin_role_capabilities WHERE role_id = '
                . '(SELECT id FROM ls_webadmin_roles WHERE code = '
                . "'site_admin') AND capability_id = (SELECT id FROM "
                . "ls_webadmin_capabilities WHERE code = 'blog.tags.edit')"
        );
        $capabilityDrift = $service->inspect(
            $this->root,
            ['es', 'en'],
            [BlogPublicOrigin::ENV => 'https://example.test'],
            '/admin',
            true,
            $plan,
            true,
            [],
            $pdo
        )->toArray();
        self::assertSame(
            'administration_not_ready',
            $capabilityDrift['tags']['status']
        );
        self::assertContains(
            'tags.administration_not_ready',
            $capabilityDrift['readiness']['blockers']
        );

        $pdo = $this->tagDatabase();
        $pdo->exec('DROP TABLE ls_blog_localization_tags');
        $schemaDrift = $service->inspect(
            $this->root,
            ['es', 'en'],
            [BlogPublicOrigin::ENV => 'https://example.test'],
            '/admin',
            true,
            $plan,
            true,
            [],
            $pdo
        )->toArray();
        self::assertSame('schema_not_ready', $schemaDrift['tags']['status']);
        self::assertContains(
            'tags.schema_not_ready',
            $schemaDrift['readiness']['blockers']
        );
    }

    public function testEffectiveConfigurationReportsProjectOwnedArticleView(): void
    {
        $this->writeProjectArticleShell();

        $data = (new BlogDiagnosticService())->inspect(
            $this->root,
            ['es', 'en'],
            $this->developmentEnvironment(),
            '/admin',
            true,
            $this->appliedPlan(),
            true
        )->toArray();

        self::assertTrue($data['configuration']['ready']);
        self::assertSame(
            'App/views/blog-article.php',
            $data['configuration']['effective']['public_article_view']
        );
        self::assertSame('project', $data['public_shell']['mode']);
        self::assertTrue($data['public_shell']['ready']);
        self::assertTrue($data['public_shell']['complete']);
        self::assertTrue($data['public_shell']['dependencies']['ready']);
        self::assertSame(
            'canonical',
            $data['public_shell']['dependencies']['profile']
        );
        self::assertSame(
            'ready',
            $data['public_shell']['metadata_head']['status']
        );
        self::assertSame(
            'default',
            $data['public_shell']['security_config']['status']
        );
        self::assertSame(
            'not_required_in_development',
            $data['public_shell']['production_manifest']['status']
        );
        self::assertSame([], $data['public_shell']['issues']);
    }

    public function testCanonicalProjectShellDependenciesAndMetadataAreRequired(): void
    {
        $this->writeProjectArticleShell();
        $this->filesystem->remove([
            $this->root . '/App/includes/_nav.php',
            $this->root . '/src/scss/resources/_moduleH1Type03.scss',
        ]);
        $this->filesystem->dumpFile(
            $this->root . '/App/includes/_globalHead.php',
            "<?php declare(strict_types=1);\n"
        );

        $data = (new BlogDiagnosticService())->inspect(
            $this->root,
            ['es', 'en'],
            $this->developmentEnvironment(),
            '/admin',
            true,
            $this->appliedPlan(),
            true
        )->toArray();

        self::assertFalse($data['public_shell']['ready']);
        self::assertFalse($data['public_shell']['dependencies']['ready']);
        self::assertSame(
            [
                'App/includes/_nav.php',
                'src/scss/resources/_moduleH1Type03.scss',
            ],
            $data['public_shell']['dependencies']['missing']
        );
        self::assertSame(
            'contract_missing',
            $data['public_shell']['metadata_head']['status']
        );
        self::assertContains(
            'public_shell.dependencies_missing_or_invalid',
            array_column($data['public_shell']['issues'], 'code')
        );
        self::assertContains(
            'public_shell.metadata_head_incompatible',
            array_column($data['public_shell']['issues'], 'code')
        );
        self::assertContains(
            'public_shell.project_not_ready',
            $data['readiness']['blockers']
        );
    }

    public function testProjectShellRejectsInvalidSecurityConfigWithoutLeakingIt(): void
    {
        $this->writeProjectArticleShell();
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/blog-public.php',
            "<?php\nreturn ['unknown' => "
                . "'https://private-csp.example.test'];\n"
        );

        $data = (new BlogDiagnosticService())->inspect(
            $this->root,
            ['es', 'en'],
            $this->developmentEnvironment(),
            '/admin',
            true,
            $this->appliedPlan(),
            true
        )->toArray();

        self::assertFalse($data['public_shell']['ready']);
        self::assertSame('invalid', $data['public_shell']['security_config']['status']);
        self::assertTrue($data['public_shell']['security_config']['present']);
        self::assertFalse($data['public_shell']['security_config']['configured']);
        self::assertContains(
            'public_shell.security_config_invalid',
            array_column($data['public_shell']['issues'], 'code')
        );
        self::assertContains(
            'public_shell.security_not_ready',
            $data['readiness']['blockers']
        );
        self::assertStringNotContainsString(
            'private-csp.example.test',
            json_encode($data, JSON_THROW_ON_ERROR)
        );
    }

    public function testProjectShellRequiresSemanticViewHookAndEntryContracts(): void
    {
        $this->writeProjectArticleShell();
        $this->filesystem->dumpFile(
            $this->root . '/App/views/blog-article.php',
            "<?php declare(strict_types=1); ?>\n"
                . "<!DOCTYPE html><html><head></head><body></body></html>\n"
        );

        $viewInvalid = $this->inspectProjectShell();

        self::assertSame(
            'contract_invalid',
            $viewInvalid['public_shell']['view']['status']
        );
        self::assertContains(
            'public_shell.view_missing_or_invalid',
            array_column($viewInvalid['public_shell']['issues'], 'code')
        );

        $this->writeProjectArticleShell();
        $this->filesystem->dumpFile(
            $this->root . '/App/app/_moduleBlogPublicArticle.php',
            "<?php declare(strict_types=1);\n"
        );

        $hookInvalid = $this->inspectProjectShell();

        self::assertSame(
            'contract_invalid',
            $hookInvalid['public_shell']['hook']['status']
        );
        self::assertContains(
            'public_shell.hook_missing_or_invalid',
            array_column($hookInvalid['public_shell']['issues'], 'code')
        );

        $this->writeProjectArticleShell();
        $this->filesystem->dumpFile(
            $this->root . '/src/js/blogArticle.js',
            "import '../scss/blogArticle.scss';\n"
        );

        $entryInvalid = $this->inspectProjectShell();

        self::assertSame(
            'contract_invalid',
            $entryInvalid['public_shell']['source_entry']['status']
        );
        self::assertContains(
            'public_shell.source_entry_missing_or_invalid',
            array_column($entryInvalid['public_shell']['issues'], 'code')
        );
    }

    public function testProjectShellAcceptsAliasedLanguageBindingAndCallsItsLocalName(): void
    {
        $this->writeProjectArticleShell();
        $entryPath = $this->root . '/src/js/blogArticle.js';
        $this->filesystem->dumpFile($entryPath, <<<'JS'
import '../scss/blogArticle.scss';
import './_global.js';
import { bindLanguageNavigation as bindProjectLanguages } from './resources/_languagePreference.mjs';

const unbindLanguageNavigation = bindProjectLanguages(window, document);
JS
        );

        $ready = $this->inspectProjectShell();

        self::assertTrue($ready['public_shell']['source_entry']['ready']);
        self::assertSame(
            'ready',
            $ready['public_shell']['source_entry']['status']
        );

        $this->filesystem->dumpFile($entryPath, <<<'JS'
import '../scss/blogArticle.scss';
import './_global.js';
import { bindLanguageNavigation as bindProjectLanguages } from './resources/_languagePreference.mjs';

const unbindLanguageNavigation = bindLanguageNavigation(window, document);
JS
        );

        $wrongLocalName = $this->inspectProjectShell();

        self::assertFalse(
            $wrongLocalName['public_shell']['source_entry']['ready']
        );
        self::assertSame(
            'contract_invalid',
            $wrongLocalName['public_shell']['source_entry']['status']
        );
    }

    public function testProjectShellRejectsInertDefaultAndNamespaceLanguageImports(): void
    {
        $invalidEntries = [
            'comment' => <<<'JS'
import '../scss/blogArticle.scss';
import './_global.js';
// import { bindLanguageNavigation } from './resources/_languagePreference.mjs';
bindLanguageNavigation(window, document);
JS,
            'template string' => <<<'JS'
import '../scss/blogArticle.scss';
import './_global.js';
const inertContract = `
import { bindLanguageNavigation } from './resources/_languagePreference.mjs';
bindLanguageNavigation(window, document);
`;
JS,
            'default import' => <<<'JS'
import '../scss/blogArticle.scss';
import './_global.js';
import bindLanguageNavigation from './resources/_languagePreference.mjs';
bindLanguageNavigation(window, document);
JS,
            'namespace import' => <<<'JS'
import '../scss/blogArticle.scss';
import './_global.js';
import * as languagePreference from './resources/_languagePreference.mjs';
languagePreference.bindLanguageNavigation(window, document);
JS,
        ];

        foreach ($invalidEntries as $case => $entry) {
            $this->writeProjectArticleShell();
            $this->filesystem->dumpFile(
                $this->root . '/src/js/blogArticle.js',
                $entry
            );

            $data = $this->inspectProjectShell();

            self::assertSame(
                'contract_invalid',
                $data['public_shell']['source_entry']['status'],
                $case
            );
        }
    }

    public function testPhpShellContractsRejectInvalidAndInertMarkers(): void
    {
        $this->writeProjectArticleShell();
        $headPath = $this->root . '/App/includes/_globalHead.php';
        $this->filesystem->dumpFile($headPath, <<<'PHP'
<?php

$inertContract = <<<'CONTRACT'
$pageMeta['title']; $pageMeta['headline']; $pageMeta['description'];
$pageMeta['canonical']; $pageMeta['alternates']; $pageMeta['x_default'];
$pageMeta['type']; $pageMeta['image']; $pageMeta['published_at'];
$pageMeta['updated_at']; $cspNonce; nonce=
CONTRACT;
?>
<script nonce="fixed"></script>
PHP
        );

        $inertHead = $this->inspectProjectShell();

        self::assertSame(
            'contract_missing',
            $inertHead['public_shell']['metadata_head']['status']
        );

        $this->filesystem->dumpFile(
            $headPath,
            (string) file_get_contents($headPath) . "\n<?php if ("
        );
        $invalidHead = $this->inspectProjectShell();

        self::assertSame(
            'contract_missing',
            $invalidHead['public_shell']['metadata_head']['status']
        );

        $this->writeProjectArticleShell();
        $this->filesystem->dumpFile(
            $this->root . '/App/views/blog-article.php',
            <<<'PHP'
<?php
require __DIR__ . '/../app/_moduleBlogPublicArticle.php';
$inertContract = <<<'CONTRACT'
$articleHero $articleMain $articleCustomCss $blogPublicRuntimeUrl
$articleTaxonomiesHtml ->headerHtml() ->mainHtml() ->customCss()
->publicRuntimeUrl() data-blog-analytics-enabled
data-blog-analytics-retention-days data-blog-analytics-session-timeout
data-blog-analytics-page-grant id="smooth-wrapper" id="smooth-content"
CONTRACT;
?>
<!DOCTYPE html>
<html><head><script nonce="<?= $cspNonce ?>"></script></head>
<body><div id="smooth-wrapper"><div id="smooth-content"></div></div></body>
</html>
PHP
        );

        $inertView = $this->inspectProjectShell();

        self::assertSame(
            'contract_invalid',
            $inertView['public_shell']['view']['status']
        );

        $this->writeProjectArticleShell();
        $this->filesystem->dumpFile(
            $this->root . '/App/app/_moduleBlogPublicArticle.php',
            <<<'PHP'
<?php
$inertContract = <<<'CONTRACT'
BlogPublicArticleViewModel BlogPublicArticleShellContext $pageMeta['headline']
$cspNonce ->nonce() $blogPublicRuntimeUrl ->publicRuntimeUrl()
$articleHero ->headerHtml() $articleMain ->mainHtml()
$articleCustomCss ->customCss() $articleTaxonomiesHtml $relatedArticles
CONTRACT;
PHP
        );

        $inertHook = $this->inspectProjectShell();

        self::assertSame(
            'contract_invalid',
            $inertHook['public_shell']['hook']['status']
        );
    }

    public function testEveryLiteralScriptMustUseTheRuntimeCspNonce(): void
    {
        $this->writeProjectArticleShell();
        $headPath = $this->root . '/App/includes/_globalHead.php';
        $head = (string) file_get_contents($headPath);
        $this->filesystem->dumpFile(
            $headPath,
            $head . "\n<script nonce=\"fixed\"></script>\n"
        );

        $invalidHead = $this->inspectProjectShell();

        self::assertSame(
            'contract_missing',
            $invalidHead['public_shell']['metadata_head']['status']
        );

        $this->writeProjectArticleShell();
        $viewPath = $this->root . '/App/views/blog-article.php';
        $view = (string) file_get_contents($viewPath);
        $this->filesystem->dumpFile(
            $viewPath,
            str_replace(
                '</head>',
                '<script nonce="fixed"></script></head>',
                $view
            )
        );

        $invalidView = $this->inspectProjectShell();

        self::assertSame(
            'contract_invalid',
            $invalidView['public_shell']['view']['status']
        );
    }

    public function testCanonicalViewMustActuallyComposeTheGlobalShell(): void
    {
        $this->writeProjectArticleShell();
        $path = $this->root . '/App/views/blog-article.php';
        $source = (string) file_get_contents($path);
        $source = str_replace(
            "    <?php include __DIR__ . '/../includes/_nav.php' ?>\n",
            '',
            $source
        );
        $this->filesystem->dumpFile($path, $source);

        $data = $this->inspectProjectShell();

        self::assertSame(
            'contract_invalid',
            $data['public_shell']['view']['status']
        );
        self::assertContains(
            'public_shell.view_missing_or_invalid',
            array_column($data['public_shell']['issues'], 'code')
        );
    }

    public function testNonCanonicalProjectViewStillRequiresHookAndEntry(): void
    {
        $this->writeProjectArticleShell();
        $this->filesystem->copy(
            $this->root . '/App/views/blog-article.php',
            $this->root . '/App/views/custom-news.php'
        );
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/blog.php',
            "<?php\nreturn ['public_article_view' => "
                . "'App/views/custom-news.php'];\n"
        );

        $ready = $this->inspectProjectShell();

        self::assertTrue($ready['public_shell']['ready']);
        self::assertSame(
            'project_owned',
            $ready['public_shell']['dependencies']['profile']
        );

        $this->filesystem->remove([
            $this->root . '/App/app/_moduleBlogPublicArticle.php',
            $this->root . '/src/js/blogArticle.js',
        ]);

        $incomplete = $this->inspectProjectShell();

        self::assertFalse($incomplete['public_shell']['ready']);
        self::assertTrue($incomplete['public_shell']['view']['ready']);
        self::assertFalse($incomplete['public_shell']['hook']['ready']);
        self::assertFalse($incomplete['public_shell']['source_entry']['ready']);
    }

    public function testStaticCookieLadLoaderRequiresEffectiveCspSources(): void
    {
        $this->writeProjectArticleShell();
        $headPath = $this->root . '/App/includes/_globalHead.php';
        $head = file_get_contents($headPath);
        self::assertIsString($head);
        $this->filesystem->dumpFile(
            $headPath,
            $head . <<<'HTML'

<!-- <script src="https://webda.eus/apis/cookielad/loader.js"></script> -->
<?php // https://webda.eus/apis/cookielad/loader.js ?>
HTML
        );

        $commentedLoader = $this->inspectProjectShell();

        self::assertTrue($commentedLoader['public_shell']['ready']);
        self::assertFalse(
            $commentedLoader['public_shell']['security_config']['cookie_lad']['detected']
        );
        $this->filesystem->dumpFile(
            $headPath,
            $head . <<<'HTML'

<script nonce="<?= $cspNonce ?>" defer src="https://webda.eus/apis/cookielad/loader.js"></script>
HTML
        );

        $missingSources = $this->inspectProjectShell();

        self::assertFalse($missingSources['public_shell']['ready']);
        self::assertSame(
            'cookielad_sources_missing',
            $missingSources['public_shell']['security_config']['status']
        );
        self::assertTrue(
            $missingSources['public_shell']['security_config']['cookie_lad']['detected']
        );
        self::assertFalse(
            $missingSources['public_shell']['security_config']['cookie_lad']['ready']
        );
        self::assertContains(
            'public_shell.cookielad_csp_not_ready',
            array_column($missingSources['public_shell']['issues'], 'code')
        );
        self::assertStringNotContainsString(
            'webda.eus',
            json_encode($missingSources, JSON_THROW_ON_ERROR)
        );

        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/blog-public.php',
            <<<'PHP'
<?php

return [
    'security_sources' => [
        'script' => ['https://webda.eus'],
        'style' => ['https://webda.eus'],
        'image' => ['https://webda.eus'],
        'connect' => ['https://webda.eus'],
    ],
];
PHP
        );

        $authorized = $this->inspectProjectShell();

        self::assertTrue($authorized['public_shell']['ready']);
        self::assertSame(
            'configured',
            $authorized['public_shell']['security_config']['status']
        );
        self::assertTrue(
            $authorized['public_shell']['security_config']['cookie_lad']['ready']
        );
        self::assertStringNotContainsString(
            'webda.eus',
            json_encode($authorized, JSON_THROW_ON_ERROR)
        );
    }

    public function testStandaloneShellIsReadyWithNonBlockingRecommendation(): void
    {
        $data = $this->inspect($this->appliedPlan())->toArray();

        self::assertTrue($data['readiness']['blog_ready']);
        self::assertSame('standalone', $data['public_shell']['mode']);
        self::assertTrue($data['public_shell']['ready']);
        self::assertFalse($data['public_shell']['complete']);
        self::assertSame(
            'public_shell.configure_project_view',
            $data['public_shell']['recommendation']
        );
        self::assertSame([[
            'code' => 'public_shell.project_view_recommended',
            'blocking' => false,
        ]], $data['public_shell']['issues']);
        self::assertNotContains(
            'public_shell.project_not_ready',
            $data['readiness']['blockers']
        );
    }

    public function testConfiguredProjectShellMissingPartsBlocksReadiness(): void
    {
        $this->writeProjectArticleShell(false, false);

        $data = (new BlogDiagnosticService())->inspect(
            $this->root,
            ['es', 'en'],
            $this->developmentEnvironment(),
            '/admin',
            true,
            $this->appliedPlan(),
            true
        )->toArray();

        self::assertFalse($data['readiness']['blog_ready']);
        self::assertSame('project', $data['public_shell']['mode']);
        self::assertSame('incomplete', $data['public_shell']['status']);
        self::assertFalse($data['public_shell']['hook']['ready']);
        self::assertFalse($data['public_shell']['source_entry']['ready']);
        self::assertContains(
            'public_shell.project_not_ready',
            $data['readiness']['blockers']
        );
        self::assertSame([
            'public_shell.hook_missing_or_invalid',
            'public_shell.source_entry_missing_or_invalid',
        ], array_column($data['public_shell']['issues'], 'code'));
    }

    public function testConfiguredMissingProjectViewHasDedicatedShellBlocker(): void
    {
        $this->writeProjectArticleShell();
        $this->filesystem->remove(
            $this->root . '/App/views/blog-article.php'
        );

        $data = (new BlogDiagnosticService())->inspect(
            $this->root,
            ['es', 'en'],
            $this->developmentEnvironment(),
            '/admin',
            true,
            $this->appliedPlan(),
            true
        )->toArray();

        self::assertFalse($data['configuration']['ready']);
        self::assertSame('project', $data['public_shell']['mode']);
        self::assertNull($data['public_shell']['view']['path']);
        self::assertFalse($data['public_shell']['view']['ready']);
        self::assertContains(
            'configuration.invalid',
            $data['readiness']['blockers']
        );
        self::assertContains(
            'public_shell.project_not_ready',
            $data['readiness']['blockers']
        );
    }

    public function testProjectShellRequiresCompleteManifestInProduction(): void
    {
        $this->writeProjectArticleShell();
        $service = new BlogDiagnosticService();
        $environment = [
            BlogPublicOrigin::PROJECT_ORIGIN_ENV =>
                'https://example.test',
        ];

        $missing = $service->inspect(
            $this->root,
            ['es', 'en'],
            $environment,
            '/admin',
            true,
            $this->appliedPlan(),
            true
        )->toArray();
        self::assertFalse($missing['readiness']['blog_ready']);
        self::assertTrue(
            $missing['public_shell']['production_manifest']['required']
        );
        self::assertSame(
            'missing_or_invalid',
            $missing['public_shell']['production_manifest']['status']
        );

        $this->filesystem->mkdir([
            $this->root . '/public/.vite',
            $this->root . '/public/assets/css',
            $this->root . '/public/assets/js',
        ]);
        $this->filesystem->dumpFile(
            $this->root . '/public/assets/css/blogArticle-test.css',
            '/* fixture */'
        );
        $this->filesystem->dumpFile(
            $this->root . '/public/assets/js/blogArticle-test.js',
            'export default true;'
        );
        $this->filesystem->dumpFile(
            $this->root . '/public/.vite/manifest.json',
            json_encode([
                'src/js/blogArticle.js' => [
                    'file' => 'assets/js/blogArticle-test.js',
                    'css' => ['assets/css/blogArticle-test.css'],
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $ready = $service->inspect(
            $this->root,
            ['es', 'en'],
            $environment,
            '/admin',
            true,
            $this->appliedPlan(),
            true
        )->toArray();
        self::assertTrue($ready['readiness']['blog_ready']);
        self::assertSame(
            'ready',
            $ready['public_shell']['production_manifest']['status']
        );
        self::assertTrue($ready['public_shell']['ready']);
        self::assertSame([], $ready['public_shell']['issues']);
    }

    public function testProductionManifestKeepsCssAndJavascriptExtensionsDistinct(): void
    {
        $this->writeProjectArticleShell();
        $this->filesystem->mkdir([
            $this->root . '/public/.vite',
            $this->root . '/public/assets/css',
            $this->root . '/public/assets/js',
        ]);
        $this->filesystem->dumpFile(
            $this->root . '/public/assets/js/blogArticle-test.css',
            '/* wrong extension */'
        );
        $this->filesystem->dumpFile(
            $this->root . '/public/assets/css/blogArticle-test.js',
            'export default true;'
        );
        $this->filesystem->dumpFile(
            $this->root . '/public/.vite/manifest.json',
            json_encode([
                'src/js/blogArticle.js' => [
                    'file' => 'assets/js/blogArticle-test.css',
                    'css' => ['assets/css/blogArticle-test.js'],
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $data = (new BlogDiagnosticService())->inspect(
            $this->root,
            ['es', 'en'],
            [BlogPublicOrigin::PROJECT_ORIGIN_ENV =>
                'https://example.test'],
            '/admin',
            true,
            $this->appliedPlan(),
            true
        )->toArray();

        self::assertFalse($data['public_shell']['ready']);
        self::assertSame(
            'assets_missing_or_invalid',
            $data['public_shell']['production_manifest']['status']
        );
        self::assertContains(
            'public_shell.production_bundle_not_ready',
            array_column($data['public_shell']['issues'], 'code')
        );
    }

    public function testProductionManifestWalksReachableImportsAndCycles(): void
    {
        $this->writeProjectArticleShell();
        $this->filesystem->mkdir([
            $this->root . '/public/.vite',
            $this->root . '/public/assets/css',
            $this->root . '/public/assets/fonts',
            $this->root . '/public/assets/js',
            $this->root . '/public/assets/media',
        ]);
        foreach ([
            'public/assets/css/blogArticle-test.css' => '/* root */',
            'public/assets/css/shared-test.css' => '/* shared */',
            'public/assets/fonts/blog-test.woff2' => 'font',
            'public/assets/js/blogArticle-test.js' => 'export default true;',
            'public/assets/js/lazy-test.js' => 'export default true;',
            'public/assets/js/shared-test.js' => 'export default true;',
            'public/assets/media/cover-test.avif' => 'image',
        ] as $relativePath => $contents) {
            $this->filesystem->dumpFile(
                $this->root . '/' . $relativePath,
                $contents
            );
        }
        $manifest = [
            'src/js/blogArticle.js' => [
                'file' => 'assets/js/blogArticle-test.js',
                'css' => ['assets/css/blogArticle-test.css'],
                'imports' => ['_shared.js'],
                'dynamicImports' => ['_lazy.js'],
            ],
            '_shared.js' => [
                'file' => 'assets/js/shared-test.js',
                'css' => ['assets/css/shared-test.css'],
                'assets' => ['assets/media/cover-test.avif'],
                'imports' => ['_lazy.js'],
            ],
            '_lazy.js' => [
                'file' => 'assets/js/lazy-test.js',
                'assets' => ['assets/fonts/blog-test.woff2'],
                'dynamicImports' => ['src/js/blogArticle.js'],
            ],
        ];
        $manifestPath = $this->root . '/public/.vite/manifest.json';
        $this->filesystem->dumpFile(
            $manifestPath,
            json_encode($manifest, JSON_THROW_ON_ERROR)
        );
        $environment = [
            BlogPublicOrigin::PROJECT_ORIGIN_ENV => 'https://example.test',
        ];
        $service = new BlogDiagnosticService();

        $ready = $service->inspect(
            $this->root,
            ['es', 'en'],
            $environment,
            '/admin',
            true,
            $this->appliedPlan(),
            true
        )->toArray();

        self::assertTrue($ready['public_shell']['ready']);
        self::assertSame(
            'ready',
            $ready['public_shell']['production_manifest']['status']
        );

        $this->filesystem->remove(
            $this->root . '/public/assets/media/cover-test.avif'
        );
        $missingNestedAsset = $service->inspect(
            $this->root,
            ['es', 'en'],
            $environment,
            '/admin',
            true,
            $this->appliedPlan(),
            true
        )->toArray();

        self::assertFalse($missingNestedAsset['public_shell']['ready']);
        self::assertSame(
            'assets_missing_or_invalid',
            $missingNestedAsset['public_shell']['production_manifest']['status']
        );

        $this->filesystem->dumpFile(
            $this->root . '/public/assets/media/cover-test.avif',
            'image'
        );
        $manifest['src/js/blogArticle.js']['imports'][] = '_missing.js';
        $this->filesystem->dumpFile(
            $manifestPath,
            json_encode($manifest, JSON_THROW_ON_ERROR)
        );
        $missingChunk = $service->inspect(
            $this->root,
            ['es', 'en'],
            $environment,
            '/admin',
            true,
            $this->appliedPlan(),
            true
        )->toArray();

        self::assertSame(
            'assets_missing_or_invalid',
            $missingChunk['public_shell']['production_manifest']['status']
        );
    }

    public function testEnabledSitemapCacheWithoutPrivateStorageBlocksReadiness(): void
    {
        $this->filesystem->mkdir($this->root . '/App/config/modules');
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/blog.php',
            "<?php\nreturn ['sitemap_cache' => ['enabled' => true]];\n"
        );
        $plan = $this->appliedPlan();

        $data = $this->inspect($plan)->toArray();

        self::assertFalse($data['readiness']['blog_ready']);
        self::assertTrue($data['sitemap_cache']['enabled']);
        self::assertFalse($data['sitemap_cache']['ready']);
        self::assertSame(
            'storage_not_ready',
            $data['sitemap_cache']['status']
        );
        self::assertContains(
            'sitemap_cache.not_ready',
            $data['readiness']['blockers']
        );
    }

    public function testSitemapCacheReadinessRequiresMatchingDbGeneration(): void
    {
        $this->filesystem->mkdir($this->root . '/App/config/modules');
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/blog.php',
            "<?php\nreturn ['sitemap_cache' => ['enabled' => true]];\n"
        );
        $environment = [
            'RAIZ' => 'http://localhost:1309',
            'DEV_MODE' => '1',
        ];
        $storage = PrivateBlogSitemapCacheStorage::forProject(
            $this->root,
            $environment
        );
        $generation = $storage->initialize()->generation();
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec(<<<'SQL'
CREATE TABLE ls_blog_sitemap_state (
    state_key TEXT PRIMARY KEY,
    public_revision INTEGER NOT NULL,
    cache_generation TEXT NULL,
    updated_at TEXT NOT NULL
)
SQL);
        $insert = $pdo->prepare(
            'INSERT INTO ls_blog_sitemap_state '
            . '(state_key, public_revision, cache_generation, updated_at) '
            . "VALUES ('sitemap', 1, :generation, "
            . "'2026-08-03 12:00:00.000000')"
        );
        $insert->execute(['generation' => $generation]);

        $service = new BlogDiagnosticService();
        $matched = $service->inspect(
            $this->root,
            ['es', 'en'],
            $environment,
            '/admin',
            true,
            $this->sitemapPlan(),
            true,
            [],
            $pdo
        )->toArray();
        self::assertTrue($matched['sitemap_cache']['ready']);
        self::assertSame('matched', $matched['sitemap_cache']['generation']);

        $pdo->exec(
            "UPDATE ls_blog_sitemap_state SET cache_generation = "
            . "'123e4567-e89b-42d3-a456-426614174000'"
        );
        $mismatch = $service->inspect(
            $this->root,
            ['es', 'en'],
            $environment,
            '/admin',
            true,
            $this->sitemapPlan(),
            true,
            [],
            $pdo
        )->toArray();
        self::assertFalse($mismatch['sitemap_cache']['ready']);
        self::assertSame(
            'generation_mismatch',
            $mismatch['sitemap_cache']['status']
        );
        self::assertContains(
            'sitemap_cache.not_ready',
            $mismatch['readiness']['blockers']
        );
    }

    public function testLegacyOriginMismatchIsAVisibleNonBlockingCompatibilityState(): void
    {
        $report = (new BlogDiagnosticService())->inspect(
            $this->root,
            ['es', 'en'],
            [
                BlogPublicOrigin::PROJECT_ORIGIN_ENV =>
                    'https://canonical.example.test',
                BlogPublicOrigin::ENV =>
                    'https://legacy.example.test',
            ],
            '/admin',
            true,
            $this->appliedPlan(),
            true
        );
        $data = $report->toArray();

        self::assertTrue($report->isReady());
        self::assertTrue(
            $data['environment']['public_origin'][
                'legacy_compatibility_override'
            ]
        );
        self::assertSame(
            BlogPublicOrigin::SOURCE_LEGACY_COMPATIBILITY,
            $data['environment']['public_origin']['source']
        );
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(
            'canonical.example.test',
            $encoded
        );
        self::assertStringNotContainsString('legacy.example.test', $encoded);
    }

    public function testLiquidStackProfileIsReportedWithoutOriginPrefixOrDatabaseSecrets(): void
    {
        $this->writeModuleDatabaseConfig(
            'blog',
            'liquidstack',
            'private_blog_'
        );
        $this->writeModuleDatabaseConfig(
            'webadmin',
            'liquidstack',
            'private_webadmin_'
        );
        $environment = [
            BlogPublicOrigin::ENV => 'https://private-origin.example.test',
            'LIQUIDSTACK_DB_PASSWORD' => 'private-database-password',
            'BBDD_PASS' => 'unused-legacy-password',
        ];

        $report = (new BlogDiagnosticService())->inspect(
            $this->root,
            ['es', 'en'],
            $environment,
            '/admin',
            true,
            $this->appliedPlan(),
            true
        );
        $data = $report->toArray();
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);

        self::assertTrue($report->isReady());
        self::assertTrue($data['configuration']['ready']);
        self::assertSame(
            'liquidstack',
            $data['configuration']['effective']['database']['connection']
        );
        self::assertSame([], $data['configuration']['issues']);
        foreach ([
            'https://private-origin.example.test',
            'private_blog_',
            'private_webadmin_',
            'private-database-password',
            'unused-legacy-password',
        ] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $encoded);
        }
    }

    /** @dataProvider databaseConnectionMismatchProvider */
    public function testDatabaseConnectionMismatchFailsClosedWithStableSafeIssue(
        string $blogConnection,
        string $webAdminConnection
    ): void {
        $this->writeModuleDatabaseConfig(
            'blog',
            $blogConnection,
            'mismatch_private_blog_'
        );
        $this->writeModuleDatabaseConfig(
            'webadmin',
            $webAdminConnection,
            'mismatch_private_webadmin_'
        );
        $environment = [
            BlogPublicOrigin::ENV => 'https://mismatch-private.example.test',
            'LIQUIDSTACK_DB_PASSWORD' => 'mismatch-dedicated-secret',
            'BBDD_PASS' => 'mismatch-shared-secret',
        ];

        $report = (new BlogDiagnosticService())->inspect(
            $this->root,
            ['es', 'en'],
            $environment,
            '/admin',
            true,
            $this->appliedPlan(),
            true
        );
        $data = $report->toArray();
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);

        self::assertFalse($report->isReady());
        self::assertFalse($data['configuration']['ready']);
        self::assertSame(
            $blogConnection,
            $data['configuration']['effective']['database']['connection']
        );
        self::assertSame([[
            'code' => 'database.connection_mismatch',
            'key' => 'database.connection',
        ]], $data['configuration']['issues']);
        self::assertSame(
            ['configuration.invalid'],
            $data['readiness']['blockers']
        );
        foreach ([
            'mismatch_private_blog_',
            'mismatch_private_webadmin_',
            'https://mismatch-private.example.test',
            'mismatch-dedicated-secret',
            'mismatch-shared-secret',
        ] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $encoded);
        }
    }

    public static function databaseConnectionMismatchProvider(): iterable
    {
        yield 'Blog dedicated and WebAdmin shared' => [
            'liquidstack',
            'shared',
        ];
        yield 'Blog shared and WebAdmin dedicated' => [
            'shared',
            'liquidstack',
        ];
    }

    public function testPendingCrossScopeCapabilityMigrationBlocksReadiness(): void
    {
        $plan = $this->plan('applied', 'pending');
        $data = $this->inspect($plan)->toArray();

        self::assertFalse($data['readiness']['blog_ready']);
        self::assertSame(
            ['0002_blog_capabilities'],
            $data['database']['pending']
        );
        self::assertContains(
            'database.migrations_not_ready',
            $data['readiness']['blockers']
        );
        self::assertFalse($data['database']['public_content']['ready']);
        self::assertFalse($data['database']['administration']['ready']);
    }

    public function testFuturePendingMigrationDoesNotBlockCurrentBlogRuntime(): void
    {
        $plan = new MigrationDatabasePlan(
            'sqlite',
            true,
            [
                ...$this->runtimeEntries('applied', 'applied'),
                $this->entry('0030_future_feature', 'pending', null),
            ],
            []
        );
        $data = $this->inspect($plan)->toArray();

        self::assertTrue($data['readiness']['blog_ready']);
        self::assertSame('applied', $data['database']['status']);
        self::assertTrue($data['database']['public_content']['ready']);
        self::assertTrue($data['database']['administration']['ready']);
        self::assertFalse($data['database']['features']['ready']);
        self::assertSame(
            ['0030_future_feature'],
            $data['database']['features']['pending']
        );
    }

    public function testConfigurationOriginDependencyAndDatabaseFailIndependently(): void
    {
        $this->filesystem->mkdir($this->root . '/App/config/modules');
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/blog.php',
            "<?php\nreturn ['database' => ['password' => 'secret']];\n"
        );
        $report = (new BlogDiagnosticService())->inspect(
            $this->root,
            ['es'],
            [],
            null,
            false,
            null,
            true
        );
        $data = $report->toArray();
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);

        self::assertFalse($report->isReady());
        self::assertContains(
            'configuration.invalid',
            $data['readiness']['blockers']
        );
        self::assertContains(
            'environment.public_origin_invalid',
            $data['readiness']['blockers']
        );
        self::assertContains(
            'dependency.webadmin_not_ready',
            $data['readiness']['blockers']
        );
        self::assertStringNotContainsString('secret', $encoded);
    }

    public function testNoDatabaseModeIsExplicitlyNotReadyAndReadOnly(): void
    {
        $data = $this->inspect(null, false)->toArray();

        self::assertFalse($data['readiness']['blog_ready']);
        self::assertSame('not_checked', $data['database']['status']);
        self::assertContains(
            'database.not_checked',
            $data['readiness']['blockers']
        );
    }

    public function testRequiredAssetsAreValidatedInsideProject(): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/public/blog-admin.css',
            '/* fixture */'
        );
        $this->filesystem->mkdir(
            $this->root . '/public/directory-masquerading-as-runtime.js'
        );

        $report = (new BlogDiagnosticService())->inspect(
            $this->root,
            ['es', 'en'],
            [BlogPublicOrigin::ENV => 'https://example.test'],
            '/admin',
            true,
            $this->appliedPlan(),
            true,
            [
                'public/blog-admin.css',
                'public/missing.js',
                'public/directory-masquerading-as-runtime.js',
                '../outside-project.txt',
            ]
        );
        $data = $report->toArray();

        self::assertFalse($report->isReady());
        self::assertSame(
            [
                'public/missing.js',
                'public/directory-masquerading-as-runtime.js',
            ],
            $data['assets']['missing']
        );
        self::assertSame(
            ['../outside-project.txt'],
            $data['assets']['invalid']
        );
        self::assertContains(
            'assets.missing_or_invalid',
            $data['readiness']['blockers']
        );
    }

    /** @return array<string, mixed> */
    private function inspectProjectShell(): array
    {
        return (new BlogDiagnosticService())->inspect(
            $this->root,
            ['es', 'en'],
            $this->developmentEnvironment(),
            '/admin',
            true,
            $this->appliedPlan(),
            true
        )->toArray();
    }

    private function writeProjectArticleShell(
        bool $withHook = true,
        bool $withSourceEntry = true
    ): void {
        $this->filesystem->mkdir([
            $this->root . '/App/app',
            $this->root . '/App/config/languages/global',
            $this->root . '/App/config/modules',
            $this->root . '/App/controllers',
            $this->root . '/App/includes',
            $this->root . '/App/templates',
            $this->root . '/App/views',
            $this->root . '/src/js',
            $this->root . '/src/js/resources',
            $this->root . '/src/scss/resources',
        ]);
        $this->filesystem->dumpFile(
            $this->root . '/App/views/blog-article.php',
            <<<'PHP'
<?php

declare(strict_types=1);

require __DIR__ . '/../app/_moduleBlogPublicArticle.php';
?>
<!DOCTYPE html>
<html lang="<?= $escape($lang) ?>"<?php if ($blogArticle->analyticsEnabled()): ?>
      data-blog-analytics-enabled="true"
      data-blog-analytics-retention-days="<?= $escape($blogArticle->analyticsRetentionDays()) ?>"
      data-blog-analytics-session-timeout="<?= $escape($blogArticle->analyticsSessionTimeoutSeconds()) ?>"
      data-blog-analytics-page-grant="<?= $escape($blogArticle->analyticsPageGrant()) ?>"<?php endif ?>>
<head>
    <?php include_once __DIR__ . '/../includes/_globalHead.php' ?>
    <style nonce="<?= $escape($cspNonce) ?>"><?= $articleCustomCss ?></style>
    <script nonce="<?= $escape($cspNonce) ?>" src="<?= $escape($blogPublicRuntimeUrl) ?>"></script>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/_globalBody.php' ?>
    <?php include __DIR__ . '/../includes/_nav.php' ?>
    <div id="smooth-wrapper">
        <div id="smooth-content">
            <?= $articleHero ?>
            <?= $articleTaxonomiesHtml ?>
            <?= $articleMain ?>
            <?php include __DIR__ . '/../includes/_footer.php' ?>
        </div>
    </div>
</body>
</html>
PHP
        );
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/blog.php',
            "<?php\nreturn ['public_article_view' => "
                . "'App/views/blog-article.php'];\n"
        );
        if ($withHook) {
            $this->filesystem->dumpFile(
                $this->root . '/App/app/_moduleBlogPublicArticle.php',
                <<<'PHP'
<?php

declare(strict_types=1);

use App\Core\Blog\Http\BlogPublicArticleShellContext;
use App\Core\Blog\Http\BlogPublicArticleViewModel;

$pageMeta = [
    'headline' => $blogArticle->h1(),
];
$cspNonce = $blogArticleShell->nonce();
$blogPublicRuntimeUrl = $blogArticleShell->publicRuntimeUrl();
$articleHero = $blogArticle->headerHtml();
$articleMain = $blogArticle->mainHtml();
$articleCustomCss = $blogArticle->customCss();
$articleTaxonomiesHtml = '';
$relatedArticles = [];
PHP
            );
        }
        if ($withSourceEntry) {
            $this->filesystem->dumpFile(
                $this->root . '/src/js/blogArticle.js',
                <<<'JS'
import '../scss/blogArticle.scss';
import './_global.js';
import { bindLanguageNavigation } from './resources/_languagePreference.mjs';

const unbindLanguageNavigation = bindLanguageNavigation(window, document);

if (import.meta.hot) {
  import.meta.hot.dispose(unbindLanguageNavigation);
}
JS
            );
        }
        foreach (['es', 'en'] as $language) {
            $this->filesystem->dumpFile(
                $this->root . '/App/config/languages/global/'
                    . $language . '.json',
                "{}\n"
            );
        }
        $dependencies = [
            'App/includes/_globalHead.php' =>
                <<<'PHP'
<?php

$metadataFixture = [
    $pageMeta['title'] ?? '',
    $pageMeta['headline'] ?? '',
    $pageMeta['description'] ?? '',
    $pageMeta['canonical'] ?? '',
    $pageMeta['alternates'] ?? [],
    $pageMeta['x_default'] ?? null,
    $pageMeta['type'] ?? 'website',
    $pageMeta['image'] ?? null,
    $pageMeta['published_at'] ?? null,
    $pageMeta['updated_at'] ?? null,
];
$nonceAttribute = ' nonce="' . $cspNonce . '"';
?>
<script<?= $nonceAttribute ?>></script>
PHP,
            'App/includes/_globalBody.php' => "<?php\n",
            'App/includes/_nav.php' => "<?php\n",
            'App/includes/_footer.php' => "<?php\n",
            'App/controllers/_moduleBlogResources.php' => "<?php\n",
            'App/controllers/sectionBlogRelated01.php' => "<?php\n",
            'App/templates/_sectionBlogRelated01.html' => "<section></section>\n",
            'App/controllers/moduleButtonType04.php' => "<?php\n",
            'App/templates/_moduleButtonType04.html' => "<a></a>\n",
            'src/js/_global.js' => "export default true;\n",
            'src/js/resources/_languagePreference.mjs' =>
                "export const bindLanguageNavigation = () => () => {};\n",
            'src/scss/blogArticle.scss' => "/* fixture */\n",
            'src/scss/_config.scss' => "/* fixture */\n",
            'src/scss/_global.scss' => "/* fixture */\n",
            'src/scss/resources/_hero00.scss' => "/* fixture */\n",
            'src/scss/resources/_hero06.scss' => "/* fixture */\n",
            'src/scss/resources/_hero07.scss' => "/* fixture */\n",
            'src/scss/resources/_moduleH1Type01.scss' => "/* fixture */\n",
            'src/scss/resources/_moduleH1Type03.scss' => "/* fixture */\n",
            'src/scss/resources/_moduleH1Type04.scss' => "/* fixture */\n",
            'src/scss/resources/_artBlogArticle01.scss' => "/* fixture */\n",
            'src/scss/resources/_moduleButtonType04.scss' => "/* fixture */\n",
            'src/scss/resources/_sectionBlogRelated01.scss' => "/* fixture */\n",
        ];
        foreach ($dependencies as $relativePath => $contents) {
            $this->filesystem->dumpFile(
                $this->root . '/' . $relativePath,
                $contents
            );
        }
    }

    /** @return array<string, string> */
    private function developmentEnvironment(): array
    {
        return [
            BlogPublicOrigin::PROJECT_ORIGIN_ENV =>
                'http://localhost:1309',
            'DEV_MODE' => '1',
        ];
    }

    private function inspect(
        ?MigrationDatabasePlan $plan,
        bool $inspectDatabase = true
    ): \App\Core\Blog\Diagnostics\BlogDiagnosticReport {
        return (new BlogDiagnosticService())->inspect(
            $this->root,
            ['es', 'en'],
            [BlogPublicOrigin::ENV => 'https://example.test'],
            '/admin',
            true,
            $plan,
            $inspectDatabase
        );
    }

    private function appliedPlan(): MigrationDatabasePlan
    {
        return $this->plan('applied', 'applied');
    }

    private function tagPlan(): MigrationDatabasePlan
    {
        $entries = $this->runtimeEntries('applied', 'applied');
        foreach ([
            '0020_blog_tags' => null,
            '0021_blog_localization_tags' => null,
            '0022_blog_tag_assignment_heads' => null,
            '0023_blog_tag_assignment_workspaces' => null,
            '0024_blog_tag_assignment_workspace_items' => null,
            '0025_blog_tag_capabilities' => 'webadmin',
        ] as $id => $target) {
            $entries[] = $this->entry($id, 'applied', $target);
        }

        return new MigrationDatabasePlan('sqlite', true, $entries, []);
    }

    private function tagDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $blogScope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $webAdminScope = MigrationScope::forTablePrefix(
            'webadmin',
            'ls_webadmin_'
        );
        $webAdminMigration = iterator_to_array(
            WebAdminMigrationProvider::migrations(),
            false
        )[0];
        foreach ($webAdminMigration->statementsFor(
            'sqlite',
            $webAdminScope
        ) as $sql) {
            $pdo->exec($sql);
        }
        foreach (BlogMigrationProvider::migrations() as $migration) {
            $scope = $migration->targetScopeModuleId() === 'webadmin'
                ? $webAdminScope : $blogScope;
            if (
                $migration->targetScopeModuleId() !== null
                && $migration->id() !== '0025_blog_tag_capabilities'
            ) {
                continue;
            }
            foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
                $pdo->exec($sql);
            }
        }

        return $pdo;
    }

    private function sitemapPlan(): MigrationDatabasePlan
    {
        return $this->appliedPlan();
    }

    private function plan(
        string $schemaStatus,
        string $capabilityStatus
    ): MigrationDatabasePlan {
        return new MigrationDatabasePlan(
            'sqlite',
            true,
            $this->runtimeEntries($schemaStatus, $capabilityStatus),
            []
        );
    }

    /** @return list<array<string, mixed>> */
    private function runtimeEntries(
        string $schemaStatus,
        string $capabilityStatus
    ): array {
        $targets = [
            '0001_blog_posts' => null,
            '0002_blog_capabilities' => 'webadmin',
            '0003_blog_categories' => null,
            '0004_blog_category_capabilities' => 'webadmin',
            '0005_blog_structured_content' => null,
            '0006_blog_sitemap_publication_state' => null,
            '0007_blog_post_tombstones' => null,
            '0008_blog_article_delete_capability' => 'webadmin',
            '0009_blog_analytics' => null,
            '0010_blog_analytics_view_capability' => 'webadmin',
            '0011_blog_layout_editor_v2' => null,
            '0012_blog_editor_preferences' => null,
            '0013_blog_settings_manage_capability' => 'webadmin',
            '0014_blog_private_draft_publication' => null,
            '0015_blog_robots_preferences' => null,
            '0016_blog_url_history' => null,
            '0017_blog_dummy_category' => null,
            '0018_blog_dummy_category_normalization' => null,
            '0019_blog_copy_operation_idempotency' => null,
        ];
        $entries = [];
        foreach ($targets as $id => $target) {
            $status = $id === '0001_blog_posts'
                ? $schemaStatus
                : ($id === '0002_blog_capabilities'
                    ? $capabilityStatus : 'applied');
            $entries[] = $this->entry($id, $status, $target);
        }

        return $entries;
    }

    /** @return array<string, mixed> */
    private function entry(
        string $id,
        string $status,
        ?string $target
    ): array {
        return [
            'module' => 'blog',
            'target_scope_module' => $target,
            'id' => $id,
            'description' => 'not exposed',
            'checksum' => str_repeat('a', 64),
            'scope_hash' => str_repeat('b', 64),
            'destructive' => false,
            'status' => $status,
        ];
    }

    private function writeModuleDatabaseConfig(
        string $module,
        string $connection,
        string $tablePrefix
    ): void {
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/' . $module . '.php',
            "<?php\n\nreturn " . var_export([
                'database' => [
                    'connection' => $connection,
                    'table_prefix' => $tablePrefix,
                ],
            ], true) . ";\n"
        );
    }
}
