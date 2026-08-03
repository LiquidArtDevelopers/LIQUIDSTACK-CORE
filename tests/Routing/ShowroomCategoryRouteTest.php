<?php

declare(strict_types=1);

use App\Core\Routing\ShowroomCategoryRoute;
use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

require_once dirname(__DIR__) . '/Support/ShowroomCatalogFixture.php';

final class ShowroomCategoryRouteTest extends TestCase
{
    private const BASE_CATEGORIES = [
        'heroes',
        'particles',
        'gsap-specials',
        'common',
        'cards-grids',
        'media',
        'forms-interactive',
        'modules-sections',
    ];

    private const CATEGORIES = [
        ...self::BASE_CATEGORIES,
        'blog',
    ];

    public function testOnlyAllowlistedChildrenOfRegisteredCatalogParentsResolve(): void
    {
        $showroom = [
            'resources' => 'templates',
            'content' => 'templates',
            'view' => '../App/views/_showroom.php',
        ];
        $templates = [
            'resources' => 'templates',
            'content' => 'templates',
            'view' => '../App/views/_templates.php',
        ];
        $routes = [
            '/es/showroom' => $showroom,
            '/es/templates' => $templates,
            '/es/servicios' => [
                'resources' => 'servicios',
                'content' => 'servicios',
                'view' => '../App/views/servicios.php',
            ],
        ];

        foreach (self::BASE_CATEGORIES as $category) {
            $resolved = ShowroomCategoryRoute::resolve(
                "/es/showroom/{$category}",
                $routes
            );

            self::assertIsArray($resolved);
            self::assertSame($category, $resolved['showroom_category']);
            self::assertSame('/es/showroom', $resolved['showroom_base_path']);
            self::assertSame('templates', $resolved['content']);
        }

        $alias = ShowroomCategoryRoute::resolve(
            '/es/templates/media',
            $routes
        );
        self::assertIsArray($alias);
        self::assertSame('media', $alias['showroom_category']);
        self::assertSame('/es/templates', $alias['showroom_base_path']);

        foreach ([
            '/es/showroom/not-registered',
            '/es/showroom/../media',
            '/es/missing/media',
            '/es/servicios/media',
            '/es/showroom/media/extra',
        ] as $invalid) {
            self::assertNull(
                ShowroomCategoryRoute::resolve($invalid, $routes),
                $invalid
            );
        }
    }

    public function testSegmentedCatalogHasBaseAndOptionalModulePartials(): void
    {
        $root = dirname(__DIR__, 2);
        $shell = (string) file_get_contents(
            $root . '/stubs/App/views/_showroom.php'
        );
        $alias = (string) file_get_contents(
            $root . '/stubs/App/views/_templates.php'
        );

        self::assertSame(self::CATEGORIES, ShowroomCategoryRoute::CATEGORIES);
        self::assertStringNotContainsString('<h1', $shell);
        self::assertStringNotContainsString(
            '<header class="showroomCatalog-header"',
            $shell
        );
        self::assertStringContainsString(
            "require __DIR__ . '/showroom/_local.php';",
            $shell
        );
        self::assertStringContainsString(
            "require __DIR__ . '/_showroom.php';",
            $alias
        );

        foreach (self::BASE_CATEGORIES as $category) {
            $partialName = '_' . $category . '.php';
            $partialPath = $root . '/stubs/App/views/showroom/' . $partialName;

            self::assertFileExists($partialPath);
            self::assertStringContainsString(
                "require __DIR__ . '/showroom/{$partialName}';",
                $shell
            );
            self::assertStringContainsString(
                "controller('",
                (string) file_get_contents($partialPath)
            );
            self::assertFileExists($root . "/src/js/showroom/{$category}.js");
            self::assertFileExists(
                $root . "/src/scss/showroom/{$category}.scss"
            );
        }

        $moduleProject = $root . '/modules/blog/resources/project';
        self::assertFileExists(
            $moduleProject . '/App/views/showroom/_blog.php'
        );
        self::assertFileExists($moduleProject . '/src/js/showroom/blog.js');
        self::assertFileExists($moduleProject . '/src/scss/showroom/blog.scss');
        self::assertStringContainsString("case 'blog':", $shell);
        self::assertStringContainsString(". '/showroom/_blog.php';", $shell);
        self::assertStringContainsString('is_file($blogShowroomPartial)', $shell);
    }

