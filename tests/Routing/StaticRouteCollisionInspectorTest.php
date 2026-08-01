<?php

declare(strict_types=1);

use App\Core\Routing\StaticRouteCollisionInspector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class StaticRouteCollisionInspectorTest extends TestCase
{
    private string $fixtureRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-route-collision-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->fixtureRoot . '/App/config/routes');
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testDetectsExactAndDescendantLiteralRoutesOnly(): void
    {
        $this->writeRouteFile('get.php', <<<'PHP'
<?php

$routes = [
    'es' => [
        '/admin' => ['view' => 'exact.php'],
        '/admin/users?status={status}' => ['view' => 'users.php'],
        '/administrator' => ['view' => 'public.php'],
        '/es/admin' => ['view' => 'localized.php'],
    ],
];

return $routes;
PHP);
        $this->writeRouteFile('post.php', <<<'PHP'
<?php

return [
    "/admin/actions/?token={token}" => 'admin.php',
    '/administrator/actions' => 'public.php',
];
PHP);

        $report = (new StaticRouteCollisionInspector())->inspect(
            $this->fixtureRoot,
            '/admin/?source=configuration'
        );

        self::assertTrue($report['complete']);
        self::assertSame('/admin', $report['prefix']);
        self::assertSame([], $report['issues']);
        self::assertSame(
            [
                ['method' => 'GET', 'route' => '/admin', 'source' => 'App/config/routes/get.php'],
                ['method' => 'GET', 'route' => '/admin/users', 'source' => 'App/config/routes/get.php'],
                ['method' => 'POST', 'route' => '/admin/actions', 'source' => 'App/config/routes/post.php'],
            ],
            array_map(
                static fn (array $collision): array => array_diff_key(
                    $collision,
                    ['line' => true]
                ),
                $report['collisions']
            )
        );
        self::assertContainsOnly('int', array_column($report['collisions'], 'line'));
    }

    public function testNonLiteralKeyMakesInspectionIncomplete(): void
    {
        $this->writeRouteFile('get.php', <<<'PHP'
<?php

// '/admin/comment' => ['view' => 'never.php'];
$dynamic = '/admin/dynamic';

return [
    'es' => [
        '/public' => [
            'view' => '/admin/value-is-not-a-key.php',
        ],
        $dynamic => ['view' => 'dynamic.php'],
    ],
];
PHP);
        $this->writeRouteFile('post.php', "<?php\n\nreturn [];\n");

        $report = (new StaticRouteCollisionInspector())->inspect(
            $this->fixtureRoot,
            '/admin'
        );

        self::assertFalse($report['complete']);
        self::assertSame([], $report['collisions']);
        self::assertSame(
            [[
                'code' => 'route_file.dynamic_key',
                'source' => 'App/config/routes/get.php',
            ]],
            $report['issues']
        );
    }

    public function testIncrementalArrayConstructionMakesInspectionIncomplete(): void
    {
        $this->writeRouteFile('get.php', <<<'PHP'
<?php

$routes = ['es' => ['/public' => ['view' => 'public.php']]];
$key = '/admin/dynamic';
$routes['es'][$key] = ['view' => 'dynamic.php'];

return $routes;
PHP);
        $this->writeRouteFile('post.php', "<?php\n\nreturn [];\n");

        $report = (new StaticRouteCollisionInspector())->inspect(
            $this->fixtureRoot,
            '/admin'
        );

        self::assertFalse($report['complete']);
        self::assertSame([], $report['collisions']);
        self::assertSame(
            ['route_file.dynamic_key'],
            array_column($report['issues'], 'code')
        );
    }

    public function testConcatenatedArrayKeyIsNotMistakenForALiteral(): void
    {
        $this->writeRouteFile('get.php', <<<'PHP'
<?php

$suffix = 'users';
return [
    'es' => [
        '/admin/' . $suffix => ['view' => 'dynamic.php'],
    ],
];
PHP);
        $this->writeRouteFile('post.php', "<?php\n\nreturn [];\n");

        $report = (new StaticRouteCollisionInspector())->inspect(
            $this->fixtureRoot,
            '/admin'
        );

        self::assertFalse($report['complete']);
        self::assertSame([], $report['collisions']);
        self::assertSame(
            ['route_file.dynamic_key'],
            array_column($report['issues'], 'code')
        );
    }

    public function testOpaqueRouteFactoryMakesInspectionIncomplete(): void
    {
        $this->writeRouteFile(
            'get.php',
            "<?php\n\nreturn build_project_routes();\n"
        );
        $this->writeRouteFile('post.php', "<?php\n\nreturn [];\n");

        $report = (new StaticRouteCollisionInspector())->inspect(
            $this->fixtureRoot,
            '/admin'
        );

        self::assertFalse($report['complete']);
        self::assertSame([], $report['collisions']);
        self::assertSame(
            ['route_file.dynamic_key'],
            array_column($report['issues'], 'code')
        );
    }

    public function testReportsNonRegularFilesWithoutAbsolutePaths(): void
    {
        $this->filesystem->mkdir(
            $this->fixtureRoot . '/App/config/routes/get.php'
        );

        $report = (new StaticRouteCollisionInspector())->inspect(
            $this->fixtureRoot,
            '/admin'
        );

        self::assertFalse($report['complete']);
        self::assertSame(
            [
                ['code' => 'route_file.not_regular', 'source' => 'App/config/routes/get.php'],
            ],
            $report['issues']
        );
        self::assertStringNotContainsString(
            str_replace('\\', '/', $this->fixtureRoot),
            str_replace('\\', '/', json_encode($report, JSON_THROW_ON_ERROR))
        );
    }

    public function testAbsentRouteFilesAreEmptyCatalogues(): void
    {
        $report = (new StaticRouteCollisionInspector())->inspect(
            $this->fixtureRoot,
            '/admin'
        );

        self::assertTrue($report['complete']);
        self::assertSame([], $report['collisions']);
        self::assertSame([], $report['issues']);
    }

    public function testReadFailureDoesNotExposeReaderException(): void
    {
        $this->writeRouteFile('get.php', "<?php\nreturn ['/admin' => []];\n");
        $this->writeRouteFile('post.php', "<?php\nreturn [];\n");

        $inspector = new StaticRouteCollisionInspector(
            static function (string $path): string {
                throw new RuntimeException('secret filesystem detail');
            }
        );
        $report = $inspector->inspect($this->fixtureRoot, '/admin');

        self::assertFalse($report['complete']);
        self::assertSame(
            ['route_file.read_failed', 'route_file.read_failed'],
            array_column($report['issues'], 'code')
        );
        self::assertStringNotContainsString(
            'secret filesystem detail',
            json_encode($report, JSON_THROW_ON_ERROR)
        );
    }

    public function testInvalidPhpIsReportedWithoutLeakingSource(): void
    {
        $this->writeRouteFile(
            'get.php',
            "<?php\nreturn ['/admin' => ]; // private-route-source\n"
        );
        $this->writeRouteFile('post.php', "<?php\nreturn [];\n");

        $report = (new StaticRouteCollisionInspector())->inspect(
            $this->fixtureRoot,
            '/admin'
        );

        self::assertFalse($report['complete']);
        self::assertSame(
            [['code' => 'route_file.invalid_php', 'source' => 'App/config/routes/get.php']],
            $report['issues']
        );
        self::assertStringNotContainsString(
            'private-route-source',
            json_encode($report, JSON_THROW_ON_ERROR)
        );
    }

    public function testRejectsSymlinkedRouteFileWhenSupported(): void
    {
        $outside = $this->fixtureRoot . '/outside.php';
        $link = $this->fixtureRoot . '/App/config/routes/get.php';
        $this->filesystem->dumpFile($outside, "<?php\nreturn ['/admin' => []];\n");
        $this->writeRouteFile('post.php', "<?php\nreturn [];\n");

        if (!@symlink($outside, $link)) {
            self::markTestSkipped('El entorno no permite crear enlaces simbolicos.');
        }

        $report = (new StaticRouteCollisionInspector())->inspect(
            $this->fixtureRoot,
            '/admin'
        );

        self::assertFalse($report['complete']);
        self::assertSame(
            [['code' => 'route_file.symlink', 'source' => 'App/config/routes/get.php']],
            $report['issues']
        );
        self::assertSame([], $report['collisions']);
    }

    public function testRejectsInvalidPrefix(): void
    {
        $this->writeRouteFile('get.php', "<?php\nreturn [];\n");
        $this->writeRouteFile('post.php', "<?php\nreturn [];\n");

        $this->expectException(InvalidArgumentException::class);

        (new StaticRouteCollisionInspector())->inspect(
            $this->fixtureRoot,
            '/'
        );
    }

    private function writeRouteFile(string $name, string $contents): void
    {
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/routes/' . $name,
            $contents
        );
    }
}
