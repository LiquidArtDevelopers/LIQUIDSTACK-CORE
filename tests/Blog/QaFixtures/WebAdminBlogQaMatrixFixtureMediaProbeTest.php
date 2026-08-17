<?php

declare(strict_types=1);

namespace Tests\Blog\QaFixtures;

use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureException;
use App\Core\Blog\QaFixtures\WebAdminBlogQaMatrixFixtureMediaProbe;
use App\Core\WebAdmin\Media\MediaCatalogAsset;
use App\Core\WebAdmin\Media\MediaCatalogRepositoryInterface;
use App\Core\WebAdmin\Media\MediaFileMetadata;
use App\Core\WebAdmin\Media\MediaStorageInterface;
use App\Core\WebAdmin\Media\MediaStoredVariant;
use PHPUnit\Framework\TestCase;

final class WebAdminBlogQaMatrixFixtureMediaProbeTest extends TestCase
{
    private const MEDIA = '11111111-1111-4111-8111-111111111111';

    public function testProbeRequiresCatalogAvifAndVerifiedPhysicalFile(): void
    {
        $asset = new MediaCatalogAsset(self::MEDIA, 'Matrix QA', 640);
        $variant = new MediaStoredVariant(
            '11/' . self::MEDIA . '/640.avif',
            640,
            360,
            128,
            str_repeat('a', 64)
        );
        $repository = $this->createMock(
            MediaCatalogRepositoryInterface::class
        );
        $repository->expects(self::once())
            ->method('catalogAssetsByPublicIds')
            ->with([self::MEDIA])
            ->willReturn([$asset]);
        $repository->expects(self::once())
            ->method('findVariant')
            ->with(self::MEDIA, 640)
            ->willReturn($variant);
        $storage = $this->createMock(MediaStorageInterface::class);
        $storage->expects(self::once())
            ->method('probeVerified')
            ->with($variant)
            ->willReturn(new MediaFileMetadata(640, 360, 128));

        (new WebAdminBlogQaMatrixFixtureMediaProbe(
            $repository,
            $storage
        ))->assertUsable(self::MEDIA);
    }

    public function testMissingCatalogAssetFailsBeforeStorageProbe(): void
    {
        $repository = $this->createMock(
            MediaCatalogRepositoryInterface::class
        );
        $repository->expects(self::once())
            ->method('catalogAssetsByPublicIds')
            ->with([self::MEDIA])
            ->willReturn([]);
        $storage = $this->createMock(MediaStorageInterface::class);
        $storage->expects(self::never())->method('probeVerified');

        try {
            (new WebAdminBlogQaMatrixFixtureMediaProbe(
                $repository,
                $storage
            ))->assertUsable(self::MEDIA);
            self::fail('A missing real media asset was accepted.');
        } catch (BlogQaMatrixFixtureException $exception) {
            self::assertSame(
                'blog.qa_fixture.media_asset_not_found',
                $exception->issueCode()
            );
        }
    }
}
