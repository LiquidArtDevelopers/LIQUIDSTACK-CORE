<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\Preview\BlogPreviewAssetContext;
use App\Core\Blog\Preview\BlogPreviewAssetSet;
use App\Core\Blog\Seo\BlogSeoAnalyzer;
use App\Core\Blog\Seo\BlogSeoSemanticProjector;
use App\Core\Blog\Seo\BlogSeoStatus;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentException;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTextProjector;
use App\Core\Blog\StructuredContent\Document\BlogHeadingLevelPolicy;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Rendering\BlogDocumentHtmlRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;
use App\Core\Blog\StructuredContent\Rendering\BlogStructuredEditorHtmlRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogStructuredPrivateHtmlRenderer;
use DateTimeImmutable;
use JsonException;
use PHPUnit\Framework\TestCase;

final class BlogHeadingLevelPolicyTest extends TestCase
{
    private const POST = '71000000-0000-4000-8000-000000000001';
    private const LOCALIZATION = '71000000-0000-4000-8000-000000000002';
    private const AUTHOR = '71000000-0000-4000-8000-000000000003';

    public function testPolicyPublishesOnlyH2ThroughH6WithContextualDefaults(): void
    {
        $policy = new BlogHeadingLevelPolicy();

        self::assertSame([2, 3, 4, 5, 6], $policy->allowedLevels());
        self::assertSame(2, $policy->defaultFor('section'));
        self::assertSame(3, $policy->defaultFor('article'));
        self::assertSame(3, $policy->defaultFor('div'));
        self::assertSame([
            'allowed_levels' => [2, 3, 4, 5, 6],
            'defaults' => ['section' => 2, 'article' => 3, 'div' => 3],
        ], $policy->toSafeArray());
        self::assertFalse($policy->allows(1));
        self::assertFalse($policy->allows(7));
        self::assertFalse($policy->allows('2'));
    }

    public function testEveryAllowedLevelWorksInSectionArticleAndDivision(): void
    {
        foreach ([2, 3, 4, 5, 6] as $level) {
            $raw = $this->documentWithLevels($level, $level, $level);
            $document = BlogDocument::fromArray($raw);
            $section = $document->blocks()[0];

            self::assertSame($level, $section['children'][0]['level']);
            self::assertSame(
                $level,
                $section['children'][1]['layout']['columns'][0]
                    ['children'][0]['level']
            );
            self::assertSame(
                $level,
                $section['children'][2]['layout']['columns'][0]
                    ['children'][0]['level']
            );
        }
    }

    public function testDirectSectionHeadingsAndRepeatedArticleLevelsAreAllowed(): void
    {
        $document = BlogDocument::fromArray($this->chosenLevelDocument());
        $section = $document->blocks()[0];

        self::assertSame(4, $section['children'][1]['level']);
        self::assertSame(
            [2, 3, 3],
            array_column(
                $section['children'][2]['layout']['columns'][0]['children'],
                'level'
            )
        );
    }

    public function testH1OutOfRangeMissingAndNonIntegerLevelsFailClosed(): void
    {
        foreach ([1, 7, '2', 2.0, null] as $level) {
            $this->assertInvalidBlock(
                $this->documentWithLevels($level, 3, 3)
            );
            $this->assertInvalidBlock(
                $this->documentWithLevels(2, $level, 3)
            );
            $this->assertInvalidBlock(
                $this->documentWithLevels(2, 3, $level)
            );
        }

        $missing = $this->documentWithLevels(2, 3, 3);
        unset($missing['blocks'][0]['children'][0]['level']);
        $this->assertInvalidBlock($missing);
    }

    public function testCanonicalRoundTripPreservesChosenLevelsAndShape(): void
    {
        $codec = new BlogDocumentCodec();
        $raw = $this->chosenLevelDocument();
        $json = json_encode(
            $raw,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        );

        $document = $codec->decode($json);

        self::assertSame($raw, $document->toArray());
        self::assertSame($json, $codec->encode($document));
        self::assertSame(
            $raw,
            $codec->decodeDraft($codec->encode($document))->toArray()
        );
        self::assertArrayNotHasKey('heading_policy', $document->toArray());
    }

