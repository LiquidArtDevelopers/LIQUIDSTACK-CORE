<?php

declare(strict_types=1);

use App\Core\Composer\Installer;
use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Script\Event;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class InstallerModuleHookTest extends TestCase
{
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-installer-module-hook-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectRoot . '/vendor');
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode([
                'require' => [
                    'liquidstack/core' => '^1.8',
                    'liquidstack/blog' => '*',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
    }

    public function testManagedPreparationUsesTheHookQueueWithoutWriting(): void
    {
        $config = new Config(false, $this->projectRoot);
        $config->merge(['config' => [
            'vendor-dir' => $this->projectRoot . '/vendor',
        ]]);
        $composer = new Composer();
        $composer->setConfig($config);
        $io = new BufferIO();
        $before = iterator_count(new FilesystemIterator(
            $this->projectRoot,
            FilesystemIterator::SKIP_DOTS
        ));

        $catalog = Installer::prepareManagedProjectFiles(
            new Event('liquidstack:sync', $composer, $io),
            true
        )->catalog();
        $targets = array_column($catalog['entries'], 'target');
        $policies = array_values(array_unique(array_column(
            $catalog['entries'],
            'policy'
        )));

        self::assertContains('App/tools/liquidstack-dev.mjs', $targets);
        self::assertContains('App/tools/php-dev-router.php', $targets);
        self::assertContains('README.LIQUIDSTACK.md', $targets);
        self::assertContains('App/controllers/hero00.php', $targets);
        self::assertContains(
            'public/assets/modules/blog/blog-public.css',
            $targets
        );
        self::assertContains('managed_hash', $policies);
        self::assertContains('install_if_missing', $policies);
        self::assertContains('merge_json_additive', $policies);
        self::assertSame(
            $before,
            iterator_count(new FilesystemIterator(
                $this->projectRoot,
                FilesystemIterator::SKIP_DOTS
            ))
        );
        foreach ($catalog['entries'] as $entry) {
            self::assertFalse(Path::isAbsolute($entry['source']));
            self::assertFalse(Path::isAbsolute($entry['target']));
        }
    }

    public function testExplicitPreviewFailsClosedWhenModulesCannotResolve(): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            "{invalid-json\n"
        );
        $config = new Config(false, $this->projectRoot);
        $config->merge(['config' => [
            'vendor-dir' => $this->projectRoot . '/vendor',
        ]]);
        $composer = new Composer();
        $composer->setConfig($config);
        $io = new BufferIO();

        $sync = Installer::prepareManagedProjectFiles(
            new Event('liquidstack:sync', $composer, $io),
            true
        );
        $preview = $sync->preview();

        self::assertSame('blocked', $preview['status']);
        self::assertContains('sync.modules_unresolved', $preview['blockers']);
        self::assertNotEmpty($preview['entries']);
        self::assertStringContainsString(
            'No se pudieron resolver',
            $io->getOutput()
        );
    }

    public function testPostUpdateResolvesModulesEvenWithoutScssConfig(): void
    {
        $config = new Config(false, $this->projectRoot);
        $config->merge(['config' => [
            'vendor-dir' => $this->projectRoot . '/vendor',
        ]]);
        $composer = new Composer();
        $composer->setConfig($config);
        $io = new BufferIO();

        Installer::postUpdate(new Event('post-update-cmd', $composer, $io));

        self::assertStringContainsString(
            'los módulos internos se resolverán de forma independiente',
            $io->getOutput()
        );
        self::assertStringContainsString(
            'Módulos LiquidStack activos: core, webadmin, blog.',
            $io->getOutput()
        );
        self::assertFileExists(
            $this->projectRoot
                . '/public/assets/modules/blog/blog-public.css'
        );
        self::assertFileExists(
            $this->projectRoot
                . '/src/js/modules/blog/blogCollectionLoader.js'
        );
        self::assertFileEquals(
            dirname(__DIR__, 2) . '/stubs/README.LIQUIDSTACK.md',
            $this->projectRoot . '/README.LIQUIDSTACK.md'
        );
        self::assertDirectoryDoesNotExist(
            $this->projectRoot . '/App/controllers'
        );
        foreach ([
            'App/app/_moduleBlogPublicArticle.php',
            'App/app/_moduleBlogPublicCollections.php',
            'App/app/_moduleBlogPublicIndex.php',
            'App/templates/_sectionBlogGrid01.html',
            'App/templates/_moduleBlogGrid02.html',
            'App/templates/_sectionBlogSlider02.html',
            'App/templates/_sectionBlogStack01.html',
            'App/templates/_artBlogArticle01.html',
            'App/templates/_moduleBlogArchive01.html',
            'App/templates/_moduleBlogCategoryBar01.html',
            'App/templates/_moduleBlogPagination01.html',
            'App/templates/_moduleBlogResults01.html',
            'App/templates/_moduleBlogSearch01.html',
            'App/templates/_sectionBlogCatalog01.html',
            'App/templates/_sectionBlogRelated01.html',
            'App/views/showroom/_blog.php',
            'src/js/resources/_moduleBlogFilters01.js',
            'src/js/resources/_moduleBlogPagination01.js',
            'src/js/resources/_moduleBlogGrid02.js',
            'src/js/resources/_sectionBlogSlider02.js',
            'src/js/resources/_sectionBlogStack01.js',
            'src/js/showroom/blog.js',
            'src/scss/resources/_sectionBlogGrid01.scss',
            'src/scss/resources/_moduleBlogGrid02.scss',
            'src/scss/resources/_sectionBlogSlider02.scss',
            'src/scss/resources/_sectionBlogStack01.scss',
            'src/scss/resources/_artBlogArticle01.scss',
            'src/scss/resources/_moduleBlogArchive01.scss',
            'src/scss/resources/_moduleBlogCategoryBar01.scss',
            'src/scss/resources/_moduleBlogPagination01.scss',
            'src/scss/resources/_moduleBlogResults01.scss',
            'src/scss/resources/_moduleBlogSearch01.scss',
            'src/scss/resources/_sectionBlogCatalog01.scss',
            'src/scss/resources/_sectionBlogRelated01.scss',
            'src/scss/showroom/blog.scss',
        ] as $relative) {
            self::assertFileDoesNotExist(
                $this->projectRoot . '/' . $relative,
                $relative
            );
        }
    }

    public function testPostUpdatePublishesTheCompleteBlogFamilyWithScssContract(): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/src/scss/_config.scss',
            '$color00: #fff;' . PHP_EOL
        );
        $config = new Config(false, $this->projectRoot);
        $config->merge(['config' => [
            'vendor-dir' => $this->projectRoot . '/vendor',
        ]]);
        $composer = new Composer();
        $composer->setConfig($config);
        $io = new BufferIO();

        Installer::postUpdate(new Event('post-update-cmd', $composer, $io));

        self::assertFileExists(
            $this->projectRoot
                . '/public/assets/modules/blog/blog-public.css'
        );
        foreach ([
            'App/app/_moduleBlogPublicArticle.php',
            'App/app/_moduleBlogPublicCollections.php',
            'App/app/_moduleBlogPublicIndex.php',
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
            'App/templates/_sectionBlogRelated01.html',
            'App/templates/_moduleBlogGrid02.html',
            'App/templates/_sectionBlogSlider02.html',
            'App/templates/_sectionBlogStack01.html',
            'App/views/showroom/_blog.php',
            'src/js/resources/_moduleBlogFilters01.js',
            'src/js/resources/_moduleBlogPagination01.js',
            'src/js/resources/_sectionBlogSlider01.js',
            'src/js/resources/_moduleBlogGrid02.js',
            'src/js/resources/_sectionBlogSlider02.js',
            'src/js/resources/_sectionBlogStack01.js',
            'src/js/showroom/blog.js',
            'src/scss/resources/_moduleBlogFilters01.scss',
            'src/scss/resources/_artBlogArticle01.scss',
            'src/scss/resources/_moduleBlogArchive01.scss',
            'src/scss/resources/_moduleBlogCategoryBar01.scss',
            'src/scss/resources/_moduleBlogPagination01.scss',
            'src/scss/resources/_moduleBlogResults01.scss',
            'src/scss/resources/_moduleBlogSearch01.scss',
            'src/scss/resources/_sectionBlogCatalog01.scss',
            'src/scss/resources/_sectionBlogGrid01.scss',
            'src/scss/resources/_moduleBlogGrid02.scss',
            'src/scss/resources/_sectionBlogSlider02.scss',
            'src/scss/resources/_sectionBlogStack01.scss',
            'src/scss/resources/_sectionBlogRelated01.scss',
            'src/scss/showroom/blog.scss',
        ] as $relative) {
            self::assertFileExists(
                $this->projectRoot . '/' . $relative,
                $relative
            );
        }
    }
}
