<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

require_once dirname(__DIR__, 2) . '/stubs/App/controllers/art02v1.php';

final class Art02V1ContractTest extends TestCase
{
    private Filesystem $filesystem;
    private string $fixtureRoot;
    private string $previousWorkingDirectory;
    private array $previousEnvironment;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-art02v1-'
            . bin2hex(random_bytes(8));
        $this->previousWorkingDirectory = (string) getcwd();
        $this->previousEnvironment = $_ENV;

        $templateTarget = $this->fixtureRoot
            . '/App/templates/_art02v1.html';
        $this->filesystem->mkdir(dirname($templateTarget));
        $this->filesystem->copy(
            dirname(__DIR__, 2) . '/stubs/App/templates/_art02v1.html',
            $templateTarget
        );

        chdir($this->fixtureRoot);
        $_ENV['RAIZ'] = 'http://localhost:1309/';

        $this->seedInstance(0, 4);
    }

    protected function tearDown(): void
    {
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'art02v1_')) {
                unset($GLOBALS[$key]);
            }
        }

        $_ENV = $this->previousEnvironment;
        chdir($this->previousWorkingDirectory);
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testItemsRespectZeroDefaultAndAlphabetLimit(): void
    {
        self::assertSame('', controller_art02v1(0, ['items' => 0]));

        $defaultHtml = controller_art02v1();
        self::assertSame(
            4,
            substr_count($defaultHtml, 'class="art02v1-card"')
        );
        self::assertStringContainsString(
            'class="art02v1 art02v1_00_classVar art02v1--items-4"',
            $defaultHtml
        );

        $maximumHtml = controller_art02v1(0, ['items' => 99]);
        self::assertSame(
            26,
            substr_count($maximumHtml, 'class="art02v1-card"')
        );
        self::assertStringContainsString(
            'art02v1--items-26',
            $maximumHtml
        );
    }

    public function testArticleSemanticsAndRelativeHeadingsSupportInjection(): void
    {
        $scaledHtml = controller_art02v1(0, [
            'items' => 1,
            'header_level' => 5,
        ]);

        self::assertMatchesRegularExpression(
            '/^\s*<article\b.*<\/article>\s*$/s',
            $scaledHtml
        );
        self::assertDoesNotMatchRegularExpression(
            '/<\s*(?:section|header)\b/i',
            $scaledHtml
        );
        self::assertStringContainsString(
            '<h5 data-lang="art02v1_00_headerPrimary">',
            $scaledHtml
        );
        self::assertStringContainsString(
            '<h6 class="art02v1-cardTitle"',
            $scaledHtml
        );

        $injectedHtml = controller_art02v1(0, [
            'items' => 1,
            '{header-primary}' => '<h2 class="injected">Equipo Matrix</h2>',
        ]);

        self::assertStringContainsString(
            '<h2 class="injected">Equipo Matrix</h2>',
            $injectedHtml
        );
        self::assertStringContainsString(
            '<h3 class="art02v1-cardTitle"',
            $injectedHtml
        );
        self::assertStringNotContainsString(
            'data-lang="art02v1_00_headerPrimary"',
            $injectedHtml
        );
    }

    public function testMultipleInstancesKeepIndependentPrefixes(): void
    {
        $this->seedInstance(7, 1);

        $html = controller_art02v1(7, ['items' => 1]);

        foreach ([
            'art02v1_07_classVar',
            'data-lang="art02v1_07_headerPrimary"',
            'data-lang="art02v1_07_intro_p"',
            'data-lang="art02v1_07_p1"',
            'data-lang="art02v1_07_p2"',
            'data-lang="art02v1_07_headerSecondary_a"',
            'data-lang="art02v1_07_a_img"',
            'data-lang="art02v1_07_a_p"',
        ] as $contract) {
            self::assertStringContainsString($contract, $html);
        }

        self::assertStringNotContainsString('art02v1_00_', $html);
    }

    public function testImageUrlsAttributesAndOptionalCtaAreSafe(): void
    {
        $GLOBALS['art02v1_00_a_img'] = (object) [
            'src' => '/assets/img/dummy/dummy01.avif',
            'alt' => 'Perfil "Matrix" & equipo <uno>',
            'title' => 'Retrato "principal"',
        ];

        $html = controller_art02v1(0, [
            'items' => 1,
            '{a-button-primary}' => '<a href="/equipo">Ver perfil</a>',
        ]);

        self::assertStringContainsString(
            'src="http://localhost:1309/assets/img/dummy/dummy01.avif"',
            $html
        );
        self::assertStringNotContainsString('1309//assets/', $html);
        self::assertStringContainsString(
            'alt="Perfil &quot;Matrix&quot; &amp; equipo &lt;uno&gt;"',
            $html
        );
        self::assertStringContainsString(
            'title="Retrato &quot;principal&quot;"',
            $html
        );
        self::assertStringContainsString(
            'width="900" height="900" loading="lazy" decoding="async"',
            $html
        );
        self::assertStringContainsString(
            '<div class="art02v1-cardCta"><a href="/equipo">'
                . 'Ver perfil</a></div>',
            $html
        );

        $withoutCta = controller_art02v1(0, ['items' => 1]);
        self::assertStringNotContainsString('art02v1-cardCta', $withoutCta);

        $GLOBALS['art02v1_00_a_img']->src = 'https://cdn.example/avatar.avif';
        $externalImage = controller_art02v1(0, ['items' => 1]);
        self::assertStringContainsString(
            'src="https://cdn.example/avatar.avif"',
            $externalImage
        );
        self::assertStringNotContainsString(
            'localhost:1309/https://',
            $externalImage
        );

        $GLOBALS['art02v1_00_a_img']->src = '   ';
        $withoutImage = controller_art02v1(0, ['items' => 1]);
        self::assertStringNotContainsString(
            'class="art02v1-avatar"',
            $withoutImage
        );
    }

    public function testStylesDefineResponsiveAvatarCardsWithoutPrivateColors(): void
    {
        $scss = (string) file_get_contents(
            dirname(__DIR__, 2) . '/resources/scss/_art02v1.scss'
        );

        foreach ([
            '.art02v1-card {',
            'align-items: stretch;',
            'border-radius: .5rem;',
            '.art02v1-avatar {',
            'aspect-ratio: 1 / 1;',
            'object-fit: cover;',
            'border-radius: 50%;',
            'filter: none;',
            'width: calc(50% - 1rem);',
            'width: calc((100% - 9rem) / 4);',
            'max-width: 20rem;',
        ] as $contract) {
            self::assertStringContainsString($contract, $scss);
        }

        self::assertStringNotContainsString('c.$color04', $scss);
        self::assertStringNotContainsString('c.$color05', $scss);
        self::assertDoesNotMatchRegularExpression(
            '/rgba\s*\(\s*(?:39\s*,\s*57\s*,\s*83|69\s*,\s*100\s*,\s*147)/i',
            $scss
        );
    }

    public function testLanguagesExposeFourProfilesAndShowroomRegistersResource(): void
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
                    'art02v1_00_'
                )
            ));

            self::assertCount(16, $keys, "{$language}: claves art02v1");

            foreach ([
                'art02v1_00_headerPrimary',
                'art02v1_00_intro_p',
                'art02v1_00_p1',
                'art02v1_00_p2',
            ] as $key) {
                self::assertIsArray($catalog[$key] ?? null, "{$language}: {$key}");
                self::assertArrayHasKey('text', $catalog[$key]);
            }

            foreach (range('a', 'd') as $letter) {
                foreach ([
                    "art02v1_00_headerSecondary_{$letter}",
                    "art02v1_00_{$letter}_p",
                ] as $key) {
                    self::assertIsArray(
                        $catalog[$key] ?? null,
                        "{$language}: {$key}"
                    );
                    self::assertArrayHasKey('text', $catalog[$key]);
                }

                $imageKey = "art02v1_00_{$letter}_img";
                self::assertIsArray(
                    $catalog[$imageKey] ?? null,
                    "{$language}: {$imageKey}"
                );
                foreach (['src', 'alt', 'title'] as $attribute) {
                    self::assertArrayHasKey($attribute, $catalog[$imageKey]);
                }
            }
        }

        $showroom = (string) file_get_contents(
            $root . '/stubs/App/views/showroom/_cards-grids.php'
        );
        $entrypoint = (string) file_get_contents(
            $root . '/src/scss/showroom/cards-grids.scss'
        );

        self::assertMatchesRegularExpression(
            "/controller\('art02v1',\s*0,\s*\[\s*'items'\s*=>\s*4/s",
            $showroom
        );
        self::assertStringContainsString(
            "@use '../resources/art02v1';",
            $entrypoint
        );
    }

    private function seedInstance(int $instance, int $items): void
    {
        $pad = sprintf('%02d', $instance);

        foreach ([
            'headerPrimary' => 'art02v1',
            'intro_p' => 'Introducción Matrix',
            'p1' => 'Primer párrafo Matrix',
            'p2' => 'Segundo párrafo Matrix',
        ] as $field => $text) {
            $GLOBALS["art02v1_{$pad}_{$field}"] = (object) [
                'text' => $text,
            ];
        }

        foreach (array_slice(range('a', 'z'), 0, $items) as $letter) {
            $GLOBALS["art02v1_{$pad}_headerSecondary_{$letter}"] = (object) [
                'text' => "Perfil {$letter}",
            ];
            $GLOBALS["art02v1_{$pad}_{$letter}_img"] = (object) [
                'src' => '/assets/img/dummy/dummy01.avif',
                'alt' => "Perfil {$letter}",
                'title' => "Retrato {$letter}",
            ];
            $GLOBALS["art02v1_{$pad}_{$letter}_p"] = (object) [
                'text' => "Descripción {$letter}",
            ];
        }
    }
}
