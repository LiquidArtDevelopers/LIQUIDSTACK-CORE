<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

use function App\Core\Support\controller;

final class BlogPaginationResourceContractTest extends TestCase
{
    public function testRuntimeKeepsSsrLinksAndCommitsOnlyValidatedFragments(): void
    {
        $path = self::moduleProjectRoot()
            . '/src/js/resources/_moduleBlogPagination01.js';

        self::assertFileExists($path);
        $javascript = (string) file_get_contents($path);

        foreach ([
            "PAGINATION_LINK_SELECTOR = `\${PAGINATION_SELECTOR} a[href]`",
            "'#blog-results[data-blog-results]'",
            'event.preventDefault()',
            "credentials: 'same-origin'",
            "'X-LiquidStack-Partial': PARTIAL_NAME",
            'REQUEST_TIMEOUT_MS = 12_000',
            'response.url || url.href',
            'incoming.length !== 1',
            'replaceResults(target, incomingTarget)',
            "historyMode === 'push'",
            '{ liquidstackBlogPagination: true }',
            "addEventListener('popstate'",
            "addEventListener('pagehide'",
            "addEventListener('pageshow'",
            'addEventListener(type, onFilterInteraction',
            "focus({ preventScroll: true })",
            "'[data-blog-card-key] h3'",
            "'[data-blog-collection-status]:not([hidden])'",
            "'aria-live', 'polite'",
            "'liquidstack:blog-results-updated'",
            'import.meta.hot.dispose(cleanupModuleBlogPagination01)',
        ] as $contract) {
            self::assertStringContainsString($contract, $javascript);
        }

        foreach ([
            'localStorage',
            'sessionStorage',
            'document.cookie',
            'innerHTML =',
            'location.reload',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $javascript);
        }
    }

    public function testAdversarialNodeHarnessCoversRuntimeBehaviour(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open no esta disponible.');
        }

        $fixture = self::coreRoot()
            . '/tests/Resources/fixtures/'
            . 'blog-pagination-runtime-contract.mjs';
        self::assertFileExists($fixture);

