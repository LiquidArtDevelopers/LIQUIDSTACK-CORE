<?php

declare(strict_types=1);

use App\Core\Composer\ManagedFileSynchronizer;
use App\Core\Composer\ModuleProjectFileSynchronizer;
use App\Core\Modules\ModuleCatalog;
use App\Core\Modules\ModuleSelection;
use Composer\Util\Filesystem as ComposerFilesystem;
use Composer\IO\BufferIO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class InstallerModuleSyncTest extends TestCase
{
    private string $fixtureRoot;
    private string $modulesRoot;
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-module-sync-'
            . bin2hex(random_bytes(8));
        $this->modulesRoot = $this->fixtureRoot . '/modules';
        $this->projectRoot = $this->fixtureRoot . '/project';
        $this->filesystem->mkdir([
            $this->modulesRoot,
            $this->projectRoot,
        ]);

        $this->createModule(
            'webadmin',
            'liquidstack/webadmin',
            [],
            'published/webadmin.txt',
            'public/assets/modules/webadmin/module.txt',
            'webadmin-v1'
        );
        $this->createModule(
            'blog',
            'liquidstack/blog',
            ['webadmin'],
            'published/blog.txt',
            'public/assets/modules/blog/module.txt',
            'blog-v1'
        );

        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/history.json',
            json_encode(
                ['schema' => 1, 'files' => []],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
    }

    protected function tearDown(): void
    {
        (new ComposerFilesystem())->removeDirectory($this->fixtureRoot);
    }

    public function testCoreOnlyPublishesNoOptionalModuleFiles(): void
    {
        $this->sync([]);

        self::assertFileDoesNotExist(
            $this->projectRoot . '/public/assets/modules/webadmin/module.txt'
        );
        self::assertFileDoesNotExist(
            $this->projectRoot . '/public/assets/modules/blog/module.txt'
        );
    }

    public function testMissingModuleOriginPreventsPartialPublication(): void
    {
        $manifestPath = $this->modulesRoot
            . '/webadmin/module.json';
        $manifest = json_decode(
            (string) file_get_contents($manifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $manifest['project_files'][] = [
            'source' => 'published/missing.txt',
            'target' => 'public/assets/modules/webadmin/missing.txt',
            'type' => 'file',
            'policy' => 'managed_hash',
        ];
        $this->filesystem->dumpFile(
            $manifestPath,
            json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );

        $this->sync(['liquidstack/webadmin']);

        self::assertFileDoesNotExist(
            $this->projectRoot
                . '/public/assets/modules/webadmin/module.txt'
        );
    }

    public function testLinkedDependencyOriginPreventsAllOptionalPublication(): void
    {
        $this->configureWebadminDirectoryEntry();
        $source = $this->modulesRoot . '/webadmin/bundle';
        $linkedSource = $this->fixtureRoot . '/linked-source';
        $this->filesystem->remove($source);
        $this->filesystem->dumpFile(
            $linkedSource . '/nested/app.js',
            'linked'
        );

        if (!$this->createDirectoryLink($linkedSource, $source)) {
            self::markTestSkipped(
                'This environment cannot create a source directory link.'
            );
        }

        $this->sync(['liquidstack/blog']);

        self::assertFileDoesNotExist(
            $this->projectRoot
                . '/public/assets/modules/webadmin/nested/app.js'
        );
        self::assertFileDoesNotExist(
            $this->projectRoot
                . '/public/assets/modules/blog/module.txt'
        );
    }

    public function testWebadminPublishesOnlyItsFiles(): void
    {
        $this->sync(['liquidstack/webadmin']);

        self::assertSame(
            'webadmin-v1',
            file_get_contents(
                $this->projectRoot . '/public/assets/modules/webadmin/module.txt'
            )
        );
        self::assertFileDoesNotExist(
            $this->projectRoot . '/public/assets/modules/blog/module.txt'
        );
    }

    public function testBlogPublishesItsDependencyFirstAndIsIdempotent(): void
    {
        $first = $this->sync(['liquidstack/blog']);
        $second = $this->sync(['liquidstack/blog']);

        self::assertSame(2, $first->stats()['added']);
        self::assertSame(2, $second->stats()['unchanged']);
        self::assertSame(
            'webadmin-v1',
            file_get_contents(
                $this->projectRoot . '/public/assets/modules/webadmin/module.txt'
            )
        );
        self::assertSame(
            'blog-v1',
            file_get_contents(
                $this->projectRoot . '/public/assets/modules/blog/module.txt'
            )
        );
    }

    public function testDirectoryEntryPublishesNestedFiles(): void
    {
        $this->configureWebadminDirectoryEntry();

        $this->sync(['liquidstack/webadmin']);

        self::assertSame(
            'nested-app',
            file_get_contents(
                $this->projectRoot
                    . '/public/assets/modules/webadmin/nested/app.js'
            )
        );
    }

    public function testNestedLinkedTargetCannotWriteOutsideProject(): void
    {
        $this->configureWebadminDirectoryEntry();
        $targetRoot = $this->projectRoot
            . '/public/assets/modules/webadmin';
        $externalRoot = $this->fixtureRoot . '/external-target';
        $this->filesystem->mkdir([$targetRoot, $externalRoot]);

        if (!$this->createDirectoryLink(
            $externalRoot,
            $targetRoot . '/nested'
        )) {
            self::markTestSkipped(
                'This environment cannot create a target directory link.'
            );
        }

        $this->sync(['liquidstack/webadmin']);

        self::assertFileDoesNotExist($externalRoot . '/app.js');
    }

    public function testLocalModuleCustomizationIsPreservedOnLaterSync(): void
    {
        $this->sync(['liquidstack/blog']);
        $target = $this->projectRoot
            . '/public/assets/modules/webadmin/module.txt';
        $source = $this->modulesRoot
            . '/webadmin/published/webadmin.txt';
        $this->filesystem->dumpFile($target, 'custom-project-version');
        $this->filesystem->dumpFile($source, 'webadmin-v2');

        $sync = $this->sync(['liquidstack/blog']);

        self::assertSame('custom-project-version', file_get_contents($target));
        self::assertSame(1, $sync->stats()['preserved']);
    }

    public function testCustomizationOnlyBlocksItsOwnManifestEntry(): void
    {
        $manifestPath = $this->modulesRoot
            . '/webadmin/module.json';
        $manifest = json_decode(
            (string) file_get_contents($manifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $manifest['project_files'][] = [
            'source' => 'published/secondary.txt',
            'target' => 'public/assets/modules/webadmin/secondary.txt',
            'type' => 'file',
            'policy' => 'managed_hash',
        ];
        $this->filesystem->dumpFile(
            $manifestPath,
            json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
        $secondarySource = $this->modulesRoot
            . '/webadmin/published/secondary.txt';
        $this->filesystem->dumpFile($secondarySource, 'secondary-v1');
        $this->sync(['liquidstack/webadmin']);

        $primaryTarget = $this->projectRoot
            . '/public/assets/modules/webadmin/module.txt';
        $secondaryTarget = $this->projectRoot
            . '/public/assets/modules/webadmin/secondary.txt';
        $this->filesystem->dumpFile($primaryTarget, 'local-primary');
        $this->filesystem->dumpFile(
            $this->modulesRoot . '/webadmin/published/webadmin.txt',
            'webadmin-v2'
        );
        $this->filesystem->dumpFile($secondarySource, 'secondary-v2');

        $sync = $this->sync(['liquidstack/webadmin']);

        self::assertSame('local-primary', file_get_contents($primaryTarget));
        self::assertSame('secondary-v2', file_get_contents($secondaryTarget));
        self::assertSame(1, $sync->stats()['preserved']);
        self::assertSame(1, $sync->stats()['updated']);
    }

    public function testInstallIfMissingModuleSeedPreservesExistingFile(): void
    {
        $manifestPath = $this->modulesRoot
            . '/webadmin/module.json';
        $manifest = json_decode(
            (string) file_get_contents($manifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $manifest['project_files'][0]['policy'] = 'install_if_missing';
        $this->filesystem->dumpFile(
            $manifestPath,
            json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
        $target = $this->projectRoot
            . '/public/assets/modules/webadmin/module.txt';
        $this->filesystem->dumpFile($target, 'project-seed');

        $sync = $this->sync(['liquidstack/webadmin']);

        self::assertSame('project-seed', file_get_contents($target));
        self::assertSame(1, $sync->stats()['protected']);
    }

    public function testAdditiveModuleJsonPreservesValuesAndAddsKeys(): void
    {
        $manifestPath = $this->modulesRoot
            . '/webadmin/module.json';
        $manifest = json_decode(
            (string) file_get_contents($manifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $manifest['project_files'] = [[
            'source' => 'published/catalog.json',
            'target' => 'public/assets/modules/webadmin/catalog.json',
            'type' => 'file',
            'policy' => 'merge_json_additive',
        ]];
        $this->filesystem->dumpFile(
            $manifestPath,
            json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
        $this->filesystem->dumpFile(
            $this->modulesRoot
                . '/webadmin/published/catalog.json',
            '{"existing":"core","added":"new"}'
        );
        $target = $this->projectRoot
            . '/public/assets/modules/webadmin/catalog.json';
        $this->filesystem->dumpFile(
            $target,
            '{"existing":"project"}'
        );

        $sync = $this->sync(['liquidstack/webadmin']);
        $merged = json_decode(
            (string) file_get_contents($target),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('project', $merged['existing']);
        self::assertSame('new', $merged['added']);
        self::assertSame(1, $sync->stats()['merged']);
    }

    public function testTrackStateFalseDoesNotClaimModuleAsset(): void
    {
        $manifestPath = $this->modulesRoot
            . '/webadmin/module.json';
        $manifest = json_decode(
            (string) file_get_contents($manifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $manifest['project_files'][0]['track_state'] = false;
        $this->filesystem->dumpFile(
            $manifestPath,
            json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );

        $this->sync(['liquidstack/webadmin']);
        $state = json_decode(
            (string) file_get_contents(
                $this->projectRoot
                    . '/.liquidstack/core/managed-files.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertArrayNotHasKey(
            'public/assets/modules/webadmin/module.txt',
            $state['files']
        );
    }

    public function testDisablingModuleNeverDeletesFilesDataOrProjectConfig(): void
    {
        $sentinels = [
            '.env' => 'SECRET=local',
            'App/config/routes/get.php' => '<?php return ["local" => true];',
            'App/config/routes/post.php' => '<?php return ["local" => true];',
            'public/robots.txt' => 'User-agent: *',
            'public/sitemap.xml' => '<urlset/>',
            'storage/blog/media/customer.avif' => 'media',
            'database/customer.sqlite' => 'database',
        ];
        foreach ($sentinels as $relative => $contents) {
            $this->filesystem->dumpFile(
                $this->projectRoot . '/' . $relative,
                $contents
            );
        }

        $this->sync(['liquidstack/blog']);
        $this->sync([]);

        self::assertFileExists(
            $this->projectRoot . '/public/assets/modules/webadmin/module.txt'
        );
        self::assertFileExists(
            $this->projectRoot . '/public/assets/modules/blog/module.txt'
        );
        foreach ($sentinels as $relative => $contents) {
            self::assertSame(
                $contents,
                file_get_contents($this->projectRoot . '/' . $relative),
                $relative
            );
        }
    }

    /**
     * @param list<string> $requirements
     */
    private function sync(array $requirements): ManagedFileSynchronizer
    {
        $io = new BufferIO();
        $catalog = ModuleCatalog::fromModulesRoot($this->modulesRoot);
        $selection = ModuleSelection::fromRequirementNames(
            $catalog,
            $requirements
        );
        $synchronizer = new ManagedFileSynchronizer(
            $this->projectRoot,
            $this->fixtureRoot,
            $io,
            $this->fixtureRoot . '/history.json',
            $this->projectRoot . '/.liquidstack/core/managed-files.json'
        );

        (new ModuleProjectFileSynchronizer(
            $this->projectRoot,
            $io
        ))->queue($selection, $synchronizer);
        $synchronizer->apply();

        return $synchronizer;
    }

    private function configureWebadminDirectoryEntry(): void
    {
        $manifestPath = $this->modulesRoot
            . '/webadmin/module.json';
        $manifest = json_decode(
            (string) file_get_contents($manifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $manifest['project_files'] = [[
            'source' => 'bundle',
            'target' => 'public/assets/modules/webadmin',
            'type' => 'dir',
            'policy' => 'managed_hash',
        ]];
        $this->filesystem->dumpFile(
            $manifestPath,
            json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
        $this->filesystem->dumpFile(
            $this->modulesRoot
                . '/webadmin/bundle/nested/app.js',
            'nested-app'
        );
    }

    private function createDirectoryLink(
        string $target,
        string $link
    ): bool {
        if (@symlink($target, $link)) {
            return true;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        try {
            (new ComposerFilesystem())->junction($target, $link);
        } catch (Throwable) {
            return false;
        }

        return is_dir($link);
    }

    /**
     * @param list<string> $requires
     */
    private function createModule(
        string $id,
        string $package,
        array $requires,
        string $source,
        string $target,
        string $contents
    ): void {
        $moduleRoot = $this->modulesRoot . '/' . $id;
        $manifest = [
            'schema' => 1,
            'id' => $id,
            'package' => $package,
            'requires' => $requires,
            'providers' => [],
            'project_files' => [[
                'source' => $source,
                'target' => $target,
                'type' => 'file',
                'policy' => 'managed_hash',
            ]],
        ];

        $this->filesystem->dumpFile(
            $moduleRoot . '/module.json',
            json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
        $this->filesystem->dumpFile(
            $moduleRoot . '/' . $source,
            $contents
        );
    }
}
