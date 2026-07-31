<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/ShowroomCatalogFixture.php';

final class ShowroomHeadingCopyTest extends TestCase
{
    /**
     * @return array<string, list<string>>
     */
    public static function headingLabels(): array
    {
        return [
            'hero03_00_front_text' => ['hero03'],
            'moduleH1Type01_00_h1_text' => ['hero01', 'moduleH1Type01'],
            'moduleH1Type01_01_h1_text' => ['hero00', 'moduleH1Type01'],
            'moduleH1Type01_02_h1_text' => ['hero02', 'moduleH1Type01'],
            'moduleH1Type01_03_h1_text' => ['hero04', 'moduleH1Type01'],
            'moduleH1Type02_00_h1_text' => ['moduleH1Type02'],
            'moduleH1Type03_00_h1_text' => ['hero06', 'moduleH1Type03'],
            'moduleH1Type04_00_h1_text' => ['hero07', 'moduleH1Type04'],
            'moduleH2Type01_00_h2_text' => ['sect01', 'moduleH2Type01'],
            'moduleH2Type01_01_h2_text' => ['sect02', 'moduleH2Type01'],
            'moduleH2Type01_02_h2_text' => ['sectTabs01', 'moduleH2Type01'],
            'moduleH2Type01_03_h2_text' => ['moduleH2Type01'],
            'moduleH2Type01_04_h2_text' => ['art17', 'moduleH2Type01'],
            'moduleH2Type01_05_h2_text' => ['art02little', 'moduleList01'],
            'moduleH2Type01_06_h2_text' => ['sectionParticles01', 'moduleH2Type01'],
            'moduleH2Type01_09_h2_text' => [
                'moduleButtonType03',
                'moduleButtonType04',
            ],
            'moduleH2Type02_00_headerPrimary' => ['moduleH2Type02'],
            'sectionDiskSlider01_00_headerPrimary' => ['sectionDiskSlider01'],
            'sectionSkewGallery01_00_headerPrimary' => ['sectionSkewGallery01'],
            'artWorksSkew01_00_headerPrimary' => ['artWorksSkew01'],
            'sectionHScroll01_00_headerPrimary' => ['sectionHScroll01'],
            'artHeroScroll01_00_headerPrimary' => ['artHeroScroll01'],
            'sectionParallax01_00_a_title' => ['sectionParallax01'],
            'art01_00_headerPrimary' => ['art01'],
            'art02_00_headerPrimary' => ['art02'],
            'art02little_00_headerPrimary' => ['art02little', 'moduleParrafo01'],
            'art02little_01_headerPrimary' => ['art02little', 'moduleList01'],
            'art03_00_headerPrimary' => ['art03'],
            'art04_00_headerPrimary' => ['art04'],
            'art05_00_headerPrimary' => ['art05'],
            'art06_00_headerPrimary' => ['art06'],
            'art07_00_h3_1' => ['art07'],
            'art08_00_headerPrimary' => ['art08'],
            'art09_00_headerPrimary' => ['art09'],
            'art10_00_headerPrimary' => ['art10'],
            'art11_00_headerPrimary' => ['art11'],
            'art12_00_headerPrimary' => ['art12'],
            'art13_00_headerPrimary' => ['art13'],
            'art14_00_headerPrimary' => ['art14'],
            'art15_00_headerPrimary' => ['art15'],
            'art16_00_h3_1' => ['art16'],
            'art17_00_headerPrimary' => ['art17'],
            'art18_00_headerPrimary' => ['art18'],
            'art19_00_headerPrimary' => ['art19'],
            'art20_00_headerPrimary' => ['art20'],
            'art21_00_headerPrimary' => ['art21'],
            'art22_00_headerPrimary' => ['art22'],
            'art23_00_headerPrimary' => ['art23'],
            'art24_00_headerPrimary' => ['art24'],
            'art25_00_headerPrimary' => ['art25'],
            'art26_00_headerPrimary' => ['art26'],
            'art27_00_headerPrimary' => ['art27'],
            'art28_00_headerPrimary' => ['art28'],
            'art29_00_headerPrimary' => ['art29'],
            'art30_00_headerPrimary' => ['art30'],
            'art31_00_headerPrimary' => ['art31'],
            'art32_00_headerPrimary' => ['art32'],
            'artPricingGlass01_00_headerSecondary_a' => ['artPricingGlass01'],
            'artSlider01_00_headerPrimary' => ['artSlider01'],
            'artSlider02_00_headerPrimary' => ['artSlider02'],
            'artForm01_00_headerPrimary' => ['artForm01'],
            'artAccordion01_00_headerPrimary' => ['artAccordion01'],
            'artAccordion02_00_headerPrimary' => ['artAccordion02'],
            'artZipper_00_h3' => ['artZipper'],
            'moduleTest_00_h2_text' => ['moduleTest'],
        ];
    }

