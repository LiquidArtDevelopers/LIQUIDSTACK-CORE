<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;
use function App\Core\Support\controller;

final class BlogCatalogControlVariantsTest extends TestCase
{
    public function testBothResourcesKeepNativeGetAndExistingRuntimeHooks(): void
    {
        $root = self::moduleProjectRoot();

        foreach ([
            'moduleBlogSearch01',
            'moduleBlogCategoryBar01',
        ] as $resource) {
            self::assertFileExists($root . "/App/controllers/{$resource}.php");
            self::assertFileExists($root . "/App/templates/_{$resource}.html");
            self::assertFileExists(
                $root . "/src/scss/resources/_{$resource}.scss"
            );

            $template = (string) file_get_contents(
                $root . "/App/templates/_{$resource}.html"
            );
            self::assertStringContainsString('method="get"', $template);
            self::assertStringContainsString('role="search"', $template);
            self::assertStringContainsString('aria-controls="{target-id}"', $template);
            self::assertStringContainsString('data-blog-filter-form', $template);
            self::assertStringContainsString(
                'data-blog-results-target="{target-selector}"',
                $template
            );
            self::assertStringContainsString('type="submit"', $template);
            self::assertStringContainsString(
                'data-blog-filter-submit',
                $template
            );
            self::assertStringContainsString('aria-live="polite"', $template);
            self::assertStringContainsString('data-state="idle"', $template);
            self::assertStringContainsString(
                'data-error-message="{error-message}"',
                $template
            );
            self::assertStringContainsString(
                'data-blog-filter-reset',
                $template
            );
            self::assertStringNotContainsString('data-inline-', $template);
        }

        $search = (string) file_get_contents(
            $root . '/App/templates/_moduleBlogSearch01.html'
        );
        self::assertStringContainsString('name="q"', $search);
        self::assertStringContainsString('minlength="2"', $search);
        self::assertStringContainsString('maxlength="120"', $search);
        self::assertStringContainsString(
            'data-minlength-message="{minimum-message}"',
            $search
        );
        self::assertStringContainsString('name="order"', $search);
        foreach (['newest', 'oldest', 'updated'] as $order) {
            self::assertStringContainsString("value=\"{$order}\"", $search);
        }

        $categories = (string) file_get_contents(
            $root . '/App/templates/_moduleBlogCategoryBar01.html'
        );
        self::assertStringContainsString('name="category_mode"', $categories);
        self::assertStringContainsString('{mode-disabled}', $categories);

        $runtime = (string) file_get_contents(
            $root . '/src/js/resources/_moduleBlogFilters01.js'
        );
        self::assertStringContainsString(
            "querySafely(form, '[data-blog-filter-submit]')",
            $runtime
        );
        self::assertStringContainsString(
            "control?.name !== 'category[]'",
            $runtime
        );
        self::assertStringContainsString(
            'scheduleRequestFromForm(form, true);',
            $runtime
        );
    }