    public function testPublicSsrAndPrivatePreviewKeepChosenSemanticTags(): void
    {
        $document = BlogDocument::fromArray($this->chosenLevelDocument());
        $renderer = $this->documentRenderer();
        $main = $renderer->renderMain($document);

        self::assertStringContainsString(
            '<section id="blog-container-' . $this->id(1)
                . '" class="blogDocument__section" aria-labelledby="'
                . 'blog-block-' . $this->id(2) . '">',
            $main
        );
        self::assertStringContainsString(
            '<h6 id="blog-block-' . $this->id(2),
            $main
        );
        self::assertStringContainsString(
            '<h4 id="blog-block-' . $this->id(3),
            $main
        );
        self::assertStringContainsString(
            '<article id="blog-container-' . $this->id(4),
            $main
        );
        self::assertStringContainsString(
            'aria-labelledby="blog-block-' . $this->id(6) . '">',
            $main
        );
        self::assertStringContainsString(
            '<h2 id="blog-block-' . $this->id(6),
            $main
        );
        self::assertStringContainsString(
            '<h5 id="blog-block-' . $this->id(11),
            $main
        );

        $preview = (new BlogStructuredPrivateHtmlRenderer($renderer))->preview(
            '/admin/blog',
            $this->variant($document),
            $document,
            null,
            BlogPreviewAssetSet::standalone(new BlogPreviewAssetContext(
                dirname(__DIR__, 3),
                false
            ))
        );
        self::assertStringContainsString($main, $preview);
        self::assertStringContainsString(
            '<meta name="robots" content="noindex,nofollow,noarchive">',
            $preview
        );
    }

    public function testPublicationSnapshotKeepsV2LevelsAndSafeV1Fallback(): void
    {
        $document = BlogDocument::fromArray($this->chosenLevelDocument());
        $draft = $this->structuredDraft($document);
        $codec = new BlogDocumentCodec();

        self::assertTrue($draft->compatibilityDraft()->isPublishable());
        self::assertSame(
            [6, 4, 2, 3, 3, 5],
            array_column(
                (new BlogSeoSemanticProjector())->project(
                    $codec->decode($draft->canonicalJson())
                )->headings(),
                'level'
            )
        );
        self::assertSame(BlogDocument::VERSION, $draft->compatibilitySchemaVersion());
        self::assertSame(
            [2, 3, 2, 3, 3, 4],
            array_column(
                (new BlogSeoSemanticProjector())->project(
                    $draft->compatibilityDocument()
                )->headings(),
                'level'
            )
        );
        self::assertSame(
            (new BlogDocumentTextProjector())->project($document),
            $draft->compatibilityDraft()->bodyText()
        );
    }

    public function testSeoReportsChosenLevelJumpsWithoutBlockingTheDocument(): void
    {
        $document = BlogDocument::fromArray($this->chosenLevelDocument());
        $draft = $this->structuredDraft($document);
        $analysis = (new BlogSeoAnalyzer())->analyze(
            $draft,
            'es',
            '/noticias'
        );
        $headingCheck = null;
        foreach ($analysis->checks() as $check) {
            if ($check->key() === 'content.heading_structure') {
                $headingCheck = $check;
                break;
            }
        }

        self::assertNotNull($headingCheck);
        self::assertSame(BlogSeoStatus::REVIEW, $headingCheck->status());
        self::assertGreaterThan(
            0,
            $headingCheck->toArray()['metrics']['hierarchy_issues']
        );
    }

    /** @throws JsonException */
    public function testEditorSsrExposesTheExactBackendHeadingPolicy(): void
    {
        $document = BlogDocument::fromArray($this->chosenLevelDocument());
        $html = (new BlogStructuredEditorHtmlRenderer())->render(
            '/admin/blog',
            'safe-csrf-token',
            $this->variant($document),
            $document,
            (new BlogDocumentCodec())->encode($document),
            layoutEditorReady: true
        );

        self::assertSame(
            1,
            preg_match('/data-blog-heading-policy="([^"]+)"/', $html, $match)
        );
        $json = html_entity_decode(
            $match[1],
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );
        self::assertSame([
            'allowed_levels' => [2, 3, 4, 5, 6],
            'defaults' => ['section' => 2, 'article' => 3, 'div' => 3],
        ], json_decode($json, true, 8, JSON_THROW_ON_ERROR));
    }

    public function testHistoricalV1DocumentRemainsByteForByteCanonical(): void
    {
        $legacy = [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::VERSION,
            'template' => 'article-basic-01',
            'blocks' => [
                $this->flatHeading(40, 2, 'Legacy section'),
                $this->flatHeading(41, 3, 'Legacy article'),
            ],
        ];
        $json = json_encode(
            $legacy,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        );
        $codec = new BlogDocumentCodec();

        self::assertSame($json, $codec->encode($codec->decode($json)));
    }

