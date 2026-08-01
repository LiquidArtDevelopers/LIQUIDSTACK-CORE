<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\ModuleRouteCollection;
use App\Core\Routing\ModuleRouteDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ModuleNeutralRouteTest extends TestCase
{
    private string $fixtureRoot;
    private Filesystem $filesystem;
    private string $previousErrorLog;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->previousErrorLog = (string) ini_get('error_log');
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-neutral-route-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->fixtureRoot . '/App/config');
        self::assertNotFalse(ini_set(
            'error_log',
            $this->fixtureRoot . '/webadmin-test-errors.log'
        ));
        $this->writeComposer(true);
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/langs.php',
            "<?php\nreturn ['es', 'en'];\n"
        );
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testEnabledWebadminOwnsItsNeutralPrefix(): void
    {
        $response = $this->dispatch('GET', '/admin/login');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(503, $response->status());
        self::assertSame('Service unavailable', $response->body());
        self::assertSame(
            'no-store, no-cache, must-revalidate, max-age=0',
            $response->headers()['Cache-Control']
        );
        self::assertSame(
            'noindex, nofollow, noarchive',
            $response->headers()['X-Robots-Tag']
        );
    }

    public function testUnknownPathAndWrongMethodUseModuleResponses(): void
    {
        $notFound = $this->dispatch('GET', '/admin/unknown');
        self::assertInstanceOf(Response::class, $notFound);
        self::assertSame(404, $notFound->status());
        self::assertSame('Not found', $notFound->body());
        self::assertSame(
            'noindex, nofollow, noarchive',
            $notFound->headers()['X-Robots-Tag']
        );

        $notAllowed = $this->dispatch('POST', '/admin');
        self::assertInstanceOf(Response::class, $notAllowed);
        self::assertSame(405, $notAllowed->status());
        self::assertSame('GET, HEAD', $notAllowed->headers()['Allow']);

        $options = $this->dispatch('OPTIONS', '/admin');
        self::assertInstanceOf(Response::class, $options);
        self::assertSame(405, $options->status());
        self::assertSame('GET, HEAD', $options->headers()['Allow']);
    }

    public function testHeadUsesGetWithoutReturningABody(): void
    {
        $response = $this->dispatch('HEAD', '/admin/login');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(503, $response->status());
        self::assertSame('', $response->body());

        $notFound = $this->dispatch('HEAD', '/admin/unknown');
        self::assertInstanceOf(Response::class, $notFound);
        self::assertSame(404, $notFound->status());
        self::assertSame('', $notFound->body());
    }

    public function testPrefixMatchingRequiresASegmentBoundary(): void
    {
        self::assertNull($this->dispatch('GET', '/administrator'));
        self::assertNull($this->dispatch('GET', '/es/admin'));
    }

    public function testTrailingSlashRemainsInsideTheNeutralRoot(): void
    {
        $response = $this->dispatch('GET', '/admin/');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(303, $response->status());
        self::assertSame('/admin/login', $response->headers()['Location']);
    }

    public function testInvalidEncodedSeparatorCannotReachAHandler(): void
    {
        $response = $this->dispatch('GET', '/admin%2Fanything');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(404, $response->status());
    }

    public function testDisabledModuleDoesNotReserveAdmin(): void
    {
        $this->writeComposer(false);

        self::assertNull($this->dispatch('GET', '/admin'));
    }

    public function testProjectCanConfigureANeutralPrefix(): void
    {
        $this->filesystem->mkdir($this->fixtureRoot . '/App/config/modules');
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/modules/webadmin.php',
            "<?php\nreturn ['path' => '/gestion-web'];\n"
        );

        self::assertNull($this->dispatch('GET', '/admin'));
        self::assertSame(
            503,
            $this->dispatch('GET', '/gestion-web/login')->status()
        );
    }

    public function testInvalidLocalizedConfigFallsBackWithoutBreakingPublicRoutes(): void
    {
        $this->filesystem->mkdir($this->fixtureRoot . '/App/config/modules');
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/modules/webadmin.php',
            "<?php\nreturn ['path' => '/es/admin'];\n"
        );

        self::assertNull($this->dispatch('GET', '/public-page'));
        self::assertNull($this->dispatch('GET', '/es/admin'));

        $response = $this->dispatch('GET', '/admin/login');
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(503, $response->status());
        self::assertSame('Service unavailable', $response->body());
        self::assertSame(
            'no-store, no-cache, must-revalidate, max-age=0',
            $response->headers()['Cache-Control']
        );
    }

    public function testCustomPrefixCollisionPreservesPublicRouteAndUsesAdminFallback(): void
    {
        $this->filesystem->mkdir([
            $this->fixtureRoot . '/App/config/modules',
            $this->fixtureRoot . '/App/config/routes',
        ]);
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/modules/webadmin.php',
            "<?php\nreturn ['path' => '/contacto'];\n"
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/routes/get.php',
            "<?php\nreturn ['es' => ['/contacto' => ['view' => 'contacto.php']]];\n"
        );

        self::assertNull($this->dispatch('GET', '/contacto'));
        self::assertNull($this->dispatch('POST', '/contacto'));

        $fallback = $this->dispatch('GET', '/admin/login');
        self::assertInstanceOf(Response::class, $fallback);
        self::assertSame(503, $fallback->status());
    }

    public function testDefaultPrefixCollisionLeavesTheExistingRouteUntouched(): void
    {
        $this->filesystem->mkdir(
            $this->fixtureRoot . '/App/config/routes'
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/routes/post.php',
            "<?php\nreturn ['/admin/action' => 'legacy-admin.php'];\n"
        );

        self::assertNull($this->dispatch('GET', '/admin'));
        self::assertNull($this->dispatch('POST', '/admin/action'));
        self::assertNull($this->dispatch('GET', '/public-page'));
    }

    public function testUnavailableResponseUsesDefensiveHeaders(): void
    {
        $response = $this->dispatch('GET', '/admin/login');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(
            "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
            $response->headers()['Content-Security-Policy']
        );
        self::assertSame(
            'nosniff',
            $response->headers()['X-Content-Type-Options']
        );
        self::assertSame('DENY', $response->headers()['X-Frame-Options']);
        self::assertArrayNotHasKey('Set-Cookie', $response->headers());
    }

    public function testInsecureAdminRequestFailsBeforeRuntimeCreation(): void
    {
        $response = $this->dispatcher()->dispatch(Request::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/login',
        ]));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(400, $response->status());
        self::assertSame('Bad request', $response->body());
        self::assertArrayNotHasKey('Set-Cookie', $response->headers());
    }

    public function testCollectionRejectsDuplicateClaimsAndRoutes(): void
    {
        $routes = new ModuleRouteCollection();
        $notFound = static fn (Request $request): Response => new Response(404);
        $notAllowed = static fn (Request $request, array $allowed): Response => new Response(405);
        $handler = static fn (Request $request): Response => new Response(200);

        $routes->claimPrefix('webadmin', '/admin', $notFound, $notAllowed);
        $routes->add('webadmin', 'GET', '/admin', $handler);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ya está registrada');

        $routes->add('webadmin', 'GET', '/admin', $handler);
    }

    private function dispatcher(): ModuleRouteDispatcher
    {
        return ModuleRouteDispatcher::forProject(
            $this->fixtureRoot,
            [],
            dirname(__DIR__, 2)
        );
    }

    private function dispatch(string $method, string $uri): ?Response
    {
        return $this->dispatcher()->dispatch(Request::fromServer([
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'HTTPS' => 'on',
        ]));
    }

    private function writeComposer(bool $webadmin): void
    {
        $requirements = ['liquidstack/core' => '^1.9'];
        if ($webadmin) {
            $requirements['liquidstack/webadmin'] = '*';
        }

        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/composer.json',
            json_encode(
                ['require' => $requirements],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
    }
}
