<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

use function App\Core\Support\controller;

final class SectionBlogCatalog02ContractTest extends TestCase
{
    public function testItComposesFiltersAndResultsInAResponsiveSplitLayout(): void
    {
        $this->withModuleProject(function (): void {
            $results = controller('moduleBlogResults01', 0, [
                '{results-slot}' => '<div class="moduleBlogGrid02"></div>',
            ]);
            $html = controller('sectionBlogCatalog02', 0, [
                'header_text' => 'sectionBlogCatalog02 · Catalogo Matrix',
                '{search-slot}' => '<form class="moduleBlogSearch01"></form>',
                '{categories-slot}' => '<form class="moduleBlogCategoryBar01"></form>',
                '{results-slot}' => $results,
            ]);
            $xpath = self::xpath($html);

            self::assertSame(1, $xpath->query(
                '/html/body/section[@id="sectionBlogCatalog02"]'
            )->length);
            self::assertSame(2, $xpath->query(
                '//*[@id="sectionBlogCatalog02"]'
                    . '//*[contains(@class, "sectionBlogCatalog02-filters")]'
                    . '/form'
            )->length);
            self::assertSame(1, $xpath->query(
                '//*[@id="sectionBlogCatalog02"]'
                    . '//*[contains(@class, "sectionBlogCatalog02-results")]'
                    . '/div[@id="blog-results" and @data-blog-results]'
            )->length);
            self::assertSame(0, $xpath->query(
                '//*[@id="sectionBlogCatalog02"]//*[self::section or self::nav]'
            )->length);
        });
    }

    public function testItFailsClosedForInvalidInstancesAndResultSlots(): void
    {
        $this->withModuleProject(function (): void {
            self::assertSame('', controller('sectionBlogCatalog02', 1, [
                'header_text' => 'Catalogo duplicado',
                '{results-slot}' => '<div id="blog-results"></div>',
            ]));
            self::assertSame('', controller('sectionBlogCatalog02', 0, [
                'header_text' => 'Catalogo invalido',
                '{results-slot}' => '<div></div>',
            ]));
            self::assertSame('', controller('sectionBlogCatalog02', 0, [
                'header_text' => 'Catalogo con landmark anidado',
                '{results-slot}' => '<div id="blog-results" '
                    . 'class="moduleBlogResults01" data-blog-results>'
                    . '<nav></nav></div>',
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
        self::assertContains('sectionBlogCatalog02', $manifest['resources']);
        $files = array_values(array_filter(
            $manifest['project_files'],
            static fn (array $file): bool => ($file['group'] ?? '')
                === 'resource-sectionBlogCatalog02'
        ));
        self::assertEqualsCanonicalizing([
            'App/controllers/sectionBlogCatalog02.php',
            'App/templates/_sectionBlogCatalog02.html',
            'src/scss/resources/_sectionBlogCatalog02.scss',
        ], array_column($files, 'target'));

        $showroom = (string) file_get_contents(
            $projectRoot . '/App/views/showroom/_blog.php'
        );
        self::assertSame(
            1,
            substr_count($showroom, "controller('sectionBlogCatalog02', 0")
        );

        $scss = (string) file_get_contents(
            $projectRoot . '/src/scss/resources/_sectionBlogCatalog02.scss'
        );
        self::assertStringContainsString(
            'grid-template-columns: minmax(0, 3fr) minmax(0, 7fr);',
            $scss
        );
        self::assertStringContainsString('width: 80%;', $scss);
        self::assertStringContainsString('font-family: c.$fuente02;', $scss);
        self::assertSame(
            0,
            preg_match('/c\.\$color(?:0[4-9]|[1-9][0-9])/', $scss)
        );
        self::assertStringNotContainsString('filterColor', $scss);
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
