<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BlogShowroomIntegrationTest extends TestCase
{
    public function testResponsiveDummySourcesShipWithTheCanonicalShowroom(): void
    {
        $expected = [
            'dummy01' => [480 => 300, 900 => 563, 1800 => 1125, 2560 => 1600],
            'dummy02' => [480 => 323, 899 => 605, 1800 => 1211, 2560 => 1722],
            'dummy03' => [480 => 318, 900 => 596, 1800 => 1193, 2560 => 1696],
            'dummy04' => [480 => 270, 900 => 506, 1800 => 1013, 2560 => 1440],
        ];
        $root = dirname(self::projectRoot(), 4)
            . '/resources/img/dummy/responsive';

        foreach ($expected as $dummy => $variants) {
            foreach ($variants as $width => $height) {
                $path = $root . '/' . $dummy . '-' . $width . '.avif';
                self::assertFileExists($path);
                $metadata = getimagesize($path);
                self::assertIsArray($metadata, $path);
                self::assertSame($width, $metadata[0] ?? null, $path);
                self::assertSame($height, $metadata[1] ?? null, $path);
                self::assertSame('image/avif', $metadata['mime'] ?? null);
            }
        }
    }

    public function testShowroomShowsEachResourceOnceAndDocumentsConfigurations(): void
    {
        $view = (string) file_get_contents(
            self::projectRoot() . '/App/views/showroom/_blog.php'
        );

        self::assertSame(
            1,
            substr_count($view, "controller('moduleBlogGrid02'")
        );
        self::assertSame(
            1,
            substr_count($view, "controller('sectionBlogSlider02'")
        );
        self::assertSame(
            1,
            substr_count($view, "controller('sectionBlogStack01'")
        );
        self::assertSame(
            1,
            substr_count($view, "controller('moduleBlogResults01'")
        );
        self::assertSame(
            1,
            substr_count($view, "controller('sectionBlogCatalog02'")
        );

        foreach ([
            "controller('sectionBlogSlider02', 0",
            "controller('moduleBlogSearch01', 0",
            "controller('moduleBlogCategoryBar01', 0",
            "controller('moduleBlogPagination01', 0",
            "controller('moduleBlogResults01', 0",
            "controller('sectionBlogCatalog02', 0",
            "'{results-slot}' => \$blogShowroomResults",
            "'{pagination-slot}' => \$blogShowroomPagination",
            "'{archive-slot}' => \$blogShowroomArchiveModule",
            "'id_prefix' => 'showroom-blog-paginated'",
            "'blog_demo_page'",
            "'items' => 10",
            "'items' => 8",
            "'items' => 4",
            "'layout' => 'regular'",
            "'pagination_mode' => 'external'",
            '// - layout: regular | bento; items: 0-50;',
            '// - items 0-50; los estados 0/1/N se cubren por test',
            '// - autoplay true|false; autoplay_delay 2-60 s.',
            '// - El carril siempre es infinito',
            "\$item['categories'] = array_map(",
            "\$item['media'] = array_merge(",
            "\$item['thumbnail'] = array_merge(",
            "\$itemMedia = \$blogShowroomMedia[",
            "\$blogCategoryGroups[\$position % count(\$blogCategoryGroups)]",
            "'items_data' => \$blogShowroomCategorizedItems",
        ] as $contract) {
            self::assertStringContainsString($contract, $view);
        }
        self::assertSame(1, substr_count($view, "'items' => 10"));
        self::assertSame(1, substr_count($view, "'items' => 8"));
        self::assertSame(10, substr_count($view, "'/es/noticias/"));
        self::assertSame(10, substr_count($view, "'/en/news/"));
        self::assertSame(10, substr_count($view, "'/eu/albisteak/"));
        self::assertSame(1, substr_count($view, "controller('artBlogArticle01'"));
        self::assertStringNotContainsString("['cover']", $view);
        self::assertStringNotContainsString(
            '<div id="blog-results"',
            $view,
            'La región reactiva debe pertenecer al controlador compositor.'
        );
        self::assertSame(1, substr_count($view, "dummy01.avif"));
        self::assertSame(1, substr_count($view, "dummy02.avif"));
        self::assertSame(1, substr_count($view, "dummy03.avif"));
        self::assertSame(1, substr_count($view, "dummy04.avif"));
        foreach ([
            'dummy01' => [480, 900, 1800, 2560],
            // El procesador canónico conserva el aspect ratio en 899 px.
            'dummy02' => [480, 899, 1800, 2560],
            'dummy03' => [480, 900, 1800, 2560],
            'dummy04' => [480, 900, 1800, 2560],
        ] as $dummy => $widths) {
            foreach ($widths as $width) {
                self::assertStringContainsString(
                    "/assets/img/dummy/responsive/{$dummy}-{$width}.avif",
                    $view
                );
            }
        }
        self::assertSame(4, substr_count($view, "'thumbnail' => ["));
        self::assertSame(4, substr_count($view, "'srcset' => "));

        foreach ([
            'sectionBlogCatalog02',
            'moduleBlogGrid02',
            'sectionBlogSlider02',
            'sectionBlogStack01',
        ] as $resource) {
            self::assertGreaterThanOrEqual(
                3,
                substr_count($view, $resource),
                "El showroom debe identificar {$resource} en todos los idiomas."
            );
        }
    }

    public function testShowroomFiltersStayOnTheLocalSsrRouteAndPreserveState(): void
    {
        $view = (string) file_get_contents(
            self::projectRoot() . '/App/views/showroom/_blog.php'
        );

        foreach ([
            "'action' => \$blogShowroomBasePath",
            "\$_GET['q'] ?? ''",
            "\$_GET['order'] ?? 'newest'",
            "\$_GET['category'] ?? []",
            "\$_GET['category_mode'] ?? 'any'",
            '$blogShowroomFilteredItems = array_values(array_filter(',
            'usort(',
            "'query' => \$blogShowroomQuery",
            "'order' => \$blogShowroomOrder",
            "'selected_categories' => \$blogShowroomSelectedCategories",
            "'category_mode' => \$blogShowroomCategoryMode",
            "http_build_query(\$query, '', '&', PHP_QUERY_RFC3986)",
            "'items' => count(\$blogShowroomPaginatedItems)",
        ] as $contract) {
            self::assertStringContainsString($contract, $view);
        }

        self::assertSame(
            2,
            substr_count($view, "'action' => \$blogShowroomBasePath")
        );
        self::assertStringNotContainsString(
            '$blogShowroomCatalogPath',
            $view
        );
        self::assertStringNotContainsString("['slug' => 'guides'", $view);
    }

    public function testShowroomBundleInitializesAndCleansEveryRuntime(): void
    {
        $javascript = (string) file_get_contents(
            self::projectRoot() . '/src/js/showroom/blog.js'
        );

        foreach ([
            '../modules/blog/blogCollectionLoader.js' => [
                'initBlogCollectionLoader(document)',
                'cleanupBlogCollections()',
            ],
            '_moduleBlogGrid02.js' => [
                'initModuleBlogGrid02(document)',
                'cleanupBlogGrid02()',
            ],
            '_sectionBlogSlider02.js' => [
                'initSectionBlogSlider02(document)',
                'cleanupBlogSlider02()',
            ],
            '_sectionBlogStack01.js' => [
                'initSectionBlogStack01(document)',
                'cleanupBlogStack01()',
            ],
        ] as $runtime => $contracts) {
            self::assertStringContainsString($runtime, $javascript);
            foreach ($contracts as $contract) {
                self::assertStringContainsString($contract, $javascript);
            }
        }

        $scss = (string) file_get_contents(
            self::projectRoot() . '/src/scss/showroom/blog.scss'
        );
        foreach ([
            "@use '../resources/moduleBlogGrid02';",
            "@use '../resources/sectionBlogSlider02';",
            "@use '../resources/sectionBlogStack01';",
            "@use '../resources/moduleBlogCategoryBar01';",
            "@use '../resources/moduleBlogPagination01';",
            "@use '../resources/moduleBlogResults01';",
            "@use '../resources/moduleBlogSearch01';",
            "@use '../resources/sectionBlogCatalog01';",
            "@use '../resources/sectionBlogCatalog02';",
        ] as $import) {
            self::assertStringContainsString($import, $scss);
        }
    }

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2)
            . '/modules/blog/resources/project';
    }
}
