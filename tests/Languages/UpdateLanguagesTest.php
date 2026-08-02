<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class UpdateLanguagesTest extends TestCase
{
    private string $fixtureRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-language-hydration-'
            . bin2hex(random_bytes(8));

        $this->filesystem->mkdir([
            $this->fixtureRoot . '/App/config/languages/templates',
            $this->fixtureRoot . '/App/config/languages/home',
            $this->fixtureRoot . '/App/config/routes',
            $this->fixtureRoot . '/App/controllers',
            $this->fixtureRoot . '/App/tools',
            $this->fixtureRoot . '/App/views',
            $this->fixtureRoot . '/public',
            $this->fixtureRoot . '/vendor',
        ]);

        $coreRoot = dirname(__DIR__, 2);
        $this->filesystem->copy(
            $coreRoot . '/stubs/App/tools/update-languages.php',
            $this->fixtureRoot . '/App/tools/update-languages.php'
        );
        $this->writeFile(
            $this->fixtureRoot . '/vendor/autoload.php',
            '<?php require '
                . var_export($coreRoot . '/vendor/autoload.php', true)
                . ';'
        );
        $this->writeFile(
            $this->fixtureRoot . '/App/config/langs.php',
            "<?php\nreturn ['es', 'en'];\n"
        );
        $this->writeFile(
            $this->fixtureRoot . '/App/config/routes/get.php',
            <<<'PHP'
<?php
return [
    'es' => [
        '/' => [
            'content' => 'home',
            'view' => '../App/views/home.php',
        ],
    ],
    'en' => [
        '/en' => [
            'content' => 'home',
            'view' => '../App/views/home.php',
        ],
    ],
];
PHP
        );
        $this->writeFile(
            $this->fixtureRoot . '/App/views/home.php',
            <<<'PHP'
<?php
echo controller('art17', 0, [
    'items' => 2,
    'list_items' => [
        'a' => 3,
        'b' => 2,
    ],
]);
echo controller('simple', 0, ['items' => 2]);
echo controller('artHeroScroll01', 0, [
    'items' => 2,
    'subitems' => 2,
]);
echo controller('art30', 0, [
    'items' => 1,
    'benefits' => 3,
]);
echo controller('artMarquee01', 0, [
    'items' => 1,
    'items_row1' => 3,
    'items_row2' => 2,
    'with_images' => true,
]);
echo controller('hero01', 0, [
    '{content}' => controller('moduleH2Type01', 0, ['items' => 2]),
]);
require __DIR__ . '/partial.php';
PHP
        );
        $this->writeFile(
            $this->fixtureRoot . '/App/views/partial.php',
            "<?php echo controller('includedResource', 0);\n"
        );

        foreach ([
            'art17',
            'simple',
            'artHeroScroll01',
            'art30',
            'artMarquee01',
            'hero01',
            'moduleH2Type01',
            'includedResource',
        ] as $controller) {
            $this->writeFile(
                $this->fixtureRoot . "/App/controllers/{$controller}.php",
                "<?php\n"
            );
        }

        $templateEs = $this->templateCatalog();
        $templateEn = $templateEs;
        $templateEn['simple_00_note'] = 'English fallback must not replace an explicit empty value';
        $templateEn['art17_00_a_img']['alt'] = 'English fallback alt';

        $this->writeJson(
            $this->fixtureRoot . '/App/config/languages/templates/es.json',
            $templateEs
        );
        $this->writeJson(
            $this->fixtureRoot . '/App/config/languages/templates/en.json',
            $templateEn
        );

        $home = [
            'title' => 'Home',
            'art17_00_headerPrimary' => 'Legacy scalar heading',
            'art17_00_a_cta' => [
                'text' => '',
                'href' => '',
                'title' => '',
            ],
            'art17_00_a_img' => [
                'src' => 'customer.svg',
                'alt' => '',
            ],
            'art17_00_note' => '',
            'art17_00_a_list_a' => ['text' => 'Customer A1'],
            'art17_00_a_list_b' => ['text' => 'Customer A2'],
            'art17_00_a_list_c' => ['text' => 'Customer A3'],
            'art17_00_b_list_a' => ['text' => 'Customer B1'],
            'art17_00_b_list_b' => ['text' => 'Customer B2'],
            'art17_00_a_list_z' => ['text' => 'Dormant customer copy'],
            'art17_00_custom_backend_value' => ['text' => 'Still active'],
            'art17_00_classVar' => 'customer-modifier',
            'obsolete_00_copy' => ['text' => 'Removed resource'],
            'errors' => ['required' => 'Required field'],
        ];
        $this->writeJson(
            $this->fixtureRoot . '/App/config/languages/home/es.json',
            $home
        );
        $this->writeJson(
            $this->fixtureRoot . '/App/config/languages/home/en.json',
            $home
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testHydrationPreservesExistingDataAndExpandsEveryDeclaredAxis(): void
    {
        $this->runUpdater('home');
        $catalog = $this->readJson(
            $this->fixtureRoot . '/App/config/languages/home/es.json'
        );

        self::assertSame('Legacy scalar heading', $catalog['art17_00_headerPrimary']);
        self::assertSame(
            ['text' => '', 'href' => '', 'title' => ''],
            $catalog['art17_00_a_cta']
        );
        self::assertSame('customer.svg', $catalog['art17_00_a_img']['src']);
        self::assertSame('', $catalog['art17_00_a_img']['alt']);
        self::assertSame('Dummy image title', $catalog['art17_00_a_img']['title']);
        self::assertSame('', $catalog['art17_00_note']);
        self::assertSame(
            'Dormant customer copy',
            $catalog['art17_00_a_list_z']['text']
        );
        self::assertSame(
            'Still active',
            $catalog['art17_00_custom_backend_value']['text']
        );
        self::assertSame('customer-modifier', $catalog['art17_00_classVar']);
        self::assertSame('Removed resource', $catalog['obsolete_00_copy']['text']);
        self::assertSame('Required field', $catalog['errors']['required']);

        foreach (['a', 'b', 'c'] as $item) {
            self::assertArrayHasKey("art17_00_a_list_{$item}", $catalog);
        }
        foreach (['a', 'b'] as $item) {
            self::assertArrayHasKey("art17_00_b_list_{$item}", $catalog);
        }
        self::assertArrayNotHasKey('art17_00_b_list_c', $catalog);
        self::assertArrayNotHasKey('art17_00_c_list_a', $catalog);

        foreach (['a', 'b'] as $item) {
            self::assertArrayHasKey("simple_00_headerSecondary_{$item}", $catalog);
        }
        self::assertArrayNotHasKey('simple_00_headerSecondary_c', $catalog);

        foreach (['a', 'b'] as $outer) {
            foreach (['a', 'b'] as $inner) {
                self::assertArrayHasKey(
                    "artHeroScroll01_00_item{$outer}_sub{$inner}",
                    $catalog
                );
            }
            self::assertArrayNotHasKey(
                "artHeroScroll01_00_item{$outer}_subc",
                $catalog
            );
        }

        foreach (['a', 'b', 'c'] as $item) {
            self::assertArrayHasKey("art30_00_benefit_{$item}_img", $catalog);
        }
        self::assertArrayNotHasKey('art30_00_benefit_d_img', $catalog);
        self::assertArrayHasKey('art30_00_intro_p_a', $catalog);
        self::assertArrayHasKey('art30_00_intro_p_b', $catalog);
        self::assertArrayNotHasKey('art30_00_intro_p_c', $catalog);

        foreach (['text', 'img'] as $kind) {
            foreach (['a', 'b', 'c'] as $item) {
                self::assertArrayHasKey(
                    "artMarquee01_00_row1_item_{$kind}_{$item}",
                    $catalog
                );
            }
            self::assertArrayNotHasKey(
                "artMarquee01_00_row1_item_{$kind}_d",
                $catalog
            );
            foreach (['a', 'b'] as $item) {
                self::assertArrayHasKey(
                    "artMarquee01_00_row2_item_{$kind}_{$item}",
                    $catalog
                );
            }
            self::assertArrayNotHasKey(
                "artMarquee01_00_row2_item_{$kind}_c",
                $catalog
            );
        }
        foreach (['a', 'b', 'c'] as $item) {
            self::assertArrayHasKey("hero01_00_label_{$item}", $catalog);
        }
        self::assertArrayHasKey('moduleH2Type01_00_h2_text', $catalog);
        self::assertArrayHasKey('moduleH2Type01_00_item_a', $catalog);
        self::assertArrayHasKey('moduleH2Type01_00_item_b', $catalog);
        self::assertArrayNotHasKey('moduleH2Type01_00_item_c', $catalog);
        self::assertArrayHasKey('includedResource_00_text', $catalog);
    }

    public function testHydrationIsIdempotentAndPruningRequiresAnExplicitFlag(): void
    {
        $this->runUpdater('home');
        $first = (string) file_get_contents(
            $this->fixtureRoot . '/App/config/languages/home/es.json'
        );
        $secondRunOutput = $this->runUpdater('home');
        $second = (string) file_get_contents(
            $this->fixtureRoot . '/App/config/languages/home/es.json'
        );

        self::assertSame($first, $second);
        self::assertSame(
            2,
            substr_count(
                $secondRunOutput,
                '[update-languages] Sin cambios:'
            )
        );
        self::assertStringNotContainsString(
            '[update-languages] Actualizado:',
            $secondRunOutput
        );
        self::assertStringNotContainsString(
            '[update-languages] Creado:',
            $secondRunOutput
        );

        $this->runUpdater('home', ['--prune-unused']);
        $catalog = $this->readJson(
            $this->fixtureRoot . '/App/config/languages/home/es.json'
        );

        self::assertArrayNotHasKey('obsolete_00_copy', $catalog);
        self::assertSame(
            'Dormant customer copy',
            $catalog['art17_00_a_list_z']['text']
        );
        self::assertSame(
            'Still active',
            $catalog['art17_00_custom_backend_value']['text']
        );
        self::assertArrayNotHasKey('art17_00_classVar', $catalog);
    }

    public function testProjectedItemsDoNotHydrateCatalogItemFixtures(): void
    {
        $this->writeFile(
            $this->fixtureRoot . '/App/views/home.php',
            <<<'PHP'
<?php
$projectedItems = [];
$catalogText = static function ($entry): string {
    return is_object($entry) && isset($entry->text)
        ? (string) $entry->text
        : '';
};

echo controller('dynamicGrid', 0, [
    'items_data' => $projectedItems,
    'items' => 8,
]);
echo controller('dynamicGrid', 1, [
    'items_data' => $projectedItems,
]);
echo controller('dynamicGrid', 2, [
    'items_data' => $projectedItems,
    'items' => 8,
]);
echo controller('dynamicGrid', 2, [
    'items' => 2,
]);
PHP
        );
        $this->writeFile(
            $this->fixtureRoot . '/App/controllers/dynamicGrid.php',
            <<<'PHP'
<?php
$pad = '00';
$idPrefix = "dynamicGrid-{$pad}";
$headerKey = "dynamicGrid_{$pad}_headerPrimary";
$header = $GLOBALS[$headerKey] ?? null;
PHP
        );

        foreach (['es', 'en'] as $language) {
            $templatePath = $this->fixtureRoot
                . "/App/config/languages/templates/{$language}.json";
            $template = $this->readJson($templatePath);
            $template['dynamicGrid_00_headerPrimary'] = [
                'text' => "Template header {$language}",
            ];
            foreach (['a', 'b', 'c'] as $letter) {
                $template["dynamicGrid_00_{$letter}_link"] = [
                    'text' => "Template {$language} {$letter}",
                    'href' => "/{$language}/{$letter}",
                    'title' => "Template title {$language} {$letter}",
                ];
            }
            $this->writeJson($templatePath, $template);

            $catalogPath = $this->fixtureRoot
                . "/App/config/languages/home/{$language}.json";
            $catalog = $this->readJson($catalogPath);
            $catalog['dynamicGrid_00_a_link'] = [
                'text' => "Customer {$language}",
                'href' => "/{$language}/customer",
                'title' => "Customer title {$language}",
                'custom' => 'preserve-me',
            ];
            $this->writeJson($catalogPath, $catalog);
        }

        $this->runUpdater('home');

        foreach (['es', 'en'] as $language) {
            $catalog = $this->readJson(
                $this->fixtureRoot
                    . "/App/config/languages/home/{$language}.json"
            );

            foreach (['00', '01', '02'] as $pad) {
                self::assertSame(
                    "Template header {$language}",
                    $catalog["dynamicGrid_{$pad}_headerPrimary"]['text']
                );
            }
            self::assertArrayNotHasKey('entry', $catalog);
            self::assertArrayNotHasKey('dynamicGrid-00', $catalog);

            self::assertSame(
                [
                    'text' => "Customer {$language}",
                    'href' => "/{$language}/customer",
                    'title' => "Customer title {$language}",
                    'custom' => 'preserve-me',
                ],
                $catalog['dynamicGrid_00_a_link']
            );

            foreach (['a', 'b'] as $letter) {
                self::assertSame(
                    "Template {$language} {$letter}",
                    $catalog["dynamicGrid_02_{$letter}_link"]['text']
                );
            }

            foreach ([
                '00' => ['dynamicGrid_00_a_link'],
                '01' => [],
                '02' => [
                    'dynamicGrid_02_a_link',
                    'dynamicGrid_02_b_link',
                ],
            ] as $pad => $expectedKeys) {
                $actualKeys = array_values(array_filter(
                    array_keys($catalog),
                    static fn (string $key): bool => preg_match(
                        "/^dynamicGrid_{$pad}_[a-z]_link$/",
                        $key
                    ) === 1
                ));
                sort($actualKeys, SORT_STRING);

                self::assertSame($expectedKeys, $actualKeys);
            }
        }
    }

    public function testInvalidCatalogAbortsBeforeAnyCatalogIsWritten(): void
    {
        $spanishPath = $this->fixtureRoot
            . '/App/config/languages/home/es.json';
        $englishPath = $this->fixtureRoot
            . '/App/config/languages/home/en.json';
        $spanishBefore = (string) file_get_contents($spanishPath);
        $englishBefore = (string) file_get_contents($englishPath);
        $invalidPath = $this->fixtureRoot
            . '/App/config/languages/unrelated/en.json';
        $invalidJson = "{\"truncated\":\n";

        $this->writeFile($invalidPath, $invalidJson);
        $result = $this->executeUpdater('home');
        $diagnostics = $result['stderr'] . $result['stdout'];

        self::assertNotSame(0, $result['exitCode']);
        self::assertStringContainsString(
            'JSON de idiomas no válido',
            $diagnostics
        );
        self::assertStringContainsString('unrelated', $diagnostics);
        self::assertSame(
            $spanishBefore,
            (string) file_get_contents($spanishPath)
        );
        self::assertSame(
            $englishBefore,
            (string) file_get_contents($englishPath)
        );
        self::assertSame($invalidJson, (string) file_get_contents($invalidPath));
    }

    #[DataProvider('routeAwareViewProvider')]
    public function testViewNamesResolveToTheirConfiguredRouteContent(
        string $showroomContent,
        string $requestedView,
        string $expectedContent
    ): void {
        $this->configureRouteAwareViewFixture($showroomContent);

        $output = str_replace(
            '\\',
            '/',
            $this->runUpdater($requestedView)
        );
        $expectedDirectory = $this->fixtureRoot
            . '/App/config/languages/'
            . $expectedContent;

        self::assertStringContainsString(
            "/App/config/languages/{$expectedContent}/es.json",
            $output
        );
        self::assertStringContainsString(
            "/App/config/languages/{$expectedContent}/en.json",
            $output
        );
        self::assertArrayHasKey(
            'simple_00_headerSecondary_b',
            $this->readJson($expectedDirectory . '/es.json')
        );
        self::assertDirectoryDoesNotExist(
            $this->fixtureRoot . '/App/config/languages/_showroom'
        );

        if ($expectedContent === 'templates') {
            self::assertDirectoryDoesNotExist(
                $this->fixtureRoot . '/App/config/languages/showroom'
            );
        } else {
            self::assertDirectoryExists($expectedDirectory);
        }
    }

    public static function routeAwareViewProvider(): array
    {
        return [
            'AIWA: nombre completo de showroom' => [
                'templates',
                '_showroom.php',
                'templates',
            ],
            'AIWA: alias bare de showroom' => [
                'templates',
                'showroom',
                'templates',
            ],
            'AIWA: nombre completo de templates' => [
                'templates',
                '_templates.php',
                'templates',
            ],
            'legacy: nombre completo de showroom' => [
                'showroom',
                '_showroom.php',
                'showroom',
            ],
            'legacy: alias bare de showroom' => [
                'showroom',
                'showroom',
                'showroom',
            ],
            'legacy: alias bare de templates' => [
                'showroom',
                'templates',
                'templates',
            ],
        ];
    }

    public function testCanonicalSegmentedShowroomHydratesAllPartialsWithoutPruningItsCopy(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $this->configureRouteAwareViewFixture('templates');

        $this->filesystem->copy(
            $coreRoot . '/stubs/App/views/_showroom.php',
            $this->fixtureRoot . '/App/views/_showroom.php',
            true
        );
        $this->filesystem->mirror(
            $coreRoot . '/stubs/App/views/showroom',
            $this->fixtureRoot . '/App/views/showroom',
            null,
            ['override' => true]
        );
        $this->filesystem->mirror(
            $coreRoot . '/stubs/App/controllers',
            $this->fixtureRoot . '/App/controllers',
            null,
            ['override' => true]
        );
        $this->filesystem->mirror(
            $coreRoot . '/stubs/App/templates',
            $this->fixtureRoot . '/App/templates',
            null,
            ['override' => true]
        );

        foreach (['es', 'en'] as $language) {
            $catalog = $this->readJson(
                "{$coreRoot}/stubs/App/config/languages/templates/{$language}.json"
            );
            $catalog['showroom_catalog_title']['text'] =
                "Título local {$language}";
            $catalog['art19_00_headerPrimary']['text'] =
                "art19 local {$language}";
            $catalog['obsolete_showroom_key'] = [
                'text' => 'Debe podarse',
            ];
            $this->writeJson(
                $this->fixtureRoot
                    . "/App/config/languages/templates/{$language}.json",
                $catalog
            );
        }

        $this->runUpdater('_showroom.php', ['--prune-unused']);

        $categoryKeys = [
            'heroes',
            'particles',
            'gsap_specials',
            'common',
            'cards_grids',
            'media',
            'forms_interactive',
            'modules_sections',
        ];
        $partialResourceKeys = [
            'hero03_00_front_text',
            'moduleH2Type01_06_h2_text',
            'artPricingGlass01_00_headerSecondary_a',
            'art01_00_headerPrimary',
            'art34_00_headerPrimary',
            'art19_00_headerPrimary',
            'moduleFormContact03_00_legend',
            'moduleTable01_00_caption',
        ];

        foreach (['es', 'en'] as $language) {
            $catalog = $this->readJson(
                $this->fixtureRoot
                    . "/App/config/languages/templates/{$language}.json"
            );

            self::assertSame(
                "Título local {$language}",
                $catalog['showroom_catalog_title']['text']
            );
            self::assertSame(
                "art19 local {$language}",
                $catalog['art19_00_headerPrimary']['text']
            );
            self::assertArrayNotHasKey('obsolete_showroom_key', $catalog);

            foreach ($categoryKeys as $category) {
                self::assertArrayHasKey(
                    "showroom_catalog_category_{$category}_label",
                    $catalog
                );
                self::assertArrayHasKey(
                    "showroom_catalog_category_{$category}_description",
                    $catalog
                );
            }
            foreach ($partialResourceKeys as $key) {
                self::assertArrayHasKey(
                    $key,
                    $catalog,
                    "{$language}: falta {$key} desde un parcial"
                );
            }
        }
    }

    public function testCanonicalCatalogCoversEveryAuditedVariableAxis(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['es', 'en', 'eu'] as $language) {
            $catalog = $this->readJson(
                "{$root}/stubs/App/config/languages/templates/{$language}.json"
            );

            foreach (['a' => 7, 'b' => 6] as $outer => $count) {
                $this->assertLetteredKeys(
                    $catalog,
                    "art17_00_{$outer}_list_",
                    $count,
                    $language
                );
            }
            foreach (['a' => 3, 'b' => 3, 'c' => 4] as $outer => $count) {
                $this->assertLetteredKeys(
                    $catalog,
                    "art18_00_{$outer}_list_",
                    $count,
                    $language
                );
            }
            foreach (['a', 'b', 'c'] as $outer) {
                $this->assertLetteredKeys(
                    $catalog,
                    "artPricingGlass01_00_{$outer}_list_",
                    5,
                    $language
                );
                $this->assertLetteredKeys(
                    $catalog,
                    "sectionParallax01_00_{$outer}_list_",
                    3,
                    $language
                );
            }
            foreach (['a', 'b', 'c', 'd'] as $outer) {
                $this->assertLetteredKeys(
                    $catalog,
                    "artHeroScroll01_00_item{$outer}_sub",
                    3,
                    $language
                );
            }
            foreach (['headerSecondary', 'img'] as $suffix) {
                for ($index = 0; $index < 6; $index++) {
                    $letter = chr(ord('a') + $index);
                    self::assertArrayHasKey(
                        "art30_00_benefit_{$letter}_{$suffix}",
                        $catalog,
                        "{$language}: falta art30 benefit {$letter} {$suffix}"
                    );
                }
            }
            foreach (['row1', 'row2'] as $row) {
                foreach (['text', 'img'] as $kind) {
                    $this->assertLetteredKeys(
                        $catalog,
                        "artMarquee01_00_{$row}_item_{$kind}_",
                        4,
                        $language
                    );
                }
            }

            foreach (['art30', 'artAccordion02'] as $resource) {
                self::assertArrayHasKey("{$resource}_00_intro_p_a", $catalog);
                self::assertArrayHasKey("{$resource}_00_intro_p_b", $catalog);
                self::assertArrayNotHasKey("{$resource}_00_intro_p_c", $catalog);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function templateCatalog(): array
    {
        return [
            'example' => [
                'text' => 'Dummy text',
                'src' => 'dummy.svg',
                'alt' => 'Dummy alt',
                'title' => 'Dummy title',
                'href' => '#dummy',
            ],
            'art17_00_headerPrimary' => ['text' => 'Dummy art17'],
            'art17_00_headerSecondary_a' => ['text' => 'Dummy A'],
            'art17_00_headerSecondary_b' => ['text' => 'Dummy B'],
            'art17_00_headerSecondary_c' => ['text' => 'Dummy C'],
            'art17_00_a_cta' => [
                'text' => 'Dummy CTA',
                'href' => '#dummy',
                'title' => 'Dummy CTA title',
            ],
            'art17_00_a_img' => [
                'src' => 'dummy.svg',
                'alt' => 'Dummy image alt',
                'title' => 'Dummy image title',
            ],
            'art17_00_note' => '',
            'art17_00_a_list_a' => ['text' => 'Dummy A1'],
            'art17_00_a_list_b' => ['text' => 'Dummy A2'],
            'art17_00_a_list_c' => ['text' => 'Dummy A3'],
            'art17_00_b_list_a' => ['text' => 'Dummy B1'],
            'art17_00_b_list_b' => ['text' => 'Dummy B2'],
            'art17_00_b_list_c' => ['text' => 'Dummy B3'],
            'art17_00_c_list_a' => ['text' => 'Dummy C1'],
            'simple_00_headerSecondary_a' => ['text' => 'Simple A'],
            'simple_00_headerSecondary_b' => ['text' => 'Simple B'],
            'simple_00_headerSecondary_c' => ['text' => 'Simple C'],
            'simple_00_note' => '',
            'artHeroScroll01_00_itema_suba' => ['text' => 'Hero A1'],
            'artHeroScroll01_00_itema_subb' => ['text' => 'Hero A2'],
            'artHeroScroll01_00_itema_subc' => ['text' => 'Hero A3'],
            'artHeroScroll01_00_itemb_suba' => ['text' => 'Hero B1'],
            'artHeroScroll01_00_itemb_subb' => ['text' => 'Hero B2'],
            'artHeroScroll01_00_itemb_subc' => ['text' => 'Hero B3'],
            'art30_00_benefit_a_img' => [
                'src' => 'a.svg',
                'alt' => 'A',
                'title' => 'A',
            ],
            'art30_00_benefit_b_img' => [
                'src' => 'b.svg',
                'alt' => 'B',
                'title' => 'B',
            ],
            'art30_00_benefit_c_img' => [
                'src' => 'c.svg',
                'alt' => 'C',
                'title' => 'C',
            ],
            'art30_00_benefit_d_img' => [
                'src' => 'd.svg',
                'alt' => 'D',
                'title' => 'D',
            ],
            'art30_00_intro_p_a' => ['text' => 'Intro A'],
            'art30_00_intro_p_b' => ['text' => 'Intro B'],
            'artMarquee01_00_row1_item_text_a' => ['text' => 'Row 1 A'],
            'artMarquee01_00_row1_item_text_b' => ['text' => 'Row 1 B'],
            'artMarquee01_00_row1_item_text_c' => ['text' => 'Row 1 C'],
            'artMarquee01_00_row1_item_text_d' => ['text' => 'Row 1 D'],
            'artMarquee01_00_row1_item_img_a' => ['src' => 'a.svg'],
            'artMarquee01_00_row1_item_img_b' => ['src' => 'b.svg'],
            'artMarquee01_00_row1_item_img_c' => ['src' => 'c.svg'],
            'artMarquee01_00_row1_item_img_d' => ['src' => 'd.svg'],
            'artMarquee01_00_row2_item_text_a' => ['text' => 'Row 2 A'],
            'artMarquee01_00_row2_item_text_b' => ['text' => 'Row 2 B'],
            'artMarquee01_00_row2_item_text_c' => ['text' => 'Row 2 C'],
            'artMarquee01_00_row2_item_text_d' => ['text' => 'Row 2 D'],
            'artMarquee01_00_row2_item_img_a' => ['src' => 'a.svg'],
            'artMarquee01_00_row2_item_img_b' => ['src' => 'b.svg'],
            'artMarquee01_00_row2_item_img_c' => ['src' => 'c.svg'],
            'artMarquee01_00_row2_item_img_d' => ['src' => 'd.svg'],
            'hero01_00_label_a' => ['text' => 'Hero fixed A'],
            'hero01_00_label_b' => ['text' => 'Hero fixed B'],
            'hero01_00_label_c' => ['text' => 'Hero fixed C'],
            'moduleH2Type01_00_h2_text' => ['text' => 'Nested heading'],
            'moduleH2Type01_00_item_a' => ['text' => 'Nested item A'],
            'moduleH2Type01_00_item_b' => ['text' => 'Nested item B'],
            'moduleH2Type01_00_item_c' => ['text' => 'Nested item C'],
            'includedResource_00_text' => ['text' => 'Included copy'],
        ];
    }

    private function configureRouteAwareViewFixture(
        string $showroomContent
    ): void {
        $this->writeFile(
            $this->fixtureRoot . '/App/views/_showroom.php',
            "<?php echo controller('simple', 0, ['items' => 2]);\n"
        );
        $this->writeFile(
            $this->fixtureRoot . '/App/views/_templates.php',
            "<?php require __DIR__ . '/_showroom.php';\n"
        );

        $routes = [];
        foreach (['es', 'en'] as $language) {
            $routes[$language] = [
                "/{$language}/templates" => [
                    'content' => 'templates',
                    'view' => '../App/views/_templates.php',
                ],
                "/{$language}/showroom" => [
                    'content' => $showroomContent,
                    'view' => '../App/views/_showroom.php',
                ],
            ];
        }

        $this->writeFile(
            $this->fixtureRoot . '/App/config/routes/get.php',
            "<?php\nreturn " . var_export($routes, true) . ";\n"
        );
    }

    private function runUpdater(string $slug, array $extraArgs = []): string
    {
        $result = $this->executeUpdater($slug, $extraArgs);

        self::assertSame(
            0,
            $result['exitCode'],
            $result['stderr'] . $result['stdout']
        );

        return $result['stdout'];
    }

    /**
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function executeUpdater(
        string $slug,
        array $extraArgs = []
    ): array
    {
        $command = array_merge(
            [
                PHP_BINARY,
                $this->fixtureRoot . '/App/tools/update-languages.php',
                $slug,
            ],
            $extraArgs
        );
        $environment = getenv();
        self::assertIsArray($environment);
        $environment['DOCUMENT_ROOT'] = $this->fixtureRoot . '/public';

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->fixtureRoot,
            $environment
        );

        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        return json_decode(
            (string) file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $this->writeFile(
            $path,
            json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n"
        );
    }

    private function writeFile(string $path, string $contents): void
    {
        $this->filesystem->mkdir(dirname($path));
        $this->filesystem->dumpFile($path, $contents);
    }

    /**
     * @param array<string, mixed> $catalog
     */
    private function assertLetteredKeys(
        array $catalog,
        string $prefix,
        int $count,
        string $language
    ): void {
        for ($index = 0; $index < $count; $index++) {
            $key = $prefix . chr(ord('a') + $index);
            self::assertArrayHasKey($key, $catalog, "{$language}: falta {$key}");
        }
    }
}
