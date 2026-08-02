<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\Blog\StructuredContent\Media\WebAdminMediaCatalogAdapter;
use App\Core\WebAdmin\Media\MediaAssetPage;
use App\Core\WebAdmin\Media\MediaRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class BlogEditorMediaCatalogTest extends TestCase
{
    public function testMapsOnlySafeRecentMediaFields(): void
    {
        $repository = $this->createMock(MediaRepositoryInterface::class);
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
    }

    public function testRejectsUnboundedCatalogReadsBeforeRepositoryAccess(): void
    {
        $repository = $this->createMock(MediaRepositoryInterface::class);
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
}