    /**
     * @dataProvider languageProvider
     */
    public function testCatalogHeadingsContainSearchableResourceIdentifiers(
        string $language
    ): void {
        $path = dirname(__DIR__, 2)
            . "/stubs/App/config/languages/templates/{$language}.json";
        $content = file_get_contents($path);

        self::assertIsString($content);

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        foreach (self::headingLabels() as $key => $identifiers) {
            self::assertArrayHasKey($key, $data, "{$language}: falta {$key}");
            self::assertIsArray($data[$key], "{$language}: {$key} debe ser un objeto");
            self::assertArrayHasKey('text', $data[$key], "{$language}: falta {$key}.text");
            self::assertIsString($data[$key]['text']);

            foreach ($identifiers as $identifier) {
                self::assertStringContainsString(
                    $identifier,
                    $data[$key]['text'],
                    "{$language}: {$key} no identifica {$identifier}"
                );
            }
        }
    }

    public function testSharedHeadingsUseIndependentShowroomInstances(): void
    {
        $showroom = ShowroomCatalogFixture::corePhp(dirname(__DIR__, 2));

        foreach ([0, 1, 2, 3] as $index) {
            self::assertStringContainsString(
                "controller('moduleH1Type01', {$index},",
                $showroom
            );
        }

        foreach ([6, 7, 8] as $index) {
            self::assertStringContainsString(
                "controller('moduleH2Type01', {$index})",
                $showroom
            );
        }

        self::assertStringContainsString(
            "'{hero05-text}' => 'hero05 · Liquid Matrix'",
            $showroom
        );

        self::assertStringContainsString(
            "controller('moduleH1Type03', 0,",
            $showroom
        );
        self::assertStringContainsString(
            "controller('moduleH1Type04', 0,",
            $showroom
        );
        self::assertStringContainsString(
            "'{hero06-content}' => \$hero06Content",
            $showroom
        );
        self::assertStringContainsString(
            "'{hero07-content}' => \$hero07Content",
            $showroom
        );
        self::assertStringContainsString(
            "controller('moduleH2Type01', 9)",
            $showroom
        );
    }

    public function testPromotedResourcesAreRegisteredInTheShowroom(): void
    {
        $showroom = ShowroomCatalogFixture::corePhp(dirname(__DIR__, 2));

        foreach ([
            'moduleH2Type02',
            'moduleButtonType03',
            'moduleButtonType04',
            'art20',
            'art21',
            'art22',
            'art23',
            'art24',
            'art25',
            'art26',
            'art27',
            'art28',
            'art29',
            'art30',
            'art31',
            'art32',
            'artAccordion02',
        ] as $resource) {
            self::assertStringContainsString(
                "controller('{$resource}', 0",
                $showroom,
                "Falta {$resource} en el showroom"
            );
        }
    }

