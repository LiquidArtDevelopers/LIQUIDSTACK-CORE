<?php

declare(strict_types=1);

namespace Tests\WebAdmin\Media;

use App\Core\WebAdmin\Media\MediaException;
use App\Core\WebAdmin\Media\MediaStoredVariant;
use App\Core\WebAdmin\Media\PrivateMediaStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class PrivateMediaStorageTest extends TestCase
{
    private string $sandbox;
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->sandbox = sys_get_temp_dir() . '/liquidstack-media-storage-'
            . bin2hex(random_bytes(8));
        $this->projectRoot = $this->sandbox . '/project';
        $this->filesystem->mkdir([
            $this->projectRoot,
            $this->projectRoot . '/public',
            $this->projectRoot . '/vendor',
            $this->projectRoot . '/.git',
        ]);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->sandbox);
    }

    public function testLocalDevelopmentUsesOnlyTheCanonicalPrivateDefault(): void
    {
        $storage = PrivateMediaStorage::forProject($this->projectRoot, [
            'DEV_MODE' => '1',
            'RAIZ' => 'http://localhost:1309',
        ]);

        $staging = $storage->createStagingDirectory();
        self::assertDirectoryExists($staging);
        self::assertStringStartsWith(
            str_replace('\\', '/', $this->projectRoot)
                . '/storage/liquidstack/webadmin/media/.staging/',
            str_replace('\\', '/', $staging)
        );

        $this->expectException(MediaException::class);
        PrivateMediaStorage::forProject($this->projectRoot, [
            'DEV_MODE' => '1',
            'RAIZ' => 'http://localhost:1309',
            PrivateMediaStorage::ROOT_ENV => $this->projectRoot . '/src/media',
        ]);
    }

    public function testProductionRequiresExplicitStorageOutsideProject(): void
    {
        try {
            PrivateMediaStorage::forProject($this->projectRoot, [
                'DEV_MODE' => '0',
                'RAIZ' => 'https://example.test',
            ]);
            self::fail('Production storage must be configured explicitly.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.storage_configuration_missing',
                $exception->issueCode()
            );
        }

        try {
            PrivateMediaStorage::forProject($this->projectRoot, [
                'DEV_MODE' => '0',
                'RAIZ' => 'https://example.test',
                PrivateMediaStorage::ROOT_ENV => $this->projectRoot
                    . '/storage/liquidstack/webadmin/media',
            ]);
            self::fail('Production storage cannot live in the deploy tree.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.storage_root_dangerous',
                $exception->issueCode()
            );
        }

        $storage = PrivateMediaStorage::forProject($this->projectRoot, [
            'DEV_MODE' => '0',
            'RAIZ' => 'https://example.test',
            PrivateMediaStorage::ROOT_ENV => $this->sandbox . '/persistent-media',
        ]);
        self::assertDirectoryExists($storage->createStagingDirectory());
    }

    /** @dataProvider dangerousRootProvider */
    public function testDangerousRootsAreRejected(string $relative): void
    {
        $root = match ($relative) {
            '[project]' => $this->projectRoot,
            '[filesystem]' => DIRECTORY_SEPARATOR === '\\'
                ? substr($this->projectRoot, 0, 3)
                : '/',
            default => $this->projectRoot . '/' . $relative,
        };

        $this->expectException(MediaException::class);
        new PrivateMediaStorage($this->projectRoot, $root);
    }

    /** @return iterable<string, array{string}> */
    public static function dangerousRootProvider(): iterable
    {
        yield 'project root' => ['[project]'];
        yield 'filesystem root' => ['[filesystem]'];
        yield 'public' => ['public/media'];
        yield 'vendor' => ['vendor/media'];
        yield 'git metadata' => ['.git/media'];
        yield 'arbitrary deploy directory' => ['src/media'];
        yield 'traversal' => ['storage/../outside'];
    }

    public function testStagingPromotionVerifiedReadAndCleanupAreAtomicBoundaries(): void
    {
        $storage = new PrivateMediaStorage(
            $this->projectRoot,
            $this->sandbox . '/persistent-media'
        );
        $staging = $storage->createStagingDirectory();
        $contents = 'deterministic-avif-test-payload';
        file_put_contents($staging . '/480.avif', $contents);
        $publicId = '12345678-1234-4234-8234-123456789abc';

        $storage->promote($staging, $publicId);
        self::assertDirectoryDoesNotExist($staging);
        self::assertSame(
            '12/' . $publicId . '/480.avif',
            $storage->storageKey($publicId, 480)
        );

        $stored = new MediaStoredVariant(
            $storage->storageKey($publicId, 480),
            480,
            320,
            strlen($contents),
            hash('sha256', $contents)
        );
        $payload = $storage->readVerified($stored);
        self::assertSame($contents, $payload->contents());
        self::assertSame(strlen($contents), $payload->bytes());
        self::assertSame([
            'ready' => true,
            'status' => 'ready',
            'orphan_count' => 0,
            'orphan_scan_status' => 'checked',
            'staging_count' => 0,
        ], $storage->diagnostic([$publicId]));

        $storage->removeAsset($publicId);
        self::assertSame(0, $storage->diagnostic([])['orphan_count']);
    }

    public function testIntegrityMismatchAndTraversalKeysFailClosed(): void
    {
        $storage = new PrivateMediaStorage(
            $this->projectRoot,
            $this->sandbox . '/persistent-media'
        );
        $staging = $storage->createStagingDirectory();
        file_put_contents($staging . '/480.avif', 'payload');
        $publicId = '12345678-1234-4234-8234-123456789abc';
        $storage->promote($staging, $publicId);

        try {
            $storage->readVerified(new MediaStoredVariant(
                '12/' . $publicId . '/480.avif',
                480,
                320,
                7,
                str_repeat('a', 64)
            ));
            self::fail('A hash mismatch must fail closed.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.file_integrity_failed',
                $exception->issueCode()
            );
        }

        $this->expectException(MediaException::class);
        $storage->readVerified(new MediaStoredVariant(
            '../' . $publicId . '/480.avif',
            480,
            320,
            7,
            hash('sha256', 'payload')
        ));
    }

    public function testSymlinkedStorageAncestorIsRejectedWhenSupported(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('symlink no esta disponible.');
        }
        $target = $this->sandbox . '/target';
        $link = $this->sandbox . '/linked';
        $this->filesystem->mkdir($target);
        if (!@symlink($target, $link)) {
            self::markTestSkipped('El entorno no permite crear symlinks.');
        }

        $this->expectException(MediaException::class);
        new PrivateMediaStorage($this->projectRoot, $link . '/media');
    }

    public function testCleanupRejectsSymlinkedAliasBeforeRealpathWhenSupported(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('symlink no esta disponible.');
        }
        $storage = new PrivateMediaStorage(
            $this->projectRoot,
            $this->sandbox . '/persistent-media'
        );
        $staging = $storage->createStagingDirectory();
        $stagingRoot = dirname($staging);
        $alias = $this->sandbox . '/staging-alias';
        if (!@symlink($stagingRoot, $alias)) {
            self::markTestSkipped('El entorno no permite crear symlinks.');
        }

        try {
            $storage->removeStaging($alias . '/' . basename($staging));
            self::fail('Cleanup must inspect the unresolved ancestor chain.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.storage_symlink_rejected',
                $exception->issueCode()
            );
            self::assertDirectoryExists($staging);
        } finally {
            @unlink($alias);
        }
    }
}
