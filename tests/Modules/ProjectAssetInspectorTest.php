<?php

declare(strict_types=1);

use App\Core\Modules\Diagnostics\ProjectAssetInspector;
use App\Core\Modules\Diagnostics\ProjectPublicRootResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ProjectAssetInspectorTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-public-root-' . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove([$this->root, $this->root . '-outside']);
    }

    public function testLogicalPublicAssetsResolveInWwwWithoutRemappingSourceFiles(): void
    {
        $this->filesystem->dumpFile($this->root . '/www/assets/app.js', 'runtime');
        $this->filesystem->dumpFile($this->root . '/src/js/app.js', 'source');
        $required = ['public/assets/app.js', 'src/js/app.js'];

        $report = (new ProjectAssetInspector())->inspect($this->root, $required);

        self::assertTrue($report['ready']);
        self::assertSame($required, $report['required']);
        self::assertSame([], $report['missing']);
        self::assertSame('www', (new ProjectPublicRootResolver())
            ->relativeDirectory($this->root));
    }

    public function testPublicTakesPriorityWhenBothDirectoriesExist(): void
    {
        $this->filesystem->dumpFile($this->root . '/www/assets/app.js', 'runtime');
        $this->filesystem->mkdir($this->root . '/public');

        $report = (new ProjectAssetInspector())->inspect(
            $this->root,
            ['public/assets/app.js']
        );

        self::assertFalse($report['ready']);
        self::assertSame(['public/assets/app.js'], $report['missing']);
        self::assertSame('public', (new ProjectPublicRootResolver())
            ->relativeDirectory($this->root));
    }

    public function testInvalidPublicPathDoesNotFallBackToValidWww(): void
    {
        $this->filesystem->dumpFile($this->root . '/public', 'not a directory');
        $this->filesystem->dumpFile($this->root . '/www/assets/app.js', 'runtime');

        self::assertNull((new ProjectPublicRootResolver())
            ->relativeDirectory($this->root));
        self::assertFalse((new ProjectAssetInspector())->inspect(
            $this->root,
            ['public/assets/app.js']
        )['ready']);
    }

    public function testAbsentRootsAndTraversalFailClosed(): void
    {
        self::assertNull((new ProjectPublicRootResolver())
            ->relativeDirectory($this->root));
        $this->filesystem->dumpFile($this->root . '/www/assets/app.js', 'runtime');
        $report = (new ProjectAssetInspector())->inspect(
            $this->root,
            ['public/../www/assets/app.js']
        );

        self::assertFalse($report['ready']);
        self::assertSame(['public/../www/assets/app.js'], $report['invalid']);
    }

    public function testWwwCannotPointOutsideProject(): void
    {
        $this->filesystem->dumpFile(
            $this->root . '-outside/assets/app.js',
            'outside'
        );
        if (!@symlink($this->root . '-outside', $this->root . '/www')) {
            self::markTestSkipped('Creating directory symlinks is not permitted.');
        }

        self::assertNull((new ProjectPublicRootResolver())
            ->relativeDirectory($this->root));
        self::assertFalse((new ProjectAssetInspector())->inspect(
            $this->root,
            ['public/assets/app.js']
        )['ready']);
    }

    public function testLinkedPublicDoesNotFallBackToWww(): void
    {
        $this->filesystem->dumpFile($this->root . '/www/assets/app.js', 'runtime');
        if (!@symlink($this->root . '/www', $this->root . '/public')) {
            self::markTestSkipped('Creating directory symlinks is not permitted.');
        }

        self::assertNull((new ProjectPublicRootResolver())
            ->relativeDirectory($this->root));
    }
}