    public function testBlogCategoryAndRouteRequireTheDirectBlogSelector(): void
    {
        $filesystem = new Filesystem();
        $fixtureRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-showroom-blog-'
            . bin2hex(random_bytes(8));
        $coreRoot = dirname(__DIR__, 2);
        $routes = [
            '/es/showroom' => [
                'resources' => 'templates',
                'content' => 'templates',
                'view' => '../App/views/_showroom.php',
            ],
        ];

        try {
            $filesystem->mkdir($fixtureRoot);
            $filesystem->dumpFile(
                $fixtureRoot . '/composer.json',
                json_encode([
                    'require' => ['liquidstack/core' => '^1.0'],
                ], JSON_THROW_ON_ERROR)
            );

            self::assertNotContains(
                'blog',
                ShowroomCategoryRoute::availableCategories(
                    $fixtureRoot,
                    $coreRoot
                )
            );
            self::assertNull(ShowroomCategoryRoute::resolve(
                '/es/showroom/blog',
                $routes,
                $fixtureRoot,
                $coreRoot
            ));

            $filesystem->dumpFile(
                $fixtureRoot . '/composer.json',
                json_encode([
                    'require' => [
                        'liquidstack/core' => '^1.0',
                        'liquidstack/webadmin' => '*',
                    ],
                ], JSON_THROW_ON_ERROR)
            );
            self::assertNotContains(
                'blog',
                ShowroomCategoryRoute::availableCategories(
                    $fixtureRoot,
                    $coreRoot
                )
            );

            $filesystem->dumpFile(
                $fixtureRoot . '/composer.json',
                json_encode([
                    'require' => [
                        'liquidstack/core' => '^1.0',
                        'liquidstack/blog' => '*',
                    ],
                ], JSON_THROW_ON_ERROR)
            );
            self::assertContains(
                'blog',
                ShowroomCategoryRoute::availableCategories(
                    $fixtureRoot,
                    $coreRoot
                )
            );

            $resolved = ShowroomCategoryRoute::resolve(
                '/es/showroom/blog',
                $routes,
                $fixtureRoot,
                $coreRoot
            );
            self::assertIsArray($resolved);
            self::assertSame('blog', $resolved['showroom_category']);
            self::assertSame('/es/showroom', $resolved['showroom_base_path']);
        } finally {
            $filesystem->remove($fixtureRoot);
        }
    }

    public function testBlogCategoryIsHiddenFromTheRenderedMenuUntilEnabled(): void
    {
        $filesystem = new Filesystem();
        $fixtureRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-showroom-blog-menu-'
            . bin2hex(random_bytes(8));
        $source = dirname(__DIR__, 2) . '/stubs/App/views/_showroom.php';
        $previousProjectRoot = Paths::projectRoot();
        $bufferLevel = ob_get_level();

        try {
            $filesystem->mkdir([
                $fixtureRoot . '/App/views',
                $fixtureRoot . '/App/includes',
            ]);
            $filesystem->copy(
                $source,
                $fixtureRoot . '/App/views/_showroom.php'
            );
            foreach ([
                '_globalHead.php',
                '_globalBody.php',
                '_nav.php',
                '_footer.php',
            ] as $include) {
                $filesystem->dumpFile(
                    $fixtureRoot . "/App/includes/{$include}",
                    ''
                );
            }

            $render = static function () use ($fixtureRoot): string {
                $lang = 'es';
                $url = '/es/showroom';
                $rutaConfig = [];
                ob_start();
                require $fixtureRoot . '/App/views/_showroom.php';

                return (string) ob_get_clean();
            };

            $filesystem->dumpFile(
                $fixtureRoot . '/composer.json',
                json_encode([
                    'require' => ['liquidstack/core' => '^1.0'],
                ], JSON_THROW_ON_ERROR)
            );
            Paths::setProjectRoot($fixtureRoot);
            self::assertStringNotContainsString(
                'data-showroom-link="blog"',
                $render()
            );

            $filesystem->dumpFile(
                $fixtureRoot . '/composer.json',
                json_encode([
                    'require' => [
                        'liquidstack/core' => '^1.0',
                        'liquidstack/blog' => '*',
                    ],
                ], JSON_THROW_ON_ERROR)
            );
            self::assertStringContainsString(
                'data-showroom-link="blog"',
                $render()
            );
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            Paths::setProjectRoot($previousProjectRoot);
            $filesystem->remove($fixtureRoot);
        }
    }

    public function testMenuSlugsMatchTheRoutingAndChunkContract(): void
    {
        $root = dirname(__DIR__, 2);
        $shell = (string) file_get_contents(
            $root . '/stubs/App/views/_showroom.php'
        );
        $start = strpos($shell, '$showroomCategories = [');
        $end = strpos($shell, "\n];", $start === false ? 0 : $start);

        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $categoryBlock = substr(
            $shell,
            (int) $start,
            (int) $end - (int) $start
        );
        preg_match_all(
            "/^    '([^']+)' => \\[$/m",
            $categoryBlock,
            $matches
        );

        self::assertSame(
            ShowroomCategoryRoute::CATEGORIES,
            $matches[1] ?? []
        );
        self::assertStringContainsString(
            'foreach ($showroomCategories as $categorySlug => $categoryMeta)',
            $shell
        );
        self::assertStringContainsString(
            'data-showroom-link="<?= htmlspecialchars($categorySlug, ENT_QUOTES) ?>"',
            $shell
        );

        foreach (self::BASE_CATEGORIES as $category) {
            self::assertFileExists(
                $root . "/stubs/App/views/showroom/_{$category}.php"
            );
            self::assertFileExists(
                $root . "/src/js/showroom/{$category}.js"
            );
            self::assertFileExists(
                $root . "/src/scss/showroom/{$category}.scss"
            );
        }

        $moduleProject = $root . '/modules/blog/resources/project';
        self::assertFileExists(
            $moduleProject . '/App/views/showroom/_blog.php'
        );
        self::assertFileExists($moduleProject . '/src/js/showroom/blog.js');
        self::assertFileExists($moduleProject . '/src/scss/showroom/blog.scss');
    }

