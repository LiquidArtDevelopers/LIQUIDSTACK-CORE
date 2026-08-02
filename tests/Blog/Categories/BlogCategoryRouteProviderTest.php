<?php

declare(strict_types=1);

namespace Tests\Blog\Categories;

use App\Core\Blog\Http\BlogAdminHttpRuntimeException;
use App\Core\Blog\Http\BlogAdminRuntimeIssueReporterInterface;
use App\Core\Blog\Http\BlogCategoryAdminHttpRuntimeFactoryInterface;
use App\Core\Blog\Http\BlogCategoryAdminHttpRuntimeInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\Blog\BlogCategoryRouteProvider;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Routing\ModuleRouteCollection;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class UnavailableCategoryRuntimeFactory implements
    BlogCategoryAdminHttpRuntimeFactoryInterface
{
    public int $calls = 0;

    public function create(
        ModuleRuntimeContext $context,
        WebAdminConfig $webAdminConfig
    ): BlogCategoryAdminHttpRuntimeInterface {
        ++$this->calls;

        throw new BlogAdminHttpRuntimeException(
            'blog.categories.schema_not_ready'
        );
    }
}

final class CapturingCategoryIssueReporter implements
    BlogAdminRuntimeIssueReporterInterface
{
    /** @var list<string> */
    public array $issues = [];

    public function report(string $issueCode): void
    {
        $this->issues[] = $issueCode;
    }
}

final class BlogCategoryRouteProviderTest extends TestCase
{
    private string $projectRoot;
    private Filesystem $filesystem;
    private UnavailableCategoryRuntimeFactory $factory;
    private CapturingCategoryIssueReporter $reporter;
    private ModuleRouteCollection $routes;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-blog-category-routes-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectRoot . '/App/config');
        $this->factory = new UnavailableCategoryRuntimeFactory();
        $this->reporter = new CapturingCategoryIssueReporter();
        $this->routes = new ModuleRouteCollection();
        $this->claimParents();
        (new BlogCategoryRouteProvider(
            runtimeFactory: $this->factory,
            issueReporter: $this->reporter
        ))->registerRoutes(
            $this->routes,
            new ModuleRuntimeContext($this->projectRoot)
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
    }

    public function testRegistersTheBoundedCategorySurface(): void
    {
        foreach ([
            '/admin/blog/categories',
            '/admin/blog/categories/new',
            '/admin/blog/categories/edit',
            '/admin/blog/categories/assign',
            '/admin/blog/categories/updated',
        ] as $path) {
            $query = match ($path) {
                '/admin/blog/categories/edit' => [
                    'category' => $this->uuid('1'),
                    'locale' => 'es',
                ],
                '/admin/blog/categories/assign' => [
                    'post' => $this->uuid('2'),
                    'locale' => 'es',
                ],
                default => [],
            };
            $response = $this->routes->dispatch($this->get($path, $query));
            self::assertNotNull($response);
            self::assertSame(303, $response->status(), $path);
            self::assertSame('/admin/login', $response->headers()['Location']);
        }

        self::assertSame(404, $this->routes->dispatch(
            $this->get('/admin/blog/categories/')
        )?->status());
        self::assertSame(404, $this->routes->dispatch(
            $this->get('/admin/blog/categories/delete')
        )?->status());
        self::assertSame(0, $this->factory->calls);
    }

    public function testGetAndHeadShareAnonymousAndPendingFeatureStatus(): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            $anonymous = $this->routes->dispatch(Request::fromInput([
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => '/admin/blog/categories',
                'HTTPS' => 'on',
            ]));
            self::assertNotNull($anonymous);
            self::assertSame(303, $anonymous->status());
            self::assertSame('', $anonymous->body());
            self::assertSame(
                '/admin/login',
                $anonymous->headers()['Location']
            );

            $pending = $this->routes->dispatch(Request::fromInput([
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => '/admin/blog/categories',
                'HTTPS' => 'on',
            ], cookies: [
                'LS_WEBADMIN_SID' => str_repeat('A', 43),
            ]));
            self::assertNotNull($pending);
            self::assertSame(503, $pending->status());
            self::assertSame(
                $method === 'HEAD' ? '' : 'Service unavailable',
                $pending->body()
            );
        }

        self::assertSame(2, $this->factory->calls);
        self::assertSame([
            'blog.categories.schema_not_ready',
            'blog.categories.schema_not_ready',
        ], $this->reporter->issues);
    }

    public function testWrongMethodsFailBeforeRuntime(): void
    {
        $wrong = $this->routes->dispatch(Request::fromServer([
            'REQUEST_METHOD' => 'DELETE',
            'REQUEST_URI' => '/admin/blog/categories/create',
            'HTTPS' => 'on',
        ]));
        self::assertNotNull($wrong);
        self::assertSame(405, $wrong->status());
        self::assertSame('POST', $wrong->headers()['Allow']);
        self::assertSame(0, $this->factory->calls);
    }

    /** @param array<string, string> $query */
    private function get(
        string $path,
        array $query = [],
        ?string $session = null
    ): Request {
        return Request::fromInput([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
            'HTTPS' => 'on',
        ], query: $query, cookies: $session === null
            ? []
            : ['LS_WEBADMIN_SID' => $session]);
    }

    private function claimParents(): void
    {
        $notFound = static fn (Request $request): Response =>
            new Response(404, 'not-found');
        $methodNotAllowed = static fn (
            Request $request,
            array $allowed
        ): Response => new Response(405, '', [
            'Allow' => implode(', ', $allowed),
        ]);
        $this->routes->claimPrefix(
            'webadmin',
            '/admin',
            $notFound,
            $methodNotAllowed
        );
        $this->routes->claimChildPrefix(
            'blog',
            'webadmin',
            '/admin',
            '/admin/blog',
            $notFound,
            $methodNotAllowed
        );
    }

    private function uuid(string $prefix): string
    {
        return $prefix . '1111111-1111-4111-8111-111111111111';
    }
}
