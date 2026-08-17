<?php

declare(strict_types=1);

namespace Tests\WebAdmin\Media;

use App\Core\WebAdmin\Media\ProcessedMediaUpload;
use App\Core\WebAdmin\Media\ProcessedMediaVariant;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProcessedMediaUploadTest extends TestCase
{
    #[DataProvider('supportedSourceMimeProvider')]
    public function testContractAcceptsEverySupportedSourceMime(string $mime): void
    {
        $processed = new ProcessedMediaUpload(
            $mime,
            16,
            16,
            128,
            str_repeat('a', 64),
            [new ProcessedMediaVariant(
                16,
                16,
                64,
                str_repeat('b', 64),
                '16.avif'
            )]
        );

        self::assertSame($mime, $processed->sourceMime());
    }

    /** @return iterable<string, array{string}> */
    public static function supportedSourceMimeProvider(): iterable
    {
        yield 'JPEG' => ['image/jpeg'];
        yield 'PNG' => ['image/png'];
        yield 'WebP' => ['image/webp'];
        yield 'AVIF' => ['image/avif'];
    }

    public function testContractStillRejectsUnknownSourceMime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProcessedMediaUpload(
            'image/gif',
            16,
            16,
            128,
            str_repeat('a', 64),
            [new ProcessedMediaVariant(
                16,
                16,
                64,
                str_repeat('b', 64),
                '16.avif'
            )]
        );
    }
}
