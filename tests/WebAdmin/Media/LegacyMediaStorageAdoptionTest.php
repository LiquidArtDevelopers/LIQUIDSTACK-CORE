<?php

declare(strict_types=1);

namespace Tests\WebAdmin\Media;

use App\Core\WebAdmin\Media\MediaException;
use App\Core\WebAdmin\Media\PdoLegacyMediaStorageAdopter;
use App\Core\WebAdmin\Media\PrivateMediaStorage;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use FilesystemIterator;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Filesystem\Filesystem;

final class LegacyMediaStorageAdoptionTest extends TestCase
{
    private const ASSET = '12345678-1234-4234-8234-123456789abc';
    private Filesystem $filesystem;
    private string $sandbox;
    private string $projectRoot;
    private string $storageRoot;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->sandbox = sys_get_temp_dir()
            . '/liquidstack-media-adoption-' . bin2hex(random_bytes(8));
        $this->projectRoot = $this->sandbox . '/project';
        $this->storageRoot = $this->sandbox . '/legacy-media';
        $this->filesystem->mkdir($this->projectRoot);

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec(
            'CREATE TABLE ls_webadmin_state ('
            . 'state_key TEXT PRIMARY KEY, value_text TEXT NOT NULL)'
        );
        $this->pdo->exec(
            'CREATE TABLE ls_webadmin_media_assets ('
            . 'id INTEGER PRIMARY KEY, public_id TEXT NOT NULL UNIQUE)'
        );
        $this->pdo->exec(
            'CREATE TABLE ls_webadmin_media_variants ('
            . 'id INTEGER PRIMARY KEY, asset_id INTEGER NOT NULL, '
            . 'width INTEGER NOT NULL, height INTEGER NOT NULL, '
            . 'bytes INTEGER NOT NULL, sha256 TEXT NOT NULL, '
            . 'storage_key TEXT NOT NULL UNIQUE, mime TEXT NOT NULL, '
            . 'FOREIGN KEY (asset_id) REFERENCES ls_webadmin_media_assets(id))'
        );
        $this->pdo->exec(
            "INSERT INTO ls_webadmin_state VALUES ('media.quota_lock', 'v1')"
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->sandbox);
    }

    public function testExactLegacyDatabaseAndFilesystemAreAdoptedIdempotently(): void
    {
        $contents = $this->seedExactLegacyVariant();
        $storage = $this->storage();
        $adopter = $this->adopter();

        $result = $adopter->adopt($storage);

        self::assertSame([
            'status' => 'adopted_existing',
            'changed' => true,
            'marker_schema' => 1,
        ], $result->toSafeArray());
        self::assertSame(
            $contents,
            file_get_contents($this->variantPath())
        );
        self::assertFileExists(
            $this->storageRoot . '/'
                . PrivateMediaStorage::INITIALIZATION_MARKER
        );
        self::assertSame("*\n", file_get_contents(
            $this->storageRoot . '/.gitignore'
        ));
        self::assertTrue($storage->diagnostic([self::ASSET])['ready']);
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_media_assets'
        )->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_media_variants'
        )->fetchColumn());

        self::assertSame('already_initialized',
            $adopter->adopt($storage)->status());
    }

    #[DataProvider('mismatchProvider')]
    public function testAnyLegacyMismatchLeavesTheRootByteForByteUntouched(
        string $mismatch
    ): void {
        $this->seedExactLegacyVariant();
        match ($mismatch) {
            'missing_file' => unlink($this->variantPath()),
            'extra_file' => file_put_contents(
                dirname($this->variantPath()) . '/900.avif',
                'unexpected'
            ),
            'hash' => $this->pdo->exec(
                "UPDATE ls_webadmin_media_variants SET sha256 = '"
                . str_repeat('a', 64) . "'"
            ),
            'storage_key' => $this->pdo->exec(
                "UPDATE ls_webadmin_media_variants SET storage_key = "
                . "'12/" . self::ASSET . "/900.avif'"
            ),
            'mime' => $this->pdo->exec(
                "UPDATE ls_webadmin_media_variants SET mime = 'image/png'"
            ),
            'staging' => file_put_contents(
                $this->storageRoot . '/.staging/unfinished.avif',
                'unfinished'
            ),
            default => self::fail('Unknown mismatch fixture.'),
        };
        $before = $this->snapshot($this->storageRoot);

        try {
            $this->adopter()->adopt($this->storage());
            self::fail('A DB/filesystem mismatch must fail closed.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.storage_adoption_mismatch',
                $exception->issueCode()
            );
        }

        self::assertSame($before, $this->snapshot($this->storageRoot));
        self::assertFileDoesNotExist(
            $this->storageRoot . '/'
                . PrivateMediaStorage::INITIALIZATION_MARKER
        );
        self::assertFalse($this->pdo->inTransaction());
    }

    /** @return iterable<string, array{string}> */
    public static function mismatchProvider(): iterable
    {
        yield 'DB variant without file' => ['missing_file'];
        yield 'filesystem variant without DB row' => ['extra_file'];
        yield 'hash mismatch' => ['hash'];
        yield 'non-canonical storage key' => ['storage_key'];
        yield 'non-AVIF DB MIME' => ['mime'];
        yield 'non-empty staging' => ['staging'];
    }

    public function testAssetWithoutVariantsAndFilesystemWithoutRowsAreRejected(): void
    {
        $this->filesystem->mkdir($this->storageRoot . '/.staging');
        $this->pdo->exec(
            "INSERT INTO ls_webadmin_media_assets (id, public_id) VALUES "
            . "(1, '" . self::ASSET . "')"
        );
        $before = $this->snapshot($this->storageRoot);

        try {
            $this->adopter()->adopt($this->storage());
            self::fail('An asset without variants must not be adopted.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.storage_adoption_mismatch',
                $exception->issueCode()
            );
        }
        self::assertSame($before, $this->snapshot($this->storageRoot));

        $this->pdo->exec('DELETE FROM ls_webadmin_media_assets');
        $this->filesystem->mkdir(dirname($this->variantPath()));
        file_put_contents($this->variantPath(), 'filesystem-only');
        $before = $this->snapshot($this->storageRoot);
        try {
            $this->adopter()->adopt($this->storage());
            self::fail('Filesystem-only media must not be adopted.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.storage_adoption_mismatch',
                $exception->issueCode()
            );
        }
        self::assertSame($before, $this->snapshot($this->storageRoot));
    }

    public function testEmptyLegacyRootIsNotSilentlyTreatedAsAdoption(): void
    {
        $this->filesystem->mkdir($this->storageRoot . '/.staging');
        $before = $this->snapshot($this->storageRoot);

        try {
            $this->adopter()->adopt($this->storage());
            self::fail('An empty root must use the normal initializer.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.storage_adoption_not_required',
                $exception->issueCode()
            );
        }

        self::assertSame($before, $this->snapshot($this->storageRoot));
    }

    public function testMissingQuotaLockFailsWithoutTouchingLegacyFiles(): void
    {
        $this->seedExactLegacyVariant();
        $this->pdo->exec(
            "DELETE FROM ls_webadmin_state WHERE state_key = 'media.quota_lock'"
        );
        $before = $this->snapshot($this->storageRoot);

        try {
            $this->adopter()->adopt($this->storage());
            self::fail('Adoption requires the canonical DB lock row.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.storage_adoption_database_failed',
                $exception->issueCode()
            );
        }
        self::assertSame($before, $this->snapshot($this->storageRoot));
    }

    private function seedExactLegacyVariant(): string
    {
        $contents = 'legacy-avif-payload';
        $this->filesystem->mkdir([
            $this->storageRoot . '/.staging',
            dirname($this->variantPath()),
        ]);
        file_put_contents($this->variantPath(), $contents);
        $this->pdo->exec(
            "INSERT INTO ls_webadmin_media_assets (id, public_id) VALUES "
            . "(1, '" . self::ASSET . "')"
        );
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_media_variants '
            . '(id, asset_id, width, height, bytes, sha256, storage_key, mime) '
            . 'VALUES (1, 1, 480, 320, :bytes, :sha256, :storage_key, '
            . "'image/avif')"
        );
        $statement->execute([
            'bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'storage_key' => '12/' . self::ASSET . '/480.avif',
        ]);

        return $contents;
    }

    private function variantPath(): string
    {
        return $this->storageRoot . '/12/' . self::ASSET . '/480.avif';
    }

    private function storage(): PrivateMediaStorage
    {
        return new PrivateMediaStorage(
            $this->projectRoot,
            $this->storageRoot
        );
    }

    private function adopter(): PdoLegacyMediaStorageAdopter
    {
        return new PdoLegacyMediaStorageAdopter(
            $this->pdo,
            WebAdminTableNames::fromPdo($this->pdo, 'ls_webadmin_')
        );
    }

    /** @return array<string, string> */
    private function snapshot(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }
        $snapshot = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = substr(
                str_replace('\\', '/', $item->getPathname()),
                strlen(str_replace('\\', '/', $root)) + 1
            );
            $snapshot[$relative] = $item->isDir()
                ? 'directory'
                : (hash_file('sha256', $item->getPathname()) ?: 'unreadable');
        }
        ksort($snapshot);

        return $snapshot;
    }
}
