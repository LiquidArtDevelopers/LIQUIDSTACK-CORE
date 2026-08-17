<?php

declare(strict_types=1);

namespace Tests\Blog\QaFixtures;

use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureCatalog;
use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureException;
use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureSnapshotComparator;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentWalker;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use PHPUnit\Framework\TestCase;

final class BlogQaMatrixFixtureCatalogTest extends TestCase
{
    private const MEDIA = '11111111-1111-4111-8111-111111111111';

    public function testCatalogBuildsThirtyTextRichSemanticV2Variants(): void
    {
        $articles = (new BlogQaMatrixFixtureCatalog())->articles(self::MEDIA);

        self::assertCount(10, $articles);
        $variantIds = [];
        foreach ($articles as $article) {
            self::assertSame(BlogQaMatrixFixtureCatalog::LOCALES, array_keys(
                $article->variants()
            ));
            foreach ($article->variants() as $variant) {
                $draft = $variant->draft();
                self::assertSame(
                    BlogDocument::LAYOUT_VERSION,
                    $draft->document()->version()
                );
                self::assertGreaterThanOrEqual(
                    1_200,
                    mb_strlen(
                        $draft->compatibilityDraft()->bodyText(),
                        'UTF-8'
                    )
                );
                self::assertSame(
                    'noindex,nofollow',
                    $draft->robotsPreferences()->directive()
                );
                self::assertSame(
                    [self::MEDIA],
                    $draft->mediaAssetPublicIds()
                );
                $variantIds[] = $variant->localizationPublicId();
                $variantIds[] = $variant->documentPublicId();
                $variantIds[] = $variant->revisionPublicId();
            }
        }
        self::assertCount(90, array_unique($variantIds));

        $document = $articles[0]->variant('es')->draft()->document();
        $types = $this->types($document->toArray()['blocks']);
        foreach ([
            'section',
            'article',
            'div',
            'paragraph',
            'cta',
            'separator',
            'image',
            'video',
        ] as $type) {
            self::assertContains($type, $types);
        }
        $modules = (new BlogDocumentWalker())->modules($document);
        $moduleTypes = array_column($modules, 'type');
        self::assertNotContains('link', $moduleTypes);
        self::assertGreaterThanOrEqual(
            2,
            count(array_filter(
                $moduleTypes,
                static fn (string $type): bool => $type === 'cta'
            ))
        );
        foreach (['heading', 'list', 'callout', 'quote'] as $legacyTextType) {
            self::assertNotContains($legacyTextType, $moduleTypes);
        }
        $flowTypes = [];
        foreach ($modules as $module) {
            if (($module['type'] ?? null) !== 'paragraph') {
                continue;
            }
            foreach (($module['content'] ?? []) as $flowNode) {
                if (is_string($flowNode['type'] ?? null)) {
                    $flowTypes[] = $flowNode['type'];
                }
            }
        }
        foreach (['heading', 'paragraph', 'list', 'callout', 'quote'] as $type) {
            self::assertContains($type, $flowTypes);
        }
        $videos = array_values(array_filter(
            $modules,
            static fn (array $module): bool =>
                ($module['type'] ?? null) === 'video'
        ));
        self::assertCount(1, $videos);
        self::assertSame('youtube', $videos[0]['provider']);
    }

    public function testCatalogIsDeterministicAndLocaleSelectionIsExplicit(): void
    {
        $catalog = new BlogQaMatrixFixtureCatalog();
        $first = $catalog->articles(self::MEDIA);
        $second = $catalog->articles(self::MEDIA);

        self::assertSame(
            $first[4]->postPublicId(),
            $second[4]->postPublicId()
        );
        self::assertSame(
            $first[4]->variant('eu')->draft()->canonicalJson(),
            $second[4]->variant('eu')->draft()->canonicalJson()
        );
        self::assertSame(
            ['es', 'eu'],
            BlogQaMatrixFixtureCatalog::parseLocales('eu,es')
        );

        foreach (['', 'es,es', 'es,fr', ' es', 'ES, en'] as $invalid) {
            try {
                BlogQaMatrixFixtureCatalog::parseLocales($invalid);
                self::fail('Invalid fixture locales were accepted.');
            } catch (BlogQaMatrixFixtureException $exception) {
                self::assertSame(
                    'blog.qa_fixture.locales_invalid',
                    $exception->issueCode()
                );
            }
        }
    }

    public function testLegacyFixtureShapeRemainsLogicallyIdempotent(): void
    {
        $expected = (new BlogQaMatrixFixtureCatalog())
            ->articles(self::MEDIA)[0]
            ->variant('es')
            ->draft();
        $data = $expected->document()->toArray();
        $textModule = $data['blocks'][0]['children'][0];
        $heading = $textModule['content'][0];
        $data['blocks'][0]['children'][0] = [
            'id' => $textModule['id'],
            'type' => 'heading',
            'level' => $heading['level'],
            'content' => $heading['content'],
            'preset' => $heading['preset'],
            'presentation' => $textModule['presentation'],
        ];
        $plain = $expected->compatibilityDraft();
        $legacy = new BlogStructuredDraft(
            $plain->h1(),
            BlogDocument::fromArray($data),
            $plain->slug(),
            $plain->seoTitle(),
            $plain->metaDescription(),
            $plain->excerpt(),
            robotsPreferences: $plain->robotsPreferences()
        );

        self::assertNotSame($legacy->canonicalJson(), $expected->canonicalJson());
        $comparator = new BlogQaMatrixFixtureSnapshotComparator();
        self::assertTrue($comparator->matches($legacy, $expected));
        self::assertTrue($comparator->matches($expected, $legacy));
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<string>
     */
    private function types(array $nodes): array
    {
        $types = [];
        foreach ($nodes as $node) {
            $type = $node['type'] ?? null;
            if (is_string($type)) {
                $types[] = $type;
            }
            if (is_array($node['children'] ?? null)) {
                $types = array_merge($types, $this->types(
                    $node['children']
                ));
            }
            $columns = $node['layout']['columns'] ?? null;
            if (is_array($columns)) {
                foreach ($columns as $column) {
                    if (is_array($column['children'] ?? null)) {
                        $types = array_merge($types, $this->types(
                            $column['children']
                        ));
                    }
                }
            }
        }

        return array_values(array_unique($types));
    }
}
