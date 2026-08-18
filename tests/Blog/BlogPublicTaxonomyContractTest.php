<?php

declare(strict_types=1);

use App\Core\Blog\BlogException;
use App\Core\Blog\PublicFeed\BlogPublicCardCategory;
use App\Core\Blog\PublicFeed\BlogPublicCardTag;
use App\Core\Blog\PublicFeed\BlogPublicCardTaxonomyBatch;
use App\Core\Blog\PublicFeed\BlogPublicCardTaxonomyQuery;
use PHPUnit\Framework\TestCase;

final class BlogPublicTaxonomyContractTest extends TestCase
{
    public function testQueryDeduplicatesAndBoundsTheCardBatch(): void
    {
        $slugs = array_map(
            static fn (int $index): string => 'article-' . $index,
            range(1, BlogPublicCardTaxonomyQuery::MAX_CARDS)
        );
        $query = new BlogPublicCardTaxonomyQuery(
            'es',
            [...$slugs, $slugs[0]]
        );

        self::assertSame('es', $query->locale());
        self::assertSame($slugs, $query->cardSlugs());

        try {
            new BlogPublicCardTaxonomyQuery('es', [
                ...$slugs,
                'article-overflow',
            ]);
            self::fail('An oversized public taxonomy batch was accepted.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::INVALID_INPUT,
                $exception->issueCode()
            );
        }
    }

    public function testBatchCapsTagsAndProjectsNoIdentifiers(): void
    {
        $tags = array_map(
            static fn (int $index): BlogPublicCardTag =>
                new BlogPublicCardTag(
                    'es',
                    'tag-' . $index,
                    'Etiqueta ' . $index
                ),
            range(1, BlogPublicCardTaxonomyBatch::MAX_TAGS_PER_CARD)
        );
        $batch = new BlogPublicCardTaxonomyBatch(
            ['article' => [new BlogPublicCardCategory(
                'es',
                'actualidad',
                'Actualidad'
            )]],
            ['article' => $tags]
        );

        $projection = $batch->tagsBySlug()['article'][0]->toResourceData();
        self::assertSame([
            'locale' => 'es',
            'slug' => 'tag-1',
            'name' => 'Etiqueta 1',
        ], $projection);
        self::assertArrayNotHasKey('id', $projection);
        self::assertArrayNotHasKey('public_id', $projection);

        $this->expectException(InvalidArgumentException::class);
        new BlogPublicCardTaxonomyBatch(
            ['article' => []],
            ['article' => [
                ...$tags,
                new BlogPublicCardTag('es', 'tag-overflow', 'Desborde'),
            ]]
        );
    }

    public function testBatchRequiresMatchingCompleteMaps(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BlogPublicCardTaxonomyBatch(
            ['article' => []],
            ['another-article' => []]
        );
    }
}
