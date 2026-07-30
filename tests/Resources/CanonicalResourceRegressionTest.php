<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

require_once dirname(__DIR__, 2) . '/stubs/App/controllers/art05.php';
require_once dirname(__DIR__, 2) . '/stubs/App/controllers/art10.php';
require_once dirname(__DIR__, 2) . '/stubs/App/controllers/hero02.php';

final class CanonicalResourceRegressionTest extends TestCase
{
    private Filesystem $filesystem;
    private string $fixtureRoot;
    private string $previousWorkingDirectory;
    private array $previousEnvironment;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-canonical-regression-'
            . bin2hex(random_bytes(8));
        $this->previousWorkingDirectory = (string) getcwd();
        $this->previousEnvironment = $_ENV;

        foreach (['art05', 'art10', 'hero02'] as $resource) {
            $target = $this->fixtureRoot
                . "/App/templates/_{$resource}.html";
            $this->filesystem->mkdir(dirname($target));
            $this->filesystem->copy(
                dirname(__DIR__, 2)
                    . "/stubs/App/templates/_{$resource}.html",
                $target
            );
        }

        chdir($this->fixtureRoot);
        $_ENV['RAIZ'] = 'http://localhost:1309/';
        $GLOBALS['lang'] = 'es';
    }

    protected function tearDown(): void
    {
        foreach (array_keys($GLOBALS) as $key) {
            if (
                str_starts_with((string) $key, 'art05_00_')
                || str_starts_with((string) $key, 'art10_00_')
                || str_starts_with((string) $key, 'hero02_video_')
            ) {
                unset($GLOBALS[$key]);
            }
        }

        unset($GLOBALS['lang']);
        $_ENV = $this->previousEnvironment;
        chdir($this->previousWorkingDirectory);
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testArt10AlwaysResolvesItsClassPlaceholder(): void
    {
        $GLOBALS['art10_00_headerPrimary'] = (object) [
            'text' => 'Art10',
        ];
        $GLOBALS['art10_00_top_fill'] = '#000';
        $GLOBALS['art10_00_bottom_fill'] = '#fff';

        $html = controller_art10(0, ['items' => 0]);

        self::assertStringContainsString(
            'class="art10 art10_00_classVar"',
            $html
        );
        self::assertStringNotContainsString('{classVar}', $html);
    }

    public function testArt05NormalizesRootAndLeadingAssetSlash(): void
    {
        $GLOBALS['art05_00_headerPrimary'] = (object) [
            'text' => 'Art05',
        ];
        $GLOBALS['art05_00_intro_p'] = (object) [
            'text' => 'Intro',
        ];
        $GLOBALS['art05_00_headerSecondary_a'] = (object) [
            'text' => 'Ficha',
        ];
        $GLOBALS['art05_00_a_img'] = (object) [
            'src' => '/assets/img/system/bookmark-outline.svg',
            'alt' => 'Icono',
            'title' => 'Icono',
        ];
        $GLOBALS['art05_00_a_p'] = (object) ['text' => 'Texto'];
        $GLOBALS['art05_00_a_firma'] = (object) ['text' => 'Firma'];

        $html = controller_art05(0, ['items' => 1]);

        self::assertStringContainsString(
            'src="http://localhost:1309/assets/img/system/bookmark-outline.svg"',
            $html
        );
        self::assertStringNotContainsString('1309//assets/', $html);
    }

    public function testHero02UsesEditableTitleKeyWithoutPlaceholders(): void
    {
        $GLOBALS['hero02_video_webm'] = (object) [
            'src' => '/assets/video/dummy/dummy.webm',
        ];
        $GLOBALS['hero02_video_mp4'] = (object) [
            'src' => '/assets/video/dummy/dummy.mp4',
        ];
        $GLOBALS['hero02_video_title'] = (object) [
            'title' => 'Vídeo Matrix',
        ];

        $html = controller_hero02();

        self::assertStringContainsString(
            'data-lang="hero02_video_title"',
            $html
        );
        self::assertStringContainsString(
            'src="http://localhost:1309/assets/video/dummy/dummy.webm"',
            $html
        );
        self::assertDoesNotMatchRegularExpression(
            '/\{[A-Za-z][A-Za-z0-9_-]*\}/',
            $html
        );
    }
}
