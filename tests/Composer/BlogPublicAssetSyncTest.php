<?php

declare(strict_types=1);

use App\Core\Composer\ManagedFileSynchronizer;
use App\Core\Composer\ModuleProjectFileSynchronizer;
use App\Core\Modules\ModuleCatalog;
use App\Core\Modules\ModuleSelection;
use Composer\IO\BufferIO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogPublicAssetSyncTest extends TestCase
{
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-blog-public-asset-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectRoot);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
    }

    public function testBlogSelectionPublishesTheStandaloneStylesheet(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $catalog = ModuleCatalog::fromCoreRoot($coreRoot);
        $selection = ModuleSelection::fromRequirementNames(
            $catalog,
            ['liquidstack/blog']
        );
        $io = new BufferIO();
        $synchronizer = new ManagedFileSynchronizer(
            $this->projectRoot,
            $coreRoot,
            $io
        );

        (new ModuleProjectFileSynchronizer(
            $this->projectRoot,
            $io
        ))->queue($selection, $synchronizer);
        $synchronizer->apply();

        $target = $this->projectRoot
            . '/public/assets/modules/blog/blog-public.css';
        self::assertFileExists($target);
        self::assertSame(
            file_get_contents(
                $coreRoot . '/modules/blog/published/assets/blog-public.css'
            ),
            file_get_contents($target)
        );
        self::assertSame(0, $synchronizer->stats()['errors']);
    }
}
