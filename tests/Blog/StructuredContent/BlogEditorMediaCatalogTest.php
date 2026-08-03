<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\Blog\StructuredContent\Media\BlogEditorMediaAsset;
use App\Core\Blog\StructuredContent\Media\WebAdminMediaCatalogAdapter;
use App\Core\WebAdmin\Media\MediaAssetPage;
use App\Core\WebAdmin\Media\MediaCatalogAsset;
use App\Core\WebAdmin\Media\MediaCatalogRepositoryInterface;
use App\Core\WebAdmin\Media\MediaRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class BlogEditorMediaCatalogTest extends TestCase
{
    public function testLegacyProjectionWithoutThumbnailRemainsCompatible(): void
    {
        $asset = new BlogEditorMediaAsset(
            '10000000-0000-4000-8000-000000000001',
            'Portada Matrix'
        );

        self::assertNull($asset->thumbnailWidth());
    }

    public function testMapsOnlySafeRecentMediaFields(): void
    {
        $repository = $this->createMock(
            MediaCatalogRepositoryInterface::class
        );
        $repository->expects(self::once())->method('listPage')->with(1, 12)
            ->willReturn(new MediaAssetPage([[
                'public_id' => '10000000-0000-4000-8000-000000000001',
                'label' => 'Portada Matrix',
                'source_width' => 1600,
                'source_height' => 900,
                'created_at' => '2026-08-02T00:00:00+00:00',
                'thumbnail_width' => 320,
            ]], 1, false));

        $items = (new WebAdminMediaCatalogAdapter($repository))->recent(12);

        self::assertCount(1, $items);
        self::assertSame(
            '10000000-0000-4000-8000-000000000001',
            $items[0]->publicId()
        );
        self::assertSame('Portada Matrix', $items[0]->label());
        self::assertSame(320, $items[0]->thumbnailWidth());
    }

    /** @dataProvider invalidThumbnailWidthProvider */
    public function testRejectsInvalidThumbnailWidths(mixed $width): void
    {
        $repository = $this->createMock(
            MediaCatalogRepositoryInterface::class
        );
        $repository->method('listPage')->willReturn(new MediaAssetPage([[
            'public_id' => '10000000-0000-4000-8000-000000000001',
            'label' => 'Portada Matrix',
            'source_width' => 1600,
            'source_height' => 900,
            'created_at' => '2026-08-02T00:00:00+00:00',
            'thumbnail_width' => $width,
        ]], 1, false));

        try {
            (new WebAdminMediaCatalogAdapter($repository))->recent(12);
            self::fail('Invalid thumbnail widths must be rejected.');
        } catch (BlogStructuredContentException $exception) {
            self::assertSame(
                BlogStructuredContentException::MEDIA_UNAVAILABLE,
                $exception->issueCode()
            );
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidThumbnailWidthProvider(): iterable
    {
        yield 'numeric string' => ['320'];
        yield 'zero' => [0];
        yield 'above private route limit' => [2561];
    }

    public function testRejectsUnboundedCatalogReadsBeforeRepositoryAccess(): void
    {
        $repository = $this->createMock(
            MediaCatalogRepositoryInterface::class
        );
        $repository->expects(self::never())->method('listPage');

        try {
            (new WebAdminMediaCatalogAdapter($repository))->recent(49);
            self::fail('Unbounded media reads must be rejected.');
        } catch (BlogStructuredContentException $exception) {
            self::assertSame(
                BlogStructuredContentException::INVALID_INPUT,
                $exception->issueCode()
            );
        }
    }

    public function testIncludesReferencedAssetOutsideRecentPage(): void
    {
        $recentId = '10000000-0000-4000-8000-000000000001';
        $referencedId = '10000000-0000-4000-8000-000000000002';
        $repository = $this->createMock(
            MediaCatalogRepositoryInterface::class
        );
        $repository->expects(self::once())->method('listPage')->with(1, 1)
            ->willReturn(new MediaAssetPage([[
                'public_id' => $recentId,
                'label' => 'Portada reciente',
                'source_width' => 1600,
                'source_height' => 900,
                'created_at' => '2026-08-03T00:00:00+00:00',
                'thumbnail_width' => 320,
            ]], 1, true));
        $repository->expects(self::once())
            ->method('catalogAssetsByPublicIds')
            ->with([$referencedId])
            ->willReturn([
                new MediaCatalogAsset(
                    $referencedId,
                    'Portada antigua referenciada',
                    480
                ),
            ]);

        $items = (new WebAdminMediaCatalogAdapter($repository))
            ->recentIncluding(1, [$referencedId]);

        self::assertCount(2, $items);
        self::assertSame($recentId, $items[0]->publicId());
        self::assertSame($referencedId, $items[1]->publicId());
        self::assertSame(480, $items[1]->thumbnailWidth());
    }

    public function testDoesNotLookUpReferencedAssetAlreadyInRecentPage(): void
    {
        $publicId = '10000000-0000-4000-8000-000000000001';
        $repository = $this->createMock(
            MediaCatalogRepositoryInterface::class
        );
        $repository->method('listPage')->willReturn(new MediaAssetPage([[
            'public_id' => $publicId,
            'label' => 'Portada Matrix',
            'source_width' => 1600,
            'source_height' => 900,
            'created_at' => '2026-08-03T00:00:00+00:00',
            'thumbnail_width' => 320,
        ]], 1, false));
        $repository->expects(self::never())
            ->method('catalogAssetsByPublicIds');

        $items = (new WebAdminMediaCatalogAdapter($repository))
            ->recentIncluding(1, [$publicId, $publicId]);

        self::assertCount(1, $items);
        self::assertSame($publicId, $items[0]->publicId());
    }

    public function testUnavailableReferencedAssetFailsClosed(): void
    {
        $missingId = '10000000-0000-4000-8000-000000000099';
        $repository = $this->createMock(
            MediaCatalogRepositoryInterface::class
        );
        $repository->method('listPage')->willReturn(
            new MediaAssetPage([], 1, false)
        );
        $repository->expects(self::once())
            ->method('catalogAssetsByPublicIds')
            ->with([$missingId])
            ->willReturn([]);

        try {
            (new WebAdminMediaCatalogAdapter($repository))
                ->recentIncluding(1, [$missingId]);
            self::fail('Missing referenced media must fail closed.');
        } catch (BlogStructuredContentException $exception) {
            self::assertSame(
                BlogStructuredContentException::MEDIA_UNAVAILABLE,
                $exception->issueCode()
            );
        }
    }

    public function testLegacyRepositoryKeepsItsRecentOnlyContract(): void
    {
        $recentId = '10000000-0000-4000-8000-000000000001';
        $repository = $this->createMock(MediaRepositoryInterface::class);
        $repository->expects(self::once())->method('listPage')->with(1, 1)
            ->willReturn(new MediaAssetPage([[
                'public_id' => $recentId,
                'label' => 'Legacy repository recent media',
                'source_width' => 1600,
                'source_height' => 900,
                'created_at' => '2026-08-03T00:00:00+00:00',
                'thumbnail_width' => 320,
            ]], 1, false));

        $items = (new WebAdminMediaCatalogAdapter($repository))
            ->recentIncluding(1, [
                '10000000-0000-4000-8000-000000000099',
            ]);

        self::assertCount(1, $items);
        self::assertSame($recentId, $items[0]->publicId());
    }

    public function testRejectsUnboundedRequiredAssetsBeforeRepositoryAccess(): void
    {
        $repository = $this->createMock(
            MediaCatalogRepositoryInterface::class
        );
        $repository->expects(self::never())->method('listPage');
        $repository->expects(self::never())
            ->method('catalogAssetsByPublicIds');

        try {
            (new WebAdminMediaCatalogAdapter($repository))->recentIncluding(
                1,
                array_fill(
                    0,
                    MediaCatalogRepositoryInterface::MAX_LOOKUP_ITEMS + 1,
                    '10000000-0000-4000-8000-000000000001'
                )
            );
            self::fail('Unbounded required media must be rejected.');
        } catch (BlogStructuredContentException $exception) {
            self::assertSame(
                BlogStructuredContentException::INVALID_INPUT,
                $exception->issueCode()
            );
        }
    }
}
