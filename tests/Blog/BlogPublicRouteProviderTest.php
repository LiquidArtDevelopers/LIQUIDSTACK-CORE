<?php

declare(strict_types=1);

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Http\BlogPublicHttpRuntime;
use App\Core\Blog\Http\BlogPublicHttpRuntimeFactoryInterface;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Http\Request;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Blog\BlogPublicRouteProvider;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Routing\ModulePublicRouteCollection;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CountingBlogPublicRuntimeFactoryFixture implements
    BlogPublicHttpRuntimeFactoryInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly ?BlogPublicHttpRuntime $runtime = null
    ) {
    }

    public function create(
        ModuleRuntimeContext $context
    ): BlogPublicHttpRuntime {
        $this->calls++;

        return $this->runtime
            ?? throw new RuntimeException('Runtime must remain lazy.');
    }
}

final class BlogPublicRouteProviderTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-blog-public-provider-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->root . '/App/config/modules');
        $this->filesystem->dumpFile(
            $this->root . '/App/config/langs.php',
            "<?php\nreturn ['es', 'en'];\n"
        );
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/blog.php',
            <<<'PHP'
<?php
return [
    'public_paths' => [
        'es' => '/noticias',
        'en' => '/en/news',
    ],
    'sitemap_path' => '/news-sitemap.xml',
];
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testMetadataUsesProjectPathsWithoutRuntime(): void
    {
        $context = new ModuleRuntimeContext($this->root);

        self::assertSame([
            '/noticias',
            '/en/news',
            '/news-sitemap.xml',
            '/_liquidstack/blog-media',
        ], BlogPublicRouteProvider::publicRoutePrefixes($context));
        self::assertSame(
            ['/news-sitemap.xml'],
            BlogPublicRouteProvider::preBootstrapPublicRoutePaths($context)
        );
        self::assertSame(
            ['/_liquidstack/blog-media'],
            BlogPublicRouteProvider::preBootstrapPublicRoutePrefixes($context)
        );
    }

    public function testBaseAndMalformedDescendantsFallThroughWithoutPdo(): void
    {
        $context = new ModuleRuntimeContext($this->root);
        $factory = new CountingBlogPublicRuntimeFactoryFixture();
        $routes = new ModulePublicRouteCollection(
            'blog',
            BlogPublicRouteProvider::publicRoutePrefixes($context)
        );
        (new BlogPublicRouteProvider($factory))->registerPublicRoutes(
            $routes,
            $context
        );

        foreach (['/noticias', '/noticias/a/b', '/noticias/Bad-Slug'] as $path) {
            self::assertNull($routes->dispatch(Request::fromServer([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => $path,
                'HTTPS' => 'on',
            ])));
        }
        self::assertSame(0, $factory->calls);

        foreach ([
            '/_liquidstack/blog-media',
            '/_liquidstack/blog-media/not-a-uuid/480.avif',
            '/_liquidstack/blog-media/11111111-1111-4111-8111-111111111111/0.avif',
            '/_liquidstack/blog-media/11111111-1111-4111-8111-111111111111/480.jpg',
        ] as $path) {
            $response = $routes->dispatch(Request::fromServer([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => $path,
                'HTTPS' => 'on',
            ]));
            self::assertNotNull($response);
            self::assertSame(404, $response->status());
            self::assertSame('no-store', $response->headers()['Cache-Control']);
        }
        self::assertSame(0, $factory->calls);
    }

    public function testInvalidConfigurationPublishesNoPrefixes(): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/blog.php',
            "<?php\nreturn ['public_paths' => ['es' => '/bad only']];\n"
        );

        self::assertSame([], BlogPublicRouteProvider::publicRoutePrefixes(
            new ModuleRuntimeContext($this->root)
        ));
        self::assertSame([], BlogPublicRouteProvider::preBootstrapPublicRoutePaths(
            new ModuleRuntimeContext($this->root)
        ));
        self::assertSame(
            ['/_liquidstack/blog-media'],
            BlogPublicRouteProvider::preBootstrapPublicRoutePrefixes(
                new ModuleRuntimeContext($this->root)
            )
        );
    }

    public function testProviderBuildsRendererWithConfiguredProjectView(): void
    {
        $view = $this->root . '/App/views/blog/public-article.php';
        $this->filesystem->dumpFile($view, <<<'PHP'
<?php
echo '<!doctype html><html><body data-project-shell="true"><h1>'
    . htmlspecialchars($blogArticle->h1(), ENT_QUOTES, 'UTF-8')
    . '</h1>' . $blogArticle->bodyHtml();
foreach ($blogArticle->languageNavigationUrls() as $locale => $url) {
    echo '<a data-language="' . htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')
        . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></a>';
}
echo '</body></html>';
PHP);
        $this->filesystem->dumpFile(
            $this->root . '/App/config/modules/blog.php',
            <<<'PHP'
<?php
return [
    'public_paths' => [
        'es' => '/noticias',
        'en' => '/en/news',
    ],
    'sitemap_path' => '/news-sitemap.xml',
    'public_article_view' => 'App/views/blog/public-article.php',
];
PHP
        );

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(
            PDO::ATTR_DEFAULT_FETCH_MODE,
            PDO::FETCH_ASSOC
        );
        $pdo->exec('PRAGMA foreign_keys = ON');
        $scope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $migration = null;
        foreach (BlogMigrationProvider::migrations() as $candidate) {
            if ($candidate->id() === '0001_blog_posts') {
                $migration = $candidate;
                break;
            }
        }
        self::assertInstanceOf(MigrationDefinition::class, $migration);
        foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
            $pdo->exec($sql);
        }
        $uuids = new class implements UuidGeneratorInterface {
            /** @var list<string> */
            private array $values = [
                '11111111-1111-4111-8111-111111111111',
                '22222222-2222-4222-8222-222222222222',
            ];

            public function generateV4(): string
            {
                return array_shift($this->values)
                    ?? throw new RuntimeException('UUID fixture exhausted.');
            }
        };
        $service = new BlogService(
            new PdoBlogRepository($pdo, $scope),
            $uuids
        );
        $actor = static fn (PDO $connection): string =>
            '33333333-3333-4333-8333-333333333333';
        $created = $service->createPost($actor, 'es', new BlogDraft(
            'Matrix & systems',
            'Safe body.',
            'matrix',
            'Matrix SEO',
            'Matrix description.',
            'Matrix excerpt.'
        ));
        $service->publish(
            $actor,
            $created->postPublicId(),
            'es',
            $created->lockVersion()
        );
        $runtime = new BlogPublicHttpRuntime(
            (new BlogConfigLoader())->load($this->root, ['es', 'en']),
            BlogPublicOrigin::fromEnvironment([
                BlogPublicOrigin::ENV => 'https://example.test',
            ]),
            $service
        );
        $factory = new CountingBlogPublicRuntimeFactoryFixture($runtime);
        $context = new ModuleRuntimeContext($this->root);
        $routes = new ModulePublicRouteCollection(
            'blog',
            BlogPublicRouteProvider::publicRoutePrefixes($context)
        );
        (new BlogPublicRouteProvider($factory))->registerPublicRoutes(
            $routes,
            $context
        );

        $response = $routes->dispatch(Request::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/noticias/matrix',
            'HTTPS' => 'on',
        ]));

        self::assertNotNull($response);
        self::assertSame(200, $response->status());
        self::assertSame(1, $factory->calls);
        self::assertStringContainsString(
            'data-project-shell="true"',
            $response->body()
        );
        self::assertStringContainsString(
            '<h1>Matrix &amp; systems</h1>',
            $response->body()
        );
        self::assertStringContainsString(
            'data-language="en" href="https://example.test/en/news"',
            $response->body()
        );
        self::assertArrayNotHasKey(
            'Content-Security-Policy',
            $response->headers()
        );
    }

    public function testInvalidOrTraversingMediaRequestsNeverOpenRuntime(): void
    {
        $context = new ModuleRuntimeContext($this->root);
        $factory = new CountingBlogPublicRuntimeFactoryFixture();
        $routes = new ModulePublicRouteCollection(
            'blog',
            BlogPublicRouteProvider::publicRoutePrefixes($context)
        );
        (new BlogPublicRouteProvider($factory))->registerPublicRoutes(
            $routes,
            $context
        );

        foreach ([
            '/_liquidstack/blog-media/../secret/480.avif',
            '/_liquidstack/blog-media/%2e%2e/secret/480.avif',
            '/_liquidstack/blog-media/11111111-1111-4111-8111-111111111111%2f480.avif',
        ] as $path) {
            self::assertNull($routes->dispatch(Request::fromServer([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => $path,
                'HTTPS' => 'on',
            ])));
        }

        $get = $routes->dispatch(Request::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/_liquidstack/blog-media/bad/480.avif',
            'HTTPS' => 'on',
        ]));
        $head = $routes->dispatch(Request::fromServer([
            'REQUEST_METHOD' => 'HEAD',
            'REQUEST_URI' => '/_liquidstack/blog-media/bad/480.avif',
            'HTTPS' => 'on',
        ]));
        self::assertNotNull($get);
        self::assertNotNull($head);
        self::assertSame(404, $get->status());
        self::assertSame($get->status(), $head->status());
        self::assertSame($get->headers(), $head->headers());
        self::assertSame('Not found', $get->body());
        self::assertSame('', $head->body());

        $write = $routes->dispatch(Request::fromServer([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/_liquidstack/blog-media/bad/480.avif',
            'HTTPS' => 'on',
        ]));
        self::assertNotNull($write);
        self::assertSame(405, $write->status());
        self::assertSame('GET, HEAD', $write->headers()['Allow']);
        self::assertSame(0, $factory->calls);
    }
}
