<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

require_once dirname(__DIR__, 2) . '/stubs/App/controllers/art16.php';
require_once dirname(__DIR__, 2) . '/stubs/App/controllers/hero00.php';

final class ExistingResourceEditorContractTest extends TestCase
{
    private Filesystem $filesystem;
    private string $fixtureRoot;
    private string $previousWorkingDirectory;
    private array $previousEnv;

    /**
     * @var array<string, array{exists: bool, value?: mixed}>
     */
    private array $globalState = [];

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-existing-editor-'
            . bin2hex(random_bytes(8));
        $this->previousWorkingDirectory = (string) getcwd();
        $this->previousEnv = $_ENV;

        foreach (['art16', 'hero00'] as $resource) {
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
        $_ENV['RAIZ'] = 'https://www.example.test/base';
        $_ENV['DEV_MODE'] = 'true';

        $this->setGlobal('art16_00_bg_mobile', 'https://cdn.example.test/mobile.avif');
        $this->setGlobal('art16_00_bg_tablet', 'assets/img/dummy/tablet.avif');
        $this->setGlobal(
            'art16_00_bg_desktop',
            'https://cdn.example.test/desktop.avif" onmouseover="alert(1)'
        );
        $this->setGlobal('art16_00_h3_1', (object) ['text' => 'Matrix ipsum']);
        $this->setGlobal('art16_00_h3_2', (object) ['text' => 'dolor sit amet']);
        $this->setGlobal(
            'art16_00_body_p',
            (object) ['text' => 'Matrix ipsum dolor sit amet.']
        );

        $this->setGlobal(
            'hero00_bg_mobile',
            (object) ['src' => 'https://cdn.example.test/hero-mobile.avif']
        );
        $this->setGlobal(
            'hero00_bg_tablet',
            (object) ['src' => 'assets/img/dummy/hero-tablet.avif']
        );
        $this->setGlobal(
            'hero00_bg_desktop',
            (object) ['src' => 'https://cdn.example.test/hero-desktop.avif']
        );
        $this->setGlobal(
            'hero00_bg_fallback',
            (object) [
                'src' => 'https://cdn.example.test/fallback.avif");color:red;/*',
            ]
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->globalState as $key => $state) {
            if ($state['exists']) {
                $GLOBALS[$key] = $state['value'];
                continue;
            }

            unset($GLOBALS[$key]);
        }

        chdir($this->previousWorkingDirectory);
        $_ENV = $this->previousEnv;
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testArt16EscapesBackgroundsAndScalesItsHeading(): void
    {
        $defaultHtml = controller_art16();
        self::assertMatchesRegularExpression(
            '/<h3\b[^>]*class="art16-title"[^>]*>\s*<span\b.*<\/span>\s*<span\b.*<\/span>\s*<\/h3>/s',
            $defaultHtml
        );

        $html = controller_art16(0, ['header_level' => 2]);

        self::assertStringContainsString(
            'data-bg-mobile="https://cdn.example.test/mobile.avif"',
            $html
        );
        self::assertStringContainsString(
            'data-bg-tablet="https://www.example.test/base/assets/img/dummy/tablet.avif"',
            $html
        );
        self::assertStringContainsString(
            'desktop.avif&quot; onmouseover=&quot;alert(1)',
            $html
        );
        self::assertStringNotContainsString(
            'https://www.example.test/base/https://',
            $html
        );
        self::assertDoesNotMatchRegularExpression(
            '/"\s+onmouseover="/i',
            $html
        );
        self::assertStringContainsString(
            'desktop.avif\\&quot; onmouseover=\\&quot;alert(1)',
            $html
        );
        self::assertMatchesRegularExpression(
            '/<h2\b[^>]*class="art16-title"[^>]*>\s*<span\b.*<\/span>\s*<span\b.*<\/span>\s*<\/h2>/s',
            $html
        );
        self::assertStringContainsString('data-inline-background', $html);
        self::assertStringNotContainsString('{header-primary}', $html);
    }

    public function testHero00EscapesAbsoluteAndRelativeBackgrounds(): void
    {
        $html = controller_hero00();

        self::assertStringContainsString(
            'data-bg-mobile="https://cdn.example.test/hero-mobile.avif"',
            $html
        );
        self::assertStringContainsString(
            'data-bg-tablet="https://www.example.test/base/assets/img/dummy/hero-tablet.avif"',
            $html
        );
        self::assertStringNotContainsString(
            'https://www.example.test/base/https://',
            $html
        );
        self::assertStringContainsString(
            'fallback.avif\\&quot;);color:red;/*',
            $html
        );
        self::assertStringContainsString('data-inline-background', $html);
        self::assertMatchesRegularExpression('/^\s*<header\b/', $html);
    }

    private function setGlobal(string $key, mixed $value): void
    {
        if (!array_key_exists($key, $this->globalState)) {
            $this->globalState[$key] = array_key_exists($key, $GLOBALS)
                ? ['exists' => true, 'value' => $GLOBALS[$key]]
                : ['exists' => false];
        }

        $GLOBALS[$key] = $value;
    }
}
