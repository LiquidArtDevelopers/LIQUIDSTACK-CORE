<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Core\Blog\BlogException;
use App\Core\Blog\PublicFeed\BlogPublicArchivePeriod;
use App\Core\Blog\PublicFeed\BlogPublicArchivePeriodsQuery;
use App\Core\Blog\PublicFeed\BlogPublicArchiveQuery;
use App\Core\Blog\PublicFeed\BlogPublicRelatedQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlogPublicDiscoveryQueryTest extends TestCase
{
    public function testRelatedInputIsTypedAndBounded(): void
    {
        self::assertSame(
            3,
            (new BlogPublicRelatedQuery('es', 'matrix'))->limit()
        );

        $query = new BlogPublicRelatedQuery(
            'es',
            'matrix-despierta',
            BlogPublicRelatedQuery::MAX_LIMIT
        );

        self::assertSame('es', $query->locale());
        self::assertSame('matrix-despierta', $query->sourceSlug());
        self::assertSame(BlogPublicRelatedQuery::MAX_LIMIT, $query->limit());
    }

    public function testArchiveQueryBuildsExactUtcMonthAndYearRanges(): void
    {
        $month = new BlogPublicArchiveQuery('es', 2030, 2, 20, 3);
        self::assertSame('2030-02-01 00:00:00.000000', $month
            ->startInclusive()->format('Y-m-d H:i:s.u'));
        self::assertSame('2030-03-01 00:00:00.000000', $month
            ->endExclusive()->format('Y-m-d H:i:s.u'));
        self::assertSame(20, $month->limit());
        self::assertSame(3, $month->offset());

        $year = new BlogPublicArchiveQuery('es', 2030);
        self::assertNull($year->month());
        self::assertSame('2030-01-01 00:00:00.000000', $year
            ->startInclusive()->format('Y-m-d H:i:s.u'));
        self::assertSame('2031-01-01 00:00:00.000000', $year
            ->endExclusive()->format('Y-m-d H:i:s.u'));

        $storageCeiling = new BlogPublicArchiveQuery('es', 9999, 12);
        self::assertNull($storageCeiling->endExclusive());
    }

    public function testArchivePeriodProducesOnlyPresentationData(): void
    {
        $period = new BlogPublicArchivePeriod('es', 2030, 4, 7);

        self::assertSame([
            'locale' => 'es',
            'year' => 2030,
            'month' => 4,
            'count' => 7,
        ], $period->toResourceData());
    }

    /** @param callable(): object $factory */
    #[DataProvider('invalidInputs')]
    public function testInvalidDiscoveryInputFailsClosed(
        callable $factory
    ): void {
        $this->expectException(BlogException::class);
        $this->expectExceptionMessage('Invalid Blog input.');

        $factory();
    }

    /** @return iterable<string, array{callable(): object}> */
    public static function invalidInputs(): iterable
    {
        yield 'related invalid slug' => [
            static fn (): object => new BlogPublicRelatedQuery('es', '../x'),
        ];
        yield 'related excessive limit' => [
            static fn (): object => new BlogPublicRelatedQuery(
                'es',
                'matrix',
                BlogPublicRelatedQuery::MAX_LIMIT + 1
            ),
        ];
        yield 'archive invalid year' => [
            static fn (): object => new BlogPublicArchiveQuery('es', 10000),
        ];
        yield 'archive invalid month' => [
            static fn (): object => new BlogPublicArchiveQuery('es', 2030, 13),
        ];
        yield 'archive excessive offset' => [
            static fn (): object => new BlogPublicArchiveQuery(
                'es',
                2030,
                null,
                12,
                BlogPublicArchiveQuery::MAX_OFFSET + 1
            ),
        ];
        yield 'period index excessive limit' => [
            static fn (): object => new BlogPublicArchivePeriodsQuery(
                'es',
                BlogPublicArchivePeriodsQuery::MAX_LIMIT + 1
            ),
        ];
        yield 'period invalid month' => [
            static fn (): object => new BlogPublicArchivePeriod(
                'es',
                2030,
                0,
                1
            ),
        ];
    }
}
