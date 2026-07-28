<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

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
            'moduleH2Type01_00_h2_text' => ['sect01', 'moduleH2Type01'],
            'moduleH2Type01_01_h2_text' => ['sect02', 'moduleH2Type01'],
            'moduleH2Type01_02_h2_text' => ['sectTabs01', 'moduleH2Type01'],
            'moduleH2Type01_03_h2_text' => ['moduleH2Type01'],
            'moduleH2Type01_04_h2_text' => ['art17', 'moduleH2Type01'],
            'moduleH2Type01_05_h2_text' => ['art02little', 'moduleList01'],
            'moduleH2Type01_06_h2_text' => ['sectionParticles01', 'moduleH2Type01'],
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
            'artPricingGlass01_00_headerSecondary_a' => ['artPricingGlass01'],
            'artSlider01_00_headerPrimary' => ['artSlider01'],
            'artSlider02_00_headerPrimary' => ['artSlider02'],
            'artForm01_00_headerPrimary' => ['artForm01'],
            'artAccordion01_00_headerPrimary' => ['artAccordion01'],
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
        $path = dirname(__DIR__, 2) . '/stubs/App/views/_showroom.php';
        $showroom = file_get_contents($path);

        self::assertIsString($showroom);

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
            'artWorksSkew01_',
            'sectionHScroll01_',
            'artHeroScroll01_',
            'art19_',
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
