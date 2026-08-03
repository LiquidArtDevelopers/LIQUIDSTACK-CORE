<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

use function App\Core\Support\controller;

final class SectionBlogGrid01ContractTest extends TestCase
{
    private string $originalCwd = '';
    private string $originalProjectRoot = '';

    /** @var array<string, array{exists: bool, value?: mixed}> */
    private array $globalState = [];

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->originalProjectRoot = Paths::projectRoot();

        Paths::setProjectRoot(self::moduleProjectRoot());
        chdir(self::coreRoot());
        $this->setGlobal('lang', 'es');

        foreach ($this->readCatalog('es') as $key => $value) {
            if (!str_starts_with($key, 'sectionBlogGrid01_')) {
                continue;
            }
            $this->setGlobal($key, is_array($value) ? (object) $value : $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->globalState as $key => $state) {
            if ($state['exists']) {
                $GLOBALS[$key] = $state['value'];
            } else {
                unset($GLOBALS[$key]);
            }
        }

        Paths::setProjectRoot($this->originalProjectRoot);
        if ($this->originalCwd !== '') {
            chdir($this->originalCwd);
        }
    }

    public function testShowroomFixtureKeepsSectionAndArticleSemantics(): void
    {
        $html = controller('sectionBlogGrid01', 0, ['items' => 4]);
        $xpath = $this->createXpath($html);

        self::assertCount(1, $xpath->query('/html/body/section'));
        self::assertCount(0, $xpath->query('//section//section | //header'));
        self::assertCount(1, $xpath->query('/html/body/section/h2'));
        self::assertCount(4, $xpath->query('//section/div/article'));
        self::assertCount(4, $xpath->query('//article/h3'));
        self::assertCount(4, $xpath->query('//article//time[@datetime]'));
        self::assertCount(4, $xpath->query('//article/h3/a[@href]'));
        self::assertStringContainsString('sectionBlogGrid01--items-4', $html);
        self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);
    }

    public function testHeadingLevelsScaleWithoutChangingStructuralElements(): void
    {
        $html = controller('sectionBlogGrid01', 0, [
            'items' => 2,
            'header_level' => 4,
        ]);
        $xpath = $this->createXpath($html);

        self::assertCount(1, $xpath->query('/html/body/section/h4'));
        self::assertCount(2, $xpath->query('//article/h5'));
        self::assertCount(2, $xpath->query('//section/div/article'));
        self::assertCount(0, $xpath->query('//section//section'));
    }

    public function testInjectedHeadingKeepsItsAccessibleRelationship(): void
    {
        $item = [[
            'url' => '/es/noticias/matrix',
            'h1' => 'Matrix',
            'excerpt' => 'Una entrada de prueba.',
            'published_at' => '2026-01-10 12:00:00',
        ]];
        $withExistingId = controller('sectionBlogGrid01', 2, [
            '{header-primary}' => '<h4 id="custom-grid-heading">'
                . 'Encabezado externo</h4>',
            'items_data' => $item,
        ]);
        $existingXpath = $this->createXpath($withExistingId);

        self::assertCount(
            1,
            $existingXpath->query(
                '/html/body/section[@aria-labelledby="custom-grid-heading"]'
            )
        );
        self::assertCount(
            1,
            $existingXpath->query('//h4[@id="custom-grid-heading"]')
        );
        self::assertCount(1, $existingXpath->query('//article/h5'));

        $withoutId = controller('sectionBlogGrid01', 3, [
            '{header-primary}' => '<h3 class="external-heading">'
                . 'Otro encabezado</h3>',
            'items_data' => $item,
        ]);
        $generatedXpath = $this->createXpath($withoutId);
        self::assertCount(
            1,
            $generatedXpath->query(
                '/html/body/section['
                    . '@aria-labelledby="sectionBlogGrid01-03-heading"'
                    . ']'
            )
        );
        self::assertCount(
            1,
            $generatedXpath->query(
                '//h3[@id="sectionBlogGrid01-03-heading"]'
            )
        );
        self::assertCount(1, $generatedXpath->query('//article/h4'));
    }

    public function testProjectedPublicItemsRenderEscapedWithoutLanguageHooks(): void
    {
        $html = controller('sectionBlogGrid01', 3, [
            'items_data' => [
                [
                    'url' => '/es/noticias/neo-y-el-script',
                    'h1' => 'Neo <script>alert(1)</script>',
                    'excerpt' => 'El código deja de ser una barrera & empieza a revelar el sistema.',
                    'published_at' => '2026-07-30T12:45:00+02:00',
                ],
                [
                    'url' => 'https://example.test/en/news/trinity',
                    'h1' => 'Trinity returns',
                    'excerpt' => 'A second projected card.',
                    'published_at' => '2026-07-31',
                ],
            ],
            'items' => 2,
        ]);
        $xpath = $this->createXpath($html);

        self::assertCount(2, $xpath->query('//article'));
        self::assertCount(0, $xpath->query('//article//*[@data-lang]'));
        self::assertCount(0, $xpath->query('//script'));
        self::assertStringContainsString(
            'Neo &lt;script&gt;alert(1)&lt;/script&gt;',
            $html
        );
        self::assertStringContainsString(
            'El código deja de ser una barrera &amp; empieza',
            $html
        );
        self::assertCount(
            1,
            $xpath->query('//time[@datetime="2026-07-30"]')
        );
        self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);
    }

    public function testInjectedEmptyOrInvalidListsNeverFallBackToFixtures(): void
    {
        $empty = controller('sectionBlogGrid01', 0, [
            'items_data' => [],
        ]);
        $emptyXpath = $this->createXpath($empty);

        self::assertCount(0, $emptyXpath->query('//article'));
        self::assertStringContainsString('sectionBlogGrid01--items-0', $empty);
        self::assertStringNotContainsString('La llamada que despertó a Neo', $empty);
        self::assertStringNotContainsString('/es/noticias/', $empty);

        $invalid = controller('sectionBlogGrid01', 0, [
            'items_data' => [
                [
                    'url' => 'javascript:alert(1)',
                    'h1' => 'Unsafe',
                    'excerpt' => 'Unsafe URL.',
                    'published_at' => '2026-01-01',
                ],
                [
                    'url' => '/es/noticias/fecha-invalida',
                    'h1' => 'Invalid date',
                    'excerpt' => 'Invalid date.',
                    'published_at' => 'not-a-date',
                ],
                [
                    'url' => '/\\evil.example',
                    'h1' => 'Backslash unsafe',
                    'excerpt' => 'Unsafe URL.',
                    'published_at' => '2026-01-01',
                ],
            ],
        ]);

        self::assertCount(0, $this->createXpath($invalid)->query('//article'));
        self::assertStringNotContainsString('Unsafe', $invalid);
        self::assertStringNotContainsString('Backslash unsafe', $invalid);
        self::assertStringNotContainsString('La llamada que despertó a Neo', $invalid);
    }

    public function testControllerIsProjectionOnlyAndContainsNoDatabaseAccess(): void
    {
        $controller = (string) file_get_contents(
            self::moduleProjectRoot()
                . '/App/controllers/sectionBlogGrid01.php'
        );

        foreach ([
            'PDO',
            'BlogService',
            'BlogRepository',
            'PdoBlogRepository',
            'SELECT ',
            'prepare(',
            'query(',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $controller);
        }
        self::assertStringContainsString("array_key_exists('items_data'", $controller);
        self::assertStringContainsString("unset(\$params['items_data'])", $controller);
    }

    public function testKeyboardFocusUsesTheAccessibleBaseColor(): void
    {
        $scss = (string) file_get_contents(
            self::moduleProjectRoot()
                . '/src/scss/resources/_sectionBlogGrid01.scss'
        );

        self::assertStringContainsString(
            'outline: 0.18rem solid c.$color02;',
            $scss
        );
        self::assertStringNotContainsString(
            'outline: 0.18rem solid c.$color02bis3;',
            $scss
        );
        self::assertStringNotContainsString(
            'color: c.$color03;',
            $scss
        );
        self::assertGreaterThanOrEqual(
            2,
            substr_count($scss, 'overflow-wrap: anywhere;')
        );
    }

    public function testCatalogsContainFourCompleteMatrixFixtures(): void
    {
        foreach (['es', 'en', 'eu'] as $language) {
            $catalog = $this->readCatalog($language);
            self::assertStringContainsString(
                'sectionBlogGrid01',
                (string) (
                    $catalog['sectionBlogGrid01_00_headerPrimary']['text']
                    ?? ''
                )
            );
            self::assertSame(
                ['text'],
                array_keys(
                    $catalog['showroom_catalog_category_blog_label'] ?? []
                )
            );

            foreach (['a', 'b', 'c', 'd'] as $letter) {
                self::assertEqualsCanonicalizing(
                    ['text', 'href', 'title'],
                    array_keys(
                        $catalog["sectionBlogGrid01_00_{$letter}_link"]
                        ?? []
                    )
                );
                self::assertSame(
                    ['text'],
                    array_keys(
                        $catalog["sectionBlogGrid01_00_{$letter}_excerpt"]
                        ?? []
                    )
                );
                self::assertEqualsCanonicalizing(
                    ['text', 'datetime'],
                    array_keys(
                        $catalog["sectionBlogGrid01_00_{$letter}_publishedAt"]
                        ?? []
                    )
                );
            }
        }
    }

    public function testBlogShowroomRegistrationIsCompleteAndHasNoResourceJs(): void
    {
        $root = self::coreRoot();
        $showroom = (string) file_get_contents(
            self::moduleProjectRoot() . '/App/views/showroom/_blog.php'
        );
        $javascript = (string) file_get_contents($root . '/src/js/templates.js');
        $scss = (string) file_get_contents(
            self::moduleProjectRoot() . '/src/scss/showroom/blog.scss'
        );
        $partial = (string) file_get_contents(
            self::moduleProjectRoot() . '/App/views/showroom/_blog.php'
        );

        self::assertSame(
            1,
            substr_count($showroom, "controller('sectionBlogGrid01', 0")
        );
        self::assertStringContainsString("'items' => 4", $partial);
        self::assertSame(
            1,
            substr_count(
                $scss,
                "@use '../resources/sectionBlogGrid01';"
            )
        );
        self::assertSame(
            1,
            substr_count($javascript, "import.meta.glob('./showroom/*.js')")
        );
        self::assertStringNotContainsString(
            "import('./showroom/blog.js')",
            $javascript
        );
        self::assertFileDoesNotExist(
            $root . '/resources/js/_sectionBlogGrid01.js'
        );
    }

    public function testStylesAreMobileFirstAndUseOnlyCoreColors(): void
    {
        $scss = (string) file_get_contents(
            self::moduleProjectRoot()
                . '/src/scss/resources/_sectionBlogGrid01.scss'
        );

        self::assertStringContainsString("@use '../config' as c;", $scss);
        self::assertStringContainsString('grid-template-columns: repeat(', $scss);
        self::assertStringContainsString('auto-fit', $scss);
        self::assertStringContainsString('@media (min-width: c.$tablet)', $scss);
        self::assertStringContainsString('@media (min-width: c.$desktop)', $scss);
        self::assertDoesNotMatchRegularExpression('/@media\s*\([^)]*max-width/i', $scss);
        self::assertDoesNotMatchRegularExpression('/\bc\.\$color(?:0[4-9]|[1-9][0-9])/', $scss);
        self::assertStringNotContainsString('filterColor', $scss);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b/i', $scss);
    }

    private function createXpath(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<!doctype html><html><body>' . $html . '</body></html>',
            LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($loaded);

        return new DOMXPath($document);
    }

    /** @return array<string, array<string, mixed>> */
    private function readCatalog(string $language): array
    {
        $decoded = json_decode(
            (string) file_get_contents(
                self::coreRoot()
                . "/stubs/App/config/languages/templates/{$language}.json"
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertIsArray($decoded);

        return $decoded;
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

    private static function moduleProjectRoot(): string
    {
        return self::coreRoot() . '/modules/blog/resources/project';
    }
}
