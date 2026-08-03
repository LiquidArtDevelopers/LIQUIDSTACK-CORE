<?php

declare(strict_types=1);

use App\Core\Modules\ModuleCatalog;
use App\Core\Modules\ModulePublishedSourceFinder;
use App\Core\Modules\ModuleSelection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ModuleCatalogTest extends TestCase
{
    private string $fixtureRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-module-catalog-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->fixtureRoot);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testCanonicalCatalogDefinesLogicalPackagesAndDependency(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $catalog = ModuleCatalog::fromCoreRoot($coreRoot);

        self::assertSame(
            ['blog', 'webadmin'],
            array_keys($catalog->all())
        );
        self::assertSame(
            'liquidstack/webadmin',
            $catalog->get('webadmin')->packageName()
        );
        self::assertSame(
            ['webadmin'],
            $catalog->get('blog')->dependencies()
        );
        self::assertSame(
            ['webadmin', 'blog'],
            ModuleSelection::fromRequirementNames(
                $catalog,
                ['liquidstack/blog']
            )->enabledIds()
        );
    }

    public function testCatalogRejectsUnknownDependencies(): void
    {
        $this->writeManifest('blog', [
            'schema' => 1,
            'id' => 'blog',
            'package' => 'liquidstack/blog',
            'requires' => ['missing'],
            'providers' => [],
            'project_files' => [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('módulo desconocido missing');
        ModuleCatalog::fromModulesRoot($this->fixtureRoot);
    }

    public function testSelectionRejectsCircularDependencies(): void
    {
        $this->writeManifest('alpha', $this->manifest(
            'alpha',
            'liquidstack/alpha',
            ['beta']
        ));
        $this->writeManifest('beta', $this->manifest(
            'beta',
            'liquidstack/beta',
            ['alpha']
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('alpha -> beta -> alpha');
        ModuleCatalog::fromModulesRoot($this->fixtureRoot);
    }

    public function testManifestRejectsProjectPathsOutsideModuleOrProject(): void
    {
        $manifest = $this->manifest(
            'unsafe',
            'liquidstack/unsafe'
        );
        $manifest['project_files'] = [[
            'source' => '../secret.php',
            'target' => 'App/secret.php',
            'type' => 'file',
        ]];
        $this->writeManifest('unsafe', $manifest);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no puede escapar del módulo');
        ModuleCatalog::fromModulesRoot($this->fixtureRoot);
    }

    public function testManifestCannotPublishOverProjectOwnedConfiguration(): void
    {
        $manifest = $this->manifest(
            'unsafe',
            'liquidstack/unsafe'
        );
        $manifest['project_files'] = [[
            'source' => 'published/config.php',
            'target' => 'App/config/routes/get.php',
            'type' => 'file',
        ]];
        $this->writeManifest('unsafe', $manifest);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'no pertenece al espacio público del módulo unsafe'
        );
        ModuleCatalog::fromModulesRoot($this->fixtureRoot);
    }

    public function testManifestCanPublishOnlyExplicitlyDeclaredResources(): void
    {
        $manifest = $this->manifest('blog', 'liquidstack/blog');
        $manifest['resources'] = ['sectionBlogGrid01'];
        $manifest['project_files'] = [
            [
                'source' => 'published/_moduleBlogResources.php',
                'target' => 'App/controllers/_moduleBlogResources.php',
            ],
            [
                'source' => 'published/sectionBlogGrid01.php',
                'target' => 'App/controllers/sectionBlogGrid01.php',
            ],
            [
                'source' => 'published/_sectionBlogGrid01.html',
                'target' => 'App/templates/_sectionBlogGrid01.html',
            ],
            [
                'source' => 'published/_sectionBlogGrid01.scss',
                'target' => 'src/scss/resources/_sectionBlogGrid01.scss',
            ],
            [
                'source' => 'published/_blog.php',
                'target' => 'App/views/showroom/_blog.php',
            ],
        ];
        $this->writeManifest('blog', $manifest);

        $definition = ModuleCatalog::fromModulesRoot(
            $this->fixtureRoot
        )->get('blog');

        self::assertSame(['sectionBlogGrid01'], $definition->resources());
        self::assertCount(5, $definition->projectFiles());
    }

    public function testManifestRejectsUndeclaredResourceAndForeignShowroomHook(): void
    {
        $manifest = $this->manifest('blog', 'liquidstack/blog');
        $manifest['resources'] = ['sectionBlogGrid01'];
        $manifest['project_files'] = [[
            'source' => 'published/sectionBlogList01.php',
            'target' => 'App/controllers/sectionBlogList01.php',
        ]];
        $this->writeManifest('blog', $manifest);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no pertenece al espacio');
        ModuleCatalog::fromModulesRoot($this->fixtureRoot);
    }

    public function testManifestRejectsInvalidResourceIdentifiers(): void
    {
        $manifest = $this->manifest('blog', 'liquidstack/blog');
        $manifest['resources'] = ['../sectionBlogGrid01'];
        $this->writeManifest('blog', $manifest);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recurso de m');
        ModuleCatalog::fromModulesRoot($this->fixtureRoot);
    }

    public function testManifestRequiresCanonicalLowercaseAssetNamespace(): void
    {
        $manifest = $this->manifest(
            'webadmin',
            'liquidstack/webadmin'
        );
        $manifest['project_files'] = [[
            'source' => 'published/app.js',
            'target' => 'PUBLIC/assets/modules/webadmin/app.js',
            'type' => 'file',
        ]];
        $this->writeManifest('webadmin', $manifest);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no pertenece al espacio público');
        ModuleCatalog::fromModulesRoot($this->fixtureRoot);
    }

    public function testManifestRejectsUnknownProviderTypes(): void
    {
        $manifest = $this->manifest(
            'webadmin',
            'liquidstack/webadmin'
        );
        $manifest['providers'] = ['route' => []];
        $this->writeManifest('webadmin', $manifest);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Proveedor de módulo inválido');
        ModuleCatalog::fromModulesRoot($this->fixtureRoot);
    }

    public function testManifestRejectsOverlappingPublishedTargets(): void
    {
        $manifest = $this->manifest(
            'webadmin',
            'liquidstack/webadmin'
        );
        $manifest['project_files'] = [
            [
                'source' => 'published/assets',
                'target' => 'public/assets/modules/webadmin',
                'type' => 'dir',
            ],
            [
                'source' => 'published/app.js',
                'target' => 'public/assets/modules/webadmin/app.js',
                'type' => 'file',
            ],
        ];
        $this->writeManifest('webadmin', $manifest);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('colisiona con otra entrada');
        ModuleCatalog::fromModulesRoot($this->fixtureRoot);
    }

    public function testAdditiveJsonPolicyRejectsNonJsonOrDirectoryEntries(): void
    {
        $manifest = $this->manifest(
            'webadmin',
            'liquidstack/webadmin'
        );
        $manifest['project_files'] = [[
            'source' => 'published/app.js',
            'target' => 'public/assets/modules/webadmin/app.js',
            'type' => 'file',
            'policy' => 'merge_json_additive',
        ]];
        $this->writeManifest('webadmin', $manifest);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('solo admite ficheros JSON');
        ModuleCatalog::fromModulesRoot($this->fixtureRoot);
    }

    public function testPublishedSourceFinderExpandsManagedDirectoriesOnly(): void
    {
        $manifest = $this->manifest(
            'webadmin',
            'liquidstack/webadmin'
        );
        $manifest['project_files'] = [
            [
                'source' => 'bundle',
                'target' => 'public/assets/modules/webadmin',
                'type' => 'dir',
                'policy' => 'managed_hash',
            ],
            [
                'source' => 'seed.txt',
                'target' => 'src/js/modules/webadmin/seed.txt',
                'type' => 'file',
                'policy' => 'install_if_missing',
            ],
        ];
        $this->writeManifest('webadmin', $manifest);
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/webadmin/bundle/nested/app.js',
            'app'
        );
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/webadmin/seed.txt',
            'seed'
        );

        $files = ModulePublishedSourceFinder::currentManagedFiles(
            ModuleCatalog::fromModulesRoot($this->fixtureRoot)
        );
        self::assertSame(
            ['modules/webadmin/bundle/nested/app.js'],
            array_keys($files)
        );
        self::assertSame(
            str_replace(
                '\\',
                '/',
                $this->fixtureRoot
                    . '/webadmin/bundle/nested/app.js'
            ),
            str_replace(
                '\\',
                '/',
                $files['modules/webadmin/bundle/nested/app.js']
            )
        );
    }

    /**
     * @param list<string> $requires
     * @return array<string, mixed>
     */
    private function manifest(
        string $id,
        string $package,
        array $requires = []
    ): array {
        return [
            'schema' => 1,
            'id' => $id,
            'package' => $package,
            'requires' => $requires,
            'providers' => [],
            'project_files' => [],
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeManifest(string $id, array $manifest): void
    {
        $path = $this->fixtureRoot . '/' . $id . '/module.json';
        $this->filesystem->mkdir(dirname($path));
        $this->filesystem->dumpFile(
            $path,
            json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
    }
}
