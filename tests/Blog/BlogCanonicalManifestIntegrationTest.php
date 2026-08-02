<?php

declare(strict_types=1);

use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Blog\BlogCategoryWebAdminNavigationProvider;
use App\Core\Modules\Blog\BlogCategoryRouteProvider;
use App\Core\Modules\Blog\BlogPublicRouteProvider;
use App\Core\Modules\Blog\BlogRouteProvider;
use App\Core\Modules\Blog\BlogWebAdminNavigationProvider;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationProviderInterface;
use App\Core\Modules\ModuleProviderInterface;
use App\Core\Modules\ModulePublicRouteProviderInterface;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRouteProviderInterface;
use App\Core\Modules\ModuleWebAdminNavigationProviderInterface;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\Modules\WebAdmin\WebAdminMediaNavigationProvider;
use App\Core\Modules\WebAdmin\WebAdminMediaRouteProvider;
use App\Core\Modules\WebAdmin\WebAdminRouteProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogCanonicalManifestIntegrationTest extends TestCase
{
    private string $projectRoot;
    private string $coreRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-blog-canonical-manifest-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectRoot);
        $this->coreRoot = dirname(__DIR__, 2);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
    }

    public function testCanonicalBlogRequirementSelectsExactTypedProviders(): void
    {
        $this->writeComposer(['liquidstack/blog' => '*']);
        $registry = $this->registry();

        self::assertSame(['blog'], $registry->selection()->requestedIds());
        self::assertSame(
            ['webadmin', 'blog'],
            $registry->selection()->enabledIds()
        );
        self::assertSame(
            ['webadmin', 'blog'],
            array_map(
                static fn ($definition): string => $definition->id(),
                $registry->selection()->enabledDefinitions()
            )
        );
        self::assertSame(
            [
                ['id' => 'webadmin', 'package' => 'liquidstack/webadmin',
                    'requires' => []],
                ['id' => 'blog', 'package' => 'liquidstack/blog',
                    'requires' => ['webadmin']],
            ],
            array_map(
                static fn ($definition): array => [
                    'id' => $definition->id(),
                    'package' => $definition->packageName(),
                    'requires' => $definition->dependencies(),
                ],
                $registry->selection()->enabledDefinitions()
            )
        );

        $privateRoutes = [
            [
                'module' => 'webadmin',
                'class' => WebAdminRouteProvider::class,
            ],
            [
                'module' => 'webadmin',
                'class' => WebAdminMediaRouteProvider::class,
            ],
            [
                'module' => 'blog',
                'class' => BlogRouteProvider::class,
            ],
            [
                'module' => 'blog',
                'class' => BlogCategoryRouteProvider::class,
            ],
        ];
        $publicRoutes = [[
            'module' => 'blog',
            'class' => BlogPublicRouteProvider::class,
        ]];
        $navigation = [
            [
                'module' => 'webadmin',
                'class' => WebAdminMediaNavigationProvider::class,
            ],
            [
                'module' => 'blog',
                'class' => BlogWebAdminNavigationProvider::class,
            ],
            [
                'module' => 'blog',
                'class' => BlogCategoryWebAdminNavigationProvider::class,
            ],
        ];
        $migrations = [
            [
                'module' => 'webadmin',
                'class' => WebAdminMigrationProvider::class,
            ],
            [
                'module' => 'blog',
                'class' => BlogMigrationProvider::class,
            ],
        ];

        self::assertSame($privateRoutes, $registry->routeProviders());
        self::assertSame($publicRoutes, $registry->publicRouteProviders());
        self::assertSame(
            array_merge($privateRoutes, $publicRoutes),
            $registry->providers('routes')
        );
        self::assertSame(
            $navigation,
            $registry->webAdminNavigationProviders()
        );
        self::assertSame(
            $migrations,
            $registry->providers('migrations')
        );
        foreach ([
            'middleware',
            'services',
            'capabilities',
            'sitemap',
        ] as $type) {
            self::assertSame([], $registry->providers($type), $type);
        }

        foreach ($privateRoutes as $provider) {
            $this->assertProvider(
                $provider['class'],
                $provider['module'],
                ModuleRouteProviderInterface::class
            );
        }
        foreach ($publicRoutes as $provider) {
            $this->assertProvider(
                $provider['class'],
                $provider['module'],
                ModulePublicRouteProviderInterface::class
            );
        }
        foreach ($navigation as $provider) {
            $instance = $this->assertProvider(
                $provider['class'],
                $provider['module'],
                ModuleWebAdminNavigationProviderInterface::class
            );
            self::assertInstanceOf(
                ModuleWebAdminNavigationProviderInterface::class,
                $instance
            );
            self::assertSame(
                $provider['module'],
                $instance->webAdminNavigationItem()->module()
            );
        }
        foreach ($migrations as $provider) {
            $this->assertProvider(
                $provider['class'],
                $provider['module'],
                MigrationProviderInterface::class
            );
        }

        $catalog = MigrationCatalog::fromRegistry($registry);
        self::assertSame(
            ['webadmin', 'blog'],
            $catalog->activeModuleIds()
        );
        self::assertSame([
            [
                'module' => 'webadmin',
                'provider' => WebAdminMigrationProvider::class,
                'migration' => '0001_webadmin_identity_and_access',
            ],
            [
                'module' => 'webadmin',
                'provider' => WebAdminMigrationProvider::class,
                'migration' => '0002_webadmin_media_library',
            ],
            [
                'module' => 'blog',
                'provider' => BlogMigrationProvider::class,
                'migration' => '0001_blog_posts',
            ],
            [
                'module' => 'blog',
                'provider' => BlogMigrationProvider::class,
                'migration' => '0002_blog_capabilities',
            ],
            [
                'module' => 'blog',
                'provider' => BlogMigrationProvider::class,
                'migration' => '0003_blog_categories',
            ],
            [
                'module' => 'blog',
                'provider' => BlogMigrationProvider::class,
                'migration' => '0004_blog_category_capabilities',
            ],
            [
                'module' => 'blog',
                'provider' => BlogMigrationProvider::class,
                'migration' => '0005_blog_structured_content',
            ],
        ], array_map(
            static fn (array $entry): array => [
                'module' => $entry['module'],
                'provider' => $entry['provider'],
                'migration' => $entry['migration']->id(),
            ],
            $catalog->entries()
        ));
    }

    public function testCoreOnlyProjectRegistersNoOptionalModuleProviders(): void
    {
        $this->writeComposer(['liquidstack/core' => '^1.9']);
        $registry = $this->registry();

        self::assertSame([], $registry->selection()->requestedIds());
        self::assertSame([], $registry->selection()->enabledIds());
        self::assertSame([], $registry->selection()->enabledDefinitions());
        self::assertSame([], $registry->routeProviders());
        self::assertSame([], $registry->publicRouteProviders());
        self::assertSame([], $registry->webAdminNavigationProviders());
        foreach ([
            'routes',
            'middleware',
            'services',
            'navigation',
            'capabilities',
            'migrations',
            'sitemap',
        ] as $type) {
            self::assertSame([], $registry->providers($type), $type);
        }

        $catalog = MigrationCatalog::fromRegistry($registry);
        self::assertSame([], $catalog->activeModuleIds());
        self::assertSame([], $catalog->entries());
    }

    /**
     * @param class-string<ModuleProviderInterface> $provider
     * @param class-string<ModuleProviderInterface> $contract
     */
    private function assertProvider(
        string $provider,
        string $module,
        string $contract
    ): ModuleProviderInterface {
        self::assertTrue(class_exists($provider), $provider);
        self::assertTrue(is_subclass_of($provider, $contract), $provider);
        self::assertSame($module, $provider::moduleId(), $provider);
        $reflection = new ReflectionClass($provider);
        self::assertTrue($reflection->isInstantiable(), $provider);
        self::assertLessThanOrEqual(
            0,
            $reflection->getConstructor()?->getNumberOfRequiredParameters()
                ?? 0,
            $provider
        );
        $instance = $reflection->newInstance();
        self::assertInstanceOf($contract, $instance);
        self::assertInstanceOf(ModuleProviderInterface::class, $instance);

        return $instance;
    }

    private function registry(): ModuleRegistry
    {
        return ModuleRegistry::forProject(
            $this->projectRoot,
            $this->coreRoot
        );
    }

    /** @param array<string, string> $requirements */
    private function writeComposer(array $requirements): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode(
                ['require' => $requirements],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
    }
}
