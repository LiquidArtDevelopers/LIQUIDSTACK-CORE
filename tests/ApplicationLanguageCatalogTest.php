<?php

use App\Core\Application;
use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

final class ApplicationLanguageCatalogTest extends TestCase
{
    private string $fixtureRoot;

    /** @var array<string, mixed> */
    private array $previousEnvironment = [];

    /** @var array<string, array{exists: bool, value?: mixed}> */
    private array $previousGlobals = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-application-language-'
            . bin2hex(random_bytes(8));

        foreach (['global', 'templates', 'showroom'] as $catalog) {
            self::assertTrue(mkdir(
                $this->fixtureRoot
                    . "/App/config/languages/{$catalog}",
                0777,
                true
            ));
        }

        $this->previousEnvironment['DEV_MODE'] = $_ENV['DEV_MODE'] ?? null;
        $this->previousEnvironment['RAIZ'] = $_ENV['RAIZ'] ?? null;
        $_ENV['DEV_MODE'] = 'true';

        foreach ([
            'global_only',
            'templates_only',
            'showroom_only',
            'shared',
            'css',
            'js',
        ] as $key) {
            $this->previousGlobals[$key] = array_key_exists($key, $GLOBALS)
                ? ['exists' => true, 'value' => $GLOBALS[$key]]
                : ['exists' => false];
        }
    }

    protected function tearDown(): void
    {
        if ($this->previousEnvironment['DEV_MODE'] === null) {
            unset($_ENV['DEV_MODE']);
        } else {
            $_ENV['DEV_MODE'] = $this->previousEnvironment['DEV_MODE'];
        }
        if ($this->previousEnvironment['RAIZ'] === null) {
            unset($_ENV['RAIZ']);
        } else {
            $_ENV['RAIZ'] = $this->previousEnvironment['RAIZ'];
        }

        foreach ($this->previousGlobals as $key => $state) {
            if ($state['exists']) {
                $GLOBALS[$key] = $state['value'];
            } else {
                unset($GLOBALS[$key]);
            }
        }

        $this->removeTree($this->fixtureRoot);
        Paths::setProjectRoot(dirname(__DIR__));

        parent::tearDown();
    }

    public function testLegacyShowroomUsesTemplatesAsAdditiveBase(): void
    {
        $this->writeJson('global', [
            'global_only' => ['text' => 'Global'],
            'shared' => ['text' => 'Global'],
        ]);
        $this->writeJson('templates', [
            'templates_only' => ['text' => 'Base CORE'],
            'shared' => ['text' => 'Base CORE'],
        ]);
        $this->writeJson('showroom', [
            'showroom_only' => ['text' => 'Local'],
            'shared' => ['text' => 'Override local'],
        ]);

        $view = $this->fixtureRoot . '/legacy-showroom-view.php';
        file_put_contents(
            $view,
            '<?php echo json_encode(['
                . '$global_only->text,'
                . '$templates_only->text,'
                . '$showroom_only->text,'
                . '$shared->text'
                . ']);'
        );

        $output = $this->renderRoute($view, [
            'content' => 'showroom',
            'resources' => 'templates',
        ]);

        self::assertSame(
            ['Global', 'Base CORE', 'Local', 'Override local'],
            json_decode($output, true, 512, JSON_THROW_ON_ERROR)
        );
        self::assertSame(
            'Override local',
            $GLOBALS['shared']->text
        );
    }

    public function testRegularRouteDoesNotImportResourceCatalog(): void
    {
        $this->writeJson('global', [
            'global_only' => ['text' => 'Global'],
        ]);
        $this->writeJson('templates', [
            'templates_only' => ['text' => 'No importar'],
        ]);
        $this->writeJson('showroom', [
            'showroom_only' => ['text' => 'Local'],
        ]);

        $view = $this->fixtureRoot . '/regular-view.php';
        file_put_contents(
            $view,
            '<?php echo isset($templates_only) ? "imported" : "preserved";'
        );

        self::assertSame(
            'preserved',
            $this->renderRoute($view, [
                'content' => 'showroom',
                'resources' => 'servicio',
            ])
        );
    }

    public function testProductionAssetsUseTheExactViteManifestEntry(): void
    {
        $_ENV['DEV_MODE'] = '0';
        $_ENV['RAIZ'] = 'https://example.test';

        $this->writeAsset('assets/js/blog-entry.js');
        $this->writeAsset('assets/js/blog-showroom-chunk.js');
        $this->writeAsset('assets/js/blogArticle-entry.js');
        $this->writeAsset('assets/css/blog-entry.css');
        $this->writeAsset('assets/css/blog-showroom.css');
        $this->writeManifest([
            'src/js/blog.js' => [
                'file' => 'assets/js/blog-entry.js',
                'src' => 'src/js/blog.js',
                'isEntry' => true,
                'css' => ['assets/css/blog-entry.css'],
            ],
            'src/js/blogArticle.js' => [
                'file' => 'assets/js/blogArticle-entry.js',
                'src' => 'src/js/blogArticle.js',
                'isEntry' => true,
            ],
            'src/js/showroom/blog.js' => [
                'file' => 'assets/js/blog-showroom-chunk.js',
                'src' => 'src/js/showroom/blog.js',
                'css' => ['assets/css/blog-showroom.css'],
            ],
        ]);

        $view = $this->fixtureRoot . '/production-assets-view.php';
        file_put_contents(
            $view,
            '<?php echo json_encode([$css ?? null, $js ?? null]);'
        );

        self::assertSame(
            [
                'https://example.test/assets/css/blog-entry.css',
                'https://example.test/assets/js/blog-entry.js',
            ],
            json_decode(
                $this->renderRoute($view, ['resources' => 'blog']),
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        );
    }

    public function testLegacyAssetFallbackRefusesAmbiguousCandidates(): void
    {
        $_ENV['DEV_MODE'] = '0';
        $_ENV['RAIZ'] = 'https://example.test';

        $this->writeAsset('assets/js/blog-first.js');
        $this->writeAsset('assets/js/blog-second.js');
        $this->writeAsset('assets/css/blog-only.css');

        $view = $this->fixtureRoot . '/legacy-assets-view.php';
        file_put_contents(
            $view,
            '<?php echo json_encode([$css ?? null, $js ?? null]);'
        );

        self::assertSame(
            ['https://example.test/assets/css/blog-only.css', null],
            json_decode(
                $this->renderRoute($view, ['resources' => 'blog']),
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        );
    }

    /**
     * @param array<string, mixed> $route
     */
    private function renderRoute(string $view, array $route): string
    {
        $application = new Application($this->fixtureRoot);
        $method = new ReflectionMethod(
            Application::class,
            'renderMatchedRoute'
        );
        $method->setAccessible(true);

        ob_start();
        $method->invoke(
            $application,
            'es',
            '/es/showroom',
            ['view' => $view] + $route
        );

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $catalog, array $data): void
    {
        file_put_contents(
            $this->fixtureRoot
                . "/App/config/languages/{$catalog}/es.json",
            json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            )
        );
    }

    private function writeAsset(string $path): void
    {
        $absolutePath = $this->fixtureRoot . '/public/' . $path;
        if (!is_dir(dirname($absolutePath))) {
            self::assertTrue(mkdir(dirname($absolutePath), 0777, true));
        }
        file_put_contents($absolutePath, 'fixture');
    }

    /** @param array<string, mixed> $manifest */
    private function writeManifest(array $manifest): void
    {
        $path = $this->fixtureRoot . '/public/.vite/manifest.json';
        if (!is_dir(dirname($path))) {
            self::assertTrue(mkdir(dirname($path), 0777, true));
        }
        file_put_contents(
            $path,
            json_encode($manifest, JSON_THROW_ON_ERROR)
        );
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $path,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
