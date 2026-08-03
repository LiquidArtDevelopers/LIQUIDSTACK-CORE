<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;
use function App\Core\Support\controller;

final class BlogResourceFamilyContractTest extends TestCase
{
    private const ARTICLE_RESOURCE = 'artBlogArticle01';

    /** @var list<string> */
    private const SECTION_RESOURCES = [
        'sectionBlogGrid01',
        'sectionBlogList01',
        'sectionBlogFeatured01',
        'sectionBlogSlider01',
        'sectionBlogRelated01',
    ];

    public function testTheBlogResourceFamilyHasACompleteProjectContract(): void
    {
        $root = self::moduleProjectRoot();

        foreach ([self::ARTICLE_RESOURCE, ...self::SECTION_RESOURCES] as $resource) {
            self::assertFileExists(
                $root . "/App/controllers/{$resource}.php",
                "Falta el controlador de {$resource}."
            );
            self::assertFileExists(
                $root . "/App/templates/_{$resource}.html",
                "Falta el template de {$resource}."
            );
            self::assertFileExists(
                $root . "/src/scss/resources/_{$resource}.scss",
                "Falta el SCSS de {$resource}."
            );
        }

        foreach (['moduleBlogArchive01', 'moduleBlogFilters01'] as $resource) {
            self::assertFileExists($root . "/App/controllers/{$resource}.php");
            self::assertFileExists($root . "/App/templates/_{$resource}.html");
            self::assertFileExists(
                $root . "/src/scss/resources/_{$resource}.scss"
            );
        }

        self::assertFileExists(
            $root . '/App/controllers/_moduleBlogResources.php'
        );
        self::assertFileExists(
            $root . '/src/js/resources/_moduleBlogFilters01.js'
        );
        self::assertFileExists(
            $root . '/src/js/resources/_sectionBlogSlider01.js'
        );
    }

    public function testSectionTemplatesKeepSectionArticleSemantics(): void
    {
        $root = self::moduleProjectRoot();

        foreach (self::SECTION_RESOURCES as $resource) {
            $template = trim((string) file_get_contents(
                $root . "/App/templates/_{$resource}.html"
            ));
            self::assertStringStartsWith('<section', $template, $resource);
            self::assertStringEndsWith('</section>', $template, $resource);
            self::assertStringContainsString(
                'aria-labelledby="{heading-id}"',
                $template,
                $resource
            );
            self::assertStringContainsString(
                '{header-primary}',
                $template,
                $resource
            );
            if ($resource === 'sectionBlogFeatured01') {
                self::assertStringContainsString(
                    '{featured-item}',
                    $template,
                    $resource
                );
                self::assertStringContainsString(
                    '{secondary-items}',
                    $template,
                    $resource
                );
            } else {
                self::assertStringContainsString('{items}', $template, $resource);
            }
            self::assertStringNotContainsString('<header', $template, $resource);
            self::assertStringNotContainsString('data-inline-', $template, $resource);
        }

        $helper = (string) file_get_contents(
            $root . '/App/controllers/_moduleBlogResources.php'
        );
        self::assertStringContainsString('<article class="', $helper);
        self::assertStringContainsString('resolve_header_levels(', $helper);
        self::assertStringContainsString("['base']", $helper);
        self::assertStringContainsString("['child']", $helper);
        self::assertStringNotContainsString('<header', $helper);
    }

