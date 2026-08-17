<?php

declare(strict_types=1);

use App\Core\Blog\Admin\BlogAdminCatalogQuery;
use App\Core\Blog\BlogException;
use App\Core\Blog\BlogPostVariant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlogAdminCatalogQueryTest extends TestCase
{
    public function testNormalizesFiltersAndKeepsAStableGetProjection(): void
    {
        $query = new BlogAdminCatalogQuery(
            search: '  MATRIX   editorial  ',
            status: BlogPostVariant::PUBLISHED,
            locale: 'eu',
            offset: 40,
            sort: BlogAdminCatalogQuery::SORT_TITLE,
            direction: BlogAdminCatalogQuery::DIRECTION_ASC
        );

        self::assertSame('MATRIX editorial', $query->search());
        self::assertSame(BlogPostVariant::PUBLISHED, $query->status());
        self::assertSame('eu', $query->locale());
        self::assertSame(40, $query->offset());
        self::assertSame(20, $query->pageSize());
        self::assertSame(21, $query->limit());
        self::assertSame(3, $query->pageNumber());
        self::assertSame(BlogAdminCatalogQuery::SORT_TITLE, $query->sort());
        self::assertSame(
            BlogAdminCatalogQuery::DIRECTION_ASC,
            $query->direction()
        );
        self::assertTrue($query->hasFilters());
        self::assertSame([
            'q' => 'MATRIX editorial',
            'status' => BlogPostVariant::PUBLISHED,
            'locale' => 'eu',
            'sort' => BlogAdminCatalogQuery::SORT_TITLE,
            'dir' => BlogAdminCatalogQuery::DIRECTION_ASC,
        ], $query->queryParameters());
    }

    public function testEmptyGetFieldsMeanNoFilter(): void
    {
        $query = new BlogAdminCatalogQuery('  ', '', '', 0);

        self::assertNull($query->search());
        self::assertNull($query->status());
        self::assertNull($query->locale());
        self::assertFalse($query->hasFilters());
        self::assertSame(20, $query->pageSize());
        self::assertSame(21, $query->limit());
        self::assertSame(BlogAdminCatalogQuery::SORT_UPDATED, $query->sort());
        self::assertSame(
            BlogAdminCatalogQuery::DIRECTION_DESC,
            $query->direction()
        );
        self::assertSame([], $query->queryParameters());
    }

    public function testProjectsOnlyNonDefaultPagingAndOrdering(): void
    {
        $query = new BlogAdminCatalogQuery(
            offset: 50,
            pageSize: 50,
            sort: BlogAdminCatalogQuery::SORT_ROBOTS,
            direction: BlogAdminCatalogQuery::DIRECTION_DESC
        );

        self::assertSame(50, $query->offset());
        self::assertSame(51, $query->limit());
        self::assertSame([
            'sort' => BlogAdminCatalogQuery::SORT_ROBOTS,
            'dir' => BlogAdminCatalogQuery::DIRECTION_DESC,
            'per_page' => '50',
        ], $query->queryParameters());
        self::assertSame([10, 20, 50], BlogAdminCatalogQuery::pageSizes());
    }

    #[DataProvider('invalidOrdering')]
    public function testRejectsUnknownPageSizesSortsAndDirections(
        int $pageSize,
        string $sort,
        string $direction
    ): void {
        $this->expectException(BlogException::class);

        new BlogAdminCatalogQuery(
            pageSize: $pageSize,
            sort: $sort,
            direction: $direction
        );
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function invalidOrdering(): iterable
    {
        yield 'page size' => [25, 'updated', 'desc'];
        yield 'SQL-shaped sort' => [20, 'updated_at DESC', 'desc'];
        yield 'direction' => [20, 'updated', 'DESC'];
    }

    #[DataProvider('invalidQueries')]
    public function testRejectsUnboundedOrNonCanonicalInput(
        ?string $search,
        ?string $status,
        ?string $locale,
        int $offset
    ): void {
        try {
            new BlogAdminCatalogQuery($search, $status, $locale, $offset);
            self::fail('The invalid admin catalog query was accepted.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::INVALID_INPUT,
                $exception->issueCode()
            );
        }
    }

    /** @return iterable<string, array{?string, ?string, ?string, int}> */
    public static function invalidQueries(): iterable
    {
        yield 'one-character search' => ['x', null, null, 0];
        yield 'oversized search' => [str_repeat('x', 121), null, null, 0];
        yield 'multiline search' => ["matrix\nagent", null, null, 0];
        yield 'unknown status' => [null, 'archived', null, 0];
        yield 'noncanonical locale' => [null, null, 'ES', 0];
        yield 'unaligned page' => [null, null, null, 25];
        yield 'offset overflow' => [null, null, null, 1_000_050];
    }
}
