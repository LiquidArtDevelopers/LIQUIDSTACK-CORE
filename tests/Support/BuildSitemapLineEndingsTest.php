<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class BuildSitemapLineEndingsTest extends TestCase
{
    private string $fixtureRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-sitemap-'
            . bin2hex(random_bytes(8));

        $this->filesystem->mkdir([
            $this->fixtureRoot . '/App/config/routes',
            $this->fixtureRoot . '/App/config/modules',
            $this->fixtureRoot . '/App/tools',
            $this->fixtureRoot . '/public',
            $this->fixtureRoot . '/vendor',
        ]);

        $coreRoot = dirname(__DIR__, 2);
        $this->filesystem->copy(
            $coreRoot . '/stubs/App/tools/build-sitemap.php',
            $this->fixtureRoot . '/App/tools/build-sitemap.php'
        );

        $autoload = '<?php require '
            . var_export($coreRoot . '/vendor/autoload.php', true)
            . ';';
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/vendor/autoload.php',
            $autoload
        );

        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/routes/get.php',
            <<<'PHP'
<?php

return [
    'es' => [
        '/' => [
            'content' => 'home',
            'view' => '../App/views/home.php',
        ],
    ],
];
PHP
        );

        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/.env',
            "RAIZ=https://example.test\nLANG_DEFAULT=es\n"
        );
        $this->writeComposerRequirements(['liquidstack/core' => '^1.0']);
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/public/robots.txt',
            "User-agent: *\r\nAllow: /\r\n\r\n"
                . "Sitemap: https://old.test/sitemap.xml\r\n"
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testRepeatedBuildsDoNotDuplicateWindowsLineBreaks(): void
    {
        $expected = implode(PHP_EOL, [
            'User-agent: *',
            'Allow: /',
            '',
            'Sitemap: https://example.test/sitemap.xml',
            '',
        ]);

        $this->runBuild();
        self::assertSame(
            $expected,
            file_get_contents($this->fixtureRoot . '/public/robots.txt')
        );

        $this->runBuild();
        self::assertSame(
            $expected,
            file_get_contents($this->fixtureRoot . '/public/robots.txt')
        );
    }

    public function testStaticBuildPreservesAnExistingBlogSitemapLine(): void
    {
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/public/robots.txt',
            "User-agent: *\r\nAllow: /\r\n\r\n"
                . "Sitemap: https://old.test/sitemap.xml\r\n"
                . "Sitemap: https://old.test/blog-sitemap.xml\r\n"
        );

        $this->runBuild();

        self::assertSame(
            implode(PHP_EOL, [
                'User-agent: *',
                'Allow: /',
                '',
                'Sitemap: https://example.test/sitemap.xml',
                'Sitemap: https://old.test/blog-sitemap.xml',
                '',
            ]),
            file_get_contents($this->fixtureRoot . '/public/robots.txt')
        );
    }

    public function testEnabledBlogAddsAndUpdatesItsConfiguredSitemap(): void
    {
        $this->writeComposerRequirements([
            'liquidstack/core' => '^1.0',
            'liquidstack/blog' => '*',
        ]);
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/modules/blog.php',
            <<<'PHP'
<?php

return [
    'public_paths' => ['es' => '/es/noticias'],
    'sitemap_path' => '/blog-sitemap.xml',
];
PHP
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/public/robots.txt',
            "User-agent: *\r\nAllow: /\r\n\r\n"
                . "Sitemap: https://old.test/sitemap.xml\r\n"
                . "Sitemap: https://old.test/blog-sitemap.xml\r\n"
        );

        $this->runBuild();
        $this->runBuild();

        self::assertSame(
            implode(PHP_EOL, [
                'User-agent: *',
                'Allow: /',
                '',
                'Sitemap: https://example.test/sitemap.xml',
                'Sitemap: https://example.test/blog-sitemap.xml',
                '',
            ]),
            file_get_contents($this->fixtureRoot . '/public/robots.txt')
        );
    }

    /** @param array<string, string> $requirements */
    private function writeComposerRequirements(array $requirements): void
    {
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/composer.json',
            json_encode(
                ['name' => 'liquidstack/sitemap-fixture', 'require' => $requirements],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
    }

    private function runBuild(): void
    {
        $process = new Process(
            [PHP_BINARY, $this->fixtureRoot . '/App/tools/build-sitemap.php'],
            $this->fixtureRoot
        );
        $process->setTimeout(20);
        $process->run();

        self::assertTrue(
            $process->isSuccessful(),
            trim($process->getErrorOutput() . PHP_EOL . $process->getOutput())
        );
    }
}
