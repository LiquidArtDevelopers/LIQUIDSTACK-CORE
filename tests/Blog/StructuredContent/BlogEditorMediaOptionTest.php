<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\StructuredContent\Rendering\BlogEditorMediaOption;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BlogEditorMediaOptionTest extends TestCase
{
    private const MEDIA = '10000000-0000-4000-8000-000000000001';

    public function testExposesExactPrivateSameOriginThumbnailUrl(): void
    {
        $url = '/gestion-web/media/file?asset=' . self::MEDIA . '&width=320';
        $option = new BlogEditorMediaOption(
            self::MEDIA,
            'Portada Matrix',
            $url
        );

        self::assertSame($url, $option->thumbnailUrl());
    }

    public function testKeepsTwoArgumentConstructionCompatible(): void
    {
        $option = new BlogEditorMediaOption(self::MEDIA, 'Portada Matrix');

        self::assertNull($option->thumbnailUrl());
    }

    /** @dataProvider unsafeThumbnailUrlProvider */
    public function testRejectsUnsafeOrMismatchedThumbnailUrls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BlogEditorMediaOption(self::MEDIA, 'Portada Matrix', $url);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeThumbnailUrlProvider(): iterable
    {
        yield 'absolute external URL' => [
            'https://example.test/admin/media/file?asset=' . self::MEDIA
                . '&width=320',
        ];
        yield 'protocol relative URL' => [
            '//example.test/admin/media/file?asset=' . self::MEDIA
                . '&width=320',
        ];
        yield 'different asset' => [
            '/admin/media/file?asset=10000000-0000-4000-8000-000000000002'
                . '&width=320',
        ];
        yield 'unsupported width' => [
            '/admin/media/file?asset=' . self::MEDIA . '&width=2561',
        ];
        yield 'HTML encoded transport URL' => [
            '/admin/media/file?asset=' . self::MEDIA . '&amp;width=320',
        ];
        yield 'extra query data' => [
            '/admin/media/file?asset=' . self::MEDIA
                . '&width=320&token=unexpected',
        ];
        yield 'control character' => [
            "/admin/media/file?asset=" . self::MEDIA . "&width=320\r\n",
        ];
    }
}
