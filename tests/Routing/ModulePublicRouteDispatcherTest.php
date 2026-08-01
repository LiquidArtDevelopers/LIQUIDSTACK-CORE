<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\ModulePublicRouteProviderInterface;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Routing\ModulePublicRouteCollection;
use App\Core\Routing\ModulePublicRouteDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class LatePublicRouteProviderFixture implements ModulePublicRouteProviderInterface
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

        return ['/noticias'];
    }

    public function registerPublicRoutes(
        ModulePublicRouteCollection $routes,
        ModuleRuntimeContext $context
    ): void {
        self::$registrations++;
        $routes->addGet(
            self::moduleId(),
            '/noticias',
            static function (Request $request): ?Response {
                self::$handlerCalls++;

                if ($request->path() === '/noticias/failure') {
                    throw new RuntimeException('internal fixture detail');
                }
                if ($request->path() === '/noticias/missing') {
                    return null;
                }

                return new Response(
                    200,
                    'public-blog',
                    ['X-Public-Route' => 'blog']
                );
            }
        );
    }

    public static function reset(): void
    {
        self::$prefixReads = 0;
        self::$constructions = 0;
        self::$registrations = 0;
        self::$handlerCalls = 0;
    }
}

final class ModulePublicRouteDispatcherTest extends TestCase
{
    private string $fixtureRoot;
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-public-route-'
            . bin2hex(random_bytes(8));
        $this->projectRoot = $this->fixtureRoot . '/project';
        $this->filesystem->mkdir([
            $this->projectRoot,
            $this->fixtureRoot . '/modules/blog',
        ]);
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode(
                ['require' => ['liquidstack/blog' => '*']],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/modules/blog/module.json',
            json_encode([
                'schema' => 1,
                'id' => 'blog',
                'package' => 'liquidstack/blog',
                'requires' => [],
                'providers' => [
                    'routes' => [LatePublicRouteProviderFixture::class],
                ],
                'project_files' => [],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
        LatePublicRouteProviderFixture::reset();
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testUnrelatedUrlDoesNotConstructRegisterOrRunProvider(): void
    {
        $response = $this->dispatch('GET', '/contacto');

        self::assertNull($response);
        self::assertSame(1, LatePublicRouteProviderFixture::$prefixReads);
        self::assertSame(0, LatePublicRouteProviderFixture::$constructions);
        self::assertSame(0, LatePublicRouteProviderFixture::$registrations);
        self::assertSame(0, LatePublicRouteProviderFixture::$handlerCalls);

        LatePublicRouteProviderFixture::reset();
        self::assertNull($this->dispatch('GET', '/noticias-extra'));
        self::assertSame(0, LatePublicRouteProviderFixture::$constructions);
    }

    public function testMatchedGetConstructsTheProviderLazily(): void
    {
        $response = $this->dispatch('GET', '/noticias/un-articulo');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->status());
        self::assertSame('public-blog', $response->body());
        self::assertSame(
            'blog',
            $response->headers()['X-Public-Route']
        );
        self::assertSame(1, LatePublicRouteProviderFixture::$constructions);
        self::assertSame(1, LatePublicRouteProviderFixture::$registrations);
        self::assertSame(1, LatePublicRouteProviderFixture::$handlerCalls);
    }

    public function testHeadUsesGetStatusAndHeadersWithoutBody(): void
    {
        $response = $this->dispatch('HEAD', '/noticias/un-articulo');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->status());
        self::assertSame('', $response->body());
        self::assertSame(
            'blog',
            $response->headers()['X-Public-Route']
        );
        self::assertSame(1, LatePublicRouteProviderFixture::$handlerCalls);
    }

    public function testRecognizedPostReturns405WithoutConstructingProvider(): void
    {
        $response = $this->dispatch('POST', '/noticias/un-articulo');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(405, $response->status());
        self::assertSame('GET, HEAD', $response->headers()['Allow']);
        self::assertSame(0, LatePublicRouteProviderFixture::$constructions);
        self::assertSame(0, LatePublicRouteProviderFixture::$registrations);
        self::assertSame(0, LatePublicRouteProviderFixture::$handlerCalls);
    }

    public function testHandlerCanFallThroughToTheExistingProject404(): void
    {
        self::assertNull($this->dispatch('GET', '/noticias/missing'));
        self::assertSame(1, LatePublicRouteProviderFixture::$handlerCalls);
    }

    public function testFailureIsContainedInsideTheMatchedPublicPrefix(): void
    {
        $previousErrorLog = (string) ini_get('error_log');
        self::assertNotFalse(ini_set(
            'error_log',
            $this->fixtureRoot . '/public-route-errors.log'
        ));

        try {
            $response = $this->dispatch('GET', '/noticias/failure');
        } finally {
            ini_set('error_log', $previousErrorLog);
        }

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(503, $response->status());
        self::assertSame('Service unavailable', $response->body());
        self::assertSame(
            'no-store, no-cache, must-revalidate, max-age=0',
            $response->headers()['Cache-Control']
        );
        self::assertStringNotContainsString(
            'internal fixture detail',
            (string) file_get_contents(
                $this->fixtureRoot . '/public-route-errors.log'
            )
        );
    }

    private function dispatch(string $method, string $path): ?Response
    {
        return ModulePublicRouteDispatcher::forProject(
            $this->projectRoot,
            [],
            $this->fixtureRoot
        )->dispatch(Request::fromServer([
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path,
            'HTTPS' => 'on',
        ]));
    }
}