    public function testSharedPresentationHelperKeepsAStableAdditiveApi(): void
    {
        $root = self::moduleProjectRoot();
        $helper = (string) file_get_contents(
            $root . '/App/controllers/_moduleBlogResources.php'
        );

        foreach ([
            'liquidstack_blog_resource_context' => '/function\s+liquidstack_blog_resource_context\s*\(\s*string\s+\$resource\s*,\s*int\s+\$index\s*,\s*array\s+\$params\s*,\s*int\s+\$defaultHeadingLevel\s*=\s*2\s*,\s*int\s+\$maximumItems\s*=\s*50\s*\)\s*:\s*array/',
            'liquidstack_blog_resource_escape' => '/function\s+liquidstack_blog_resource_escape\s*\(\s*string\s+\$value\s*\)\s*:\s*string/',
            'liquidstack_blog_resource_card' => '/function\s+liquidstack_blog_resource_card\s*\(\s*array\s+\$context\s*,\s*array\s+\$item\s*,\s*string\s+\$modifier\s*=\s*[\'\"]{2}\s*\)\s*:\s*string/',
            'liquidstack_blog_resource_heading' => '/function\s+liquidstack_blog_resource_heading\s*\(\s*array\s+\$context\s*\)\s*:\s*string/',
        ] as $function => $signature) {
            self::assertSame(
                1,
                preg_match($signature, $helper),
                "Firma pública inestable: {$function}."
            );
            self::assertSame(
                1,
                substr_count($helper, "function {$function}("),
                $function
            );
        }
        foreach ([
            "'resource' => \$resource",
            "'id' => \$id",
            "'heading_id' => \$headingId",
            "'primary_tag' => 'h' . \$levels['base']",
            "'child_tag' => 'h' . \$levels['child']",
            "'heading_markup' => \$injectedHeading",
            "'heading_text' => \$headingText",
            "'heading_lang' => \$headingLang",
            "'class_var' => \$classVar",
            "'items' => \$items",
        ] as $contextKey) {
            self::assertStringContainsString($contextKey, $helper);
        }

        foreach ([
            'sectionBlogFeatured01',
            'sectionBlogList01',
            'sectionBlogSlider01',
            'sectionBlogRelated01',
        ] as $resource) {
            $controller = (string) file_get_contents(
                $root . "/App/controllers/{$resource}.php"
            );
            self::assertStringContainsString(
                "require_once __DIR__ . '/_moduleBlogResources.php';",
                $controller
            );
            self::assertStringContainsString(
                'liquidstack_blog_resource_context(',
                $controller
            );
            self::assertStringContainsString(
                'liquidstack_blog_resource_heading(',
                $controller
            );
        }
    }

    public function testSharedSectionsPreserveAnInjectedHeadingAndScaleItems(): void
    {
        $previousRoot = Paths::projectRoot();
        $previousCwd = getcwd();
        Paths::setProjectRoot(self::moduleProjectRoot());
        chdir(dirname(__DIR__, 2));

        $item = [
            'url' => '/es/noticias/matrix',
            'h1' => 'Matrix',
            'excerpt' => 'Una entrada de prueba.',
            'published_at' => '2026-01-10 12:00:00',
        ];

        try {
            foreach ([
                'sectionBlogFeatured01',
                'sectionBlogList01',
                'sectionBlogSlider01',
                'sectionBlogRelated01',
            ] as $resource) {
                $html = controller($resource, 2, [
                    '{header-primary}' => '<h4 class="external-heading">'
                        . 'Encabezado externo</h4>',
                    'items_data' => [$item],
                ]);

                self::assertStringContainsString(
                    'aria-labelledby="' . $resource . '-02-heading"',
                    $html,
                    $resource
                );
                self::assertStringContainsString(
                    '<h4 id="' . $resource . '-02-heading" '
                        . 'class="external-heading">Encabezado externo</h4>',
                    $html,
                    $resource
                );
                self::assertMatchesRegularExpression(
                    '/<h5\b[^>]*><a[^>]*>Matrix<\/a><\/h5>/',
                    $html,
                    $resource
                );
                self::assertStringNotContainsString(
                    '>' . $resource . '</h4>',
                    $html,
                    $resource
                );
            }

            $withExistingId = controller('sectionBlogList01', 3, [
                '{header-primary}' => '<h3 id="custom-blog-heading">'
                    . 'Título propio</h3>',
                'items_data' => [$item],
            ]);
            self::assertStringContainsString(
                'aria-labelledby="custom-blog-heading"',
                $withExistingId
            );
            self::assertSame(
                1,
                substr_count($withExistingId, 'id="custom-blog-heading"')
            );

            $unsafeCard = controller('sectionBlogList01', 4, [
                'items_data' => [[
                    'url' => '/\\evil.example',
                    'h1' => 'Destino inseguro',
                    'excerpt' => 'No debe renderizarse.',
                    'published_at' => '2026-01-10 12:00:00',
                ]],
            ]);
            self::assertStringNotContainsString('Destino inseguro', $unsafeCard);
        } finally {
            Paths::setProjectRoot($previousRoot);
            if (is_string($previousCwd)) {
                chdir($previousCwd);
            }
        }
    }

