<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

use function App\Core\Support\controller;

final class ModuleBlogResults01ContractTest extends TestCase
{
    public function testItComposesDirectOptionalControllerSlots(): void
    {
        $this->withModuleProject(function (): void {
            $results = controller('moduleBlogGrid02', 0, [
                'header_level' => 2,
                'items_data' => [self::item()],
                'pagination_mode' => 'external',
            ]);
            $pagination = controller('moduleBlogPagination01', 0, [
                'pages_data' => [
                    ['page' => 1, 'current' => true, 'url' => ''],
                    ['page' => 2, 'current' => false, 'url' => '/page/2'],
                ],
                'next_url' => '/page/2',
            ]);
            $archive = controller('moduleBlogArchive01', 0, [
                'header_level' => 3,
                'periods_data' => [[
                    'url' => '/archive/2026',
                    'label' => '2026',
                    'count' => 1,
                ]],
            ]);

            $html = controller('moduleBlogResults01', 0, [
                '{results-slot}' => $results,
                '{pagination-slot}' => $pagination,
                '{archive-slot}' => $archive,
            ]);
            $xpath = self::xpath($html);

            self::assertSame(1, $xpath->query(
                '//*[@id="blog-results" and @data-blog-results]'
            )->length);
            self::assertSame(1, $xpath->query(
                '//*[@id="blog-results"]/div[contains('
                    . 'concat(" ", normalize-space(@class), " "), '
                    . '" moduleBlogGrid02 ")]'
            )->length);
            self::assertSame(1, $xpath->query(
                '//*[@id="blog-results"]/div[contains('
                    . 'concat(" ", normalize-space(@class), " "), '
                    . '" moduleBlogPagination01 ")]'
            )->length);
            self::assertSame(1, $xpath->query(
                '//*[@id="blog-results"]/div[contains('
                    . 'concat(" ", normalize-space(@class), " "), '
                    . '" moduleBlogArchive01 ")]'
            )->length);
            self::assertSame(3, $xpath->query(
                '//*[@id="blog-results"]/*'
            )->length);
            self::assertSame(0, $xpath->query(
                '//*[@id="blog-results"]//*[self::section or self::nav]'
            )->length);
            self::assertStringContainsString($results, $html);
            self::assertStringContainsString($pagination, $html);
            self::assertStringContainsString($archive, $html);
            self::assertStringNotContainsString(
                'moduleBlogResults01-results',
                $html
            );
        });
    }

    public function testMainSlotPreservesANeutralCollectionWithoutRewriting(): void
    {
        $this->withModuleProject(function (): void {
            $child = controller('moduleBlogGrid02', 0, [
                'header_level' => 2,
                'items_data' => [self::item()],
                'items' => 1,
            ]);
            $html = controller('moduleBlogResults01', 0, [
                '{results-slot}' => $child,
            ]);

            self::assertStringContainsString($child, $html);
            self::assertSame(1, self::xpath($html)->query(
                '//*[@id="blog-results"]/*[1][self::div and '
                    . 'contains(concat(" ", normalize-space(@class), " "), '
                    . '" moduleBlogGrid02 ")]'
            )->length);
            self::assertSame(0, self::xpath($html)->query(
                '//*[@id="blog-results"]//*[self::section or self::nav]'
            )->length);
        });
    }

    public function testEmptyAndNonStringSlotsFailClosedWithoutEmptyWrappers(): void
    {
        $this->withModuleProject(function (): void {
            $empty = controller('moduleBlogResults01', 0, []);
            $invalid = controller('moduleBlogResults01', 0, [
                '{results-slot}' => ['not', 'html'],
                '{pagination-slot}' => new stdClass(),
                '{archive-slot}' => "\xC3\x28",
                '{unknown-slot}' => '<script>unknown()</script>',
                '{results-id}' => 'attacker-results',
                'class' => 'attacker-class',
            ]);

            self::assertSame('', $empty);
            self::assertSame('', $invalid);
        });
    }

