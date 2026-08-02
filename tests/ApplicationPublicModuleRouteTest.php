<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\ModulePublicRouteProviderInterface;
use App\Core\Modules\ModulePreBootstrapPublicRouteProviderInterface;
use App\Core\Modules\ModulePreBootstrapPublicRoutePrefixProviderInterface;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Routing\ModulePublicRouteCollection;
use App\Core\Support\Paths;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ApplicationPublicRouteProviderFixture implements
    ModulePublicRouteProviderInterface,
    ModulePreBootstrapPublicRouteProviderInterface,
    ModulePreBootstrapPublicRoutePrefixProviderInterface
{
    public static int $prefixReads = 0;
    public static int $constructions = 0;
    public static int $registrations = 0;
    public static int $handlerCalls = 0;

    public function __construct()
    {
        self::$constructions++;
    }

    public static function moduleId(): string
    {
        return 'blog';
    }

    public static function publicRoutePrefixes(
        ModuleRuntimeContext $context
    ): array {
        self::$prefixReads++;

        return [
            '/noticias',
            '/showroom',
            '/neutral-sitemap.xml',
            '/module-media',
        ];
    }

    public static function preBootstrapPublicRoutePaths(
        ModuleRuntimeContext $context
    ): array {
        return [
            '/neutral-sitemap.xml',
            '/showroom/media',
            '/orphan-sitemap.xml',
        ];
    }

    public static function preBootstrapPublicRoutePrefixes(
        ModuleRuntimeContext $context
    ): array {
        return ['/module-media'];
    }

    public function registerPublicRoutes(
        ModulePublicRouteCollection $routes,
        ModuleRuntimeContext $context
    ): void {
        self::$registrations++;

        foreach (self::publicPrefixesWithoutProbe() as $prefix) {
            $routes->addGet(
                self::moduleId(),
                $prefix,
                static function (Request $request): ?Response {
                    self::$handlerCalls++;

                    if ($request->path() === '/noticias/missing') {
                        return null;
                    }
                    if ($request->path() === '/noticias/failure') {
                        throw new RuntimeException('fixture failure detail');
                    }

                    return new Response(
                        $request->path() === '/module-media/malformed'
                            ? 404
                            : 200,
                        match (true) {
                            $request->path() === '/neutral-sitemap.xml' =>
                                'pre-bootstrap-public',
                            str_starts_with(
                                $request->path(),
                                '/module-media/'
                            ) => $request->path() ===
                                '/module-media/malformed'
                                    ? 'Not found'
                                    : 'pre-bootstrap-media',
                            default => 'late-public',
                        },
                        ['X-Late-Public' => 'yes']
                    );
                }
            );
        }
    }

    public static function reset(): void
    {
        self::$prefixReads = 0;
        self::$constructions = 0;
        self::$registrations = 0;
        self::$handlerCalls = 0;
    }

    /** @return list<string> */
    private static function publicPrefixesWithoutProbe(): array
    {
        return [
            '/noticias',
            '/showroom',
            '/neutral-sitemap.xml',
            '/module-media',
        ];
    }
}

