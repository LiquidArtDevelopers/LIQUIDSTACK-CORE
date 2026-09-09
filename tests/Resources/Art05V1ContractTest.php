<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

require_once dirname(__DIR__, 2) . '/stubs/App/controllers/art05v1.php';

final class Art05V1ContractTest extends TestCase
{
    private Filesystem $filesystem;
    private string $fixtureRoot;
    private string $previousWorkingDirectory;
    private array $previousEnvironment;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-art05v1-'
            . bin2hex(random_bytes(8));
        $this->previousWorkingDirectory = (string) getcwd();
        $this->previousEnvironment = $_ENV;

        $templateTarget = $this->fixtureRoot
            . '/App/templates/_art05v1.html';
        $this->filesystem->mkdir(dirname($templateTarget));
        $this->filesystem->copy(
            dirname(__DIR__, 2) . '/stubs/App/templates/_art05v1.html',
            $templateTarget
        );

        chdir($this->fixtureRoot);
        $_ENV['RAIZ'] = 'http://localhost:1309/';

        $this->seedInstance(0, 3);
    }

    protected function tearDown(): void
    {
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'art05v1_')) {
                unset($GLOBALS[$key]);
            }
        }

        $_ENV = $this->previousEnvironment;
        chdir($this->previousWorkingDirectory);
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testZeroItemsDoesNotRenderTheResource(): void
    {
        self::assertSame('', controller_art05v1(0, ['items' => 0]));
    }

    public function testRendersPhotographicCardsWithStableKeysAndAssetUrls(): void
    {
        $html = controller_art05v1(0, ['items' => 3]);

        self::assertStringContainsString(
            'class="art05v1 art05v1_00_classVar art05v1--items-3"',
            $html
        );
        self::assertSame(3, substr_count($html, 'class="art05v1-card"'));
        self::assertStringContainsString(
            'data-lang="art05v1_00_headerSecondary_a"',
            $html
        );
        self::assertStringContainsString(
            'src="http://localhost:1309/assets/img/dummy/dummy01.avif"',
            $html
        );
        self::assertStringNotContainsString('1309//assets/', $html);
        self::assertDoesNotMatchRegularExpression(
            '/\{[A-Za-z][A-Za-z0-9_-]*\}/',
            $html
        );
    }

    public function testItemsAreCappedAtTheAlphabetContract(): void
    {
        $html = controller_art05v1(0, ['items' => 99]);

        self::assertSame(26, substr_count($html, 'class="art05v1-card"'));
        self::assertStringContainsString('art05v1--items-26', $html);
    }

    public function testMultipleInstancesKeepIndependentLanguagePrefixes(): void
    {
        $this->seedInstance(2, 1);

        $html = controller_art05v1(2, ['items' => 1]);

        self::assertStringContainsString('art05v1_02_classVar', $html);
        self::assertStringContainsString(
            'data-lang="art05v1_02_headerSecondary_a"',
            $html
        );
        self::assertStringNotContainsString(
            'data-lang="art05v1_00_headerSecondary_a"',
            $html
        );
    }

    public function testHeadingLevelsRemainRelativeAndClampAtH6(): void
    {
        $html = controller_art05v1(0, [
            'items' => 1,
            'header_level' => 5,
        ]);

        self::assertStringContainsString(
            '<h5 class="art05v1-title"',
            $html
        );
        self::assertStringContainsString(
            '<h6 class="art05v1-cardTitle"',
            $html
        );

        $injected = controller_art05v1(0, [
            'items' => 1,
            '{header-primary}' => '<h2>Encabezado inyectado</h2>',
        ]);

        self::assertStringContainsString('<h2>Encabezado inyectado</h2>', $injected);
        self::assertStringContainsString(
            '<h3 class="art05v1-cardTitle"',
            $injected
        );
    }

    public function testStylesAreStandaloneResponsiveAndColorContractSafe(): void
    {
        $scss = (string) file_get_contents(
            dirname(__DIR__, 2) . '/resources/scss/_art05v1.scss'
        );

        foreach ([
            '--art05v1-card-background:',
            '.art05v1-cardMedia {',
            'aspect-ratio: 4 / 3;',
            'object-fit: contain;',
            'width: 48%;',
            'width: 31%;',
            'max-width: 28rem;',
            'justify-content: center;',
            'margin-top: auto;',
        ] as $contract) {
            self::assertStringContainsString($contract, $scss);
        }

        self::assertStringNotContainsString('c.$color04', $scss);
        self::assertStringNotContainsString('c.$color05', $scss);
        self::assertStringNotContainsString('69, 100, 147', $scss);
    }

    public function testShowroomLanguagesContainEveryRenderedField(): void
    {
        foreach (['es', 'en', 'eu'] as $language) {
            $catalog = json_decode(
                (string) file_get_contents(
                    dirname(__DIR__, 2)
                        . "/stubs/App/config/languages/templates/{$language}.json"
                ),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            foreach ([
                'art05v1_00_headerPrimary',
                'art05v1_00_intro_p',
            ] as $key) {
                self::assertIsArray($catalog[$key] ?? null, "{$language}: {$key}");
                self::assertArrayHasKey('text', $catalog[$key]);
            }

            foreach (['a', 'b', 'c'] as $letter) {
                foreach ([
                    "art05v1_00_headerSecondary_{$letter}",
                    "art05v1_00_{$letter}_p",
                    "art05v1_00_{$letter}_firma",
                ] as $key) {
                    self::assertIsArray($catalog[$key] ?? null, "{$language}: {$key}");
                    self::assertArrayHasKey('text', $catalog[$key]);
                }

                $imageKey = "art05v1_00_{$letter}_img";
                self::assertIsArray(
                    $catalog[$imageKey] ?? null,
                    "{$language}: {$imageKey}"
                );
                foreach (['src', 'alt', 'title'] as $property) {
                    self::assertArrayHasKey($property, $catalog[$imageKey]);
                }
            }
        }
    }

    private function seedInstance(int $instance, int $items): void
    {
        $pad = sprintf('%02d', $instance);
        $GLOBALS["art05v1_{$pad}_headerPrimary"] = (object) [
            'text' => 'art05v1',
        ];
        $GLOBALS["art05v1_{$pad}_intro_p"] = (object) [
            'text' => 'Introducción de prueba',
        ];

        foreach (array_slice(range('a', 'z'), 0, $items) as $offset => $letter) {
            $GLOBALS["art05v1_{$pad}_headerSecondary_{$letter}"] = (object) [
                'text' => 'Ficha ' . ($offset + 1),
            ];
            $GLOBALS["art05v1_{$pad}_{$letter}_img"] = (object) [
                'src' => '/assets/img/dummy/dummy01.avif',
                'alt' => 'Imagen de prueba',
                'title' => 'Título de imagen',
            ];
            $GLOBALS["art05v1_{$pad}_{$letter}_p"] = (object) [
                'text' => 'Texto de prueba',
            ];
            $GLOBALS["art05v1_{$pad}_{$letter}_firma"] = (object) [
                'text' => 'Firma de prueba',
            ];
        }
    }
}
