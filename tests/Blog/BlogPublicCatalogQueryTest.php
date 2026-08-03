<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Core\Blog\BlogException;
use App\Core\Blog\PublicFeed\BlogPublicCatalogQuery;
use PHPUnit\Framework\TestCase;

final class BlogPublicCatalogQueryTest extends TestCase
{
    public function testDefaultsAndNormalizedFiltersAreImmutable(): void
    {
        $defaults = new BlogPublicCatalogQuery('es');
        self::assertSame('es', $defaults->locale());
        self::assertNull($defaults->search());
        self::assertSame([], $defaults->categorySlugs());
        self::assertSame(BlogPublicCatalogQuery::MODE_ANY, $defaults->categoryMode());
        self::assertSame(12, $defaults->limit());
        self::assertSame(0, $defaults->offset());
        self::assertNull($defaults->excludeSlug());
        self::assertFalse($defaults->hasFilters());

        $query = new BlogPublicCatalogQuery(
            'es',
            '  Matrix    Reloaded  ',
            ['noticias', 'cine', 'noticias'],
            BlogPublicCatalogQuery::MODE_ALL,
            50,
            10_000,
            'matrix-reloaded'
        );
        self::assertSame('Matrix Reloaded', $query->search());
        self::assertSame(['noticias', 'cine'], $query->categorySlugs());
        self::assertSame(BlogPublicCatalogQuery::MODE_ALL, $query->categoryMode());
        self::assertSame(50, $query->limit());
        self::assertSame(10_000, $query->offset());
        self::assertSame('matrix-reloaded', $query->excludeSlug());
        self::assertTrue($query->hasFilters());

        self::assertTrue((new BlogPublicCatalogQuery(
            'es',
            null,
            [],
            'any',
            12,
            0,
            'actual'
        ))->hasFilters());
    }

    public function testBlankSearchNormalizesToNoSearch(): void
    {
        $query = new BlogPublicCatalogQuery('es', '      ');

        self::assertNull($query->search());
        self::assertFalse($query->hasFilters());
    }

    public function testExactBoundariesAreAccepted(): void
    {
        $categories = [];
        for (
            $index = 1;
            $index <= BlogPublicCatalogQuery::MAX_CATEGORIES;
            ++$index
        ) {
            $categories[] = 'categoria-' . $index;
        }
        $query = new BlogPublicCatalogQuery(
            'es',
            str_repeat(
                'x',
                BlogPublicCatalogQuery::MAX_SEARCH_CHARACTERS
            ),
            $categories,
            BlogPublicCatalogQuery::MODE_ANY,
            BlogPublicCatalogQuery::MAX_LIMIT,
            BlogPublicCatalogQuery::MAX_OFFSET
        );

        self::assertSame(
            BlogPublicCatalogQuery::MAX_SEARCH_CHARACTERS,
            mb_strlen($query->search() ?? '', 'UTF-8')
        );
        self::assertCount(
            BlogPublicCatalogQuery::MAX_CATEGORIES,
            $query->categorySlugs()
        );

        $unicode = new BlogPublicCatalogQuery('es', str_repeat('á', 61));
        self::assertSame(61, mb_strlen($unicode->search() ?? '', 'UTF-8'));
        self::assertSame(122, strlen($unicode->search() ?? ''));

        $fourByte = new BlogPublicCatalogQuery('es', str_repeat('😀', 120));
        self::assertSame(120, mb_strlen(
            $fourByte->search() ?? '',
            'UTF-8'
        ));
        self::assertSame(480, strlen($fourByte->search() ?? ''));
    }

    public function testEveryInvalidBoundaryFailsWithTheStableDomainError(): void
    {
        $tooManyCategories = [];
        for (
            $index = 1;
            $index <= BlogPublicCatalogQuery::MAX_CATEGORIES + 1;
            ++$index
        ) {
            $tooManyCategories[] = 'categoria-' . $index;
        }
        $cases = [
            static fn (): BlogPublicCatalogQuery =>
                new BlogPublicCatalogQuery('ES'),
            static fn (): BlogPublicCatalogQuery =>
                new BlogPublicCatalogQuery('es', str_repeat('x', 121)),
            static fn (): BlogPublicCatalogQuery =>
                new BlogPublicCatalogQuery('es', 'x'),
            static fn (): BlogPublicCatalogQuery =>
                new BlogPublicCatalogQuery(
                    'es',
                    str_repeat(' ', 479) . 'ab'
                ),
            static fn (): BlogPublicCatalogQuery =>
                new BlogPublicCatalogQuery('es', "linea\nnueva"),
            static fn (): BlogPublicCatalogQuery =>
                new BlogPublicCatalogQuery('es', "\xC3\x28"),
            static fn (): BlogPublicCatalogQuery =>
                new BlogPublicCatalogQuery('es', '<strong>matrix</strong>'),
            static fn (): BlogPublicCatalogQuery =>
                new BlogPublicCatalogQuery('es', null, ['No-Valida']),
            static fn (): BlogPublicCatalogQuery =>
                new BlogPublicCatalogQuery('es', null, [42]),
            static fn (): BlogPublicCatalogQuery =>
                new BlogPublicCatalogQuery('es', null, $tooManyCategories),
            static fn (): BlogPublicCatalogQuery =>
                new BlogPublicCatalogQuery('es', null, [], 'some'),
            static fn (): BlogPublicCatalogQuery =>
                new BlogPublicCatalogQuery('es', null, [], 'ANY'),
            static fn (): BlogPublicCatalogQuery =>
                new BlogPublicCatalogQuery('es', null, [], 'any', 0),
            static fn (): BlogPublicCatalogQuery =>
                new BlogPublicCatalogQuery('es', null, [], 'any', 51),
            static fn (): BlogPublicCatalogQuery =>
                new BlogPublicCatalogQuery('es', null, [], 'any', 12, -1),
            static fn (): BlogPublicCatalogQuery =>
                new BlogPublicCatalogQuery(
                    'es',
                    null,
                    [],
                    'any',
                    12,
                    10_001
                ),
            static fn (): BlogPublicCatalogQuery =>
                new BlogPublicCatalogQuery(
                    'es',
                    null,
                    [],
                    'any',
                    12,
                    0,
                    'No-Valido'
                ),
        ];

        foreach ($cases as $position => $case) {
            try {
                $case();
                self::fail('Invalid query case ' . $position . ' was accepted.');
            } catch (BlogException $exception) {
                self::assertSame(
                    BlogException::INVALID_INPUT,
                    $exception->issueCode(),
                    'Unexpected issue for invalid query case ' . $position
                );
            }
        }
    }
}
