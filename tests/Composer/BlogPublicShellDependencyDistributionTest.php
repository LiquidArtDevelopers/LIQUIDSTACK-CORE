<?php

declare(strict_types=1);

use App\Core\Composer\ManagedFileRegistry;
use App\Core\Modules\ModuleCatalog;
use PHPUnit\Framework\TestCase;

final class BlogPublicShellDependencyDistributionTest extends TestCase
{
    public function testCoreDistributesEveryReusableShellDependency(): void
    {
        $root = dirname(__DIR__, 2);
        $blog = ModuleCatalog::fromCoreRoot($root)->get('blog');
        $moduleFiles = [];
        foreach ($blog->projectFiles() as $entry) {
            if ($entry['type'] === 'file') {
                $moduleFiles[$entry['target']] = $entry['source'];
            }
        }

        foreach ([
            'App/app/_moduleBlogPublicArticle.php' =>
                'resources/project/App/app/_moduleBlogPublicArticle.php',
            'App/views/blog-article.php' =>
                'resources/project/App/views/blog-article.php',
            'src/js/blogArticle.js' =>
                'resources/project/src/js/blogArticle.js',
            'src/scss/blogArticle.scss' =>
                'resources/project/src/scss/blogArticle.scss',
            'App/controllers/_moduleBlogResources.php' =>
                'resources/project/App/controllers/_moduleBlogResources.php',
            'App/controllers/sectionBlogRelated01.php' =>
                'resources/project/App/controllers/sectionBlogRelated01.php',
            'App/templates/_sectionBlogRelated01.html' =>
                'resources/project/App/templates/_sectionBlogRelated01.html',
            'src/scss/resources/_artBlogArticle01.scss' =>
                'resources/project/src/scss/resources/'
                    . '_artBlogArticle01.scss',
            'src/scss/resources/_sectionBlogRelated01.scss' =>
                'resources/project/src/scss/resources/'
                    . '_sectionBlogRelated01.scss',
        ] as $target => $source) {
            self::assertSame($source, $moduleFiles[$target] ?? null, $target);
            self::assertFileExists(
                $root . '/modules/blog/' . $source,
                $source
            );
        }

        foreach ([
            'resources/js/_languagePreference.mjs',
            'resources/scss/_hero00.scss',
            'resources/scss/_hero06.scss',
            'resources/scss/_hero07.scss',
            'resources/scss/_moduleH1Type01.scss',
            'resources/scss/_moduleH1Type03.scss',
            'resources/scss/_moduleH1Type04.scss',
            'resources/scss/_moduleButtonType04.scss',
            'stubs/App/controllers/moduleButtonType04.php',
            'stubs/App/templates/_moduleButtonType04.html',
        ] as $source) {
            self::assertFileExists($root . '/' . $source, $source);
            self::assertSame(
                ManagedFileRegistry::POLICY_MANAGED,
                ManagedFileRegistry::policyForSource($source),
                $source
            );
        }

        self::assertFileExists(
            $root . '/modules/blog/published/assets/blog-public.js'
        );
        $composer = json_decode(
            (string) file_get_contents($root . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertContains(
            'src/Core/Support/helpers.php',
            $composer['autoload']['files'] ?? []
        );
        $helpers = (string) file_get_contents(
            $root . '/src/Core/Support/helpers.php'
        );
        foreach ([
            'function controller(',
            'function render(',
            'function resolve_header_levels(',
            'function resolve_localized_href(',
        ] as $function) {
            self::assertStringContainsString($function, $helpers, $function);
        }
    }

    public function testConsumerOwnedDependenciesAreNotClaimedByBlog(): void
    {
        $root = dirname(__DIR__, 2);
        $targets = array_column(
            ModuleCatalog::fromCoreRoot($root)
                ->get('blog')
                ->projectFiles(),
            'target'
        );

        foreach ([
            'App/includes/_globalHead.php',
            'App/includes/_globalBody.php',
            'App/includes/_nav.php',
            'App/includes/_footer.php',
            'src/js/_global.js',
            'src/scss/_global.scss',
            'src/scss/_config.scss',
            'App/config/languages/global/{locale}.json',
        ] as $target) {
            self::assertNotContains($target, $targets, $target);
        }

        foreach ([
            'stubs/App/includes/_globalHead.php',
            'stubs/App/includes/_globalBody.php',
            'stubs/App/includes/_nav.php',
            'stubs/App/includes/_footer.php',
            'resources/js/_global.js',
            'resources/scss/_global.scss',
            'resources/scss/_config.scss',
            'stubs/App/config/languages/global/es.json',
        ] as $source) {
            self::assertFileDoesNotExist($root . '/' . $source, $source);
        }
    }

    public function testArticleAdapterProjectsAllMetadataUsedByGlobalHead(): void
    {
        $root = dirname(__DIR__, 2);
        $adapter = (string) file_get_contents(
            $root . '/modules/blog/resources/project/App/app/'
                . '_moduleBlogPublicArticle.php'
        );
        $view = (string) file_get_contents(
            $root . '/modules/blog/resources/project/App/views/'
                . 'blog-article.php'
        );

        foreach ([
            'title',
            'description',
            'headline',
            'canonical',
            'alternates',
            'x_default',
            'type',
            'image',
            'published_at',
            'updated_at',
        ] as $key) {
            self::assertStringContainsString("'{$key}' =>", $adapter, $key);
        }
        self::assertStringContainsString(
            "include_once __DIR__ . '/../includes/_globalHead.php'",
            $view
        );
    }
}
