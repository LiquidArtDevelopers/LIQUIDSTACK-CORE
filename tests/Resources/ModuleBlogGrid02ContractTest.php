<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

use function App\Core\Support\controller;

final class ModuleBlogGrid02ContractTest extends TestCase
{
    public function testItIsAlwaysANeutralModuleWithArticleHeadings(): void
    {
        $this->withModuleProject(function (): void {
            $html = controller('moduleBlogGrid02', 2, [
                'header_level' => 2,
                'header_text' => 'Este encabezado no se renderiza',
                '{header-primary}' => '<h2>Encabezado inyectado</h2>',
                'items_data' => [self::item(1)],
                'next_url' => '/es/noticias/pagina/2',
            ]);
            $xpath = self::xpath($html);

            self::assertSame(1, $xpath->query(
                '/html/body/div['
                    . '@id="moduleBlogGrid02-02" and '
                    . 'contains(concat(" ", normalize-space(@class), " "), '
                    . '" moduleBlogGrid02 ") and '
                    . 'contains(concat(" ", normalize-space(@class), " "), '
                    . '" moduleBlogGrid02--items-1 ") and '
                    . '@data-blog-collection="moduleBlogGrid02" and '
                    . '@data-blog-load-mode="manual" and '
                    . '@data-blog-grid02]'
            )->length);
            self::assertSame(0, $xpath->query('//section')->length);
            self::assertSame(0, $xpath->query(
                '/html/body/div/@aria-labelledby'
            )->length);
            self::assertSame(0, $xpath->query(
                '/html/body/div/*[self::h1 or self::h2 or self::h3 or '
                    . 'self::h4 or self::h5 or self::h6]'
            )->length);
            self::assertStringNotContainsString(
                'Este encabezado no se renderiza',
                $html
            );
            self::assertStringNotContainsString(
                'Encabezado inyectado',
                $html
            );
            self::assertSame(1, $xpath->query(
                '/html/body/div/div[@data-blog-collection-items]'
                    . '/article[@data-blog-card-key and @aria-labelledby]'
                    . '/h3[@id=../@aria-labelledby]'
            )->length);
            self::assertSame(1, $xpath->query(
                '/html/body/div//a[@data-blog-collection-next]'
            )->length);
            self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);
        });
    }

    public function testItPreservesZeroOneAndManyServerRenderedStates(): void
    {
        $this->withModuleProject(function (): void {
            foreach ([0, 1, 3] as $count) {
                $items = [];
                for ($position = 1; $position <= $count; $position++) {
                    $items[] = self::item($position);
                }

                $html = controller('moduleBlogGrid02', $count, [
                    'header_level' => 2,
                    'items_data' => $items,
                    'empty_message' => 'Sin resultados',
                ]);
                $xpath = self::xpath($html);

                self::assertSame(1, $xpath->query(
                    '/html/body/div['
                        . '@id="moduleBlogGrid02-0' . $count . '" and '
                        . 'contains(concat(" ", normalize-space(@class), " "), '
                        . '" moduleBlogGrid02--items-' . $count . ' ") and '
                        . '@data-blog-collection="moduleBlogGrid02" and '
                        . '@data-blog-grid02]'
                )->length);
                self::assertSame($count, $xpath->query(
                    '/html/body/div/div[@data-blog-collection-items]/article'
                )->length);
                self::assertSame($count, $xpath->query(
                    '/html/body/div/div[@data-blog-collection-items]'
                        . '/article[@aria-labelledby]/h3['
                        . '@id=../@aria-labelledby]'
                )->length);
                self::assertSame($count, $xpath->query(
                    '/html/body/div/div[@data-blog-collection-items]'
                        . '/article/a[contains(concat(" ", '
                        . 'normalize-space(@class), " "), '
                        . '" moduleBlogGrid02-cta ")]'
                )->length);
                self::assertSame(0, $xpath->query('//section')->length);

                if ($count === 0) {
                    self::assertSame(1, $xpath->query(
                        '/html/body/div//p['
                            . '@data-blog-collection-status and '
                            . '@data-state="empty" and '
                            . 'normalize-space(.)="Sin resultados"]'
                    )->length);
                }
            }
        });
    }

    public function testEveryArticleHasAVisibleSafeCtaAndAReadableTitle(): void
    {
        $this->withModuleProject(function (): void {
            $item = self::item(1);
            $item['h1'] = 'Criterio & estrategia';
            $html = controller('moduleBlogGrid02', 7, [
                'header_level' => 2,
                'items_data' => [$item],
                'cta_label' => 'Leer más',
            ]);
            $xpath = self::xpath($html);

            self::assertSame(1, $xpath->query(
                '/html/body/div/div[@data-blog-collection-items]'
                    . '/article/h3/a['
                    . '@href="/es/noticias/matrix-1" and '
                    . 'normalize-space(.)="Criterio & estrategia"]'
            )->length);
            self::assertSame(1, $xpath->query(
                '/html/body/div/div[@data-blog-collection-items]'
                    . '/article/a['
                    . 'contains(concat(" ", normalize-space(@class), " "), '
                    . '" moduleBlogGrid02-cta ") and '
                    . '@href="/es/noticias/matrix-1" and '
                    . 'not(@target) and not(@role) and not(@tabindex)]'
            )->length);
            self::assertSame(1, $xpath->query(
                '/html/body/div/div[@data-blog-collection-items]'
                    . '/article/a[contains(@class, "moduleBlogGrid02-cta")]'
                    . '/span[1]'
            )->length);
            self::assertSame(1, $xpath->query(
                '/html/body/div/div[@data-blog-collection-items]'
                    . '/article/a[contains(@class, "moduleBlogGrid02-cta")]'
                    . '/span[2][@aria-hidden="true"]'
            )->length);
            self::assertSame(0, $xpath->query(
                '//h3//a[contains(@class, "moduleBlogGrid02-cta")]'
            )->length);
            self::assertStringContainsString(
                'aria-label="Leer más: Criterio &amp; estrategia"',
                $html
            );
            self::assertStringContainsString(
                '<span>Leer más</span><span aria-hidden="true">&rarr;</span>',
                $html
            );

            $scss = (string) file_get_contents(
                self::moduleProjectRoot()
                    . '/src/scss/resources/_moduleBlogGrid02.scss'
            );
            foreach ([
                'font-size: clamp(1.25rem, 2vw, 1.55rem);',
                'font-weight: 700;',
                'display: inline-block;',
                'font-size: inherit;',
                'line-height: inherit;',
                'text-decoration-line: underline;',
                '.moduleBlogGrid02-cta {',
                'margin-top: auto;',
                'border-radius: 999px;',
                'background: c.$color02;',
                "span[aria-hidden='true']",
                '&:focus-visible {',
                '@media (hover: hover) and (pointer: fine) {',
                '&:focus-within {',
            ] as $contract) {
                self::assertStringContainsString($contract, $scss);
            }

            $ctaStart = strpos($scss, '.moduleBlogGrid02-cta {');
            $paginationStart = strpos(
                $scss,
                '.moduleBlogGrid02-pagination {',
                $ctaStart === false ? 0 : $ctaStart
            );
            self::assertNotFalse($ctaStart);
            self::assertNotFalse($paginationStart);
            self::assertStringNotContainsString(
                'box-shadow',
                substr($scss, $ctaStart, $paginationStart - $ctaStart)
            );
        });
    }

    public function testRegularDesktopRowsKeepThreeEqualColumns(): void
    {
        $scss = (string) file_get_contents(
            self::moduleProjectRoot()
                . '/src/scss/resources/_moduleBlogGrid02.scss'
        );

        self::assertMatchesRegularExpression(
            '/@media\s*\(min-width:\s*c\.\$desktop\)\s*\{'
                . '.*?&\.moduleBlogGrid02--regular\s*\{'
                . '.*?\.moduleBlogGrid02-item\s*\{'
                . '.*?grid-column:\s*span\s+4;'
                . '.*?&:last-child:nth-child\(odd\)\s*\{'
                . '\s*grid-column:\s*span\s+4;\s*\}'
                . '.*?&:last-child:nth-child\(3n\s*\+\s*1\)/s',
            $scss
        );
        self::assertStringContainsString(
            'grid-template-columns: repeat(12, minmax(0, 1fr));',
            $scss
        );
    }

    public function testInvisibleHeadingParametersCannotChangeCardLevel(): void
    {
        $this->withModuleProject(function (): void {
            $html = controller('moduleBlogGrid02', 6, [
                '{header-primary}' => '<h4 id="hidden-heading">Oculto</h4>',
                'header_text' => 'Texto oculto',
                'header_lang' => 'hidden.heading',
                'items_data' => [self::item(1)],
            ]);
            $xpath = self::xpath($html);

            self::assertSame(0, $xpath->query(
                '//*[@id="hidden-heading"] | //h4'
            )->length);
            self::assertStringNotContainsString('Oculto', $html);
            self::assertStringNotContainsString('Texto oculto', $html);
            self::assertStringNotContainsString('hidden.heading', $html);
            self::assertSame(1, $xpath->query(
                '/html/body/div/div[@data-blog-collection-items]'
                    . '/article[@aria-labelledby]/h3['
                    . '@id=../@aria-labelledby]'
            )->length);
        });
    }

    public function testItsCanonicalBackpackHasNoSectionAlias(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $projectRoot = self::moduleProjectRoot();
        $manifest = json_decode(
            (string) file_get_contents($coreRoot . '/modules/blog/module.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertContains('moduleBlogGrid02', $manifest['resources']);
        self::assertNotContains('sectionBlogGrid02', $manifest['resources']);
        $files = array_values(array_filter(
            $manifest['project_files'],
            static fn (array $file): bool => ($file['group'] ?? '')
                === 'resource-moduleBlogGrid02'
        ));
        self::assertCount(4, $files);
        self::assertEqualsCanonicalizing([
            'App/controllers/moduleBlogGrid02.php',
            'App/templates/_moduleBlogGrid02.html',
            'src/scss/resources/_moduleBlogGrid02.scss',
            'src/js/resources/_moduleBlogGrid02.js',
        ], array_column($files, 'target'));

        foreach ([
            'App/controllers/sectionBlogGrid02.php',
            'App/templates/_sectionBlogGrid02.html',
            'src/scss/resources/_sectionBlogGrid02.scss',
            'src/js/resources/_sectionBlogGrid02.js',
        ] as $legacyPath) {
            self::assertFileDoesNotExist($projectRoot . '/' . $legacyPath);
        }

        $controller = (string) file_get_contents(
            $projectRoot . '/App/controllers/moduleBlogGrid02.php'
        );
        $template = (string) file_get_contents(
            $projectRoot . '/App/templates/_moduleBlogGrid02.html'
        );
        $scss = (string) file_get_contents(
            $projectRoot . '/src/scss/resources/_moduleBlogGrid02.scss'
        );
        $javascript = (string) file_get_contents(
            $projectRoot . '/src/js/resources/_moduleBlogGrid02.js'
        );
        $helper = (string) file_get_contents(
            $projectRoot . '/App/controllers/_moduleBlogResources.php'
        );

        self::assertStringContainsString(
            'function controller_moduleBlogGrid02(',
            $controller
        );
        self::assertStringNotContainsString('render_mode', $controller);
        self::assertStringStartsWith('<div ', trim($template));
        self::assertStringEndsWith('</div>', trim($template));
        self::assertStringNotContainsString('<section', $template);
        self::assertStringNotContainsString('aria-labelledby', $template);
        self::assertStringNotContainsString('{header-primary}', $template);
        self::assertStringContainsString('.moduleBlogGrid02 {', $scss);
        self::assertStringContainsString(
            'width: min(100%, c.$textMax);',
            $scss
        );
        self::assertStringNotContainsString('90rem', $scss);
        self::assertStringContainsString(
            "const ROOT_SELECTOR = '[data-blog-grid02]'",
            $javascript
        );
        self::assertStringContainsString(
            'export const initModuleBlogGrid02',
            $javascript
        );
        self::assertStringContainsString("'moduleBlogGrid02' =>", $helper);

        foreach ([$controller, $template, $scss, $javascript] as $source) {
            self::assertStringNotContainsString('sectionBlogGrid02', $source);
        }
    }

    /** @return array<string, mixed> */
    private static function item(int $position): array
    {
        return [
            'url' => '/es/noticias/matrix-' . $position,
            'h1' => 'Entrada Matrix ' . $position,
            'excerpt' => 'Contenido verificable de la entrada '
                . $position . '.',
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