    public function testFiltersRemainAWorkingGetFormWithoutJavascript(): void
    {
        $root = self::moduleProjectRoot();
        $template = (string) file_get_contents(
            $root . '/App/templates/_moduleBlogFilters01.html'
        );
        $controller = (string) file_get_contents(
            $root . '/App/controllers/moduleBlogFilters01.php'
        );

        self::assertStringContainsString('<form', $template);
        self::assertStringContainsString('action="{action}"', $template);
        self::assertStringContainsString('method="get"', $template);
        self::assertStringContainsString('role="search"', $template);
        self::assertStringContainsString('name="q"', $template);
        self::assertStringContainsString('minlength="2"', $template);
        self::assertStringContainsString('maxlength="120"', $template);
        self::assertStringContainsString('name="category_mode"', $template);
        self::assertStringContainsString('{mode-disabled}', $template);
        self::assertStringContainsString('type="submit"', $template);
        self::assertStringContainsString('aria-live="polite"', $template);
        self::assertStringContainsString('name="category[]"', $controller);
        self::assertStringContainsString('array_slice(', $controller);
        self::assertStringContainsString("        100\n", $controller);
        self::assertStringContainsString(
            '$maximumSelectableCategories = 10;',
            $controller
        );
        self::assertStringContainsString(
            "'{mode-disabled}' => \$filtersHtml === '' ? ' disabled' : ''",
            $controller
        );
        self::assertStringContainsString("!str_starts_with(\$action, '/')", $controller);
        self::assertStringContainsString("str_starts_with(\$action, '//')", $controller);
        self::assertStringNotContainsString('data-inline-', $template);
    }

