<?php

declare(strict_types=1);

namespace Tests\WebAdmin\Media;

use App\Core\WebAdmin\Media\MediaException;
use App\Core\WebAdmin\Media\MediaStoredVariant;
use App\Core\WebAdmin\Media\PrivateMediaStorage;
use Composer\Util\Filesystem as ComposerFilesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

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

        $initialized = $storage->initialize();
        self::assertTrue($initialized->changed());
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
        $storage->initialize();
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
        $storage->initialize();
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
        $storage->initialize();
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
        $storage->initialize();
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

    public function testInitializationIsExplicitIdempotentAndPathFree(): void
    {
        $root = $this->sandbox . '/explicit-media';
        $storage = new PrivateMediaStorage($this->projectRoot, $root);

        self::assertSame('not_initialized', $storage->diagnostic()['status']);
        $first = $storage->initialize();
        self::assertSame([
            'status' => 'initialized',
            'changed' => true,
            'marker_schema' => 1,
        ], $first->toSafeArray());
        self::assertFileExists(
            $root . '/' . PrivateMediaStorage::INITIALIZATION_MARKER
        );
        self::assertDirectoryExists($root . '/.staging');
        self::assertSame("*\n", file_get_contents($root . '/.gitignore'));
        self::assertTrue($storage->diagnostic()['ready']);

        self::assertSame([
            'status' => 'already_initialized',
            'changed' => false,
            'marker_schema' => 1,
        ], $storage->initialize()->toSafeArray());
    }

    public function testUnmarkedNonEmptyRootRequiresExplicitAdoption(): void
    {
        $root = $this->sandbox . '/foreign-media';
        $this->filesystem->mkdir($root);
        $this->filesystem->dumpFile($root . '/foreign.txt', 'preserve');
        $storage = new PrivateMediaStorage($this->projectRoot, $root);

        try {
            $storage->initialize();
            self::fail('An unmarked non-empty root must not be adopted.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.storage_requires_explicit_adoption',
                $exception->issueCode()
            );
        }

        self::assertSame('preserve', file_get_contents($root . '/foreign.txt'));
        self::assertFileDoesNotExist(
            $root . '/' . PrivateMediaStorage::INITIALIZATION_MARKER
        );
        self::assertSame(
            ['.', '..', 'foreign.txt'],
            scandir($root)
        );
    }

    /** @dataProvider unstableAbsolutePathProvider */
    public function testUnstableAbsolutePathFormsAreRejected(string $root): void
    {
        try {
            new PrivateMediaStorage($this->projectRoot, $root);
            self::fail('An unstable absolute path must be rejected.');
        } catch (MediaException $exception) {
            self::assertContains($exception->issueCode(), [
                'webadmin.media.storage_root_not_absolute',
                'webadmin.media.storage_path_invalid',
            ]);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unstableAbsolutePathProvider(): iterable
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            yield 'drive relative root' => ['\\temporary\\media'];
            yield 'UNC root' => ['\\\\server\\share\\media'];
            yield 'device path' => ['\\\\?\\C:\\temporary\\media'];
            yield 'trailing dot' => ['C:\\temporary.\\media'];
            yield 'alternate stream' => ['C:\\temporary:stream\\media'];

            return;
        }

        yield 'double slash root' => ['//temporary/media'];
    }

    public function testInvalidMarkerFailsDiagnosticClosed(): void
    {
        $root = $this->sandbox . '/invalid-marker';
        $this->filesystem->mkdir([$root, $root . '/.staging']);
        $this->filesystem->dumpFile(
            $root . '/' . PrivateMediaStorage::INITIALIZATION_MARKER,
            'not-a-liquidstack-marker'
        );
        $storage = new PrivateMediaStorage($this->projectRoot, $root);

        self::assertSame([
            'ready' => false,
            'status' => 'invalid',
            'orphan_count' => null,
            'orphan_scan_status' => 'not_checked',
            'staging_count' => 0,
        ], $storage->diagnostic());
    }

    public function testFileAtStorageRootIsInvalidRatherThanUninitialized(): void
    {
        $root = $this->sandbox . '/occupied-root';
        $this->filesystem->dumpFile($root, 'foreign');
        $storage = new PrivateMediaStorage($this->projectRoot, $root);

        self::assertSame('invalid', $storage->diagnostic()['status']);
        try {
            $storage->initialize();
            self::fail('A file at the storage root must not be replaced.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.storage_root_invalid',
                $exception->issueCode()
            );
        }
        self::assertSame('foreign', file_get_contents($root));
    }

    public function testCanonicalGitIgnoreCannotBeSilentlyChanged(): void
    {
        $root = $this->sandbox . '/modified-ignore';
        $storage = new PrivateMediaStorage($this->projectRoot, $root);
        $storage->initialize();
        $this->filesystem->dumpFile($root . '/.gitignore', "!.staging\n");

        self::assertSame('invalid', $storage->diagnostic()['status']);
        try {
            $storage->initialize();
            self::fail('A modified storage ignore file must fail closed.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.storage_ignore_invalid',
                $exception->issueCode()
            );
        }
    }

    public function testInitializationRecoversOnlyItsCanonicalPartialScaffold(): void
    {
        $root = $this->sandbox . '/partial-scaffold';
        $this->filesystem->mkdir([$root, $root . '/.staging']);
        $this->filesystem->dumpFile($root . '/.gitignore', "*\n");
        $storage = new PrivateMediaStorage($this->projectRoot, $root);

        self::assertTrue($storage->initialize()->changed());
        self::assertTrue($storage->diagnostic()['ready']);

        self::assertTrue(unlink($root . '/.gitignore'));
        self::assertTrue($storage->initialize()->changed());
        self::assertSame("*\n", file_get_contents($root . '/.gitignore'));
        self::assertFalse($storage->initialize()->changed());
    }

    public function testWindowsJunctionAncestorIsRejectedWhenSupported(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('NTFS junctions only exist on Windows.');
        }
        $target = $this->sandbox . '/junction-target';
        $junction = $this->sandbox . '/junction-root';
        $this->filesystem->mkdir($target);
        $composerFilesystem = new ComposerFilesystem();
        try {
            $composerFilesystem->junction($target, $junction);
        } catch (\Throwable) {
            self::markTestSkipped('The environment cannot create junctions.');
        }

        try {
            new PrivateMediaStorage(
                $this->projectRoot,
                $junction . '/media'
            );
            self::fail('A junctioned storage ancestor must be rejected.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.storage_symlink_rejected',
                $exception->issueCode()
            );
        } finally {
            if ($composerFilesystem->isJunction($junction)) {
                self::assertTrue(
                    $composerFilesystem->removeJunction($junction)
                );
            }
        }
    }

    public function testWindowsJunctionCannotReplaceOwnedStaging(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('NTFS junctions only exist on Windows.');
        }
        $root = $this->sandbox . '/junction-staging-media';
        $target = $this->sandbox . '/external-staging-target';
        $sentinel = $target . '/sentinel.txt';
        $storage = new PrivateMediaStorage($this->projectRoot, $root);
        $storage->initialize();
        self::assertTrue(rmdir($root . '/.staging'));
        $this->filesystem->mkdir($target);
        $this->filesystem->dumpFile($sentinel, 'preserve');
        $composerFilesystem = new ComposerFilesystem();
        try {
            $composerFilesystem->junction($target, $root . '/.staging');
        } catch (\Throwable) {
            self::markTestSkipped('The environment cannot create junctions.');
        }

        try {
            $storage->createStagingDirectory();
            self::fail('A junctioned staging directory must be rejected.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.storage_symlink_rejected',
                $exception->issueCode()
            );
            self::assertSame('preserve', file_get_contents($sentinel));
        } finally {
            $junction = $root . '/.staging';
            if ($composerFilesystem->isJunction($junction)) {
                self::assertTrue(
                    $composerFilesystem->removeJunction($junction)
                );
            }
        }
    }

    public function testConcurrentInitializersConvergeOnOneCanonicalState(): void
    {
        $root = $this->sandbox . '/concurrent-media';
        $script = $this->sandbox . '/initialize.php';
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $this->filesystem->dumpFile(
            $script,
            '<?php require ' . var_export($autoload, true) . '; '
                . '$storage = new \\App\\Core\\WebAdmin\\Media\\PrivateMediaStorage('
                . var_export($this->projectRoot, true) . ', '
                . var_export($root, true) . '); '
                . 'echo json_encode($storage->initialize()->toSafeArray(), '
                . 'JSON_THROW_ON_ERROR);'
        );
        $first = new Process([PHP_BINARY, $script]);
        $second = new Process([PHP_BINARY, $script]);

        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        self::assertTrue($first->isSuccessful(), $first->getErrorOutput());
        self::assertTrue($second->isSuccessful(), $second->getErrorOutput());
        $statuses = [
            json_decode($first->getOutput(), true, 512, JSON_THROW_ON_ERROR)[
                'status'
            ],
            json_decode($second->getOutput(), true, 512, JSON_THROW_ON_ERROR)[
                'status'
            ],
        ];
        sort($statuses);
        self::assertSame(
            ['already_initialized', 'initialized'],
            $statuses
        );
        $storage = new PrivateMediaStorage($this->projectRoot, $root);
        self::assertTrue($storage->diagnostic()['ready']);
        self::assertSame("*\n", file_get_contents($root . '/.gitignore'));
    }
}
