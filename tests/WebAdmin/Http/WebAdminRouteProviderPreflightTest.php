<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Modules\WebAdmin\WebAdminRouteProvider;
use App\Core\Routing\ModuleRouteCollection;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Http\WebAdminHttpRuntime;
use App\Core\WebAdmin\Http\WebAdminHttpRuntimeException;
use App\Core\WebAdmin\Http\WebAdminHttpRuntimeFactoryInterface;
use App\Core\WebAdmin\Http\WebAdminRuntimeIssueReporterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CountingUnavailableWebAdminRuntimeFactory implements
    WebAdminHttpRuntimeFactoryInterface
{
    public int $calls = 0;
    public ?Throwable $exception = null;

    public function create(
        ModuleRuntimeContext $context,
        WebAdminConfig $config
    ): WebAdminHttpRuntime {
        ++$this->calls;

        throw $this->exception
            ?? new RuntimeException('runtime creation intentionally blocked');
    }
}

final class CapturingWebAdminRuntimeIssueReporter implements
    WebAdminRuntimeIssueReporterInterface
{
    /** @var list<string> */
    public array $issueCodes = [];

    public function report(string $issueCode): void
    {
        $this->issueCodes[] = $issueCode;
    }
}

final class WebAdminRouteProviderPreflightTest extends TestCase
{
    private string $projectRoot;
    private Filesystem $filesystem;
    private CountingUnavailableWebAdminRuntimeFactory $factory;
    private CapturingWebAdminRuntimeIssueReporter $reporter;
    private ModuleRouteCollection $routes;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-webadmin-http-preflight-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectRoot . '/App/config');
        $this->factory = new CountingUnavailableWebAdminRuntimeFactory();
        $this->reporter = new CapturingWebAdminRuntimeIssueReporter();
        $this->routes = new ModuleRouteCollection();
        (new WebAdminRouteProvider(
            runtimeFactory: $this->factory,
            issueReporter: $this->reporter
        ))
            ->registerRoutes(
                $this->routes,
                new ModuleRuntimeContext($this->projectRoot)
            );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
    }

    public function testInsecureRequestNeverCreatesRuntime(): void
    {
        $response = $this->routes->dispatch(Request::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/login',
        ]));

        self::assertNotNull($response);
        self::assertSame(400, $response->status());
        self::assertSame(0, $this->factory->calls);
        self::assertSame([], $this->reporter->issueCodes);
    }

    public function testMalformedRequestNeverCreatesRuntime(): void
    {
        $response = $this->routes->dispatch(Request::fromInput(
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/admin/login?next=%2Fadmin',
                'HTTPS' => 'on',
            ],
            query: ['next' => '/admin']
        ));

        self::assertNotNull($response);
        self::assertSame(400, $response->status());
        self::assertSame(0, $this->factory->calls);
        self::assertSame([], $this->reporter->issueCodes);
    }

    public function testAnonymousRootRedirectNeedsNeitherPdoNorSchema(): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            $response = $this->routes->dispatch(Request::fromServer([
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => '/admin',
                'HTTPS' => 'on',
            ]));

            self::assertNotNull($response);
            self::assertSame(303, $response->status());
            self::assertSame('/admin/login', $response->headers()['Location']);
            self::assertSame('', $response->body());
        }
        self::assertSame(0, $this->factory->calls);
        self::assertSame([], $this->reporter->issueCodes);
    }

    public function testLoginWithoutPreAuthenticationCookieDoesNotOpenPdo(): void
    {
        $response = $this->routes->dispatch(Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/login',
                'HTTPS' => 'on',
            ],
            form: [
                'csrf' => 'syntactically-valid-shape',
                'email' => 'admin@example.test',
                'password' => 'not inspected during preflight',
            ],
            headers: [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]
        ));

        self::assertNotNull($response);
        self::assertSame(400, $response->status());
        self::assertSame(0, $this->factory->calls);
        self::assertSame([], $this->reporter->issueCodes);
    }

    public function testAnonymousUserManagementRoutesRedirectBeforeRuntime(): void
    {
        $requests = [
            Request::fromInput([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/admin/users',
                'HTTPS' => 'on',
            ]),
            Request::fromInput([
                'REQUEST_METHOD' => 'HEAD',
                'REQUEST_URI' => '/admin/users?after=cursor',
                'HTTPS' => 'on',
            ], ['after' => 'cursor']),
            Request::fromInput([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/admin/users/invite',
                'HTTPS' => 'on',
            ]),
            Request::fromInput([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/admin/users/edit?user=target',
                'HTTPS' => 'on',
            ], ['user' => 'target']),
            Request::fromInput([
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/users/invite',
                'HTTPS' => 'on',
            ], form: [
                'csrf' => 'csrf',
                'display_name' => 'Editor',
                'email' => 'editor@example.test',
            ], headers: [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]),
            Request::fromInput([
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/users/capabilities',
                'HTTPS' => 'on',
            ], form: [
                'csrf' => 'csrf',
                'target' => 'target',
                'capabilities' => ['webadmin.users.view'],
            ], headers: [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]),
            Request::fromInput([
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/users/suspend',
                'HTTPS' => 'on',
            ], form: ['csrf' => 'csrf', 'target' => 'target'], headers: [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]),
            Request::fromInput([
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/users/resume',
                'HTTPS' => 'on',
            ], form: ['csrf' => 'csrf', 'target' => 'target'], headers: [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]),
            Request::fromInput([
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/users/invite/resend',
                'HTTPS' => 'on',
            ], form: ['csrf' => 'csrf', 'target' => 'target'], headers: [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]),
            Request::fromInput([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/admin/users/updated',
                'HTTPS' => 'on',
            ]),
        ];

        foreach ($requests as $request) {
            $response = $this->routes->dispatch($request);
            self::assertNotNull($response);
            self::assertSame(303, $response->status());
            self::assertSame('/admin/login', $response->headers()['Location']);
            self::assertSame('', $response->body());
        }
        self::assertSame(0, $this->factory->calls);
        self::assertSame([], $this->reporter->issueCodes);
    }

    public function testMalformedUserManagementRequestNeverCreatesRuntime(): void
    {
        $response = $this->routes->dispatch(Request::fromInput([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/users?after=cursor&role=admin',
            'HTTPS' => 'on',
        ], ['after' => 'cursor', 'role' => 'admin']));

        self::assertNotNull($response);
        self::assertSame(400, $response->status());
        self::assertSame(0, $this->factory->calls);
        self::assertSame([], $this->reporter->issueCodes);
    }

    public function testLoginAndForgotPostsNeverAcceptAuthenticatedCookieAsPreauth(): void
    {
        $login = $this->routes->dispatch(Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/login',
                'HTTPS' => 'on',
            ],
            form: [
                'csrf' => 'syntactically-valid-shape',
                'email' => 'admin@example.test',
                'password' => 'not inspected during preflight',
            ],
            cookies: ['LS_WEBADMIN_SID' => str_repeat('A', 43)],
            headers: [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]
        ));
        $forgot = $this->routes->dispatch(Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/password/forgot',
                'HTTPS' => 'on',
            ],
            form: [
                'csrf' => 'syntactically-valid-shape',
                'email' => 'admin@example.test',
            ],
            cookies: ['LS_WEBADMIN_SID' => str_repeat('B', 43)],
            headers: [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]
        ));

        self::assertNotNull($login);
        self::assertNotNull($forgot);
        self::assertSame(400, $login->status());
        self::assertSame(400, $forgot->status());
        self::assertSame(0, $this->factory->calls);
        self::assertSame([], $this->reporter->issueCodes);

        $preauth = $this->routes->dispatch(Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/login',
                'HTTPS' => 'on',
            ],
            form: [
                'csrf' => 'syntactically-valid-shape',
                'email' => 'admin@example.test',
                'password' => 'not inspected during preflight',
            ],
            cookies: ['LS_WEBADMIN_PREAUTH' => str_repeat('C', 43)],
            headers: [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]
        ));
        self::assertNotNull($preauth);
        self::assertSame(503, $preauth->status());
        self::assertSame(1, $this->factory->calls);
        self::assertSame(
            ['webadmin.runtime_unavailable'],
            $this->reporter->issueCodes
        );
    }

    public function testValidLoginNavigationReachesRuntimeExactlyOnce(): void
    {
        $response = $this->routes->dispatch(Request::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/login',
            'HTTPS' => 'on',
        ]));

        self::assertNotNull($response);
        self::assertSame(503, $response->status());
        self::assertSame(1, $this->factory->calls);
        self::assertSame(
            ['webadmin.runtime_unavailable'],
            $this->reporter->issueCodes
        );
    }

    public function testUnusableEnvironmentNeverCreatesRuntime(): void
    {
        $routes = new ModuleRouteCollection();
        $factory = new CountingUnavailableWebAdminRuntimeFactory();
        $reporter = new CapturingWebAdminRuntimeIssueReporter();
        (new WebAdminRouteProvider(
            runtimeFactory: $factory,
            issueReporter: $reporter
        ))->registerRoutes(
            $routes,
            new ModuleRuntimeContext($this->projectRoot, [], false)
        );

        $response = $routes->dispatch(Request::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/login',
            'HTTPS' => 'on',
        ]));

        self::assertNotNull($response);
        self::assertSame(503, $response->status());
        self::assertSame(0, $factory->calls);
        self::assertSame(
            ['webadmin.environment_unusable'],
            $reporter->issueCodes
        );
    }

    public function testInvalidConfigurationIsReportedWithoutItsValue(): void
    {
        $this->filesystem->mkdir(
            $this->projectRoot . '/App/config/modules'
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/modules/webadmin.php',
            "<?php\n\nreturn ['path' => '/admin?private-secret'];\n"
        );
        $routes = new ModuleRouteCollection();
        $factory = new CountingUnavailableWebAdminRuntimeFactory();
        $reporter = new CapturingWebAdminRuntimeIssueReporter();
        (new WebAdminRouteProvider(
            runtimeFactory: $factory,
            issueReporter: $reporter
        ))->registerRoutes(
            $routes,
            new ModuleRuntimeContext($this->projectRoot)
        );

        $response = $routes->dispatch(Request::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/login',
            'HTTPS' => 'on',
        ]));

        self::assertNotNull($response);
        self::assertSame(503, $response->status());
        self::assertSame(0, $factory->calls);
        self::assertSame(
            ['webadmin.configuration_invalid'],
            $reporter->issueCodes
        );
        self::assertStringNotContainsString(
            'private-secret',
            json_encode($reporter->issueCodes, JSON_THROW_ON_ERROR)
        );
    }

    public function testStableRuntimeIssueIsLoggedButNotExposed(): void
    {
        $this->factory->exception = new WebAdminHttpRuntimeException(
            'webadmin.schema_not_ready'
        );

        $response = $this->routes->dispatch(Request::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/login',
            'HTTPS' => 'on',
        ]));

        self::assertNotNull($response);
        self::assertSame(503, $response->status());
        self::assertSame('Service unavailable', $response->body());
        self::assertStringNotContainsString(
            'schema_not_ready',
            $response->body()
        );
        self::assertSame(
            ['webadmin.schema_not_ready'],
            $this->reporter->issueCodes
        );
    }
}
