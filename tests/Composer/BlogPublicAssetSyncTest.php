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
            'App/controllers/_moduleBlogResources.php',
            'App/controllers/moduleBlogFilters01.php',
            'App/controllers/sectionBlogFeatured01.php',
            'App/controllers/sectionBlogGrid01.php',
            'App/controllers/sectionBlogList01.php',
            'App/controllers/sectionBlogSlider01.php',
            'App/templates/_moduleBlogFilters01.html',
            'App/templates/_sectionBlogFeatured01.html',
            'App/templates/_sectionBlogGrid01.html',
            'App/templates/_sectionBlogList01.html',
            'App/templates/_sectionBlogSlider01.html',
            'src/js/resources/_moduleBlogFilters01.js',
            'src/js/resources/_sectionBlogSlider01.js',
            'src/js/showroom/blog.js',
            'src/scss/resources/_moduleBlogFilters01.scss',
            'src/scss/resources/_sectionBlogFeatured01.scss',
            'src/scss/resources/_sectionBlogGrid01.scss',
            'src/scss/resources/_sectionBlogList01.scss',
            'src/scss/resources/_sectionBlogSlider01.scss',
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
            $this->projectRoot . '/App/controllers/sectionBlogGrid01.php'
        );
        self::assertFileDoesNotExist(
            $this->projectRoot . '/src/js/showroom/blog.js'
        );
        self::assertSame(0, $synchronizer->stats()['errors']);
    }
}
