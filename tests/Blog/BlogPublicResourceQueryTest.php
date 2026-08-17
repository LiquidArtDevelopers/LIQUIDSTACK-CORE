<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Core\Blog\BlogException;
use App\Core\Blog\PublicFeed\BlogPublicCardCategoryQuery;
use App\Core\Blog\PublicFeed\BlogPublicResourceQuery;
use PHPUnit\Framework\TestCase;

final class BlogPublicResourceQueryTest extends TestCase
{
    public function testCardCategoryBatchInputIsTypedBoundedAndUnique(): void
    {
        $query = new BlogPublicCardCategoryQuery(
            'es',
            ['uno', 'dos', 'uno']
        );
        self::assertSame('es', $query->locale());
        self::assertSame(['uno', 'dos'], $query->cardSlugs());

        $slugs = [];
        for ($index = 0; $index <= BlogPublicCardCategoryQuery::MAX_CARDS;
            ++$index) {
            $slugs[] = 'entrada-' . $index;
        }
        try {
            new BlogPublicCardCategoryQuery('es', $slugs);
            self::fail('An oversized category batch was accepted.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::INVALID_INPUT,
                $exception->issueCode()
            );
        }
    }

    public function testAllAndSelectedScopesAreExplicitAndBounded(): void
    {
        $all = new BlogPublicResourceQuery(
            locale: 'es',
            categoryScope: BlogPublicResourceQuery::SCOPE_ALL,
            excludeDummy: true,
            items: 4,
            limit: 8,
            order: BlogPublicResourceQuery::ORDER_UPDATED
        );
        self::assertSame('es', $all->locale());
        self::assertSame(BlogPublicResourceQuery::SCOPE_ALL, $all->categoryScope());
        self::assertSame([], $all->categories());
        self::assertTrue($all->excludeDummy());
        self::assertSame(4, $all->items());
        self::assertSame(8, $all->limit());
        self::assertSame(0, $all->offset());
        self::assertSame(BlogPublicResourceQuery::ORDER_UPDATED, $all->order());
        self::assertSame(
            [BlogPublicResourceQuery::DUMMY_CATEGORY_SLUG],
            $all->catalogQuery()->excludedCategorySlugs()
        );

        $selected = new BlogPublicResourceQuery(
            locale: 'eu',
            categoryScope: BlogPublicResourceQuery::SCOPE_SELECTED,
            categories: ['full', 'albisteak', 'full'],
            categoryMode: BlogPublicResourceQuery::MODE_ALL,
            items: 2,
            limit: 2
        );
        self::assertSame(
            BlogPublicResourceQuery::SCOPE_SELECTED,
            $selected->categoryScope()
        );
        self::assertSame(['full', 'albisteak'], $selected->categories());
        self::assertSame(BlogPublicResourceQuery::MODE_ALL, $selected->categoryMode());
        self::assertSame(['full', 'albisteak'], $selected->catalogQuery()->categorySlugs());
    }

    public function testInvalidScopeCategoryAndItemCombinationsFailClosed(): void
    {
        $cases = [
            static fn (): BlogPublicResourceQuery => new BlogPublicResourceQuery(
                'es',
                BlogPublicResourceQuery::SCOPE_ALL,
                ['noticias']
            ),
            static fn (): BlogPublicResourceQuery => new BlogPublicResourceQuery(
                'es',
                BlogPublicResourceQuery::SCOPE_SELECTED,
                []
            ),
            static fn (): BlogPublicResourceQuery => new BlogPublicResourceQuery(
                'es',
                'full'
            ),
            static fn (): BlogPublicResourceQuery => new BlogPublicResourceQuery(
                'es',
                items: -1
            ),
            static fn (): BlogPublicResourceQuery => new BlogPublicResourceQuery(
                'es',
                items: 5,
                limit: 4
            ),
            static fn (): BlogPublicResourceQuery => new BlogPublicResourceQuery(
                'es',
                excludeDummy: false
            ),
            static fn (): BlogPublicResourceQuery => new BlogPublicResourceQuery(
                'es',
                order: 'popular'
            ),
        ];

        foreach ($cases as $position => $case) {
            try {
                $case();
                self::fail('Invalid resource query ' . $position . ' was accepted.');
            } catch (BlogException $exception) {
                self::assertSame(
                    BlogException::INVALID_INPUT,
                    $exception->issueCode()
                );
            }
        }
    }
}
