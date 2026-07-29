<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

require_once dirname(__DIR__, 2) . '/stubs/App/controllers/art32.php';

final class Art32ContractTest extends TestCase
{
    private Filesystem $filesystem;
    private string $fixtureRoot;
    private string $previousWorkingDirectory;
    private array $previousEnv;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-art32-'
            . bin2hex(random_bytes(8));
        $this->previousWorkingDirectory = (string) getcwd();
        $this->previousEnv = $_ENV;

        $templateTarget = $this->fixtureRoot
            . '/App/templates/_art32.html';
        $this->filesystem->mkdir(dirname($templateTarget));
        $this->filesystem->copy(
            dirname(__DIR__, 2) . '/stubs/App/templates/_art32.html',
            $templateTarget
        );

        chdir($this->fixtureRoot);
        $_ENV['RAIZ'] = 'http://localhost:1309';
        $GLOBALS['lang'] = 'es';

        foreach ([
            'headerPrimary',
            'intro_p',
            'p1',
            'p2',
        ] as $field) {
            $GLOBALS["art32_00_{$field}"] = (object) [
                'text' => "Matrix {$field}",
            ];
        }

        foreach (range('a', 'p') as $letter) {
            $GLOBALS["art32_00_headerSecondary_{$letter}"] = (object) [
                'text' => "Matrix {$letter}",
            ];
            $GLOBALS["art32_00_{$letter}_img"] = (object) [
                'src' => "assets/img/system/{$letter}.svg",
                'alt' => "Alt {$letter}",
                'title' => "Title {$letter}",
            ];
            $GLOBALS["art32_00_{$letter}_p"] = (object) [
                'text' => "Copy {$letter}",
            ];
        }
    }

    protected function tearDown(): void
    {
        chdir($this->previousWorkingDirectory);
        $_ENV = $this->previousEnv;
        unset($GLOBALS['lang']);

        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'art32_00_')) {
                unset($GLOBALS[$key]);
            }
        }

        $this->filesystem->remove($this->fixtureRoot);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function itemCounts(): array
    {
        return [
            'default family count' => [2],
            'showroom count' => [8],
            'home count' => [10],
            'service count' => [16],
        ];
    }

    #[DataProvider('itemCounts')]
    public function testControllerRendersVariableCardCounts(int $items): void
    {
        $html = controller_art32(0, ['items' => $items]);

        self::assertSame(
            $items,
            substr_count($html, 'class="art32-card"')
        );
        self::assertDoesNotMatchRegularExpression(
            '/\{[A-Za-z][A-Za-z0-9_-]*\}/',
            $html
        );
    }

    public function testArticleSemanticsAndRelativeHeadingsArePreserved(): void
    {
        $defaultHtml = controller_art32(0, ['items' => 2]);
        $scaledHtml = controller_art32(0, [
            'items' => 2,
            'header_level' => 2,
        ]);

        self::assertMatchesRegularExpression(
            '/^\s*<article\b.*<\/article>\s*$/s',
            $defaultHtml
        );
        self::assertDoesNotMatchRegularExpression(
            '/<\s*(?:section|header)\b/i',
            $defaultHtml
        );
        self::assertSame(2, substr_count($defaultHtml, '<div class="art32-card">'));
        self::assertSame(1, preg_match_all('/<h3\b/', $defaultHtml));
        self::assertSame(
            2,
            preg_match_all('/<h4\b[^>]*class="art32-cardTitle"/', $defaultHtml)
        );

        self::assertSame(1, preg_match_all('/<h2\b/', $scaledHtml));
        self::assertSame(
            2,
            preg_match_all('/<h3\b[^>]*class="art32-cardTitle"/', $scaledHtml)
        );
    }

    public function testStylesheetOwnsTheFormerViewModifiers(): void
    {
        $scss = file_get_contents(
            dirname(__DIR__, 2) . '/resources/scss/_art32.scss'
        );

        self::assertIsString($scss);

        foreach ([
            'justify-content: center;',
            'gap: 2rem;',
            'padding: 2rem;',
            'box-shadow: 0 0 20px c.$color01bis5;',
            'width: calc((100% - 6rem) / 4);',
            'width: 25%;',
            'filter: c.$color03SVG;',
            'bottom: 2rem;',
            '>.art32-cardTitle',
        ] as $rule) {
            self::assertStringContainsString($rule, $scss);
        }

        self::assertStringNotContainsString('>h4', $scss);
    }

    public function testEveryTemplateLanguageContainsEightDummyCards(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['es', 'en', 'eu'] as $language) {
            $catalog = json_decode(
                (string) file_get_contents(
                    "{$root}/stubs/App/config/languages/templates/{$language}.json"
                ),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $keys = array_values(array_filter(
                array_keys($catalog),
                static fn (string $key): bool => str_starts_with(
                    $key,
                    'art32_00_'
                )
            ));

            self::assertCount(28, $keys);
            self::assertStringContainsString(
                'art32',
                (string) ($catalog['art32_00_headerPrimary']['text'] ?? '')
            );

            foreach (range('a', 'h') as $letter) {
                self::assertArrayHasKey(
                    "art32_00_headerSecondary_{$letter}",
                    $catalog
                );
                self::assertArrayHasKey("art32_00_{$letter}_img", $catalog);
                self::assertArrayHasKey("art32_00_{$letter}_p", $catalog);
            }
        }
    }
}
