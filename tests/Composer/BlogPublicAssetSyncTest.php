<?php

declare(strict_types=1);

use App\Core\Composer\ManagedFileSynchronizer;
use App\Core\Composer\ModuleProjectFileSynchronizer;
use App\Core\Modules\ModuleCatalog;
use App\Core\Modules\ModuleSelection;
use Composer\IO\BufferIO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogPublicAssetSyncTest extends TestCase
{
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-blog-public-asset-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectRoot);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
    }

    public function testBlogSelectionPublishesAssetsAndVisualResources(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $catalog = ModuleCatalog::fromCoreRoot($coreRoot);
        $selection = ModuleSelection::fromRequirementNames(
            $catalog,
            ['liquidstack/blog']
        );
        $io = new BufferIO();
        $synchronizer = new ManagedFileSynchronizer(
            $this->projectRoot,
            $coreRoot,
            $io
        );

        (new ModuleProjectFileSynchronizer(
            $this->projectRoot,
            $io
        ))->queue($selection, $synchronizer);
        $synchronizer->apply();

        foreach (['blog-public.css', 'blog-public.js'] as $asset) {
            $target = $this->projectRoot
                . '/public/assets/modules/blog/' . $asset;
            self::assertFileExists($target);
            self::assertSame(
                file_get_contents(
                    $coreRoot . '/modules/blog/published/assets/' . $asset
                ),
                file_get_contents($target)
            );
        }
        foreach ([
            'App/app/_moduleBlogPublicArticle.php',
            'App/app/_moduleBlogPublicCollections.php',
            'App/app/_moduleBlogPublicIndex.php',
            'App/views/blog-article.php',
            'src/js/blogArticle.js',
            'src/scss/blogArticle.scss',
            'App/controllers/_moduleBlogResources.php',
            'src/js/modules/blog/blogCollectionLoader.js',
            'App/controllers/artBlogArticle01.php',
            'App/controllers/moduleBlogArchive01.php',
            'App/controllers/moduleBlogCategoryBar01.php',
            'App/controllers/moduleBlogFilters01.php',
            'App/controllers/moduleBlogPagination01.php',
            'App/controllers/moduleBlogResults01.php',
            'App/controllers/moduleBlogSearch01.php',
            'App/controllers/sectionBlogCatalog01.php',
            'App/controllers/sectionBlogFeatured01.php',
            'App/controllers/sectionBlogGrid01.php',
            'App/controllers/moduleBlogGrid02.php',
            'App/controllers/sectionBlogList01.php',
            'App/controllers/sectionBlogRelated01.php',
            'App/controllers/sectionBlogSlider01.php',
            'App/controllers/sectionBlogSlider02.php',
            'App/controllers/sectionBlogStack01.php',
            'App/templates/_moduleBlogArchive01.html',
            'App/templates/_moduleBlogCategoryBar01.html',
            'App/templates/_moduleBlogFilters01.html',
            'App/templates/_moduleBlogPagination01.html',
            'App/templates/_moduleBlogResults01.html',
            'App/templates/_moduleBlogSearch01.html',
            'App/templates/_sectionBlogCatalog01.html',
            'App/templates/_artBlogArticle01.html',
            'App/templates/_sectionBlogFeatured01.html',
            'App/templates/_sectionBlogGrid01.html',
            'App/templates/_moduleBlogGrid02.html',
            'App/templates/_sectionBlogList01.html',
            'App/templates/_sectionBlogRelated01.html',
            'App/templates/_sectionBlogSlider01.html',
            'App/templates/_sectionBlogSlider02.html',
            'App/templates/_sectionBlogStack01.html',
            'src/js/resources/_moduleBlogFilters01.js',
            'src/js/resources/_moduleBlogPagination01.js',
            'src/js/resources/_sectionBlogSlider01.js',
            'src/js/resources/_sectionBlogSlider02.js',
            'src/js/resources/_moduleBlogGrid02.js',
            'src/js/resources/_sectionBlogStack01.js',
            'src/js/showroom/blog.js',
            'src/scss/resources/_moduleBlogFilters01.scss',
            'src/scss/resources/_moduleBlogArchive01.scss',
            'src/scss/resources/_moduleBlogCategoryBar01.scss',
            'src/scss/resources/_moduleBlogPagination01.scss',
            'src/scss/resources/_moduleBlogResults01.scss',
            'src/scss/resources/_moduleBlogSearch01.scss',
            'src/scss/resources/_sectionBlogCatalog01.scss',
            'src/scss/resources/_artBlogArticle01.scss',
            'src/scss/resources/_sectionBlogFeatured01.scss',
            'src/scss/resources/_sectionBlogGrid01.scss',
            'src/scss/resources/_moduleBlogGrid02.scss',
            'src/scss/resources/_sectionBlogList01.scss',
            'src/scss/resources/_sectionBlogRelated01.scss',
            'src/scss/resources/_sectionBlogSlider01.scss',
            'src/scss/resources/_sectionBlogSlider02.scss',
            'src/scss/resources/_sectionBlogStack01.scss',
            'src/scss/showroom/blog.scss',
            'App/views/showroom/_blog.php',
        ] as $relative) {
            $target = $this->projectRoot . '/' . $relative;
            $source = $coreRoot
                . '/modules/blog/resources/project/'
                . $relative;
            self::assertFileExists($target, $relative);
            self::assertSame(
                file_get_contents($source),
                file_get_contents($target),
                $relative
            );
        }
        self::assertSame(0, $synchronizer->stats()['errors']);
    }

    public function testCoreOnlySelectionPublishesNoBlogResource(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $catalog = ModuleCatalog::fromCoreRoot($coreRoot);
        $selection = ModuleSelection::fromRequirementNames($catalog, []);
        $io = new BufferIO();
        $synchronizer = new ManagedFileSynchronizer(
            $this->projectRoot,
            $coreRoot,
            $io
        );

        (new ModuleProjectFileSynchronizer(
            $this->projectRoot,
            $io
        ))->queue($selection, $synchronizer);
        $synchronizer->apply();

        self::assertFileDoesNotExist(
            $this->projectRoot . '/App/app/_moduleBlogPublicArticle.php'
        );
        self::assertFileDoesNotExist(
            $this->projectRoot . '/App/app/_moduleBlogPublicCollections.php'
        );
        self::assertFileDoesNotExist(
            $this->projectRoot . '/App/app/_moduleBlogPublicIndex.php'
        );
        self::assertFileDoesNotExist(
            $this->projectRoot . '/App/views/blog-article.php'
        );
        self::assertFileDoesNotExist(
            $this->projectRoot . '/src/js/blogArticle.js'
        );
        self::assertFileDoesNotExist(
            $this->projectRoot . '/src/scss/blogArticle.scss'
        );
        self::assertFileDoesNotExist(
            $this->projectRoot . '/App/controllers/sectionBlogGrid01.php'
        );
        self::assertFileDoesNotExist(
            $this->projectRoot . '/App/controllers/artBlogArticle01.php'
        );
        self::assertFileDoesNotExist(
            $this->projectRoot . '/App/controllers/moduleBlogArchive01.php'
        );
        self::assertFileDoesNotExist(
            $this->projectRoot . '/App/controllers/sectionBlogRelated01.php'
        );
        self::assertFileDoesNotExist(
            $this->projectRoot . '/src/js/showroom/blog.js'
        );
        self::assertSame(0, $synchronizer->stats()['errors']);
    }

    public function testCustomizedArticleShellPreservesTheWholeGroup(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $selection = ModuleSelection::fromRequirementNames(
            ModuleCatalog::fromCoreRoot($coreRoot),
            ['liquidstack/blog']
        );
        $sync = function () use ($coreRoot, $selection): ManagedFileSynchronizer {
            $io = new BufferIO();
            $synchronizer = new ManagedFileSynchronizer(
                $this->projectRoot,
                $coreRoot,
                $io
            );
            (new ModuleProjectFileSynchronizer(
                $this->projectRoot,
                $io
            ))->queue($selection, $synchronizer);
            $synchronizer->apply();

            return $synchronizer;
        };

        $sync();
        $view = $this->projectRoot . '/App/views/blog-article.php';
        $javascript = $this->projectRoot . '/src/js/blogArticle.js';
        $stylesheet = $this->projectRoot . '/src/scss/blogArticle.scss';
        $stylesheetBefore = (string) file_get_contents($stylesheet);
        $this->filesystem->dumpFile($view, 'project-owned article shell');
        $this->filesystem->remove($javascript);

        $second = $sync();

        self::assertSame(
            'project-owned article shell',
            file_get_contents($view)
        );
        self::assertFileDoesNotExist($javascript);
        self::assertSame($stylesheetBefore, file_get_contents($stylesheet));
        self::assertGreaterThanOrEqual(3, $second->stats()['preserved']);
        self::assertSame(0, $second->stats()['errors']);
    }

    public function testCustomizedVisualSupportCannotBlockTheIndexAdapter(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $selection = ModuleSelection::fromRequirementNames(
            ModuleCatalog::fromCoreRoot($coreRoot),
            ['liquidstack/blog']
        );
        $sync = function () use ($coreRoot, $selection): ManagedFileSynchronizer {
            $io = new BufferIO();
            $synchronizer = new ManagedFileSynchronizer(
                $this->projectRoot,
                $coreRoot,
                $io
            );
            (new ModuleProjectFileSynchronizer(
                $this->projectRoot,
                $io
            ))->queue($selection, $synchronizer);
            $synchronizer->apply();

            return $synchronizer;
        };

        $sync();
        $visualHelper = $this->projectRoot
            . '/App/controllers/_moduleBlogResources.php';
        $indexAdapter = $this->projectRoot
            . '/App/app/_moduleBlogPublicIndex.php';
        $this->filesystem->dumpFile($visualHelper, 'project customization');
        $this->filesystem->remove($indexAdapter);

        $second = $sync();

        self::assertSame('project customization', file_get_contents(
            $visualHelper
        ));
        self::assertFileExists($indexAdapter);
        self::assertSame(
            file_get_contents(
                $coreRoot
                    . '/modules/blog/resources/project/App/app/'
                    . '_moduleBlogPublicIndex.php'
            ),
            file_get_contents($indexAdapter)
        );
        self::assertGreaterThanOrEqual(1, $second->stats()['preserved']);
        self::assertGreaterThanOrEqual(1, $second->stats()['added']);
    }

    public function testCustomizedIndexAdapterCannotBlockTheArticleAdapter(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $selection = ModuleSelection::fromRequirementNames(
            ModuleCatalog::fromCoreRoot($coreRoot),
            ['liquidstack/blog']
        );
        $sync = function () use ($coreRoot, $selection): ManagedFileSynchronizer {
            $io = new BufferIO();
            $synchronizer = new ManagedFileSynchronizer(
                $this->projectRoot,
                $coreRoot,
                $io
            );
            (new ModuleProjectFileSynchronizer(
                $this->projectRoot,
                $io
            ))->queue($selection, $synchronizer);
            $synchronizer->apply();

            return $synchronizer;
        };

        $sync();
        $indexAdapter = $this->projectRoot
            . '/App/app/_moduleBlogPublicIndex.php';
        $articleAdapter = $this->projectRoot
            . '/App/app/_moduleBlogPublicArticle.php';
        $collectionsAdapter = $this->projectRoot
            . '/App/app/_moduleBlogPublicCollections.php';
        $this->filesystem->dumpFile($indexAdapter, 'project customization');
        $this->filesystem->remove($articleAdapter);
        $this->filesystem->remove($collectionsAdapter);

        $second = $sync();

        self::assertSame('project customization', file_get_contents(
            $indexAdapter
        ));
        self::assertFileExists($articleAdapter);
        self::assertSame(
            file_get_contents(
                $coreRoot
                    . '/modules/blog/resources/project/App/app/'
                    . '_moduleBlogPublicArticle.php'
            ),
            file_get_contents($articleAdapter)
        );
        self::assertFileExists($collectionsAdapter);
        self::assertSame(
            file_get_contents(
                $coreRoot
                    . '/modules/blog/resources/project/App/app/'
                    . '_moduleBlogPublicCollections.php'
            ),
            file_get_contents($collectionsAdapter)
        );
        self::assertGreaterThanOrEqual(1, $second->stats()['preserved']);
        self::assertGreaterThanOrEqual(2, $second->stats()['added']);
    }
}
