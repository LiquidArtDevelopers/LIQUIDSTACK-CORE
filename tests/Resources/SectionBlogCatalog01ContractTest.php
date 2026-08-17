<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

use function App\Core\Support\controller;

final class SectionBlogCatalog01ContractTest extends TestCase
{
    public function testItComposesAHeadingControlsAndResultsAsDirectSiblings(): void
    {
        $this->withModuleProject(function (): void {
            $search = controller('moduleBlogSearch01', 0, [
                'target_id' => 'blog-results',
            ]);
            $categories = controller('moduleBlogCategoryBar01', 0, [
                'target_id' => 'blog-results',
                'filters' => [[
                    'slug' => 'matrix',
                    'name' => 'Matrix',
                    'count' => 1,
                ]],
            ]);
            $collection = controller('moduleBlogGrid02', 0, [
                'header_level' => 2,
                'items_data' => [self::item()],
                'pagination_mode' => 'external',
            ]);
            $results = controller('moduleBlogResults01', 0, [
                '{results-slot}' => $collection,
            ]);

            $html = controller('sectionBlogCatalog01', 0, [
                'header_text' => 'sectionBlogCatalog01 · Catalogo Matrix',
                'header_lang' => 'sectionBlogCatalog01_00_headerPrimary',
                '{search-slot}' => $search,
                '{categories-slot}' => $categories,
                '{results-slot}' => $results,
            ]);
            $xpath = self::xpath($html);

            self::assertSame(1, $xpath->query(
                '/html/body/section['
                    . '@id="sectionBlogCatalog01" and '
                    . '@class="sectionBlogCatalog01" and '
                    . '@aria-labelledby="sectionBlogCatalog01-heading"]'
            )->length);
            self::assertSame(1, $xpath->query(
                '//*[@id="sectionBlogCatalog01"]/h2['
                    . '@id="sectionBlogCatalog01-heading" and '
                    . '@data-lang="sectionBlogCatalog01_00_headerPrimary"]'
            )->length);
            self::assertSame(2, $xpath->query(
                '//*[@id="sectionBlogCatalog01"]/form[@role="search"]'
            )->length);
            self::assertSame(1, $xpath->query(
                '//*[@id="sectionBlogCatalog01"]/div['
                    . '@id="blog-results" and @data-blog-results]'
            )->length);
            self::assertSame(0, $xpath->query(
                '//*[@id="blog-results"]//form'
            )->length);
            self::assertSame(1, $xpath->query(
                '//*[@id="blog-results"]//article/h3'
            )->length);
            self::assertSame(0, $xpath->query(
                '//*[@id="sectionBlogCatalog01"]//*[self::section or self::nav]'
            )->length);
            self::assertSame(4, $xpath->query(
                '//*[@id="sectionBlogCatalog01"]/*'
            )->length);
            self::assertSame(1, $xpath->query(
                '//*[@id="sectionBlogCatalog01"]/*[1][self::h2]'
            )->length);
            self::assertSame(1, $xpath->query(
                '//*[@id="sectionBlogCatalog01"]/*[2]['
                    . 'contains(concat(" ", normalize-space(@class), " "), '
                    . '" moduleBlogSearch01 ")]'
            )->length);
            self::assertSame(1, $xpath->query(
                '//*[@id="sectionBlogCatalog01"]/*[3]['
                    . 'contains(concat(" ", normalize-space(@class), " "), '
                    . '" moduleBlogCategoryBar01 ")]'
            )->length);
            self::assertSame(1, $xpath->query(
                '//*[@id="sectionBlogCatalog01"]/*[4]['
                    . '@id="blog-results"]'
            )->length);
            self::assertStringNotContainsString(
                'sectionBlogCatalog01-search',
                $html
            );
            self::assertStringNotContainsString(
                'sectionBlogCatalog01-results',
                $html
            );
        });
    }

    public function testZeroOneAndManyOptionalControlsAreSelfContained(): void
    {
        $this->withModuleProject(function (): void {
            $results = controller('moduleBlogResults01', 0, [
                '{results-slot}' => '<div class="sectionBlogGrid01">'
                    . '<h3>Resultados</h3></div>',
            ]);

            self::assertSame('', controller('sectionBlogCatalog01', 0, []));
            self::assertSame('', controller('sectionBlogCatalog01', 0, [
                'header_text' => 'Catalogo sin resultados',
            ]));
            self::assertSame('', controller('sectionBlogCatalog01', 0, [
                '{results-slot}' => $results,
            ]));
            self::assertSame('', controller('sectionBlogCatalog01', 0, [
                'header_text' => 'Catalogo invalido',
                '{results-slot}' => ['not', 'html'],
            ]));

            $one = controller('sectionBlogCatalog01', 0, [
                'header_text' => 'Catalogo con resultados',
                '{results-slot}' => $results,
            ]);
            self::assertSame(2, self::xpath($one)->query(
                '//*[@id="sectionBlogCatalog01"]/*'
            )->length);

            $many = controller('sectionBlogCatalog01', 0, [
                'header_text' => 'Catalogo con controles',
                '{search-slot}' => '<form class="moduleBlogSearch01"></form>',
                '{categories-slot}' => '<form class="moduleBlogCategoryBar01">'
                    . '</form>',
                '{results-slot}' => $results,
            ]);
            self::assertSame(4, self::xpath($many)->query(
                '//*[@id="sectionBlogCatalog01"]/*'
            )->length);
        });
    }