    /** @return array<string, mixed> */
    private function chosenLevelDocument(): array
    {
        return [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => 'article-basic-01',
            'blocks' => [[
                'id' => $this->id(1),
                'type' => 'section',
                'children' => [
                    $this->heading(2, 6, 'Custom section level'),
                    $this->heading(3, 4, 'Direct section heading'),
                    $this->article(4, 5, [
                        $this->heading(6, 2, 'Custom article level'),
                        $this->heading(7, 3, 'Article detail'),
                        $this->heading(8, 3, 'Repeated article level'),
                    ]),
                    $this->division(9, 10, [
                        $this->heading(11, 5, 'Division heading'),
                    ]),
                ],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function documentWithLevels(
        mixed $sectionLevel,
        mixed $articleLevel,
        mixed $divisionLevel
    ): array {
        return [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => 'article-basic-01',
            'blocks' => [[
                'id' => $this->id(20),
                'type' => 'section',
                'children' => [
                    $this->heading(21, $sectionLevel, 'Section'),
                    $this->article(22, 23, [
                        $this->heading(24, $articleLevel, 'Article'),
                    ]),
                    $this->division(25, 26, [
                        $this->heading(27, $divisionLevel, 'Division'),
                    ]),
                ],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function article(int $id, int $columnId, array $children): array
    {
        return $this->container('article', $id, $columnId, $children);
    }

    /** @return array<string, mixed> */
    private function division(int $id, int $columnId, array $children): array
    {
        return $this->container('div', $id, $columnId, $children);
    }

    /**
     * @param list<array<string, mixed>> $children
     * @return array<string, mixed>
     */
    private function container(
        string $type,
        int $id,
        int $columnId,
        array $children
    ): array {
        return [
            'id' => $this->id($id),
            'type' => $type,
            'layout' => [
                'preset' => '1',
                'columns' => [[
                    'id' => $this->id($columnId),
                    'children' => $children,
                ]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function heading(int $id, mixed $level, string $text): array
    {
        return $this->flatHeading($id, $level, $text) + [
            'presentation' => [
                'width' => 'full',
                'align' => 'start',
                'text_align' => 'start',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function flatHeading(int $id, mixed $level, string $text): array
    {
        return [
            'id' => $this->id($id),
            'type' => 'heading',
            'level' => $level,
            'content' => [[
                'type' => 'text',
                'text' => $text,
                'marks' => [],
            ]],
        ];
    }

    /** @param array<string, mixed> $raw */
    private function assertInvalidBlock(array $raw): void
    {
        try {
            BlogDocument::fromArray($raw);
            self::fail('The invalid heading level was accepted.');
        } catch (BlogDocumentException $exception) {
            self::assertSame(
                BlogDocumentException::INVALID_BLOCK,
                $exception->issueCode()
            );
        }
    }

    private function structuredDraft(BlogDocument $document): BlogStructuredDraft
    {
        return new BlogStructuredDraft(
            'A complete Matrix heading hierarchy guide',
            $document,
            'matrix-heading-levels',
            'Matrix heading levels for structured Blog articles',
            'Choose semantic heading levels without changing their visual preset.',
            'A guide to deliberately chosen semantic heading levels.'
        );
    }

    private function variant(BlogDocument $document): BlogPostVariant
    {
        $draft = $this->structuredDraft($document)->compatibilityDraft();
        $now = new DateTimeImmutable('2026-08-10T12:00:00+00:00');

        return new BlogPostVariant(
            self::POST,
            self::LOCALIZATION,
            'es',
            $draft,
            BlogPostVariant::DRAFT,
            null,
            1,
            self::AUTHOR,
            self::AUTHOR,
            $now,
            $now
        );
    }

    private function documentRenderer(): BlogDocumentHtmlRenderer
    {
        return new BlogDocumentHtmlRenderer(
            new class implements BlogImageResolverInterface {
                public function resolve(
                    string $mediaAssetPublicId
                ): ?BlogResolvedImage {
                    return null;
                }
            }
        );
    }

    private function id(int $number): string
    {
        return sprintf(
            '72000000-0000-4000-8000-%012d',
            $number
        );
    }
}