    public function testInteractiveShowroomResourcesHaveTheSameKeysInEveryLanguage(): void
    {
        $languages = [];

        foreach (['es', 'en', 'eu'] as $language) {
            $path = dirname(__DIR__, 2)
                . "/stubs/App/config/languages/templates/{$language}.json";
            $content = file_get_contents($path);

            self::assertIsString($content);
            $languages[$language] = json_decode(
                $content,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        }

        foreach ([
            'sectionDiskSlider01_',
            'sectionSkewGallery01_',
            'sectionParallax01_',
            'artWorksSkew01_',
            'sectionHScroll01_',
            'artHeroScroll01_',
            'artMarquee01_',
            'artPricingGlass01_',
            'art17_',
            'art18_',
            'art19_',
            'hero06_',
            'hero07_',
            'moduleH1Type03_',
            'moduleH1Type04_',
            'moduleH2Type02_',
            'moduleButtonType02_',
            'moduleButtonType03_',
            'moduleButtonType04_',
            'moduleList01_',
            'art20_',
            'art21_',
            'art22_',
            'art23_',
            'art24_',
            'art25_',
            'art26_',
            'art27_',
            'art28_',
            'art29_',
            'art30_',
            'art31_',
            'art32_',
            'artAccordion02_',
        ] as $prefix) {
            $expected = $this->keysWithPrefix($languages['es'], $prefix);

            foreach (['en', 'eu'] as $language) {
                self::assertSame(
                    $expected,
                    $this->keysWithPrefix($languages[$language], $prefix),
                    "{$language}: catálogo incompleto para {$prefix}"
                );
            }
        }
    }

    public function testArt02ShowroomUsesEightIconCardsWithSubstantialCopy(): void
    {
        $root = dirname(__DIR__, 2);
        $showroom = ShowroomCatalogFixture::corePhp($root);
        self::assertSame(
            1,
            preg_match_all(
                "/echo controller\\('art02',\\s*0,\\s*\\[\\s*"
                . "'items'\\s*=>\\s*8,?\\s*\\]\\);/s",
                $showroom
            ),
            'El showroom debe mostrar una sola instancia de art02 con ocho items'
        );

        foreach (['es', 'en', 'eu'] as $language) {
            $path = $root
                . "/stubs/App/config/languages/templates/{$language}.json";
            $content = file_get_contents($path);

            self::assertIsString($content);
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            $this->assertWordRange(
                $data['art02_00_intro_p']['text'] ?? '',
                25,
                35,
                "{$language}: intro de art02"
            );
            $this->assertWordRange(
                $data['art02_00_p1']['text'] ?? '',
                25,
                35,
                "{$language}: primer párrafo de art02"
            );
            $this->assertWordRange(
                $data['art02_00_p2']['text'] ?? '',
                18,
                25,
                "{$language}: segundo párrafo de art02"
            );

            foreach (range('a', 'h') as $item) {
                $headingKey = "art02_00_headerSecondary_{$item}";
                $imageKey = "art02_00_{$item}_img";
                $paragraphKey = "art02_00_{$item}_p";

                self::assertNotSame(
                    '',
                    trim((string) ($data[$headingKey]['text'] ?? '')),
                    "{$language}: falta el encabezado del item {$item}"
                );

                $image = $data[$imageKey] ?? null;
                self::assertIsArray(
                    $image,
                    "{$language}: falta la imagen del item {$item}"
                );

                $source = (string) ($image['src'] ?? '');
                self::assertStringStartsWith(
                    'assets/img/system/',
                    $source,
                    "{$language}: el item {$item} debe usar un icono de sistema"
                );
                self::assertFileExists(
                    $root . '/resources/img/'
                    . substr($source, strlen('assets/img/')),
                    "{$language}: no existe el icono del item {$item}"
                );

                $this->assertWordRange(
                    $data[$paragraphKey]['text'] ?? '',
                    22,
                    32,
                    "{$language}: párrafo del item {$item}"
                );
            }
        }
    }

    private function assertWordRange(
        mixed $text,
        int $minimum,
        int $maximum,
        string $context
    ): void {
        self::assertIsString($text, "{$context}: el copy debe ser texto");

        $words = preg_split(
            '/\s+/u',
            trim($text),
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        self::assertIsArray($words);
        self::assertGreaterThanOrEqual(
            $minimum,
            count($words),
            "{$context}: copy demasiado breve"
        );
        self::assertLessThanOrEqual(
            $maximum,
            count($words),
            "{$context}: copy demasiado extenso"
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function keysWithPrefix(array $data, string $prefix): array
    {
        $keys = array_values(array_filter(
            array_keys($data),
            static fn (string $key): bool => str_starts_with($key, $prefix)
        ));
        sort($keys);

        return $keys;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function languageProvider(): array
    {
        return [
            'Spanish' => ['es'],
            'English' => ['en'],
            'Basque' => ['eu'],
        ];
    }
}
