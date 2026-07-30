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
        $_ENV['DEV_MODE'] = 'true';

        foreach ([
            'global_only',
            'templates_only',
            'showroom_only',
            'shared',
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
