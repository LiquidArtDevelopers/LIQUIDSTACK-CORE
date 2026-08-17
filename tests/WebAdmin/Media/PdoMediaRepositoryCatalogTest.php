<?php

declare(strict_types=1);

namespace Tests\WebAdmin\Media;

use App\Core\WebAdmin\Authorization\WebAdminAuthorizedActor;
use App\Core\WebAdmin\Media\MediaException;
use App\Core\WebAdmin\Media\MediaPickerQuery;
use App\Core\WebAdmin\Media\PdoMediaRepository;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoMediaRepositoryCatalogTest extends TestCase
{
    public function testPickerSearchIsLiteralUnicodeAndPaginationIsStable(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite es necesario.');
        }
        $pdo = $this->pickerDatabase();
        $asset = $pdo->prepare(
            'INSERT INTO ls_webadmin_media_assets '
            . '(id, public_id, label, source_mime, source_width, '
            . 'source_height, source_bytes, source_sha256, '
            . 'created_by_user_id, created_at) VALUES '
            . "(:id, :public_id, :label, 'image/avif', 1800, 1200, "
            . "1000, :sha256, 1, '2030-01-01 10:00:00.000000')"
        );
        $variant = $pdo->prepare(
            'INSERT INTO ls_webadmin_media_variants '
            . '(asset_id, width, height, bytes) VALUES '
            . '(:asset, 480, 320, 100), (:asset, 900, 600, 250)'
        );
        for ($id = 1; $id <= 14; ++$id) {
            $asset->execute([
                'id' => $id,
                'public_id' => sprintf(
                    '12345678-1234-4234-8234-%012d',
                    $id
                ),
                'label' => $id === 14
                    ? 'Árbol 100%_ real' : 'Imagen ' . $id,
                'sha256' => str_repeat(dechex($id % 16), 64),
            ]);
            $variant->execute(['asset' => $id]);
        }
        $repository = new PdoMediaRepository(
            $pdo,
            WebAdminTableNames::fromPdo($pdo, 'ls_webadmin_')
        );

        $first = $repository->pickerPage(new MediaPickerQuery(null, 1, 12));
        self::assertCount(12, $first->items());
        self::assertTrue($first->hasNext());
        self::assertSame(
            '12345678-1234-4234-8234-000000000014',
            $first->items()[0]->publicId()
        );
        $second = $repository->pickerPage(new MediaPickerQuery(null, 2, 12));
        self::assertCount(2, $second->items());
        self::assertFalse($second->hasNext());
        self::assertSame(
            '12345678-1234-4234-8234-000000000002',
            $second->items()[0]->publicId()
        );

        $literal = $repository->pickerPage(
            new MediaPickerQuery('  Árbol   100%_  ', 1, 24)
        );
        self::assertCount(1, $literal->items());
        self::assertSame('Árbol 100%_ real', $literal->items()[0]->label());
        self::assertSame(480, $literal->items()[0]->thumbnailWidth());
        self::assertSame([
            ['width' => 480, 'height' => 320, 'bytes' => 100],
            ['width' => 900, 'height' => 600, 'bytes' => 250],
        ], $literal->items()[0]->variants());
    }

    public function testCatalogProjectsEveryAvailableVariantWithItsBytes(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite es necesario.');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec(
            'CREATE TABLE ls_webadmin_media_assets ('
            . 'id INTEGER PRIMARY KEY, public_id TEXT NOT NULL, label TEXT NOT NULL, '
            . 'source_mime TEXT NOT NULL, '
            . 'source_width INTEGER NOT NULL, source_height INTEGER NOT NULL, '
            . 'source_bytes INTEGER NOT NULL, source_sha256 TEXT NOT NULL, '
            . 'created_by_user_id INTEGER NOT NULL, '
            . 'created_at TEXT NOT NULL)'
        );
        $pdo->exec(
            'CREATE TABLE ls_webadmin_media_variants ('
            . 'asset_id INTEGER NOT NULL, width INTEGER NOT NULL, '
            . 'height INTEGER NOT NULL, bytes INTEGER NOT NULL)'
        );
        $pdo->exec(
            "INSERT INTO ls_webadmin_media_assets VALUES (7, "
            . "'12345678-1234-4234-8234-123456789abc', 'Portada', "
            . "'image/avif', 1600, 900, 180000, '"
            . str_repeat('a', 64)
            . "', 1, '2026-08-02 10:00:00.000000')"
        );
        $pdo->exec(
            'INSERT INTO ls_webadmin_media_variants VALUES '
            . '(7, 1600, 900, 180000), (7, 480, 270, 12345), '
            . '(7, 900, 506, 45678)'
        );

        $items = (new PdoMediaRepository(
            $pdo,
            WebAdminTableNames::fromPdo($pdo, 'ls_webadmin_')
        ))->listPage(1, 24)->items();

        self::assertCount(1, $items);
        self::assertSame(480, $items[0]['thumbnail_width']);
        self::assertSame([
            ['width' => 480, 'height' => 270, 'bytes' => 12345],
            ['width' => 900, 'height' => 506, 'bytes' => 45678],
            ['width' => 1600, 'height' => 900, 'bytes' => 180000],
        ], $items[0]['variants']);
        self::assertArrayNotHasKey('asset_id', $items[0]);
    }

    public function testUploadRequestLookupIsBoundToActorAndPayload(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite es necesario.');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec(
            'CREATE TABLE ls_webadmin_media_assets ('
            . 'public_id TEXT NOT NULL PRIMARY KEY, label TEXT NOT NULL, '
            . 'source_sha256 TEXT NOT NULL)'
        );
        $pdo->exec(
            'CREATE TABLE ls_webadmin_audit_log ('
            . 'id INTEGER PRIMARY KEY, request_id TEXT NOT NULL, '
            . 'actor_user_id INTEGER NOT NULL, event_code TEXT NOT NULL, '
            . 'outcome TEXT NOT NULL, target_type TEXT NOT NULL, '
            . 'target_public_id TEXT NOT NULL)'
        );
        $assetId = '12345678-1234-4234-8234-123456789abc';
        $requestId = '40000000-0000-4000-8000-000000000004';
        $sourceHash = str_repeat('a', 64);
        $insertAsset = $pdo->prepare(
            'INSERT INTO ls_webadmin_media_assets '
            . '(public_id, label, source_sha256) VALUES (?, ?, ?)'
        );
        $insertAsset->execute([$assetId, 'Portada', $sourceHash]);
        $insertAudit = $pdo->prepare(
            'INSERT INTO ls_webadmin_audit_log VALUES '
            . '(1, ?, 7, ?, ?, ?, ?)'
        );
        $insertAudit->execute([
            $requestId,
            'webadmin.media.created',
            'success',
            'media_asset',
            $assetId,
        ]);
        $repository = new PdoMediaRepository(
            $pdo,
            WebAdminTableNames::fromPdo($pdo, 'ls_webadmin_')
        );
        $actor = new WebAdminAuthorizedActor(
            7,
            '10000000-0000-4000-8000-000000000001',
            '20000000-0000-4000-8000-000000000002'
        );

        $pdo->beginTransaction();
        self::assertSame(
            $assetId,
            $repository->createdPublicIdForRequest(
                $actor,
                $requestId,
                'Portada',
                $sourceHash
            )
        );
        try {
            $repository->createdPublicIdForRequest(
                $actor,
                $requestId,
                'Otra etiqueta',
                $sourceHash
            );
            self::fail('A reused key cannot identify a different payload.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.idempotency_conflict',
                $exception->issueCode()
            );
        } finally {
            $pdo->rollBack();
        }
    }

    private function pickerDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec(
            'CREATE TABLE ls_webadmin_media_assets ('
            . 'id INTEGER PRIMARY KEY, public_id TEXT NOT NULL, '
            . 'label TEXT NOT NULL, source_mime TEXT NOT NULL, '
            . 'source_width INTEGER NOT NULL, source_height INTEGER NOT NULL, '
            . 'source_bytes INTEGER NOT NULL, source_sha256 TEXT NOT NULL, '
            . 'created_by_user_id INTEGER NOT NULL, created_at TEXT NOT NULL)'
        );
        $pdo->exec(
            'CREATE TABLE ls_webadmin_media_variants ('
            . 'asset_id INTEGER NOT NULL, width INTEGER NOT NULL, '
            . 'height INTEGER NOT NULL, bytes INTEGER NOT NULL)'
        );

        return $pdo;
    }
}
