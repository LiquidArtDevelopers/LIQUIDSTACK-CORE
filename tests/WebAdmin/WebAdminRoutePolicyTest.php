<?php

declare(strict_types=1);

use App\Core\WebAdmin\Routing\WebAdminRoutePolicy;
use App\Core\WebAdmin\Routing\WebAdminRouteResolution;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class WebAdminRoutePolicyTest extends TestCase
{
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-webadmin-route-policy-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir(
            $this->projectRoot . '/App/config/routes'
        );
        $this->writeRoutes('get.php', []);
        $this->writeRoutes('post.php', []);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
    }

    public function testAcceptsANeutralNonCollidingPath(): void
    {
        $resolution = $this->resolve('/gestion-web', ['es', 'en']);

        self::assertTrue($resolution->isReady());
        self::assertTrue($resolution->isAvailable());
        self::assertSame('/gestion-web', $resolution->registeredPath());
        self::assertFalse($resolution->toArray()['fallback']);
    }

    public function testLocalizedPathFallsBackToAdminButIsNotReady(): void
    {
        $resolution = $this->resolve('/es/admin', ['es', 'en']);

        self::assertFalse($resolution->isReady());
        self::assertTrue($resolution->isAvailable());
        self::assertSame('/admin', $resolution->registeredPath());
        self::assertTrue($resolution->toArray()['fallback']);
        self::assertContains([
            'code' => 'config.localized_base_path',
            'key' => 'path',
        ], $resolution->issues());
    }

    public function testCustomCollisionPreservesPublicRouteAndUsesSafeFallback(): void
    {
        $this->writeRoutes('get.php', ['/gestion-web']);

        $resolution = $this->resolve('/gestion-web', ['es']);

        self::assertFalse($resolution->isReady());
        self::assertSame('/admin', $resolution->registeredPath());
        self::assertSame(
            ['/gestion-web'],
            array_column($resolution->toArray()['collisions'], 'route')
        );
    }

    public function testDefaultCollisionDisablesRegistration(): void
    {
        $this->writeRoutes('post.php', ['/admin/action']);

        $resolution = $this->resolve('/admin', ['es']);

        self::assertFalse($resolution->isReady());
        self::assertFalse($resolution->isAvailable());
        self::assertNull($resolution->registeredPath());
        self::assertContains([
            'code' => 'config.route_collision',
            'key' => 'path',
        ], $resolution->issues());
    }

    public function testIncompleteInspectionDisablesRegistration(): void
    {
        $this->filesystem->remove(
            $this->projectRoot . '/App/config/routes/get.php'
        );
        $this->filesystem->mkdir(
            $this->projectRoot . '/App/config/routes/get.php'
        );

        $resolution = $this->resolve('/admin', ['es']);

        self::assertFalse($resolution->isReady());
        self::assertFalse($resolution->isAvailable());
        self::assertSame(
            ['route_file.not_regular'],
            array_column($resolution->issues(), 'code')
        );
    }

    public function testDynamicLegacyRouteDisablesRegistration(): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/routes/get.php',
            <<<'PHP'
<?php

$adminPath = '/admin';
return ['es' => [$adminPath => ['view' => 'legacy.php']]];
PHP
        );

        $resolution = $this->resolve('/admin', ['es']);

        self::assertFalse($resolution->isReady());
        self::assertFalse($resolution->isAvailable());
        self::assertNull($resolution->registeredPath());
        self::assertSame(
            ['route_file.dynamic_key'],
            array_column($resolution->issues(), 'code')
        );
    }

    /** @param list<string> $languages */
    private function resolve(
        string $path,
        array $languages
    ): WebAdminRouteResolution
    {
        return (new WebAdminRoutePolicy())->resolve(
            $this->projectRoot,
            $path,
            $languages
        );
    }

    /** @param list<string> $routes */
    private function writeRoutes(string $file, array $routes): void
    {
        $values = [];
        foreach ($routes as $route) {
            $values[$route] = ['view' => 'fixture.php'];
        }

        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/routes/' . $file,
            "<?php\n\nreturn " . var_export($values, true) . ";\n"
        );
    }
}
