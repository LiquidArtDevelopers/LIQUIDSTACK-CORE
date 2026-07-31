<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/ShowroomCatalogFixture.php';
use Symfony\Component\Filesystem\Filesystem;

require_once dirname(__DIR__, 2)
    . '/stubs/App/controllers/moduleTable01.php';

final class ModuleTableResourceTest extends TestCase
{
    private Filesystem $filesystem;
    private string $fixtureRoot;
    private string $previousWorkingDirectory;

    /**
     * @var array<string, array{exists: bool, value?: mixed}>
     */
    private array $globalState = [];

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-module-table-'
            . bin2hex(random_bytes(8));
        $this->previousWorkingDirectory = (string) getcwd();

        $templateTarget = $this->fixtureRoot
            . '/App/templates/_moduleTable01.html';

        $this->filesystem->mkdir(dirname($templateTarget));
        $this->filesystem->copy(
            self::coreRoot() . '/stubs/App/templates/_moduleTable01.html',
            $templateTarget
        );

        chdir($this->fixtureRoot);

        foreach ([0, 1, 2] as $instance) {
            $this->loadTableGlobals($instance);
        }
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
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testDefaultTableRendersAnEditableSemanticThreeByThreeGrid(): void
    {
        $html = controller_moduleTable01();

        self::assertStringContainsString(
            'class="moduleTable01 moduleTable01_00_classVar moduleTable01--columns-3"',
            $html
        );
        self::assertStringContainsString(
            'role="region"',
            $html
        );
        self::assertStringContainsString(
            'aria-labelledby="moduleTable01-00-caption"',
            $html
        );
        self::assertStringContainsString(
            '<caption id="moduleTable01-00-caption" data-lang="moduleTable01_00_caption">',
            $html
        );
        self::assertSame(3, substr_count($html, '<th scope="col"'));
        self::assertSame(3, substr_count($html, '<th scope="row"'));
        self::assertSame(6, substr_count($html, '<td data-lang="'));
        self::assertStringContainsString(
            'data-lang="moduleTable01_00_header_03"',
            $html
        );
        self::assertStringContainsString(
            'data-lang="moduleTable01_00_c_list_c"',
            $html
        );
        self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);
    }

    public function testRowAndColumnCountsAreClampedToTheirMaximums(): void
    {
        $html = controller_moduleTable01(1, [
            'items' => 100,
            'list_items' => 100,
        ]);

        self::assertStringContainsString(
            'moduleTable01--columns-8',
            $html
        );
        self::assertSame(8, substr_count($html, '<th scope="col"'));
        self::assertSame(26, substr_count($html, '<th scope="row"'));
        self::assertSame(182, substr_count($html, '<td data-lang="'));
        self::assertStringContainsString(
            'data-lang="moduleTable01_01_z_list_h"',
            $html
        );
        self::assertStringNotContainsString(
            'data-lang="moduleTable01_01_z_list_i"',
            $html
        );
    }

    public function testRowAndColumnCountsAreClampedToOne(): void
    {
        $html = controller_moduleTable01(2, [
            'items' => -10,
            'list_items' => 0,
        ]);

        self::assertStringContainsString(
            'moduleTable01--columns-1',
            $html
        );
        self::assertSame(1, substr_count($html, '<th scope="col"'));
        self::assertSame(1, substr_count($html, '<th scope="row"'));
        self::assertSame(0, substr_count($html, '<td data-lang="'));
        self::assertStringContainsString(
            'data-lang="moduleTable01_02_a_list_a"',
            $html
        );
    }

    public function testCatalogsProvideTheCompleteShowroomContract(): void
    {
        foreach (['es', 'en', 'eu'] as $language) {
            $catalog = $this->readJson(
                self::coreRoot()
                    . "/stubs/App/config/languages/templates/{$language}.json"
            );

            for ($column = 1; $column <= 8; $column++) {
                $key = sprintf('moduleTable01_00_header_%02d', $column);

                self::assertSame(
                    ['text'],
                    array_keys($catalog[$key] ?? []),
                    "{$language}: falta {$key}"
                );
            }

            self::assertStringContainsString(
                'moduleTable01',
                (string) (
                    $catalog['moduleTable01_00_caption']['text']
                    ?? ''
                )
            );

            foreach (range('a', 'c') as $row) {
                foreach (range('a', 'c') as $column) {
                    $key = "moduleTable01_00_{$row}_list_{$column}";

                    self::assertSame(
                        ['text'],
                        array_keys($catalog[$key] ?? []),
                        "{$language}: falta {$key}"
                    );
                }
            }
        }
    }

    public function testResourceIsRegisteredInShowroomAndScssEntrypoint(): void
    {
        $showroom = ShowroomCatalogFixture::corePhp(self::coreRoot());
        $entrypoint = ShowroomCatalogFixture::scss(self::coreRoot());

        self::assertSame(
            1,
            substr_count(
                $showroom,
                "controller('moduleTable01', 0"
            )
        );
        self::assertSame(
            1,
            substr_count(
                $entrypoint,
                "@use '../resources/moduleTable01';"
            )
        );
        self::assertFileExists(
            self::coreRoot() . '/resources/scss/_moduleTable01.scss'
        );
        self::assertFileExists(
            self::coreRoot() . '/stubs/App/controllers/moduleTable01.php'
        );
        self::assertFileExists(
            self::coreRoot() . '/stubs/App/templates/_moduleTable01.html'
        );
    }

    public function testScssUsesOnlyTheStandardResourceColorFamilies(): void
    {
        $scss = $this->readFile(
            self::coreRoot() . '/resources/scss/_moduleTable01.scss'
        );

        self::assertStringContainsString("@use '../config' as c;", $scss);
        self::assertStringContainsString('overflow-x: auto;', $scss);
        self::assertStringContainsString(
            '@media (min-width: c.$tablet)',
            $scss
        );

        preg_match_all(
            '/c\.\$(color[A-Za-z0-9_-]+)/',
            $scss,
            $matches
        );

        self::assertNotEmpty($matches[1] ?? []);

        foreach (array_unique($matches[1]) as $variable) {
            if (in_array($variable, ['colorERROR', 'colorOK'], true)) {
                continue;
            }

            self::assertMatchesRegularExpression(
                '/^color0[0-3](?:bis[0-9]*|SVG)?$/',
                $variable,
                "Variable de color no permitida en moduleTable01: {$variable}"
            );
        }

        self::assertStringNotContainsString('filterColor', $scss);
    }

    private function loadTableGlobals(int $instance): void
    {
        $pad = sprintf('%02d', $instance);

        $this->setGlobal(
            "moduleTable01_{$pad}_caption",
            (object) ['text' => "moduleTable01 {$pad}"]
        );

        for ($column = 1; $column <= 8; $column++) {
            $key = sprintf(
                'moduleTable01_%s_header_%02d',
                $pad,
                $column
            );
            $this->setGlobal($key, (object) ['text' => $key]);
        }

        foreach (range('a', 'z') as $row) {
            foreach (range('a', 'h') as $column) {
                $key = "moduleTable01_{$pad}_{$row}_list_{$column}";
                $this->setGlobal($key, (object) ['text' => $key]);
            }
        }
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

        self::assertIsArray($decoded, "{$path} debe contener un objeto JSON");

        return $decoded;
    }

    private function readFile(string $path): string
    {
        $contents = file_get_contents($path);

        self::assertIsString($contents, "No se pudo leer {$path}");

        return $contents;
    }

    private static function coreRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