    public function testEmptyFilterCatalogDisablesItsMeaninglessModeControl(): void
    {
        $previousRoot = Paths::projectRoot();
        $previousCwd = getcwd();
        Paths::setProjectRoot(self::moduleProjectRoot());
        chdir(dirname(__DIR__, 2));

        try {
            $empty = controller('moduleBlogFilters01', 0, [
                'action' => '/es/noticias',
                'filters' => [],
            ]);
            self::assertMatchesRegularExpression(
                '/<select[^>]+name="category_mode"[^>]+disabled>/',
                $empty
            );
            self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $empty);

            $withCategories = controller('moduleBlogFilters01', 1, [
                'action' => '/es/noticias',
                'filters' => [[
                    'slug' => 'matrix',
                    'name' => 'Matrix',
                    'count' => 4,
                ]],
            ]);
            self::assertDoesNotMatchRegularExpression(
                '/<select[^>]+name="category_mode"[^>]+disabled>/',
                $withCategories
            );
            self::assertStringContainsString(
                'name="category[]" value="matrix"',
                $withCategories
            );

            $unsafeAction = controller('moduleBlogFilters01', 2, [
                'action' => '/\\evil.example',
                'filters' => [],
            ]);
            self::assertStringContainsString('action="/"', $unsafeAction);
            self::assertStringNotContainsString('evil.example', $unsafeAction);
        } finally {
            Paths::setProjectRoot($previousRoot);
            if (is_string($previousCwd)) {
                chdir($previousCwd);
            }
        }
    }

    public function testFilterControlsShareTheTenCategoryQueryCeiling(): void
    {
        $previousRoot = Paths::projectRoot();
        $previousCwd = getcwd();
        Paths::setProjectRoot(self::moduleProjectRoot());
        chdir(dirname(__DIR__, 2));

        $filters = [];
        for ($index = 1; $index <= 12; ++$index) {
            $filters[] = [
                'slug' => 'categoria-' . $index,
                'name' => 'Categoría ' . $index,
                'count' => $index,
            ];
        }

        try {
            $html = controller('moduleBlogFilters01', 2, [
                'action' => '/es/noticias',
                'filters' => $filters,
                'selected_categories' => ['categoria-12'],
            ]);

            self::assertSame(10, substr_count($html, 'name="category[]"'));
            self::assertStringContainsString(
                'value="categoria-12" checked',
                $html
            );
            self::assertStringNotContainsString('value="categoria-10"', $html);
        } finally {
            Paths::setProjectRoot($previousRoot);
            if (is_string($previousCwd)) {
                chdir($previousCwd);
            }
        }
    }

    public function testProgressiveFilterRuntimeIsAbortableAndFallsBackToSsr(): void
    {
        $root = self::moduleProjectRoot();
        $javascript = (string) file_get_contents(
            $root . '/src/js/resources/_moduleBlogFilters01.js'
        );

        foreach ([
            "method === 'get'",
            'if (!canEnhance)',
            'event.preventDefault()',
            'new FormDataConstructor(form)',
            'params.append(name, rawValue)',
            'QUERY_DEBOUNCE_MS = 350',
            "control?.name !== 'category[]'",
            "control?.name !== 'category_mode'",
            "addEventListener('popstate'",
            'new Parser().parseFromString(',
            'requestController?.abort()',
            'ownGeneration !== requestGeneration',
            "setAttribute('aria-busy', 'true')",
            "credentials: 'same-origin'",
            "historyMode === 'push'",
            "historyMode === 'live-search'",
            'typeof view.history.replaceState',
            'syncDocumentMetadata(documentRef, responseDocument)',
            "syncHeadElement('meta[name=\"robots\"]', 'content')",
            "syncHeadElement('link[rel=\"canonical\"]', 'href')",
            "location.assign(url.href)",
            "liquidstack:blog-results-updated",
            'import.meta.hot.dispose(cleanupModuleBlogFilters01)',
        ] as $contract) {
            self::assertStringContainsString($contract, $javascript);
        }

        foreach ([
            'localStorage',
            'sessionStorage',
            'document.cookie',
            'innerHTML = await response.text()',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $javascript);
        }
    }

    public function testSliderRuntimeSupportsSeveralInstancesAndCleansUp(): void
    {
        $root = self::moduleProjectRoot();
        $javascript = (string) file_get_contents(
            $root . '/src/js/resources/_sectionBlogSlider01.js'
        );
        $scss = (string) file_get_contents(
            $root . '/src/scss/resources/_sectionBlogSlider01.scss'
        );

        foreach ([
            "querySelectorAll(selector)",
            'new Map()',
            'viewport.scrollBy({',
            "behavior: reducedMotion() ? 'auto' : 'smooth'",
            "'(prefers-reduced-motion: reduce)'",
            'new ResizeObserverConstructor(scheduleControlsUpdate)',
            'resizeObserver?.disconnect()',
            'listenerController.abort()',
            'import.meta.hot.dispose(cleanupSectionBlogSlider01)',
        ] as $contract) {
            self::assertStringContainsString($contract, $javascript);
        }

        self::assertStringContainsString(
            'scroll-snap-type: inline mandatory;',
            $scss
        );
        self::assertStringContainsString('scroll-snap-align: start;', $scss);
        self::assertStringNotContainsString('localStorage', $javascript);
        self::assertStringNotContainsString('sessionStorage', $javascript);
        self::assertStringNotContainsString('document.cookie', $javascript);
    }

    public function testStandardBlogResourcesOnlyUseTheCoreColorFamilies(): void
    {
        $root = self::moduleProjectRoot();
        $resources = [
            self::ARTICLE_RESOURCE,
            ...self::SECTION_RESOURCES,
            'moduleBlogArchive01',
            'moduleBlogFilters01',
        ];

        foreach ($resources as $resource) {
            $scss = (string) file_get_contents(
                $root . "/src/scss/resources/_{$resource}.scss"
            );
            self::assertSame(
                0,
                preg_match('/c\.\$color(?:0[4-9]|[1-9][0-9])/', $scss),
                "{$resource} depende de una familia cromática no estándar."
            );
            self::assertStringNotContainsString('filterColor', $scss, $resource);
        }
    }

    public function testManifestOwnsEachResourceAsACohesiveManagedGroup(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $manifest = json_decode(
            (string) file_get_contents(
                $coreRoot . '/modules/blog/module.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame([
            'artBlogArticle01',
            'moduleBlogArchive01',
            'moduleBlogFilters01',
            'sectionBlogFeatured01',
            'sectionBlogGrid01',
            'sectionBlogList01',
            'sectionBlogRelated01',
            'sectionBlogSlider01',
        ], $manifest['resources']);

        $groups = [];
        foreach ($manifest['project_files'] as $entry) {
            $groups[$entry['group'] ?? 'assets'][] = $entry['target'];
        }

        self::assertSame([
            'App/controllers/_moduleBlogResources.php',
        ], $groups['resource-support']);
        self::assertCount(3, $groups['resource-artBlogArticle01']);
        self::assertCount(3, $groups['resource-moduleBlogArchive01']);
        self::assertCount(4, $groups['resource-moduleBlogFilters01']);
        self::assertCount(3, $groups['resource-sectionBlogFeatured01']);
        self::assertCount(3, $groups['resource-sectionBlogGrid01']);
        self::assertCount(3, $groups['resource-sectionBlogList01']);
        self::assertCount(3, $groups['resource-sectionBlogRelated01']);
        self::assertCount(4, $groups['resource-sectionBlogSlider01']);
        self::assertEqualsCanonicalizing([
            'App/views/showroom/_blog.php',
            'src/js/showroom/blog.js',
            'src/scss/showroom/blog.scss',
        ], $groups['showroom-hooks']);

        foreach ([
            '/resources/scss/_sectionBlogGrid01.scss',
            '/stubs/App/controllers/sectionBlogGrid01.php',
            '/stubs/App/templates/_sectionBlogGrid01.html',
            '/stubs/App/views/showroom/_blog.php',
            '/src/js/showroom/blog.js',
            '/src/scss/showroom/blog.scss',
        ] as $legacyPath) {
            self::assertFileDoesNotExist($coreRoot . $legacyPath);
        }
    }

    public function testLegacyGridFingerprintsCanMoveToModuleOwnership(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $baseline = json_decode(
            (string) file_get_contents(
                $coreRoot
                    . '/manifests/managed-file-legacy-baselines.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $files = $baseline['files'];

        foreach ([
            'modules/blog/resources/project/App/controllers/sectionBlogGrid01.php',
            'modules/blog/resources/project/App/templates/_sectionBlogGrid01.html',
            'modules/blog/resources/project/App/views/showroom/_blog.php',
            'modules/blog/resources/project/src/js/showroom/blog.js',
            'modules/blog/resources/project/src/scss/resources/_sectionBlogGrid01.scss',
            'modules/blog/resources/project/src/scss/showroom/blog.scss',
        ] as $sourceId) {
            self::assertArrayHasKey($sourceId, $files);
            self::assertNotSame([], $files[$sourceId]);
        }
    }

    public function testBrowserlessNodeFixtureExercisesBothRuntimes(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open no está disponible.');
        }

        $root = dirname(__DIR__, 2);
        $fixture = $root
            . '/tests/Resources/fixtures/blog-resource-js-contract.mjs';
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
            'Blog progressive resource runtimes: OK',
            $stdout
        );
    }
    private static function moduleProjectRoot(): string
    {
        return dirname(__DIR__, 2)
            . '/modules/blog/resources/project';
    }
}