final class ApplicationPublicModuleRouteTest extends TestCase
{
    private string $fixtureRoot;
    private string $coreRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-app-public-route-'
            . bin2hex(random_bytes(8));
        $this->coreRoot = $this->fixtureRoot . '/core';
        $this->filesystem->mkdir([
            $this->fixtureRoot . '/App/app',
            $this->fixtureRoot . '/App/config/enums',
            $this->fixtureRoot . '/App/config/languages/global',
            $this->fixtureRoot . '/App/config/languages/404',
            $this->fixtureRoot . '/App/config/routes',
            $this->fixtureRoot . '/App/views',
            $this->fixtureRoot . '/public',
            $this->fixtureRoot . '/sessions',
            $this->coreRoot . '/modules/blog',
        ]);

        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/composer.json',
            json_encode(
                ['require' => ['liquidstack/blog' => '*']],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
        $this->filesystem->dumpFile(
            $this->coreRoot . '/modules/blog/module.json',
            json_encode([
                'schema' => 1,
                'id' => 'blog',
                'package' => 'liquidstack/blog',
                'requires' => [],
                'providers' => [
                    'routes' => [ApplicationPublicRouteProviderFixture::class],
                ],
                'project_files' => [],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/config.php',
            "<?php\n"
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/enums/_roles.php',
            "<?php\n"
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/langs.php',
            "<?php\nreturn ['es'];\n"
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/languages/global/es.json',
            "{}\n"
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/languages/404/es.json',
            "{}\n"
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/views/404.php',
            "<?php echo 'legacy-404';\n"
        );
        $this->writeGetRoutes([]);
        $this->writePostRoutes([]);

        self::assertTrue(ini_set(
            'session.save_path',
            $this->fixtureRoot . '/sessions'
        ) !== false);
        self::assertTrue(ini_set('session.use_cookies', '0') !== false);
        self::assertTrue(ini_set('session.cache_limiter', '') !== false);
        session_id('liquidstack-' . bin2hex(random_bytes(8)));

        $_COOKIE = [];
        $_GET = [];
        $_POST = [];
        $_ENV = [
            'LANG_DEFAULT' => 'es',
            'MULTILANG' => '0',
            'ES_SIMPLIFICADO' => '1',
            'DEV_MODE' => '1',
        ];
        ApplicationPublicRouteProviderFixture::reset();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $this->filesystem->remove($this->fixtureRoot);
        Paths::setProjectRoot(dirname(__DIR__));

        parent::tearDown();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStaticGetWinsAfterCheapPublicPrefixClaim(): void
    {
        $view = $this->fixtureRoot . '/App/views/static.php';
        $this->filesystem->dumpFile($view, "<?php echo 'static-get';\n");
        $this->writeGetRoutes([
            '/noticias/fija' => ['view' => $view],
        ]);

        self::assertSame('static-get', $this->runApplication('GET', '/noticias/fija'));
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertSame(1, ApplicationPublicRouteProviderFixture::$prefixReads);
        self::assertSame(0, ApplicationPublicRouteProviderFixture::$constructions);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStaticGetCanStrictlyOptOutOfLegacySession(): void
    {
        $view = $this->fixtureRoot . '/App/views/sessionless.php';
        $this->filesystem->dumpFile(
            $view,
            "<?php echo session_status() === PHP_SESSION_NONE "
                . "? 'sessionless' : 'session-active';\n"
        );
        $this->writeGetRoutes([
            '/noticias/sessionless' => [
                'view' => $view,
                'session' => false,
            ],
        ]);

        self::assertSame(
            'sessionless',
            $this->runApplication('GET', '/noticias/sessionless')
        );
        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame(1, ApplicationPublicRouteProviderFixture::$prefixReads);
        self::assertSame(0, ApplicationPublicRouteProviderFixture::$constructions);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnclaimedStaticRouteKeepsLegacySessionBootstrap(): void
    {
        $view = $this->fixtureRoot . '/App/views/legacy-session.php';
        $this->filesystem->dumpFile(
            $view,
            "<?php echo session_status() === PHP_SESSION_ACTIVE "
                . "? 'session-active' : 'sessionless';\n"
        );
        $this->writeGetRoutes([
            '/contacto' => [
                'view' => $view,
                'session' => false,
            ],
        ]);

        self::assertSame(
            'session-active',
            $this->runApplication('GET', '/contacto')
        );
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertSame(1, ApplicationPublicRouteProviderFixture::$prefixReads);
        self::assertSame(0, ApplicationPublicRouteProviderFixture::$constructions);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnclaimedRouteCatalogueStillLoadsAfterSessionStart(): void
    {
        $marker = $this->fixtureRoot . '/route-session.marker';
        $view = $this->fixtureRoot . '/App/views/contacto.php';
        $this->filesystem->dumpFile($view, "<?php echo 'contacto';\n");
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/routes/get.php',
            "<?php\nfile_put_contents("
                . var_export($marker, true)
                . ", session_status() === PHP_SESSION_ACTIVE "
                . "? 'active' : 'none');\nreturn ['es' => ["
                . "'/contacto' => ['view' => "
                . var_export($view, true)
                . "]]];\n"
        );

        self::assertSame(
            'contacto',
            $this->runApplication('GET', '/contacto')
        );
        self::assertSame('active', file_get_contents($marker));
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOnlyLiteralFalseDisablesStaticRouteSession(): void
    {
        $view = $this->fixtureRoot . '/App/views/session-default.php';
        $this->filesystem->dumpFile(
            $view,
            "<?php echo session_status() === PHP_SESSION_ACTIVE "
                . "? 'session-active' : 'sessionless';\n"
        );
        $this->writeGetRoutes([
            '/noticias/fija' => [
                'view' => $view,
                'session' => 0,
            ],
        ]);

        self::assertSame(
            'session-active',
            $this->runApplication('GET', '/noticias/fija')
        );
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertSame(0, ApplicationPublicRouteProviderFixture::$constructions);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExactStaticGetWinsOverPreBootstrapPublicRoute(): void
    {
        $view = $this->fixtureRoot . '/App/views/static-sitemap.php';
        $this->filesystem->dumpFile($view, "<?php echo 'static-sitemap';\n");
        $this->writeGetRoutes([
            '/neutral-sitemap.xml' => ['view' => $view],
        ]);

        self::assertSame(
            'static-sitemap',
            $this->runApplication('GET', '/neutral-sitemap.xml')
        );
        self::assertSame(1, ApplicationPublicRouteProviderFixture::$prefixReads);
        self::assertSame(0, ApplicationPublicRouteProviderFixture::$constructions);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPublicGetBeatsMultilangRedirectAndSession(): void
    {
        $configMarker = $this->fixtureRoot . '/legacy-config.marker';
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/config.php',
            "<?php file_put_contents("
                . var_export($configMarker, true)
                . ", 'loaded');\n"
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/langs.php',
            "<?php\nreturn ['es', 'en'];\n"
        );
        $_ENV['MULTILANG'] = '1';
        $_ENV['ES_SIMPLIFICADO'] = '1';

        self::assertSame(
            'pre-bootstrap-public',
            $this->runApplication('GET', '/neutral-sitemap.xml')
        );
        self::assertSame(200, http_response_code());
        self::assertFileDoesNotExist($configMarker);
        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame(1, ApplicationPublicRouteProviderFixture::$handlerCalls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPreBootstrapHeadKeepsStatusWithoutBodyOrSession(): void
    {
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/langs.php',
            "<?php\nreturn ['es', 'en'];\n"
        );
        $_ENV['MULTILANG'] = '1';
        $_ENV['ES_SIMPLIFICADO'] = '1';

        self::assertSame(
            '',
            $this->runApplication('HEAD', '/neutral-sitemap.xml')
        );
        self::assertSame(200, http_response_code());
        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame(1, ApplicationPublicRouteProviderFixture::$handlerCalls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPreBootstrapMediaPrefixAvoidsLanguageRedirectAndSession(): void
    {
        $configMarker = $this->fixtureRoot . '/legacy-config.marker';
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/config.php',
            "<?php file_put_contents("
                . var_export($configMarker, true)
                . ", 'loaded');\n"
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/langs.php',
            "<?php\nreturn ['es', 'en'];\n"
        );
        $_ENV['MULTILANG'] = '1';
        $_ENV['ES_SIMPLIFICADO'] = '1';

        self::assertSame(
            'pre-bootstrap-media',
            $this->runApplication('GET', '/module-media/image.avif')
        );
        self::assertSame(200, http_response_code());
        self::assertFileDoesNotExist($configMarker);
        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame(1, ApplicationPublicRouteProviderFixture::$handlerCalls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPreBootstrapMediaHeadKeepsStatusWithoutBodyOrSession(): void
    {
        self::assertSame(
            '',
            $this->runApplication('HEAD', '/module-media/image.avif')
        );
        self::assertSame(200, http_response_code());
        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame(1, ApplicationPublicRouteProviderFixture::$handlerCalls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMalformedPreBootstrapMediaAvoidsRedirectAndSession(): void
    {
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/langs.php',
            "<?php\nreturn ['es', 'en'];\n"
        );
        $_ENV['MULTILANG'] = '1';
        $_ENV['ES_SIMPLIFICADO'] = '1';

        self::assertSame(
            'Not found',
            $this->runApplication('GET', '/module-media/malformed')
        );
        self::assertSame(404, http_response_code());
        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame(1, ApplicationPublicRouteProviderFixture::$handlerCalls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPublicFileBlocksThePreBootstrapClaim(): void
    {
        $configMarker = $this->fixtureRoot . '/legacy-config.marker';
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/config.php',
            "<?php file_put_contents("
                . var_export($configMarker, true)
                . ", 'loaded');\n"
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/public/neutral-sitemap.xml',
            '<project-sitemap/>'
        );

        self::assertSame(
            'legacy-404',
            $this->runApplication('GET', '/neutral-sitemap.xml')
        );
        self::assertFileExists($configMarker);
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertSame(0, ApplicationPublicRouteProviderFixture::$constructions);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPublicMediaFileBlocksThePreBootstrapPrefixClaim(): void
    {
        $this->filesystem->mkdir(
            $this->fixtureRoot . '/public/module-media'
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/public/module-media/image.avif',
            'project-media'
        );

        self::assertSame(
            'legacy-404',
            $this->runApplication('GET', '/module-media/image.avif')
        );
        self::assertSame(404, http_response_code());
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertSame(0, ApplicationPublicRouteProviderFixture::$constructions);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIncompleteGetCatalogueFallsBackToLatePublicRouting(): void
    {
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/routes/get.php',
            <<<'PHP'
<?php
$dynamic = '/project-owned';
return ['es' => [$dynamic => ['view' => 'dynamic.php']]];
PHP
        );

        self::assertSame(
            'pre-bootstrap-public',
            $this->runApplication('GET', '/neutral-sitemap.xml')
        );
        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame(1, ApplicationPublicRouteProviderFixture::$handlerCalls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPreBootstrapClaimMustAlsoBelongToPublicPrefixes(): void
    {
        self::assertSame(
            'Service unavailable',
            $this->runApplication('GET', '/orphan-sitemap.xml')
        );
        self::assertSame(503, http_response_code());
        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame(0, ApplicationPublicRouteProviderFixture::$constructions);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testShowroomDynamicRouteWinsBeforePublicModule(): void
    {
        $view = $this->fixtureRoot . '/App/views/_showroom.php';
        $this->filesystem->dumpFile($view, "<?php echo 'showroom-media';\n");
        $this->writeGetRoutes([
            '/showroom' => [
                'view' => $view,
                'resources' => 'templates',
            ],
        ]);

        self::assertSame('showroom-media', $this->runApplication('GET', '/showroom/media'));
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertSame(1, ApplicationPublicRouteProviderFixture::$prefixReads);
        self::assertSame(0, ApplicationPublicRouteProviderFixture::$constructions);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStaticPostWinsBeforeRecognizedPublicPrefix(): void
    {
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/app/static-post.php',
            "<?php echo 'static-post';\n"
        );
        $this->writePostRoutes([
            '/noticias/fija' => 'static-post.php',
        ]);

        self::assertSame('static-post', $this->runApplication('POST', '/noticias/fija'));
        self::assertSame(0, ApplicationPublicRouteProviderFixture::$prefixReads);
        self::assertSame(0, ApplicationPublicRouteProviderFixture::$constructions);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLatePublicGetIsDispatchedAfterStaticMiss(): void
    {
        self::assertSame(
            'late-public',
            $this->runApplication('GET', '/noticias/dinamica')
        );
        self::assertSame(200, http_response_code());
        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame(1, ApplicationPublicRouteProviderFixture::$handlerCalls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLatePublicHeadEmitsNoBodyAndKeepsStatus(): void
    {
        self::assertSame('', $this->runApplication('HEAD', '/noticias/dinamica'));
        self::assertSame(200, http_response_code());
        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame(1, ApplicationPublicRouteProviderFixture::$handlerCalls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLatePublicFailureStaysClosedWithoutLegacySession(): void
    {
        self::assertNotFalse(ini_set(
            'error_log',
            $this->fixtureRoot . '/public-failure.log'
        ));

        self::assertSame(
            'Service unavailable',
            $this->runApplication('GET', '/noticias/failure')
        );
        self::assertSame(503, http_response_code());
        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertStringNotContainsString(
            'fixture failure detail',
            (string) file_get_contents(
                $this->fixtureRoot . '/public-failure.log'
            )
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRecognizedPublicPostEmits405WithoutConstructingProvider(): void
    {
        self::assertSame(
            'Method not allowed',
            $this->runApplication('POST', '/noticias/dinamica')
        );
        self::assertSame(405, http_response_code());
        self::assertSame(0, ApplicationPublicRouteProviderFixture::$constructions);
        self::assertSame(0, ApplicationPublicRouteProviderFixture::$registrations);
        self::assertSame(0, ApplicationPublicRouteProviderFixture::$handlerCalls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnrelatedAndFallenThroughUrlsKeepTheExisting404(): void
    {
        self::assertSame('legacy-404', $this->runApplication('GET', '/contacto'));
        self::assertSame(404, http_response_code());
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertSame(0, ApplicationPublicRouteProviderFixture::$constructions);
        self::assertSame(0, ApplicationPublicRouteProviderFixture::$handlerCalls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMatchedHandlerMayFallThroughToTheExisting404(): void
    {
        self::assertSame(
            'legacy-404',
            $this->runApplication('GET', '/noticias/missing')
        );
        self::assertSame(404, http_response_code());
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertSame(1, ApplicationPublicRouteProviderFixture::$handlerCalls);
    }

    private function runApplication(string $method, string $uri): string
    {
        $_SERVER = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => '',
            'HTTPS' => 'on',
        ];

        ob_start();
        (new Application($this->fixtureRoot, $this->coreRoot))->run();

        return (string) ob_get_clean();
    }

    /** @param array<string, array<string, mixed>> $routes */
    private function writeGetRoutes(array $routes): void
    {
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/routes/get.php',
            "<?php\nreturn ['es' => " . var_export($routes, true) . "];\n"
        );
    }

    /** @param array<string, string> $routes */
    private function writePostRoutes(array $routes): void
    {
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/routes/post.php',
            "<?php\nreturn " . var_export($routes, true) . ";\n"
        );
    }
}
