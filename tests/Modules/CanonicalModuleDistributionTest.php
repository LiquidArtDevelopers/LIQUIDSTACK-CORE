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
            'moduleBlogCategoryBar01',
            'moduleBlogFilters01',
            'moduleBlogGrid02',
            'moduleBlogPagination01',
            'moduleBlogResults01',
            'moduleBlogSearch01',
            'sectionBlogCatalog01',
            'sectionBlogFeatured01',
            'sectionBlogGrid01',
            'sectionBlogList01',
            'sectionBlogRelated01',
            'sectionBlogSlider01',
            'sectionBlogSlider02',
            'sectionBlogStack01',
        ], $blog->resources());
        self::assertCount(66, $blog->projectFiles());

        $targets = array_column($blog->projectFiles(), 'target');
        self::assertSame($targets, array_values(array_unique($targets)));
        foreach ([
            'public/assets/modules/blog',
            'App/app/_moduleBlogPublicArticle.php',
            'App/views/blog-article.php',
            'src/js/blogArticle.js',
            'src/scss/blogArticle.scss',
            'App/app/_moduleBlogPublicCollections.php',
            'App/app/_moduleBlogPublicIndex.php',
            'App/controllers/_moduleBlogResources.php',
            'src/js/modules/blog/blogCollectionLoader.js',
            'App/controllers/artBlogArticle01.php',
            'App/templates/_artBlogArticle01.html',
            'src/scss/resources/_artBlogArticle01.scss',
            'App/controllers/moduleBlogArchive01.php',
            'App/templates/_moduleBlogArchive01.html',
            'src/scss/resources/_moduleBlogArchive01.scss',
            'App/controllers/moduleBlogCategoryBar01.php',
            'App/templates/_moduleBlogCategoryBar01.html',
            'src/scss/resources/_moduleBlogCategoryBar01.scss',
            'App/controllers/moduleBlogFilters01.php',
            'App/templates/_moduleBlogFilters01.html',
            'src/scss/resources/_moduleBlogFilters01.scss',
            'src/js/resources/_moduleBlogFilters01.js',
            'App/controllers/moduleBlogPagination01.php',
            'App/templates/_moduleBlogPagination01.html',
            'src/scss/resources/_moduleBlogPagination01.scss',
            'src/js/resources/_moduleBlogPagination01.js',
            'App/controllers/moduleBlogResults01.php',
            'App/templates/_moduleBlogResults01.html',
            'src/scss/resources/_moduleBlogResults01.scss',
            'App/controllers/moduleBlogSearch01.php',
            'App/templates/_moduleBlogSearch01.html',
            'src/scss/resources/_moduleBlogSearch01.scss',
            'App/controllers/sectionBlogCatalog01.php',
            'App/templates/_sectionBlogCatalog01.html',
            'src/scss/resources/_sectionBlogCatalog01.scss',
            'App/controllers/sectionBlogFeatured01.php',
            'App/controllers/sectionBlogGrid01.php',
            'App/controllers/moduleBlogGrid02.php',
            'App/templates/_moduleBlogGrid02.html',
            'src/scss/resources/_moduleBlogGrid02.scss',
            'src/js/resources/_moduleBlogGrid02.js',
            'App/controllers/sectionBlogList01.php',
            'App/controllers/sectionBlogRelated01.php',
            'App/templates/_sectionBlogRelated01.html',
            'src/scss/resources/_sectionBlogRelated01.scss',
            'App/controllers/sectionBlogSlider01.php',
            'src/js/resources/_sectionBlogSlider01.js',
            'App/controllers/sectionBlogSlider02.php',
            'App/templates/_sectionBlogSlider02.html',
            'src/scss/resources/_sectionBlogSlider02.scss',
            'src/js/resources/_sectionBlogSlider02.js',
            'App/controllers/sectionBlogStack01.php',
            'App/templates/_sectionBlogStack01.html',
            'src/scss/resources/_sectionBlogStack01.scss',
            'src/js/resources/_sectionBlogStack01.js',
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
            'modules/blog/published/assets/icons/position-center.svg',
            'modules/blog/published/assets/icons/position-left.svg',
            'modules/blog/published/assets/icons/position-right.svg',
            'modules/blog/resources/project/App/app/_moduleBlogPublicArticle.php',
            'modules/blog/resources/project/App/views/blog-article.php',
            'modules/blog/resources/project/src/js/blogArticle.js',
            'modules/blog/resources/project/src/scss/blogArticle.scss',
            'modules/blog/resources/project/App/app/_moduleBlogPublicCollections.php',
            'modules/blog/resources/project/App/app/_moduleBlogPublicIndex.php',
            'modules/blog/resources/project/App/controllers/_moduleBlogResources.php',
            'modules/blog/resources/project/src/js/modules/blog/blogCollectionLoader.js',
            'modules/blog/resources/project/App/controllers/artBlogArticle01.php',
            'modules/blog/resources/project/App/templates/_artBlogArticle01.html',
            'modules/blog/resources/project/App/controllers/moduleBlogArchive01.php',
            'modules/blog/resources/project/App/templates/_moduleBlogArchive01.html',
            'modules/blog/resources/project/App/controllers/moduleBlogCategoryBar01.php',
            'modules/blog/resources/project/App/templates/_moduleBlogCategoryBar01.html',
            'modules/blog/resources/project/App/controllers/moduleBlogFilters01.php',
            'modules/blog/resources/project/App/controllers/moduleBlogPagination01.php',
            'modules/blog/resources/project/App/templates/_moduleBlogPagination01.html',
            'modules/blog/resources/project/src/js/resources/_moduleBlogPagination01.js',
            'modules/blog/resources/project/App/controllers/moduleBlogResults01.php',
            'modules/blog/resources/project/App/templates/_moduleBlogResults01.html',
            'modules/blog/resources/project/src/scss/resources/_moduleBlogResults01.scss',
            'modules/blog/resources/project/App/controllers/moduleBlogSearch01.php',
            'modules/blog/resources/project/App/templates/_moduleBlogSearch01.html',
            'modules/blog/resources/project/App/controllers/sectionBlogCatalog01.php',
            'modules/blog/resources/project/App/templates/_sectionBlogCatalog01.html',
            'modules/blog/resources/project/src/scss/resources/_sectionBlogCatalog01.scss',
            'modules/blog/resources/project/App/controllers/sectionBlogGrid01.php',
            'modules/blog/resources/project/App/templates/_sectionBlogGrid01.html',
            'modules/blog/resources/project/App/controllers/moduleBlogGrid02.php',
            'modules/blog/resources/project/App/templates/_moduleBlogGrid02.html',
            'modules/blog/resources/project/App/controllers/sectionBlogRelated01.php',
            'modules/blog/resources/project/App/templates/_sectionBlogRelated01.html',
            'modules/blog/resources/project/App/views/showroom/_blog.php',
            'modules/blog/resources/project/src/js/resources/_moduleBlogFilters01.js',
            'modules/blog/resources/project/src/js/resources/_sectionBlogSlider01.js',
            'modules/blog/resources/project/App/controllers/sectionBlogSlider02.php',
            'modules/blog/resources/project/App/templates/_sectionBlogSlider02.html',
            'modules/blog/resources/project/src/js/resources/_sectionBlogSlider02.js',
            'modules/blog/resources/project/App/controllers/sectionBlogStack01.php',
            'modules/blog/resources/project/App/templates/_sectionBlogStack01.html',
            'modules/blog/resources/project/src/js/resources/_sectionBlogStack01.js',
            'modules/blog/resources/project/src/js/showroom/blog.js',
            'modules/blog/resources/project/src/scss/resources/_sectionBlogGrid01.scss',
            'modules/blog/resources/project/src/scss/resources/_moduleBlogGrid02.scss',
            'modules/blog/resources/project/src/scss/resources/_sectionBlogSlider02.scss',
            'modules/blog/resources/project/src/scss/resources/_sectionBlogStack01.scss',
            'modules/blog/resources/project/src/scss/resources/_moduleBlogArchive01.scss',
            'modules/blog/resources/project/src/scss/resources/_sectionBlogRelated01.scss',
            'modules/blog/resources/project/src/scss/resources/_artBlogArticle01.scss',
            'modules/blog/resources/project/src/scss/showroom/blog.scss',
            'modules/commerce/resources/project/App/views/showroom/_commerce.php',
            'modules/commerce/resources/project/src/js/showroom/commerce.js',
            'modules/commerce/resources/project/src/scss/showroom/commerce.scss',
            'modules/webadmin/published/assets/webadmin.css',
            'modules/webadmin/published/assets/webadmin.js',
            'modules/webadmin/published/assets/webadmin-media-picker.css',
            'modules/webadmin/published/assets/webadmin-media-picker.js',
        ] as $source) {
            self::assertContains($source, $published);
        }
        self::assertCount(107, $published);
    }
}
