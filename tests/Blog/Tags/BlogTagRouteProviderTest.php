<?php

declare(strict_types=1);

namespace Tests\Blog\Tags;

use App\Core\Blog\Http\BlogAdminHttpRuntimeException;
use App\Core\Blog\Http\BlogAdminHttpRuntimeFactoryInterface;
use App\Core\Blog\Http\BlogAdminHttpRuntimeInterface;
use App\Core\Blog\Http\BlogAdminRuntimeIssueReporterInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\Blog\BlogTagRouteProvider;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Routing\ModuleRouteCollection;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class UnavailableTagRuntimeFactory implements
    BlogAdminHttpRuntimeFactoryInterface
{
    public int $calls = 0;

    public function create(
        ModuleRuntimeContext $context,
        WebAdminConfig $webAdminConfig
    ): BlogAdminHttpRuntimeInterface {
        ++$this->calls;

        throw new BlogAdminHttpRuntimeException('blog.tags.schema_not_ready');
    }
}

final class CapturingTagIssueReporter implements
    BlogAdminRuntimeIssueReporterInterface
{
    /** @var list<string> */
    public array $issues = [];

    public function report(string $issueCode): void
    {
        $this->issues[] = $issueCode;
    }
}

final class BlogTagRouteProviderTest extends TestCase
{
    private string $projectRoot;
    private Filesystem $filesystem;
    private UnavailableTagRuntimeFactory $factory;
    private CapturingTagIssueReporter $reporter;
    private ModuleRouteCollection $routes;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-blog-tag-routes-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectRoot . '/App/config');
        $this->factory = new UnavailableTagRuntimeFactory();
        $this->reporter = new CapturingTagIssueReporter();
        $this->routes = new ModuleRouteCollection();
        $this->claimParents();
        (new BlogTagRouteProvider(
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

    public function testRegistersOnlyTheBoundedPostMutation(): void
    {
        $anonymous = $this->routes->dispatch($this->post());
        self::assertSame(303, $anonymous?->status());
        self::assertSame('/admin/login', $anonymous?->headers()['Location']);
        self::assertSame(0, $this->factory->calls);

        $get = $this->routes->dispatch(Request::fromInput([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/blog/tags/assign',
            'HTTPS' => 'on',
        ]));
        self::assertSame(405, $get?->status());
        self::assertSame('POST', $get?->headers()['Allow']);

        $unknown = $this->routes->dispatch(Request::fromInput([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/blog/tags/catalog',
            'HTTPS' => 'on',
        ]));
        self::assertSame(404, $unknown?->status());
        self::assertSame(0, $this->factory->calls);
    }

    public function testAuthenticatedPendingRuntimeFailsClosed(): void
    {
        $pending = $this->routes->dispatch($this->post(str_repeat('S', 43)));
        self::assertSame(503, $pending?->status());
        self::assertSame('Service unavailable', $pending?->body());
        self::assertSame(1, $this->factory->calls);
        self::assertSame(
            ['blog.tags.schema_not_ready'],
            $this->reporter->issues
        );
    }

    private function post(?string $session = null): Request
    {
        return Request::fromInput([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/blog/tags/assign',
            'HTTPS' => 'on',
            'REMOTE_ADDR' => '192.0.2.60',
        ], form: [
            'csrf' => str_repeat('A', 43),
            'post' => '90000000-0000-4000-8000-000000000001',
            'locale' => 'es',
            'lock_version' => '1',
            'tag_workspace_version' => '0',
            'tags' => 'Ahorro',
        ], cookies: $session === null ? [] : [
            'LS_WEBADMIN_SID' => $session,
        ], headers: [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => 'Tag route test browser',
        ]);
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
}
