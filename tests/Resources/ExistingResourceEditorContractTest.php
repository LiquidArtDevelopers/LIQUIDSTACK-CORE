<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

require_once dirname(__DIR__, 2) . '/stubs/App/controllers/art16.php';
require_once dirname(__DIR__, 2) . '/stubs/App/controllers/hero00.php';
require_once dirname(__DIR__, 2) . '/stubs/App/controllers/hero01.php';
require_once dirname(__DIR__, 2) . '/stubs/App/controllers/moduleH1Type01.php';

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

        foreach (['art16', 'hero00', 'hero01', 'moduleH1Type01'] as $resource) {
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
        $this->setGlobal(
            'hero01_00_img',
            (object) [
                'src' => 'assets/img/dummy/hero01.avif',
                'alt' => 'Editable hero image',
                'title' => 'Hero image title',
                'width' => 2560,
                'height' => 1600,
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

    public function testHero00RendersEscapedResponsiveImageWithoutInlineStyle(): void
    {
        $html = controller_hero00();

        self::assertStringContainsString(
            'https://cdn.example.test/hero-mobile.avif 480w',
            $html
        );
        self::assertStringContainsString(
            'https://www.example.test/base/assets/img/dummy/hero-tablet.avif 900w',
            $html
        );
        self::assertStringNotContainsString(
            'https://www.example.test/base/https://',
            $html
        );
        self::assertStringContainsString(
            'fallback.avif&quot;);color:red;/*',
            $html
        );
        self::assertStringContainsString('<picture class="hero00-picture">', $html);
        self::assertStringContainsString('class="hero00-media bg"', $html);
        self::assertStringContainsString(
            'data-bg-mobile="https://cdn.example.test/hero-mobile.avif"',
            $html
        );
        self::assertStringContainsString(
            'data-bg-tablet="https://www.example.test/base/assets/img/dummy/hero-tablet.avif"',
            $html
        );
        self::assertStringContainsString(
            'data-bg-desktop="https://cdn.example.test/hero-desktop.avif"',
            $html
        );
        self::assertStringContainsString(
            'data-inline-background-picture-source=".hero00-picture source"',
            $html
        );
        foreach ([
            'mobile' => '480w',
            'tablet' => '900w',
            'desktop' => '1800w',
        ] as $variant => $descriptor) {
            self::assertStringContainsString(
                "data-inline-background-{$variant}-descriptor=\"{$descriptor}\"",
                $html
            );
        }
        self::assertStringNotContainsString('style=', $html);
        self::assertStringContainsString('data-inline-background', $html);
        self::assertMatchesRegularExpression('/^\s*<header\b/', $html);
    }

    public function testHero01ImageIsEditableAndStrictlyOptIn(): void
    {
        $defaultHtml = controller_hero01();

        self::assertStringNotContainsString('hero01-picture', $defaultHtml);
        self::assertStringNotContainsString('data-inline-background', $defaultHtml);

        $html = controller_hero01(0, [
            'with_image' => true,
            '{editor-attributes}' => 'data-injected="unsafe"',
            '{hero01-media}' => '<script>unsafe()</script>',
        ]);

        self::assertStringContainsString('<picture class="hero01-picture">', $html);
        self::assertStringContainsString('class="hero01-media"', $html);
        self::assertStringContainsString('data-lang="hero01_00_img"', $html);
        self::assertStringContainsString(
            'src="https://www.example.test/base/assets/img/dummy/hero01.avif"',
            $html
        );
        self::assertStringContainsString(
            'data-inline-background-target=".hero01-media"',
            $html
        );
        self::assertStringContainsString(
            'data-inline-background-image-key="hero01_00_img"',
            $html
        );
        self::assertStringNotContainsString('data-injected="unsafe"', $html);
        self::assertStringNotContainsString('<script>unsafe()</script>', $html);
        self::assertSame(4, substr_count($html, '<span></span>'));
    }

    public function testModuleH1Type01PrefersInjectedCopyWithoutGlobals(): void
    {
        foreach (['h1_text', 'p01_text', 'p02_text'] as $suffix) {
            $this->unsetGlobal("moduleH1Type01_87_{$suffix}");
        }

        $html = controller_moduleH1Type01(87, [
            '{h1-text}' => 'Título inyectado',
            '{p-01-text}' => 'Primer texto inyectado.',
            '{p-02-text}' => 'Segundo texto inyectado.',
        ]);

        self::assertStringContainsString('Título inyectado', $html);
        self::assertStringContainsString('Primer texto inyectado.', $html);
        self::assertStringContainsString('Segundo texto inyectado.', $html);
        self::assertStringNotContainsString('{h1-text}', $html);
    }

    public function testModuleH1Type01HasAnEmptySafeFallback(): void
    {
        foreach (['h1_text', 'p01_text', 'p02_text'] as $suffix) {
            $this->unsetGlobal("moduleH1Type01_88_{$suffix}");
        }

        $html = controller_moduleH1Type01(88);

        self::assertStringContainsString(
            'data-lang="moduleH1Type01_88_h1_text"',
            $html
        );
        self::assertStringNotContainsString('{h1-text}', $html);
        self::assertStringNotContainsString('{p-01-text}', $html);
        self::assertStringNotContainsString('{p-02-text}', $html);
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

    private function unsetGlobal(string $key): void
    {
        if (!array_key_exists($key, $this->globalState)) {
            $this->globalState[$key] = array_key_exists($key, $GLOBALS)
                ? ['exists' => true, 'value' => $GLOBALS[$key]]
                : ['exists' => false];
        }

        unset($GLOBALS[$key]);
    }
}
