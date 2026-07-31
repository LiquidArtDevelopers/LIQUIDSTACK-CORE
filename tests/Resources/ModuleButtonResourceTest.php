<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/ShowroomCatalogFixture.php';
use Symfony\Component\Filesystem\Filesystem;

final class ModuleButtonResourceTest extends TestCase
{
    private const RESOURCES = [
        'moduleButtonType02',
        'moduleButtonType03',
        'moduleButtonType04',
    ];

    private Filesystem $filesystem;
    private string $fixtureRoot;
    private string $previousWorkingDirectory;
    private string $previousProjectRoot;
    private array $previousEnv;

    /**
     * @var array<string, array{exists: bool, value?: mixed}>
     */
    private array $globalState = [];

    public static function setUpBeforeClass(): void
    {
        foreach (self::RESOURCES as $resource) {
            require_once self::coreRoot()
                . "/stubs/App/controllers/{$resource}.php";
        }
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-module-buttons-'
            . bin2hex(random_bytes(8));
        $this->previousWorkingDirectory = (string) getcwd();
        $this->previousProjectRoot = Paths::projectRoot();
        $this->previousEnv = $_ENV;

        foreach (self::RESOURCES as $resource) {
            $target = $this->fixtureRoot
                . "/App/templates/_{$resource}.html";

            $this->filesystem->mkdir(dirname($target));
            $this->filesystem->copy(
                self::coreRoot() . "/stubs/App/templates/_{$resource}.html",
                $target
            );
        }

        Paths::setProjectRoot($this->fixtureRoot);
        chdir($this->fixtureRoot);

        $_ENV['RAIZ'] = 'https://www.example.test';
        $_ENV['LANG_DEFAULT'] = 'es';
        $this->setGlobal('lang', 'es');
        $this->loadButtonGlobals();
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

        $_ENV = $this->previousEnv;
        chdir($this->previousWorkingDirectory);
        Paths::setProjectRoot($this->previousProjectRoot);
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testButtonFamilyProvidesAndRegistersEveryStaticFile(): void
    {
        $templatesEntry = ShowroomCatalogFixture::scss(self::coreRoot());

        foreach (self::RESOURCES as $resource) {
            self::assertFileExists(
                self::coreRoot() . "/stubs/App/controllers/{$resource}.php"
            );
            self::assertFileExists(
                self::coreRoot() . "/stubs/App/templates/_{$resource}.html"
            );
            self::assertFileExists(
                self::coreRoot() . "/resources/scss/_{$resource}.scss"
            );
            self::assertGreaterThanOrEqual(
                1,
                substr_count(
                    $templatesEntry,
                    "@use '../resources/{$resource}';"
                ),
                "{$resource}: a showroom category must register the resource"
            );
        }

        self::assertFileExists(
            self::coreRoot()
            . '/resources/img/system/arrow-forward-outline.svg'
        );
    }

    public function testNewButtonTypesRenderTheirEditableContracts(): void
    {
        $type03 = controller_moduleButtonType03();

        self::assertStringContainsString('class="moduleButtonType03 ', $type03);
        self::assertStringContainsString(
            'data-lang="moduleButtonType03_00_cta_link"',
            $type03
        );
        self::assertStringContainsString(
            'data-lang="moduleButtonType03_00_cta_span"',
            $type03
        );
        self::assertStringContainsString(
            'class="moduleButtonType03-circle" aria-hidden="true"',
            $type03
        );
        self::assertStringNotContainsString('<button', $type03);
        self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $type03);

        $type04 = controller_moduleButtonType04();

        self::assertStringContainsString('class="moduleButtonType04 ', $type04);
        self::assertStringContainsString(
            'data-lang="moduleButtonType04_00_cta_link"',
            $type04
        );
        self::assertStringContainsString(
            'data-lang="moduleButtonType04_00_cta_span"',
            $type04
        );
        self::assertStringNotContainsString('<button', $type04);
        self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $type04);
    }

    public function testType02AlwaysRendersAnEditableFallbackIcon(): void
    {
        $withCatalogImage = controller_moduleButtonType02();

        self::assertStringContainsString(
            'data-lang="moduleButtonType02_00_cta_img"',
            $withCatalogImage
        );
        self::assertStringContainsString(
            'src="https://www.example.test/assets/img/system/arrow-forward-outline.svg"',
            $withCatalogImage
        );
        self::assertDoesNotMatchRegularExpression(
            '/\{[^}]+\}/',
            $withCatalogImage
        );

        $this->setGlobal('moduleButtonType02_07_cta_link', [
            'href' => '#fallback',
            'title' => 'Fallback',
        ]);
        $this->setGlobal('moduleButtonType02_07_cta_span', [
            'text' => 'Continuar',
        ]);
        $this->setGlobal('moduleButtonType02_07_cta_img', null);

        $withFallback = controller_moduleButtonType02(7);

        self::assertStringContainsString(
            'data-lang="moduleButtonType02_07_cta_img"',
            $withFallback
        );
        self::assertStringContainsString(
            'src="https://www.example.test/assets/img/system/arrow-forward-outline.svg"',
            $withFallback
        );
        self::assertStringContainsString('alt=""', $withFallback);
        self::assertStringContainsString('title=""', $withFallback);
    }

    public function testType04PreservesRootLinksAndAcceptsTrustedAttributes(): void
    {
        $this->setGlobal('moduleButtonType04_04_cta_link', [
            'href' => '/aviso-legal',
            'title' => 'Aviso legal',
        ]);
        $this->setGlobal('moduleButtonType04_04_cta_span', [
            'text' => 'Consultar',
        ]);

        $button = controller_moduleButtonType04(4, [
            '{cta-link-attributes}' => ' target="_blank" rel="noopener"',
        ]);

        self::assertStringContainsString(
            'href="/aviso-legal"',
            $button
        );
        self::assertStringContainsString(
            'target="_blank" rel="noopener"',
            $button
        );
        self::assertStringNotContainsString(
            'https://www.example.test/es/aviso-legal',
            $button
        );
        self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $button);
    }

    public function testCatalogsProvideCompleteEditableButtonEntries(): void
    {
        foreach (['es', 'en', 'eu'] as $language) {
            $catalog = $this->readJson(
                self::coreRoot()
                . "/stubs/App/config/languages/templates/{$language}.json"
            );

            foreach (['moduleButtonType03', 'moduleButtonType04'] as $resource) {
                self::assertEqualsCanonicalizing(
                    ['href', 'title'],
                    array_keys($catalog["{$resource}_00_cta_link"] ?? [])
                );
                self::assertSame(
                    ['text'],
                    array_keys($catalog["{$resource}_00_cta_span"] ?? [])
                );
            }

            self::assertEqualsCanonicalizing(
                ['src', 'alt', 'title'],
                array_keys(
                    $catalog['moduleButtonType02_00_cta_img'] ?? []
                )
            );
            self::assertSame(
                'assets/img/system/arrow-forward-outline.svg',
                $catalog['moduleButtonType02_00_cta_img']['src'] ?? null
            );
        }
    }

    public function testButtonStylesUseThemeVariablesAndAccessibleStates(): void
    {
        $type02 = $this->readFile(
            self::coreRoot() . '/resources/scss/_moduleButtonType02.scss'
        );

        self::assertStringContainsString("@use '../config' as c;", $type02);
        self::assertStringContainsString('> img', $type02);
        self::assertStringContainsString(':focus-visible', $type02);
        self::assertStringContainsString(
            'prefers-reduced-motion: reduce',
            $type02
        );

        foreach (['moduleButtonType03', 'moduleButtonType04'] as $resource) {
            $scss = $this->readFile(
                self::coreRoot() . "/resources/scss/_{$resource}.scss"
            );

            self::assertStringContainsString("@use '../config' as c;", $scss);
            self::assertStringContainsString('&:hover', $scss);
            self::assertStringContainsString(':focus-visible', $scss);
            self::assertStringContainsString(
                'prefers-reduced-motion: reduce',
                $scss
            );
            self::assertDoesNotMatchRegularExpression(
                '/#[0-9a-f]{3,8}\b/i',
                $scss
            );
            self::assertDoesNotMatchRegularExpression(
                '/\brgba?\s*\(/i',
                $scss
            );
        }

        self::assertStringContainsString(
            '&:active',
            $this->readFile(
                self::coreRoot() . '/resources/scss/_moduleButtonType04.scss'
            )
        );
    }

    private function loadButtonGlobals(): void
    {
        $catalog = $this->readJson(
            self::coreRoot()
            . '/stubs/App/config/languages/templates/es.json'
        );

        foreach ($catalog as $key => $value) {
            foreach (self::RESOURCES as $resource) {
                if (!str_starts_with($key, "{$resource}_")) {
                    continue;
                }

                $this->setGlobal(
                    $key,
                    is_array($value) ? (object) $value : $value
                );
                break;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode(
            $this->readFile($path),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertIsArray($decoded, "{$path} must contain a JSON object");

        return $decoded;
    }

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);

        self::assertIsString($content, "Unable to read {$path}");

        return $content;
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

    private static function coreRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