    public function testHeadingRangeIsClosedAndCopyIsEscaped(): void
    {
        $this->withModuleProject(function (): void {
            $results = '<div id="blog-results" class="moduleBlogResults01" '
                . 'data-blog-results><div></div></div>';

            foreach ([2, 3, 4, 5] as $level) {
                $html = controller('sectionBlogCatalog01', 0, [
                    'header_level' => $level,
                    'header_text' => 'Catalogo nivel ' . $level,
                    '{results-slot}' => $results,
                ]);
                self::assertStringContainsString(
                    '<h' . $level . ' id="sectionBlogCatalog01-heading">',
                    $html
                );
            }

            foreach ([1, 6, 'invalid'] as $invalidLevel) {
                $html = controller('sectionBlogCatalog01', 0, [
                    'header_level' => $invalidLevel,
                    'header_text' => 'Catalogo por defecto',
                    '{results-slot}' => $results,
                ]);
                self::assertStringContainsString(
                    '<h2 id="sectionBlogCatalog01-heading">',
                    $html
                );
            }

            $escaped = controller('sectionBlogCatalog01', 0, [
                'header_text' => 'Catalogo <script>alert(1)</script> & Matrix',
                'header_lang' => 'bad language value',
                '{results-slot}' => $results,
            ]);
            self::assertStringContainsString(
                'Catalogo &lt;script&gt;alert(1)&lt;/script&gt; &amp; Matrix',
                $escaped
            );
            self::assertStringNotContainsString('<script>', $escaped);
            self::assertStringNotContainsString('data-lang=', $escaped);
        });
    }

