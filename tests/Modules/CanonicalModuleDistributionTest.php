<?php

declare(strict_types=1);

use App\Core\Modules\ModuleCatalog;
use App\Core\Modules\ModulePublishedSourceFinder;
use PHPUnit\Framework\TestCase;

final class CanonicalModuleDistributionTest extends TestCase
{
    public function testOptionalModulesPublishOnlyTheirDeclaredManagedFiles(): void
    {
        $root = dirname(__DIR__, 2);
        $catalog = ModuleCatalog::fromCoreRoot($root);

        self::assertSame([[
            'source' => 'published/assets',
            'target' => 'public/assets/modules/webadmin',
            'type' => 'dir',
            'policy' => 'managed_hash',
            'group' => 'module:webadmin:public/assets/modules/webadmin',
            'track_state' => true,
        ]], $catalog->get('webadmin')->projectFiles());

        $blog = $catalog->get('blog');
        self::assertSame([
            'artBlogArticle01',
            'moduleBlogArchive01',
            'moduleBlogFilters01',
            'sectionBlogFeatured01',
            'sectionBlogGrid01',
            'sectionBlogList01',
            'sectionBlogRelated01',
            'sectionBlogSlider01',
        ], $blog->resources());
        self::assertCount(31, $blog->projectFiles());

        $targets = array_column($blog->projectFiles(), 'target');
        self::assertSame($targets, array_values(array_unique($targets)));
        foreach ([
            'public/assets/modules/blog',
            'App/controllers/_moduleBlogResources.php',
            'App/controllers/artBlogArticle01.php',
            'App/templates/_artBlogArticle01.html',
            'src/scss/resources/_artBlogArticle01.scss',
            'App/controllers/moduleBlogArchive01.php',
            'App/templates/_moduleBlogArchive01.html',
            'src/scss/resources/_moduleBlogArchive01.scss',
            'App/controllers/moduleBlogFilters01.php',
            'App/templates/_moduleBlogFilters01.html',
            'src/scss/resources/_moduleBlogFilters01.scss',
            'src/js/resources/_moduleBlogFilters01.js',
            'App/controllers/sectionBlogFeatured01.php',
            'App/controllers/sectionBlogGrid01.php',
            'App/controllers/sectionBlogList01.php',
            'App/controllers/sectionBlogRelated01.php',
            'App/templates/_sectionBlogRelated01.html',
            'src/scss/resources/_sectionBlogRelated01.scss',
            'App/controllers/sectionBlogSlider01.php',
            'src/js/resources/_sectionBlogSlider01.js',
            'App/views/showroom/_blog.php',
            'src/scss/showroom/blog.scss',
            'src/js/showroom/blog.js',
        ] as $target) {
            self::assertContains($target, $targets);
        }

        $published = array_keys(
            ModulePublishedSourceFinder::currentManagedFiles($catalog)
        );
        foreach ([
            'modules/blog/published/assets/blog-admin.css',
            'modules/blog/published/assets/blog-admin-list.js',
            'modules/blog/published/assets/blog-analytics.js',
            'modules/blog/published/assets/blog-editor.js',
            'modules/blog/published/assets/blog-public.css',
            'modules/blog/published/assets/blog-public.js',
            'modules/blog/published/assets/flags/es.svg',
            'modules/blog/published/assets/flags/es-pv.svg',
            'modules/blog/published/assets/flags/gb.svg',
            'modules/blog/published/assets/flags/LICENSE.flag-icons.txt',
            'modules/blog/resources/project/App/controllers/_moduleBlogResources.php',
            'modules/blog/resources/project/App/controllers/artBlogArticle01.php',
            'modules/blog/resources/project/App/templates/_artBlogArticle01.html',
            'modules/blog/resources/project/App/controllers/moduleBlogArchive01.php',
            'modules/blog/resources/project/App/templates/_moduleBlogArchive01.html',
            'modules/blog/resources/project/App/controllers/moduleBlogFilters01.php',
            'modules/blog/resources/project/App/controllers/sectionBlogGrid01.php',
            'modules/blog/resources/project/App/templates/_sectionBlogGrid01.html',
            'modules/blog/resources/project/App/controllers/sectionBlogRelated01.php',
            'modules/blog/resources/project/App/templates/_sectionBlogRelated01.html',
            'modules/blog/resources/project/App/views/showroom/_blog.php',
            'modules/blog/resources/project/src/js/resources/_moduleBlogFilters01.js',
            'modules/blog/resources/project/src/js/resources/_sectionBlogSlider01.js',
            'modules/blog/resources/project/src/js/showroom/blog.js',
            'modules/blog/resources/project/src/scss/resources/_sectionBlogGrid01.scss',
            'modules/blog/resources/project/src/scss/resources/_moduleBlogArchive01.scss',
            'modules/blog/resources/project/src/scss/resources/_sectionBlogRelated01.scss',
            'modules/blog/resources/project/src/scss/resources/_artBlogArticle01.scss',
            'modules/blog/resources/project/src/scss/showroom/blog.scss',
            'modules/webadmin/published/assets/webadmin.css',
            'modules/webadmin/published/assets/webadmin.js',
        ] as $source) {
            self::assertContains($source, $published);
        }
        self::assertCount(42, $published);
    }
}
