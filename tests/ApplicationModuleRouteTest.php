<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Support\Paths;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ApplicationModuleRouteTest extends TestCase
{
    private string $fixtureRoot;
    private string $configMarker;
    private string $rolesMarker;
    private string $endpointMarker;
    private Filesystem $filesystem;
    private string $previousErrorLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem();
        $this->previousErrorLog = (string) ini_get('error_log');
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-application-module-route-'
            . bin2hex(random_bytes(8));
        $this->configMarker = $this->fixtureRoot . '/config-loaded.marker';
        $this->rolesMarker = $this->fixtureRoot . '/roles-loaded.marker';
        $this->endpointMarker = $this->fixtureRoot . '/endpoint-run.marker';

        $this->filesystem->mkdir([
            $this->fixtureRoot . '/App/app',
            $this->fixtureRoot . '/App/config/enums',
            $this->fixtureRoot . '/App/config/routes',
            $this->fixtureRoot . '/App/views',
            $this->fixtureRoot . '/sessions',
        ]);
        self::assertNotFalse(ini_set(
            'error_log',
            $this->fixtureRoot . '/webadmin-test-errors.log'
        ));

        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/config.php',
            $this->markerScript($this->configMarker)
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/enums/_roles.php',
            $this->markerScript($this->rolesMarker)
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/langs.php',
            "<?php\n\nreturn ['es'];\n"
        );

        $this->prepareRequestRuntime();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        ini_set('error_log', $this->previousErrorLog);
        $this->filesystem->remove($this->fixtureRoot);
        Paths::setProjectRoot(dirname(__DIR__));

        parent::tearDown();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWebAdminRouteRunsBeforeLegacyConfigAndSession(): void
    {
        $this->writeComposerRequirements([
            'liquidstack/core' => '^1.9',
            'liquidstack/webadmin' => '*',
        ]);
        $this->setRequest('GET', '/admin/login');

        self::assertFileDoesNotExist($this->configMarker);
        self::assertFileDoesNotExist($this->rolesMarker);
        self::assertSame(PHP_SESSION_NONE, session_status());

        ob_start();
        (new Application($this->fixtureRoot))->run();
        $body = (string) ob_get_clean();

        self::assertSame('Service unavailable', $body);
        self::assertSame(503, http_response_code());
        self::assertFileDoesNotExist(
            $this->configMarker,
            'La ruta neutral no debe cargar App/config/config.php.'
        );
        self::assertFileDoesNotExist(
            $this->rolesMarker,
            'La ruta neutral no debe cargar App/config/enums/_roles.php.'
        );
        self::assertSame(
            PHP_SESSION_NONE,
            session_status(),
            'WebAdmin no debe iniciar la sesion PHP legacy.'
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWebAdminReceivesEnvironmentInjectedOnlyInProcess(): void
    {
        $this->writeComposerRequirements([
            'liquidstack/core' => '^1.9',
            'liquidstack/webadmin' => '*',
        ]);
        $this->setRequest('GET', '/admin/login');
        $securityKey = rtrim(strtr(
            base64_encode(str_repeat('p', 32)),
            '+/',
            '-_'
        ), '=');
        self::assertTrue(putenv(
            'LIQUIDSTACK_WEBADMIN_SECURITY_KEY=' . $securityKey
        ));
        unset(
            $_ENV['LIQUIDSTACK_WEBADMIN_SECURITY_KEY'],
            $_SERVER['LIQUIDSTACK_WEBADMIN_SECURITY_KEY']
        );
        foreach (['BBDD_SERVER', 'BBDD_USER', 'BBDD_PASS', 'BBDD_NAME'] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        ob_start();
        (new Application($this->fixtureRoot))->run();
        $body = (string) ob_get_clean();
        $log = (string) file_get_contents(
            $this->fixtureRoot . '/webadmin-test-errors.log'
        );

        self::assertSame('Service unavailable', $body);
        self::assertSame(503, http_response_code());
        self::assertStringContainsString(
            'webadmin.runtime_unavailable',
            $log
        );
        self::assertStringNotContainsString(
            'webadmin.security_key_missing',
            $log
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnusableEnvironmentFailsClosedBeforeRuntimeCreation(): void
    {
        $this->writeComposerRequirements([
            'liquidstack/core' => '^1.9',
            'liquidstack/webadmin' => '*',
        ]);
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/.env',
            "BROKEN='unterminated\n"
        );
        $this->setRequest('GET', '/admin/login');
        self::assertTrue(putenv(
            'LIQUIDSTACK_WEBADMIN_SECURITY_KEY=' . rtrim(strtr(
                base64_encode(str_repeat('p', 32)),
                '+/',
                '-_'
            ), '=')
        ));
        unset(
            $_ENV['LIQUIDSTACK_WEBADMIN_SECURITY_KEY'],
            $_SERVER['LIQUIDSTACK_WEBADMIN_SECURITY_KEY']
        );

        ob_start();
        (new Application($this->fixtureRoot))->run();
        $body = (string) ob_get_clean();
        $log = (string) file_get_contents(
            $this->fixtureRoot . '/webadmin-test-errors.log'
        );

        self::assertSame('Service unavailable', $body);
        self::assertSame(503, http_response_code());
        self::assertStringContainsString(
            'webadmin.environment_unusable',
            $log
        );
        self::assertStringNotContainsString(
            'webadmin.security_key_missing',
            $log
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPublicGetKeepsLegacyBootstrapWithBlogEnabled(): void
    {
        $this->writeComposerRequirements([
            'liquidstack/core' => '^1.9',
            'liquidstack/blog' => '*',
        ]);
        $this->writePublicGetFixture();
        $this->setRequest('GET', '/publica');

        ob_start();
        (new Application($this->fixtureRoot))->run();
        $body = (string) ob_get_clean();

        self::assertSame(
            [
                'result' => 'public-get-ok',
                'config_loaded' => true,
                'roles_loaded' => true,
                'session_active' => true,
            ],
            json_decode($body, true, 512, JSON_THROW_ON_ERROR)
        );
        self::assertFileExists($this->configMarker);
        self::assertFileExists($this->rolesMarker);
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLegacyPostKeepsBootstrapAndExecutesEndpoint(): void
    {
        $this->writeComposerRequirements([
            'liquidstack/core' => '^1.9',
            'liquidstack/blog' => '*',
        ]);
        $this->writeLegacyPostFixture();
        $this->setRequest('POST', '/api/probe');

        ob_start();
        (new Application($this->fixtureRoot))->run();
        $body = (string) ob_get_clean();

        self::assertSame(
            [
                'result' => 'legacy-post-ok',
                'config_loaded' => true,
                'roles_loaded' => true,
                'session_active' => true,
            ],
            json_decode($body, true, 512, JSON_THROW_ON_ERROR)
        );
        self::assertFileExists($this->configMarker);
        self::assertFileExists($this->rolesMarker);
        self::assertFileExists(
            $this->endpointMarker,
            'La ruta POST debe ejecutar el endpoint legacy configurado.'
        );
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    /** @param array<string, string> $requirements */
    private function writeComposerRequirements(array $requirements): void
    {
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/composer.json',
            json_encode(
                ['require' => $requirements],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
    }

    private function writePublicGetFixture(): void
    {
        $viewPath = $this->fixtureRoot . '/App/views/public.php';
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/routes/get.php',
            "<?php\n\nreturn [\n"
                . "    'es' => [\n"
                . "        '/publica' => [\n"
                . "            'view' => " . var_export($viewPath, true) . ",\n"
                . "        ],\n"
                . "    ],\n"
                . "];\n"
        );
        $this->filesystem->dumpFile(
            $viewPath,
            $this->probeResponseScript('public-get-ok')
        );
    }

    private function writeLegacyPostFixture(): void
    {
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/routes/post.php',
            "<?php\n\nreturn [\n"
                . "    '/api/probe' => 'probe.php',\n"
                . "];\n"
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/app/probe.php',
            "<?php\n\n"
                . 'file_put_contents('
                . var_export($this->endpointMarker, true)
                . ", 'executed');\n"
                . substr($this->probeResponseScript('legacy-post-ok'), 6)
        );
    }

    private function probeResponseScript(string $result): string
    {
        return "<?php\n\n"
            . 'echo json_encode([' . "\n"
            . "    'result' => " . var_export($result, true) . ",\n"
            . "    'config_loaded' => is_file("
            . var_export($this->configMarker, true) . "),\n"
            . "    'roles_loaded' => is_file("
            . var_export($this->rolesMarker, true) . "),\n"
            . "    'session_active' => session_status() === PHP_SESSION_ACTIVE,\n"
            . '], JSON_THROW_ON_ERROR);' . "\n";
    }

    private function markerScript(string $path): string
    {
        return "<?php\n\nfile_put_contents("
            . var_export($path, true)
            . ", 'loaded');\n";
    }

    private function prepareRequestRuntime(): void
    {
        self::assertTrue(ini_set(
            'session.save_path',
            $this->fixtureRoot . '/sessions'
        ) !== false);
        self::assertTrue(ini_set('session.use_cookies', '0') !== false);
        self::assertTrue(ini_set('session.cache_limiter', '') !== false);
        session_id('liquidstack-' . bin2hex(random_bytes(8)));

        $_COOKIE = [];
        $_POST = [];
        $_GET = [];
        $_ENV = [
            'LANG_DEFAULT' => 'es',
            'MULTILANG' => '0',
            'ES_SIMPLIFICADO' => '1',
            'DEV_MODE' => '1',
        ];
    }

    private function setRequest(string $method, string $uri): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => '',
            'HTTPS' => 'on',
        ];
    }
}
