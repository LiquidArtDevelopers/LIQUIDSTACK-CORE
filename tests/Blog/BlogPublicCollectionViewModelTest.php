<?php

declare(strict_types=1);

use App\Core\Blog\BlogException;
use App\Core\Blog\PublicFeed\BlogPublicCollectionViewModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlogPublicCollectionViewModelTest extends TestCase
{
    public function testFactoriesExposeThreeMutuallyExclusiveStates(): void
    {
        $item = ['slug' => 'matrix-despierta'];
        $ready = BlogPublicCollectionViewModel::ready([$item]);
        $empty = BlogPublicCollectionViewModel::empty();
        $unavailable = BlogPublicCollectionViewModel::unavailable();

        self::assertSame(BlogPublicCollectionViewModel::STATE_READY, $ready->state());
        self::assertSame([$item], $ready->items());
        self::assertSame(1, $ready->count());
        self::assertTrue($ready->isReady());
        self::assertFalse($ready->isEmpty());
        self::assertFalse($ready->isUnavailable());

        self::assertSame(BlogPublicCollectionViewModel::STATE_EMPTY, $empty->state());
        self::assertSame([], $empty->items());
        self::assertSame(0, $empty->count());
        self::assertFalse($empty->isReady());
        self::assertTrue($empty->isEmpty());
        self::assertFalse($empty->isUnavailable());

        self::assertSame(
            BlogPublicCollectionViewModel::STATE_UNAVAILABLE,
            $unavailable->state()
        );
        self::assertSame([], $unavailable->items());
        self::assertSame(0, $unavailable->count());
        self::assertFalse($unavailable->isReady());
        self::assertFalse($unavailable->isEmpty());
        self::assertTrue($unavailable->isUnavailable());
    }

    #[DataProvider('invalidReadyItems')]
    public function testReadyRejectsInvalidOrEmptyPresentationItems(array $items): void
    {
        $this->expectException(BlogException::class);
        $this->expectExceptionMessage('Invalid Blog input.');

        BlogPublicCollectionViewModel::ready($items);
    }

    /** @return iterable<string, array{0:array<mixed>}> */
    public static function invalidReadyItems(): iterable
    {
        yield 'empty collection' => [[]];
        yield 'associative collection' => [['card' => []]];
        yield 'scalar item' => [['not-a-presentation-array']];
    }
}