    public function testCompactSearchPreservesCategoryStateAndClearsOnlyQuery(): void
    {
        $categories = [];
        for ($index = 1; $index <= 12; ++$index) {
            $categories[] = 'categoria-' . $index;
        }

        $html = $this->render('moduleBlogSearch01', 3, [
            'action' => '/es/noticias',
            'target_id' => 'catalog-results-3',
            'query' => '  Matrix   & Zion  ',
            'selected_categories' => $categories,
            'category_mode' => 'all',
            'order' => 'updated',
            'labels' => [
                'error' => 'Try <again> & keep the page.',
            ],
        ]);

        self::assertStringContainsString('id="moduleBlogSearch01-03"', $html);
        self::assertStringContainsString(
            'aria-controls="catalog-results-3"',
            $html
        );
        self::assertStringContainsString(
            'data-blog-results-target="#catalog-results-3"',
            $html
        );
        self::assertStringContainsString(
            'value="Matrix &amp; Zion"',
            $html
        );
        self::assertStringContainsString(
            'data-minlength-message="Escribe al menos 2 caracteres."',
            $html
        );
        self::assertSame(10, substr_count($html, 'name="category[]"'));
        self::assertStringContainsString(
            'name="category_mode" value="all"',
            $html
        );
        self::assertMatchesRegularExpression(
            '/<option value="updated" selected>/',
            $html
        );
        self::assertStringContainsString(
            'href="/es/noticias?category%5B0%5D=categoria-1&amp;',
            $html
        );
        self::assertStringContainsString('order=updated"', $html);
        self::assertMatchesRegularExpression(
            '/<a href="[^"]+" data-blog-filter-reset>/',
            $html
        );
        self::assertStringContainsString(
            'data-error-message="Try &lt;again&gt; &amp; keep the page."',
            $html
        );
        self::assertDoesNotMatchRegularExpression(
            '/href="[^"]*\bq=/',
            $html
        );
        self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);
    }

    public function testCategoryBarPreservesSearchAndOrderAndResetsCategories(): void
    {
        $filters = [];
        for ($index = 1; $index <= 12; ++$index) {
            $filters[] = [
                'slug' => 'categoria-' . $index,
                'name' => 'Categoría ' . $index,
                'count' => $index,
            ];
        }

        $html = $this->render('moduleBlogCategoryBar01', 4, [
            'action' => '/es/noticias',
            'target_id' => 'catalog-results-4',
            'query' => 'Matrix',
            'order' => 'oldest',
            'filters' => $filters,
            'selected_categories' => ['categoria-12'],
            'category_mode' => 'all',
        ]);

        self::assertStringContainsString(
            'id="moduleBlogCategoryBar01-04"',
            $html
        );
        self::assertStringContainsString(
            'aria-controls="catalog-results-4"',
            $html
        );
        self::assertStringContainsString(
            'name="q" value="Matrix"',
            $html
        );
        self::assertStringContainsString(
            'name="order" value="oldest"',
            $html
        );
        self::assertSame(10, substr_count($html, 'name="category[]"'));
        self::assertStringContainsString(
            'value="categoria-12" checked',
            $html
        );
        self::assertStringNotContainsString('value="categoria-10"', $html);
        self::assertMatchesRegularExpression(
            '/<option value="all" selected>/',
            $html
        );
        self::assertStringContainsString(
            'href="/es/noticias?q=Matrix&amp;order=oldest"',
            $html
        );
        self::assertMatchesRegularExpression(
            '/<a href="[^"]+" data-blog-filter-reset>/',
            $html
        );
        self::assertDoesNotMatchRegularExpression(
            '/href="[^"]*category/',
            $html
        );
        self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);
    }

    public function testInstancesHaveScopedIdsAndUnsafeInputFallsBackClosed(): void
    {
        $first = $this->render('moduleBlogSearch01', 0, [
            'action' => '//evil.example',
            'target_id' => 'bad selector',
            'id_prefix' => '1-invalid',
            'order' => 'DROP TABLE',
        ]);
        $second = $this->render('moduleBlogSearch01', 1, []);

        self::assertStringContainsString('id="moduleBlogSearch01-00"', $first);
        self::assertStringContainsString('action="/"', $first);
        self::assertStringContainsString('aria-controls="blog-results"', $first);
        self::assertMatchesRegularExpression(
            '/<option value="newest" selected>/',
            $first
        );
        self::assertStringNotContainsString('evil.example', $first);
        self::assertStringNotContainsString('DROP TABLE', $first);
        self::assertStringContainsString('id="moduleBlogSearch01-01"', $second);
        self::assertStringContainsString(
            'data-blog-filter-reset hidden',
            $second
        );
        self::assertSame(0, substr_count($first . $second, 'id="blog-results"'));

        $empty = $this->render('moduleBlogCategoryBar01', 2, [
            'filters' => [],
        ]);
        self::assertSame(0, substr_count($empty, 'name="category[]"'));
        self::assertMatchesRegularExpression(
            '/<select[^>]+name="category_mode"[^>]+disabled>/',
            $empty
        );
        self::assertStringContainsString(
            'class="moduleBlogCategoryBar01-empty"',
            $empty
        );
        self::assertStringContainsString(
            'data-blog-filter-reset hidden',
            $empty
        );
    }

    public function testNewStylesUseOnlyCoreColorFamiliesAndStayResponsive(): void
    {
        foreach ([
            'moduleBlogSearch01',
            'moduleBlogCategoryBar01',
        ] as $resource) {
            $scss = (string) file_get_contents(
                self::moduleProjectRoot()
                    . "/src/scss/resources/_{$resource}.scss"
            );
            self::assertSame(
                0,
                preg_match('/c\.\$color(?:0[4-9]|[1-9][0-9])/', $scss)
            );
            self::assertStringNotContainsString('filterColor', $scss);
            self::assertStringContainsString(
                '@media (min-width: c.$tablet)',
                $scss
            );
            self::assertStringContainsString('min-height: 2.75rem;', $scss);
        }
    }

    public function testSelectedCategoryCopyKeepsContrastInConsumerThemes(): void
    {
        $scss = (string) file_get_contents(
            self::moduleProjectRoot()
                . '/src/scss/resources/_moduleBlogCategoryBar01.scss'
        );

        self::assertMatchesRegularExpression(
            '/> span,\s*> small\s*\{\s*color:\s*inherit;/s',
            $scss
        );
        self::assertMatchesRegularExpression(
            '/&:has\(input:checked\)\s*\{[^}]*color:\s*c\.\$color00;'
                . '[^}]*background:\s*c\.\$color02;/s',
            $scss
        );
        self::assertStringContainsString(
            '&:hover {',
            $scss
        );
        self::assertStringContainsString(
            'background: c.$color02bis3;',
            $scss
        );
        self::assertStringContainsString(
            'background: c.$color00bis;',
            $scss
        );
        self::assertMatchesRegularExpression(
            '/legend\s*\{[^}]*font-family:\s*c\.\$fuente02;'
                . '[^}]*font-size:\s*1rem;/s',
            $scss
        );
    }

    public function testSearchResetKeepsAMobileSizedHitTarget(): void
    {
        $scss = (string) file_get_contents(
            self::moduleProjectRoot()
                . '/src/scss/resources/_moduleBlogSearch01.scss'
        );

        self::assertMatchesRegularExpression(
            '/\.moduleBlogSearch01-actions\s*\{.*?a\s*\{'
                . '(?=[^}]*min-height:\s*2\.75rem;)'
                . '(?=[^}]*display:\s*inline-flex;)'
                . '(?=[^}]*align-items:\s*center;)/s',
            $scss
        );
    }

    public function testSequentialControlsOwnTheirOuterSpacing(): void
    {
        foreach ([
            'moduleBlogSearch01',
            'moduleBlogCategoryBar01',
        ] as $resource) {
            $scss = (string) file_get_contents(
                self::moduleProjectRoot()
                    . "/src/scss/resources/_{$resource}.scss"
            );

            self::assertMatchesRegularExpression(
                "/\\.{$resource}\\s*\\{"
                    . '(?=[^}]*margin-block:\s*clamp\()'
                    . '(?=[^}]*margin-inline:\s*auto;)/s',
                $scss
            );
            self::assertStringNotContainsString(
                ".moduleBlogSearch01 + .moduleBlogCategoryBar01",
                $scss
            );
            self::assertStringNotContainsString(
                ".moduleBlogCategoryBar01 + .moduleBlogSearch01",
                $scss
            );
        }
    }

    public function testFilterPanelsPreserveMeasuredResponsiveBreathingRoom(): void
    {
        $searchScss = (string) file_get_contents(
            self::moduleProjectRoot()
                . '/src/scss/resources/_moduleBlogSearch01.scss'
        );
        $categoryScss = (string) file_get_contents(
            self::moduleProjectRoot()
                . '/src/scss/resources/_moduleBlogCategoryBar01.scss'
        );

        self::assertStringContainsString(
            'padding: clamp(1.5rem, 4vw, 3rem);',
            $searchScss
        );
        self::assertStringContainsString(
            'row-gap: clamp(1.25rem, 3vw, 2rem);',
            $searchScss
        );
        self::assertStringContainsString('column-gap: 0.85rem;', $searchScss);
        self::assertMatchesRegularExpression(
            '/@media \(min-width: c\.\$tablet\)\s*\{.*?'
                . 'grid-template-columns:\s*minmax\(0, 1fr\) '
                . 'minmax\(11rem, 0\.35fr\);.*?'
                . 'grid-column:\s*1 \/ -1;/s',
            $searchScss
        );
        self::assertMatchesRegularExpression(
            '/@media \(min-width: c\.\$desktop\)\s*\{.*?'
                . 'grid-template-columns:\s*minmax\(15rem, 1fr\) '
                . 'minmax\(11rem, 0\.35fr\) auto;.*?'
                . 'grid-column:\s*auto;/s',
            $searchScss
        );
        self::assertStringContainsString(
            'padding: clamp(1.5rem, 4vw, 3rem);',
            $categoryScss
        );
        self::assertStringContainsString(
            'gap: clamp(1.5rem, 3vw, 1.9rem);',
            $categoryScss
        );
        self::assertStringContainsString(
            'gap: clamp(0.9rem, 2vw, 1.6rem);',
            $categoryScss
        );
        self::assertStringContainsString(
            'gap: clamp(1rem, 2vw, 1.75rem);',
            $categoryScss
        );
    }

    public function testRecoverableFilterErrorsBecomeVisibleWithoutReplacingResults(): void
    {
        foreach (['moduleBlogSearch01', 'moduleBlogCategoryBar01'] as $resource) {
            $scss = (string) file_get_contents(
                self::moduleProjectRoot()
                    . "/src/scss/resources/_{$resource}.scss"
            );

            self::assertMatchesRegularExpression(
                "/\.{$resource}-status\\s*\\{.*?"
                    . "&\\[data-state='error'\\]\\s*\\{"
                    . '(?=[^}]*position:\s*static;)'
                    . '(?=[^}]*width:\s*auto;)'
                    . '(?=[^}]*white-space:\s*normal;)'
                    . '(?=[^}]*border-inline-start:)'
                    . '(?=[^}]*color:\s*c\.\$color01;)'
                    . '(?=[^}]*background:\s*c\.\$color00;)/s',
                $scss
            );
        }
    }

    public function testExistingRuntimeEnhancesBothFormsTogether(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open no está disponible.');
        }

        $root = dirname(__DIR__, 2);
        $fixture = $root
            . '/tests/Resources/fixtures/blog-catalog-controls-harness.mjs';
        $pipes = [];
        $process = @proc_open(
            ['node', $fixture],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root
        );
        if (!is_resource($process)) {
            self::markTestSkipped('Node.js no está disponible.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $stderr ?: $stdout);
        self::assertStringContainsString(
            'Blog catalog control variants: OK',
            $stdout
        );
    }

    public function testSharedTargetCoordinatesRacesHistoryAndHiddenState(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open no estÃ¡ disponible.');
        }

        $root = dirname(__DIR__, 2);
        $fixture = $root
            . '/tests/Resources/fixtures/blog-filter-coordination-harness.mjs';
        $pipes = [];
        $process = @proc_open(
            ['node', $fixture],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root
        );
        if (!is_resource($process)) {
            self::markTestSkipped('Node.js no estÃ¡ disponible.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $stderr ?: $stdout);
        self::assertStringContainsString(
            'Blog filter coordination: OK',
            $stdout
        );
    }

    /** @param array<string, mixed> $params */
    private function render(string $resource, int $index, array $params): string
    {
        $previousRoot = Paths::projectRoot();
        $previousCwd = getcwd();
        Paths::setProjectRoot(self::moduleProjectRoot());
        chdir(dirname(__DIR__, 2));

        try {
            return controller($resource, $index, $params);
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