    public function testItDoesNotPublishFalseIdClassOrMultiInstanceOptions(): void
    {
        $this->withModuleProject(function (): void {
            $html = controller('sectionBlogCatalog01', 0, [
                'header_text' => 'Catalogo estable',
                'id_prefix' => 'attacker-id',
                'class' => 'attacker-class',
                '{heading-id}' => 'attacker-heading',
                '{results-slot}' => '<div id="blog-results" '
                    . 'class="moduleBlogResults01" data-blog-results></div>',
                '{unknown-slot}' => '<p>attacker-slot</p>',
            ]);

            self::assertStringContainsString(
                'id="sectionBlogCatalog01" class="sectionBlogCatalog01"',
                $html
            );
            self::assertStringNotContainsString('attacker', $html);
            self::assertSame('', controller('sectionBlogCatalog01', 1, [
                'header_text' => 'Segunda instancia',
                '{results-slot}' => '<div></div>',
            ]));
            self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);
        });
    }

    public function testItRejectsNestedSectionAndNavigationLandmarks(): void
    {
        $this->withModuleProject(function (): void {
            foreach (['section', 'nav', 'SECTION', 'NAV'] as $tag) {
                $results = controller('moduleBlogResults01', 0, [
                    '{results-slot}' => '<' . $tag
                        . ' class="legacy-resource"></' . $tag . '>',
                ]);

                self::assertSame('', controller('sectionBlogCatalog01', 0, [
                    'header_text' => 'Catalogo sin landmarks anidados',
                    '{results-slot}' => $results,
                ]), $tag);
            }

            $neutralResults = controller('moduleBlogResults01', 0, [
                '{results-slot}' => '<div class="moduleBlogGrid02"></div>',
            ]);
            self::assertNotSame('', controller('sectionBlogCatalog01', 0, [
                'header_text' => 'Catalogo con modulos compuestos',
                '{results-slot}' => $neutralResults,
            ]));
            self::assertSame('', controller('sectionBlogCatalog01', 0, [
                'header_text' => 'Catalogo con region falsa',
                '{results-slot}' => '<div id="blog-results" '
                    . 'class="moduleBlogResults01" data-blog-results '
                    . 'role="region"></div>',
            ]));
            self::assertSame('', controller('sectionBlogCatalog01', 0, [
                'header_text' => 'Catalogo con contenedor informal',
                '{results-slot}' => '<div></div>',
            ]));
        });
    }

    public function testManifestShowroomAndStylesCarryTheCompleteResource(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $projectRoot = self::moduleProjectRoot();
        $manifest = json_decode(
            (string) file_get_contents($coreRoot . '/modules/blog/module.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertContains('sectionBlogCatalog01', $manifest['resources']);
        $files = array_values(array_filter(
            $manifest['project_files'],
            static fn (array $file): bool => ($file['group'] ?? '')
                === 'resource-sectionBlogCatalog01'
        ));
        self::assertCount(3, $files);
        self::assertEqualsCanonicalizing([
            'App/controllers/sectionBlogCatalog01.php',
            'App/templates/_sectionBlogCatalog01.html',
            'src/scss/resources/_sectionBlogCatalog01.scss',
        ], array_column($files, 'target'));
        self::assertFileDoesNotExist(
            $projectRoot . '/src/js/resources/_sectionBlogCatalog01.js'
        );

        $showroom = (string) file_get_contents(
            $projectRoot . '/App/views/showroom/_blog.php'
        );
        self::assertSame(
            1,
            substr_count($showroom, "controller('sectionBlogCatalog01', 0")
        );
        foreach ([
            '$blogShowroomSearch = controller(',
            '$blogShowroomCategories = controller(',
            '$blogShowroomResultsRegion = controller(',
            "'{search-slot}' => \$blogShowroomSearch",
            "'{categories-slot}' => \$blogShowroomCategories",
            "'{results-slot}' => \$blogShowroomResultsRegion",
            "'header_text' => \$blogHeadings['catalog']",
            '// - header_level admite 2-5;',
        ] as $contract) {
            self::assertStringContainsString($contract, $showroom);
        }
        self::assertStringNotContainsString(
            "echo controller('moduleBlogSearch01'",
            $showroom
        );
        self::assertStringNotContainsString(
            "echo controller('moduleBlogCategoryBar01'",
            $showroom
        );
        self::assertStringNotContainsString(
            "echo controller('moduleBlogResults01'",
            $showroom
        );
        self::assertMatchesRegularExpression(
            '/moduleBlogGrid02.*?header_level\'\s*=>\s*2/s',
            $showroom
        );
        self::assertStringContainsString(
            "controller('moduleBlogPagination01', 0",
            $showroom
        );
        self::assertMatchesRegularExpression(
            '/moduleBlogArchive01.*?header_level\'\s*=>\s*3/s',
            $showroom
        );
        self::assertStringNotContainsString('render_mode', $showroom);

        $showroomScss = (string) file_get_contents(
            $projectRoot . '/src/scss/showroom/blog.scss'
        );
        self::assertStringContainsString(
            "@use '../resources/sectionBlogCatalog01';",
            $showroomScss
        );

        $scss = (string) file_get_contents(
            $projectRoot . '/src/scss/resources/_sectionBlogCatalog01.scss'
        );
        foreach ([
            '.sectionBlogCatalog01 {',
            'display: grid;',
            '> h2,',
            '> h5 {',
            '> * {',
            '@media (min-width: c.$tablet)',
        ] as $contract) {
            self::assertStringContainsString($contract, $scss);
        }
        self::assertDoesNotMatchRegularExpression(
            '/(?:body|main|\.blog-index)\s+\.sectionBlogCatalog01/',
            $scss
        );
        self::assertSame(
            0,
            preg_match('/c\.\$color(?:0[4-9]|[1-9][0-9])/', $scss)
        );
        self::assertStringNotContainsString('filterColor', $scss);
    }

    /** @return array<string, mixed> */
    private static function item(): array
    {
        return [
            'url' => '/es/noticias/matrix',
            'h1' => 'Entrada Matrix',
            'excerpt' => 'Contenido para probar el catalogo semantico.',
            'published_at' => '2026-08-16T09:00:00+00:00',
        ];
    }

    private static function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrors = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<!doctype html><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_COMPACT
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        return new DOMXPath($document);
    }

    private function withModuleProject(callable $assertions): void
    {
        $previousRoot = Paths::projectRoot();
        $previousCwd = getcwd();
        Paths::setProjectRoot(self::moduleProjectRoot());
        chdir(dirname(__DIR__, 2));

        try {
            $assertions();
        } finally {
            Paths::setProjectRoot($previousRoot);
            if (is_string($previousCwd)) {
                chdir($previousCwd);
            }
        }
    }

    private static function moduleProjectRoot(): string
    {
        return dirname(__DIR__, 2)
            . '/modules/blog/resources/project';
    }
}