    public function testDynamicEntrypointLoadsOnlyTheRequestedCategoryAndLocalHook(): void
    {
        $root = dirname(__DIR__, 2);
        $entrypoint = (string) file_get_contents($root . '/src/js/templates.js');

        self::assertSame(
            1,
            substr_count(
                $entrypoint,
                "import.meta.glob('./showroom/*.js')"
            )
        );
        foreach (self::CATEGORIES as $category) {
            self::assertStringNotContainsString(
                "import('./showroom/{$category}.js')",
                $entrypoint
            );
        }

        self::assertStringContainsString(
            "import.meta.glob('./showroom/local/*.js')",
            $entrypoint
        );
        self::assertStringContainsString(
            "if (document.readyState === 'loading')",
            $entrypoint
        );
        self::assertStringContainsString(
            "import ScrollTrigger from 'gsap/ScrollTrigger';",
            $entrypoint
        );
        self::assertStringContainsString(
            'if (ScrollTrigger.isTouch)',
            $entrypoint
        );
        self::assertStringContainsString(
            "catalog.classList.add('showroomCatalog-hasPin');",
            $entrypoint
        );
        self::assertStringContainsString(
            'void initRequestedCategory();',
            $entrypoint
        );
        self::assertStringNotContainsString(
            "from './resources/_sectionParticles01.js'",
            $entrypoint
        );

        $styles = (string) file_get_contents(
            $root . '/src/scss/templates.scss'
        );
        self::assertStringContainsString(
            'padding-top: calc(8dvh + clamp(2rem, 6vw, 5rem));',
            $styles
        );
        self::assertStringContainsString('top: 6dvh;', $styles);
        self::assertStringContainsString(
            '&.showroomCatalog-hasPin',
            $styles
        );
        self::assertStringContainsString('height: auto;', $styles);
        self::assertStringContainsString('transition: none;', $styles);

        $application = (string) file_get_contents(
            $root . '/src/Core/Application.php'
        );
        self::assertStringContainsString(
            'ShowroomCategoryRoute::resolve($url, $rutasPorIdioma)',
            $application
        );
    }

    public function testCatalogShellRendersHydratedCopyOnTheServer(): void
    {
        $filesystem = new Filesystem();
        $fixtureRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-showroom-ssr-'
            . bin2hex(random_bytes(8));
        $source = dirname(__DIR__, 2) . '/stubs/App/views/_showroom.php';
        $keys = [
            'showroom_catalog_title' => 'Título hidratado',
            'showroom_catalog_intro' => 'Introducción hidratada',
            'showroom_catalog_nav' => 'Navegación hidratada',
            'showroom_catalog_index' => 'Índice hidratado',
            'showroom_catalog_category_heroes_label' => 'Héroes hidratados',
            'showroom_catalog_category_heroes_description'
                => 'Descripción hidratada',
        ];
        $previousGlobals = [];
        $bufferLevel = ob_get_level();

        try {
            $filesystem->mkdir([
                $fixtureRoot . '/App/views',
                $fixtureRoot . '/App/includes',
            ]);
            $filesystem->copy(
                $source,
                $fixtureRoot . '/App/views/_showroom.php'
            );
            foreach ([
                '_globalHead.php',
                '_globalBody.php',
                '_nav.php',
                '_footer.php',
            ] as $include) {
                $filesystem->dumpFile(
                    $fixtureRoot . "/App/includes/{$include}",
                    ''
                );
            }

            foreach ($keys as $key => $value) {
                $previousGlobals[$key] = [
                    'exists' => array_key_exists($key, $GLOBALS),
                    'value' => $GLOBALS[$key] ?? null,
                ];
                $GLOBALS[$key] = (object) ['text' => $value];
            }

            $lang = 'es';
            $url = '/es/showroom';
            $rutaConfig = [];
            ob_start();
            require $fixtureRoot . '/App/views/_showroom.php';
            $html = (string) ob_get_clean();

            foreach ($keys as $value) {
                self::assertStringContainsString($value, $html);
            }
            self::assertStringNotContainsString(
                'Catálogo de recursos LiquidStack',
                $html
            );
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            foreach ($previousGlobals as $key => $previous) {
                if ($previous['exists']) {
                    $GLOBALS[$key] = $previous['value'];
                } else {
                    unset($GLOBALS[$key]);
                }
            }
            $filesystem->remove($fixtureRoot);
        }
    }
}
