<?php

declare(strict_types=1);

use App\Core\Commerce\Http\CommerceAdminHttpRuntimeFactoryInterface;
use App\Core\Commerce\Http\CommerceAdminHttpRuntimeInterface;
use App\Core\Commerce\Http\CommerceAdminHttpRuntimeException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\Commerce\CommerceRouteProvider;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Routing\ModuleRouteCollection;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class UnavailableCommerceAdminRuntimeFactory implements
    CommerceAdminHttpRuntimeFactoryInterface
{
    public int $calls = 0;

    public function create(
        ModuleRuntimeContext $context,
        WebAdminConfig $webAdminConfig
    ): CommerceAdminHttpRuntimeInterface {
        ++$this->calls;
        throw new CommerceAdminHttpRuntimeException(
            'commerce.schema_not_ready'
        );
    }
}

final class CommerceAdminHttpTest extends TestCase
{
    private Filesystem $filesystem;
    private string $projectRoot;
    private ModuleRouteCollection $routes;
    private UnavailableCommerceAdminRuntimeFactory $factory;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-commerce-admin-' . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectRoot . '/App/config/routes');
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/langs.php',
            "<?php\nreturn ['es', 'eu'];\n"
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/routes/get.php',
            "<?php\nreturn [];\n"
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/routes/post.php',
            "<?php\nreturn [];\n"
        );
        $this->routes = new ModuleRouteCollection();
        $notFound = static fn (Request $request): Response =>
            new Response(404, 'not-found');
        $methodNotAllowed = static fn (Request $request, array $allowed): Response =>
            new Response(405, '', ['Allow' => implode(', ', $allowed)]);
        $this->routes->claimPrefix(
            'webadmin',
            '/admin',
            $notFound,
            $methodNotAllowed
        );
        $this->factory = new UnavailableCommerceAdminRuntimeFactory();
        (new CommerceRouteProvider(runtimeFactory: $this->factory))
            ->registerRoutes(
                $this->routes,
                new ModuleRuntimeContext($this->projectRoot)
            );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
    }

    public function testRegistersBoundedPrivateSurfaceAndDefersRuntime(): void
    {
        foreach ([
            '/admin/commerce',
            '/admin/commerce/products/new',
            '/admin/commerce/taxonomies',
            '/admin/commerce/inquiries',
        ] as $path) {
            $response = $this->routes->dispatch($this->get($path));
            self::assertSame(303, $response?->status(), $path);
            self::assertSame('/admin/login', $response?->headers()['Location'] ?? null);
        }
        $edit = $this->routes->dispatch($this->get(
            '/admin/commerce/products/edit',
            [
                'product' => '11111111-1111-4111-8111-111111111111',
                'locale' => 'es',
            ]
        ));
        self::assertSame(303, $edit?->status());
        self::assertSame(0, $this->factory->calls);

        self::assertSame(404, $this->routes->dispatch(
            $this->get('/admin/commerce/unknown')
        )?->status());
        $wrong = $this->routes->dispatch($this->get(
            '/admin/commerce/products/create'
        ));
        self::assertSame(405, $wrong?->status());
        self::assertSame('POST', $wrong?->headers()['Allow'] ?? null);
    }

    public function testValidCookieBuildsRuntimeButMalformedInputDoesNot(): void
    {
        $pending = $this->routes->dispatch($this->get(
            '/admin/commerce',
            [],
            str_repeat('A', 43)
        ));
        self::assertSame(503, $pending?->status());
        self::assertSame(1, $this->factory->calls);

        $malformed = $this->routes->dispatch($this->post(
            '/admin/commerce/taxonomies/tags/create',
            [
                'csrf' => str_repeat('B', 43),
                'name' => 'Furgonetas',
                'slug' => 'furgonetas',
                'unexpected' => '1',
            ],
            str_repeat('A', 43)
        ));
        self::assertSame(400, $malformed?->status());
        self::assertSame(1, $this->factory->calls);
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = [], ?string $cookie = null): Request
    {
        return Request::fromInput([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
            'HTTPS' => 'on',
        ], query: $query, cookies: $cookie === null
            ? [] : ['LS_WEBADMIN_SID' => $cookie]);
    }

    /** @param array<string, string> $form */
    private function post(string $path, array $form, string $cookie): Request
    {
        return Request::fromInput([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => $path,
            'HTTPS' => 'on',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], form: $form, cookies: ['LS_WEBADMIN_SID' => $cookie]);
    }
}