        $pipes = [];
        $process = @proc_open(
            ['node', $fixture],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            self::coreRoot()
        );
        if (!is_resource($process)) {
            self::markTestSkipped('Node.js no esta disponible.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $stderr ?: $stdout);
        self::assertStringContainsString(
            'Blog reactive pagination adversarial runtime: OK',
            $stdout
        );
    }

    public function testItIsolatedlyResetsProjectWideNavigationStyles(): void
    {
        $css = (string) file_get_contents(
            self::moduleProjectRoot()
            . '/src/scss/resources/_moduleBlogPagination01.scss'
        );

        self::assertMatchesRegularExpression(
            '/\.moduleBlogPagination01\s*\{[^}]*position:\s*static;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.moduleBlogPagination01\s*\{[^}]*height:\s*auto;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/>\s*\.moduleBlogPagination01-pages\s*\{[^}]*width:\s*auto;[^}]*height:\s*auto;/s',
            $css
        );
        self::assertStringContainsString('flex-wrap: wrap;', $css);
    }

    public function testItRendersBoundedAccessibleRootRelativePagination(): void
    {
        $previousRoot = Paths::projectRoot();
        $previousCwd = (string) getcwd();
        Paths::setProjectRoot(self::moduleProjectRoot());
        chdir(self::coreRoot());

        try {
            $html = controller('moduleBlogPagination01', 2, [
                'id_prefix' => 'matrix-pages',
                'previous_url' => '/es/showroom/blog?demo=1#matrix',
                'next_url' => '/es/showroom/blog?demo=3#matrix',
                'pages_data' => [
                    ['page' => 1, 'url' => '/es/showroom/blog?demo=1#matrix'],
                    ['page' => 2, 'current' => true],
                    ['page' => 3, 'url' => '/es/showroom/blog?demo=3#matrix'],
                ],
                'labels' => [
                    'previous' => 'Anterior',
                    'next' => 'Siguiente',
                    'page' => 'Pagina',
                ],
            ]);

            self::assertStringContainsString('<div id="matrix-pages"', $html);
            self::assertStringNotContainsString('<nav', $html);
            self::assertStringContainsString('rel="prev"', $html);
            self::assertStringContainsString('rel="next"', $html);
            self::assertStringContainsString('aria-current="page"', $html);
            self::assertStringContainsString('aria-label="Pagina 2"', $html);
            self::assertStringContainsString(
                'href="/es/showroom/blog?demo=3#matrix"',
                $html
            );
        } finally {
            Paths::setProjectRoot($previousRoot);
            if ($previousCwd !== '') {
                chdir($previousCwd);
            }
        }
    }

    public function testItSelfSuppressesZeroAndCurrentOnlyPagination(): void
    {
        $previousRoot = Paths::projectRoot();
        $previousCwd = (string) getcwd();
        Paths::setProjectRoot(self::moduleProjectRoot());
        chdir(self::coreRoot());

        try {
            self::assertSame(
                '',
                controller('moduleBlogPagination01', 0, [])
            );
            self::assertSame(
                '',
                controller('moduleBlogPagination01', 1, [
                    'pages_data' => [[
                        'page' => 1,
                        'current' => true,
                    ]],
                ])
            );
            self::assertSame(
                '',
                controller('moduleBlogPagination01', 2, [
                    'previous_url' => 'https://example.test/page/1',
                    'next_url' => '//example.test/page/2',
                    'pages_data' => [[
                        'page' => 1,
                        'current' => true,
                    ]],
                ])
            );
        } finally {
            Paths::setProjectRoot($previousRoot);
            if ($previousCwd !== '') {
                chdir($previousCwd);
            }
        }
    }

    public function testItRendersOneUsefulLinkAndManyPageStates(): void
    {
        $previousRoot = Paths::projectRoot();
        $previousCwd = (string) getcwd();
        Paths::setProjectRoot(self::moduleProjectRoot());
        chdir(self::coreRoot());

        try {
            $singleLink = controller('moduleBlogPagination01', 0, [
                'next_url' => '/es/noticias?page=2',
            ]);
            self::assertStringContainsString('<div ', $singleLink);
            self::assertStringContainsString('rel="next"', $singleLink);

            $manyPages = controller('moduleBlogPagination01', 1, [
                'pages_data' => [
                    ['page' => 1, 'current' => true],
                    ['page' => 2, 'url' => '/es/noticias?page=2'],
                    ['page' => 3, 'url' => '/es/noticias?page=3'],
                ],
            ]);
            self::assertStringContainsString('<div ', $manyPages);
            self::assertSame(3, substr_count($manyPages, '<li>'));
            self::assertStringContainsString(
                'aria-current="page"',
                $manyPages
            );
        } finally {
            Paths::setProjectRoot($previousRoot);
            if ($previousCwd !== '') {
                chdir($previousCwd);
            }
        }
    }

    public function testArchiveSelfContainsZeroOneAndManyPeriodStates(): void
    {
        $previousRoot = Paths::projectRoot();
        $previousCwd = (string) getcwd();
        Paths::setProjectRoot(self::moduleProjectRoot());
        chdir(self::coreRoot());

        try {
            self::assertSame(
                '',
                controller('moduleBlogArchive01', 0, [])
            );
            self::assertSame(
                '',
                controller('moduleBlogArchive01', 1, [
                    'periods_data' => [[
                        'url' => 'javascript:alert(1)',
                        'label' => 'Invalid',
                        'count' => 1,
                    ]],
                ])
            );
            self::assertSame(
                '',
                controller('moduleBlogArchive01', 2, [
                    'items' => 0,
                    'periods_data' => [[
                        'url' => '/es/noticias?year=2026&month=8',
                        'label' => 'Agosto de 2026',
                        'count' => 1,
                    ]],
                ])
            );

            $singlePeriod = controller('moduleBlogArchive01', 3, [
                'periods_data' => [[
                    'url' => '/es/noticias?year=2026&month=8',
                    'label' => 'Agosto de 2026',
                    'count' => 1,
                    'active' => true,
                ]],
                'header_text' => 'Archivo',
            ]);
            self::assertStringContainsString('<div ', $singlePeriod);
            self::assertStringNotContainsString('<nav', $singlePeriod);
            self::assertSame(1, substr_count($singlePeriod, '<li '));
            self::assertStringContainsString(
                'aria-current="date"',
                $singlePeriod
            );

            $manyPeriods = controller('moduleBlogArchive01', 4, [
                'periods_data' => [
                    [
                        'url' => '/es/noticias?year=2026&month=8',
                        'label' => 'Agosto de 2026',
                        'count' => 2,
                    ],
                    [
                        'url' => '/es/noticias?year=2026&month=7',
                        'label' => 'Julio de 2026',
                        'count' => 3,
                    ],
                    [
                        'url' => '/es/noticias?year=2026&month=6',
                        'label' => 'Junio de 2026',
                        'count' => 4,
                    ],
                ],
                'header_text' => 'Archivo',
            ]);
            self::assertStringContainsString('<div ', $manyPeriods);
            self::assertSame(3, substr_count($manyPeriods, '<li '));
        } finally {
            Paths::setProjectRoot($previousRoot);
            if ($previousCwd !== '') {
                chdir($previousCwd);
            }
        }
    }

    public function testItRejectsOffSiteOrMalformedNavigationUrls(): void
    {
        $previousRoot = Paths::projectRoot();
        $previousCwd = (string) getcwd();
        Paths::setProjectRoot(self::moduleProjectRoot());
        chdir(self::coreRoot());

        try {
            $html = controller('moduleBlogPagination01', 0, [
                'previous_url' => 'https://example.test/page/1',
                'next_url' => '//example.test/page/3',
                'pages_data' => [
                    ['page' => 1, 'url' => 'javascript:alert(1)'],
                    ['page' => 2, 'current' => true],
                    ['page' => 3, 'url' => "\n/es/page/3"],
                ],
            ]);

            self::assertStringNotContainsString('example.test', $html);
            self::assertStringNotContainsString('javascript:', $html);
            self::assertStringNotContainsString('rel="prev"', $html);
            self::assertStringNotContainsString('rel="next"', $html);
            self::assertSame('', $html);
        } finally {
            Paths::setProjectRoot($previousRoot);
            if ($previousCwd !== '') {
                chdir($previousCwd);
            }
        }
    }

    public function testGridCanDelegatePaginationWithoutAnnouncingAFalseEnd(): void
    {
        $previousRoot = Paths::projectRoot();
        $previousCwd = (string) getcwd();
        Paths::setProjectRoot(self::moduleProjectRoot());
        chdir(self::coreRoot());

        try {
            $html = controller('moduleBlogGrid02', 0, [
                'pagination_mode' => 'external',
                'items_data' => [[
                    'url' => '/es/noticias/matrix',
                    'h1' => 'Una publicación de prueba',
                    'excerpt' => 'Contenido de prueba.',
                    'published_at' => '2026-08-14T10:00:00+00:00',
                ]],
                'items' => 1,
                'end_message' => 'No hay más entradas.',
            ]);

            self::assertStringContainsString(
                'class="moduleBlogGrid02-item"',
                $html
            );
            self::assertStringNotContainsString(
                'data-blog-collection-status',
                $html
            );
            self::assertStringNotContainsString(
                'No hay más entradas.',
                $html
            );

            $empty = controller('moduleBlogGrid02', 1, [
                'pagination_mode' => 'external',
                'items_data' => [],
                'items' => 0,
                'empty_message' => 'No hay resultados.',
            ]);
            self::assertStringContainsString(
                'data-blog-collection-status',
                $empty
            );
            self::assertStringContainsString('No hay resultados.', $empty);
        } finally {
            Paths::setProjectRoot($previousRoot);
            if ($previousCwd !== '') {
                chdir($previousCwd);
            }
        }
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
