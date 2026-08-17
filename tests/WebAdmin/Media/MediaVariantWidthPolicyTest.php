<?php

declare(strict_types=1);

namespace Tests\WebAdmin\Media;

use App\Core\WebAdmin\Media\MediaVariantWidthPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaVariantWidthPolicyTest extends TestCase
{
    /** @param list<int> $expected */
    #[DataProvider('widthProvider')]
    public function testUsesExistingStandardsOnlyUpToTheRealMaster(
        int $masterWidth,
        array $expected
    ): void {
        self::assertSame(
            $expected,
            (new MediaVariantWidthPolicy())->widthsForMaster($masterWidth)
        );
        self::assertLessThanOrEqual($masterWidth, max($expected));
    }

    /** @return iterable<string, array{int, list<int>}> */
    public static function widthProvider(): iterable
    {
        yield 'small source is never enlarged' => [320, [320]];
        yield 'between first and second standard' => [640, [480, 640]];
        yield 'standard widths plus real master' => [1200, [480, 900, 1200]];
        yield 'bounded maximum master' => [2560, [480, 900, 1800, 2560]];
    }
}