    public function testCanonicalReactiveRegionIsAnExplicitSingleton(): void
    {
        $this->withModuleProject(function (): void {
            $first = controller('moduleBlogResults01', 0, [
                '{results-slot}' => '<div class="moduleBlogGrid02"></div>',
            ]);

            self::assertStringContainsString('id="blog-results"', $first);
            self::assertSame('', controller('moduleBlogResults01', 1, []));
            self::assertSame('', controller('moduleBlogResults01', -1, []));
            self::assertSame(1, substr_count($first, 'id="blog-results"'));
            self::assertSame('', controller('moduleBlogResults01', 0, [
                '{results-slot}' => '<section></section>',
            ]));
            self::assertSame('', controller('moduleBlogResults01', 0, [
                '{archive-slot}' => '<nav></nav>',
            ]));
            self::assertSame('', controller('moduleBlogResults01', 0, [
                '{results-slot}' => '<article></article>',
            ]));
            self::assertSame('', controller('moduleBlogResults01', 0, [
                '{pagination-slot}' => '<div role="group"></div>',
            ]));
            self::assertSame('', controller('moduleBlogResults01', 0, [
                '{archive-slot}' => '<div role="navigation"></div>',
            ]));
        });
    }

    public function testManifestAndShowroomCarryTheWholeResourceWithoutEmptyJs(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $projectRoot = self::moduleProjectRoot();
        $manifest = json_decode(
            (string) file_get_contents($coreRoot . '/modules/blog/module.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertContains('moduleBlogResults01', $manifest['resources']);
        $files = array_values(array_filter(
            $manifest['project_files'],
            static fn (array $file): bool => ($file['group'] ?? '')
                === 'resource-moduleBlogResults01'
        ));
        self::assertCount(3, $files);
        self::assertEqualsCanonicalizing([
            'App/controllers/moduleBlogResults01.php',
            'App/templates/_moduleBlogResults01.html',
            'src/scss/resources/_moduleBlogResults01.scss',
        ], array_column($files, 'target'));
        self::assertFileDoesNotExist(
            $projectRoot . '/src/js/resources/_moduleBlogResults01.js'
        );

        $showroom = (string) file_get_contents(
            $projectRoot . '/App/views/showroom/_blog.php'
        );
        self::assertSame(
            1,
            substr_count($showroom, "controller('moduleBlogResults01', 0")
        );
        self::assertStringNotContainsString(
            '<div id="blog-results"',
            $showroom
        );
        foreach ([
            '$blogShowroomResults',
            '$blogShowroomPagination',
            '$blogShowroomArchiveModule',
            "'{results-slot}'",
            "'{pagination-slot}'",
            "'{archive-slot}'",
            'sectionBlogList01',
            'sectionBlogSlider02',
        ] as $contract) {
            self::assertStringContainsString($contract, $showroom);
        }

        $showroomScss = (string) file_get_contents(
            $projectRoot . '/src/scss/showroom/blog.scss'
        );
        self::assertStringContainsString(
            "@use '../resources/moduleBlogResults01';",
            $showroomScss
        );
    }

    public function testStylesBelongToTheCompositorAndItsDirectChildren(): void
    {
        $scss = (string) file_get_contents(
            self::moduleProjectRoot()
                . '/src/scss/resources/_moduleBlogResults01.scss'
        );

        foreach ([
            '.moduleBlogResults01 {',
            'width: 100%;',
            'margin-block: clamp(',
            'display: grid;',
            '> * {',
            "&[aria-busy='true']",
            "&[data-blog-results-stale='true']",
            '@media (min-width: c.$tablet)',
        ] as $contract) {
            self::assertStringContainsString($contract, $scss);
        }
        self::assertDoesNotMatchRegularExpression(
            '/(?:body|main|\.blog-index)\s+\.moduleBlogResults01/',
            $scss
        );
        self::assertSame(
            0,
            preg_match('/c\.\$color(?:0[4-9]|[1-9][0-9])/', $scss)
        );
        self::assertStringNotContainsString('filterColor', $scss);
        self::assertMatchesRegularExpression(
            "/&\\[aria-busy='true'\\]\\s*\\{\\s*cursor: progress;/",
            $scss
        );
        self::assertDoesNotMatchRegularExpression(
            "/&\\[data-blog-results-stale='true'\\]\\s*\\{[^}]*"
                . "cursor: progress;/s",
            $scss
        );
    }

    /** @return array<string, mixed> */
    private static function item(int $position = 1): array
    {
        return [
            'url' => '/es/noticias/matrix-' . $position,
            'h1' => 'Entrada Matrix ' . $position,
            'excerpt' => 'Una entrada valida para probar el compositor.',
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
