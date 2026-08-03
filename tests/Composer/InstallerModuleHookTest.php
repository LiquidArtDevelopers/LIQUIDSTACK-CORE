<?php

declare(strict_types=1);

use App\Core\Composer\Installer;
use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Script\Event;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

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
        self::assertDirectoryDoesNotExist(
            $this->projectRoot . '/App/controllers'
        );
        foreach ([
            'App/templates/_sectionBlogGrid01.html',
            'App/views/showroom/_blog.php',
            'src/js/resources/_moduleBlogFilters01.js',
            'src/js/showroom/blog.js',
            'src/scss/resources/_sectionBlogGrid01.scss',
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
            'App/controllers/_moduleBlogResources.php',
            'App/controllers/moduleBlogFilters01.php',
            'App/controllers/sectionBlogFeatured01.php',
            'App/controllers/sectionBlogGrid01.php',
            'App/controllers/sectionBlogList01.php',
            'App/controllers/sectionBlogSlider01.php',
            'App/templates/_moduleBlogFilters01.html',
            'App/views/showroom/_blog.php',
            'src/js/resources/_moduleBlogFilters01.js',
            'src/js/resources/_sectionBlogSlider01.js',
            'src/js/showroom/blog.js',
            'src/scss/resources/_moduleBlogFilters01.scss',
            'src/scss/resources/_sectionBlogGrid01.scss',
            'src/scss/showroom/blog.scss',
        ] as $relative) {
            self::assertFileExists(
                $this->projectRoot . '/' . $relative,
                $relative
            );
        }
    }
}
